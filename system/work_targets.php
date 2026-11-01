<?php
/**
 ***********************************************************************************************
 * Arbeitsdienst: Sollstunden abgleichen
 *
 * Berechnet fuer jedes Mitglied das Soll nach den Regeln aus Einstellungen -> Arbeitsdienst (WorkRules)
 * mit den Rollen am 31.12. des Leistungsjahres und vergleicht es mit dem Profilfeld
 * "Sollstunden Arbeitsdienst" (ADTARGET<org>). Abweichungen werden angezeigt und nur fuer die
 * angehakten Mitglieder uebernommen; von Hand eingetragene Sonderfaelle bleiben so erhalten.
 *
 * Neuer Wert: pflichtig = Sollstunden, befreit = 0, keine Rolle mit Sollstunden = leer.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode : show (Standard) | apply (POST usr_id[])
 * year : Leistungsjahr (Standard: aktuelles Jahr)
 * all  : 1 = auch Mitglieder ohne Abweichung anzeigen
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Work\WorkProfileFields;
use Plugins\MembershipFee\classes\Work\WorkRules;
use Plugins\MembershipFee\classes\Work\WorkTarget;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    if (!isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'show', 'validValues' => array('show', 'apply')));
    $getYear = admFuncVariableIsValid($_GET, 'year', 'int', array('defaultValue' => (int) date('Y')));
    if ($getYear < 2000 || $getYear > 2100) {
        $getYear = (int) date('Y');
    }
    $getAll = admFuncVariableIsValid($_GET, 'all', 'bool');

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    $rules = WorkRules::fromConfig($pPreferences->config);
    $orgId = (int) $gCurrentOrgId;
    $selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/work_targets.php';

    // Profilfelder bei Bedarf anlegen
    WorkProfileFields::ensure($orgId);
    $targetField = WorkProfileFields::targetField($orgId);
    $targetUsfId = (int) $gProfileFields->getProperty($targetField, 'usf_id');

    /**
     * Soll aller Mitglieder im Leistungsjahr und bisheriger Feldwert.
     * @return array<int, array{name:string, target:WorkTarget, new:string, current:string}>
     */
    $calculate = static function (int $year) use ($gDb, $gProfileFields, $orgId, $rules, $targetUsfId): array {
        $reference = sprintf('%04d-12-31', $year);

        // Rollen am 31.12. des Leistungsjahres
        $roles = array();
        $statement = $gDb->queryPrepared('SELECT mem.mem_usr_id, rol.rol_id, rol.rol_name, rol.rol_cat_id
              FROM ' . TBL_MEMBERS . ' AS mem
        INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id AND rol.rol_valid = true
        INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id AND cat.cat_type = \'ROL\' AND (cat.cat_org_id = ? OR cat.cat_org_id IS NULL)
             WHERE mem.mem_begin <= ? AND mem.mem_end >= ?', array($orgId, $reference, $reference));
        while ($row = $statement->fetch()) {
            $roles[(int) $row['mem_usr_id']][] = array('id' => (int) $row['rol_id'], 'name' => (string) $row['rol_name'], 'category' => (int) $row['rol_cat_id']);
        }

        // Profildaten: Name, Geburtstag, bisheriges Soll
        $data = array();
        $usfIds = array(
            'last' => (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
            'first' => (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
            'birthday' => (int) $gProfileFields->getProperty('BIRTHDAY', 'usf_id'),
            'target' => $targetUsfId
        );
        $statement = $gDb->queryPrepared('SELECT usd.usd_usr_id, usd.usd_usf_id, usd.usd_value
              FROM ' . TBL_USER_DATA . ' AS usd
        INNER JOIN ' . TBL_USERS . ' AS usr ON usr.usr_id = usd.usd_usr_id AND usr.usr_valid = true
             WHERE usd.usd_usf_id IN (?, ?, ?, ?)', array_values($usfIds));
        $keyByUsf = array_flip($usfIds);
        while ($row = $statement->fetch()) {
            $data[(int) $row['usd_usr_id']][$keyByUsf[(int) $row['usd_usf_id']]] = (string) $row['usd_value'];
        }

        $result = array();
        foreach (array_unique(array_merge(array_keys($roles), array_keys(array_filter($data, static fn (array $d): bool => ($d['target'] ?? '') !== '')))) as $usrId) {
            if (!isset($data[$usrId])) {
                continue;   // kein gueltiger Benutzer
            }
            $target = $rules->target($roles[$usrId] ?? array(), $data[$usrId]['birthday'] ?? null, $year);
            $new = match ($target->status) {
                WorkTarget::REQUIRED => rtrim(rtrim(number_format($target->hours, 2, '.', ''), '0'), '.'),
                WorkTarget::EXEMPT => '0',
                default => ''
            };
            $current = (string) ($data[$usrId]['target'] ?? '');
            if ($new === '' && $current === '' && $target->status === WorkTarget::NONE) {
                continue;   // weder pflichtig noch bisher ein Wert: nicht anzeigen
            }
            $result[$usrId] = array(
                'name' => trim(($data[$usrId]['last'] ?? '') . ', ' . ($data[$usrId]['first'] ?? ''), ', '),
                'target' => $target,
                'new' => $new,
                'current' => $current
            );
        }
        uasort($result, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $result;
    };

    $differs = static function (array $row): bool {
        if ($row['new'] === '' || $row['current'] === '') {
            return $row['new'] !== $row['current'];
        }
        return abs((float) str_replace(',', '.', $row['current']) - (float) $row['new']) >= 0.005;
    };

    // ------------------------------------------------------------------
    // Uebernehmen
    // ------------------------------------------------------------------
    if ($getMode === 'apply') {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $selected = array_flip(array_map('intval', (array) ($_POST['usr_id'] ?? array())));
        $rows = $calculate($getYear);

        $gDb->startTransaction();
        $count = 0;
        foreach ($rows as $usrId => $row) {
            if (!isset($selected[$usrId]) || !$differs($row)) {
                continue;
            }
            $user = new User($gDb, $gProfileFields, $usrId);
            $user->setValue($targetField, $row['new']);
            $user->save();
            $count++;
        }
        $gDb->endTransaction();

        $_SESSION['pMembershipFee']['work_message'] = $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_APPLIED', array($count));
        admRedirect(SecurityUtils::encodeUrl($selfUrl, array('year' => $getYear)));
        // => EXIT
    }

    // ------------------------------------------------------------------
    // Anzeige
    // ------------------------------------------------------------------
    $headline = $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS');
    $gNavigation->addUrl(CURRENT_URL, $headline);
    $page = new HtmlPage('plg-mitgliedsbeitrag-work-targets', $headline);
    $page->setContentFullWidth();

    if (!empty($_SESSION['pMembershipFee']['work_message'])) {
        $page->addHtml('<div class="alert alert-success" role="alert"><i class="bi bi-check-lg"></i> ' . SecurityUtils::encodeHTML($_SESSION['pMembershipFee']['work_message']) . '</div>');
        unset($_SESSION['pMembershipFee']['work_message']);
    }

    if (count($rules->targetHours()) === 0) {
        $page->addHtml('<div class="alert alert-warning" role="alert">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_NO_RULES') . '</div>');
        $page->show();
        exit();
    }

    // Leistungsjahr
    $yearLinks = array();
    foreach (array((int) date('Y') + 1, (int) date('Y'), (int) date('Y') - 1) as $year) {
        $yearLinks[] = ($year === $getYear)
            ? '<span class="btn btn-primary btn-sm disabled">' . $year . '</span>'
            : '<a class="btn btn-secondary btn-sm" href="' . SecurityUtils::encodeUrl($selfUrl, array('year' => $year)) . '">' . $year . '</a>';
    }
    $page->addHtml('<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_YEAR') . ': ' . implode(' ', $yearLinks)
        . '<br/><small class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_DESC', array($getYear)) . '</small></p>');

    $rows = $calculate($getYear);
    $countRequired = 0;
    $countExempt = 0;
    $sumTarget = 0.0;
    $changes = array();
    foreach ($rows as $usrId => $row) {
        if ($row['target']->isRequired()) {
            $countRequired++;
            $sumTarget += $row['target']->hours;
        } elseif ($row['target']->status === WorkTarget::EXEMPT) {
            $countExempt++;
        }
        if ($differs($row)) {
            $changes[$usrId] = true;
        }
    }

    // Kennzahlen
    $stat = static fn (string $value, string $label): string => '<div class="text-center px-2"><div class="fs-4 fw-bold lh-1">' . $value . '</div><div class="small text-muted">' . $label . '</div></div>';
    $page->addHtml('<div class="card mb-3 border-primary"><div class="card-body d-flex flex-wrap align-items-center gap-3">'
        . '<h4 class="mb-0 me-auto"><i class="bi bi-bullseye"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_HEADING', array($getYear)) . '</h4>'
        . $stat((string) $countRequired, $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_REQUIRED'))
        . $stat(substr(WorkRules::formatHours($sumTarget), 0, -2), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_TARGET_HOURS'))
        . $stat((string) $countExempt, $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_EXEMPT'))
        . $stat((string) count($changes), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_CHANGES'))
        . '</div></div>');

    $page->addHtml('<p>' . ($getAll
        ? '<a href="' . SecurityUtils::encodeUrl($selfUrl, array('year' => $getYear)) . '">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_ONLY_CHANGES') . '</a>'
        : '<a href="' . SecurityUtils::encodeUrl($selfUrl, array('year' => $getYear, 'all' => 1)) . '">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_SHOW_ALL') . '</a>') . '</p>');

    $visible = $getAll ? $rows : array_intersect_key($rows, $changes);
    if (count($visible) === 0) {
        $page->addHtml('<div class="alert alert-success" role="alert"><i class="bi bi-check-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_NO_CHANGES') . '</div>');
        $page->show();
        exit();
    }

    $formatValue = static fn (string $value): string => $value === '' ? '<span class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_EMPTY') . '</span>' : WorkRules::formatHours((float) str_replace(',', '.', $value));
    $tableRows = '';
    foreach ($visible as $usrId => $row) {
        $changed = isset($changes[$usrId]);
        $tableRows .= '<tr' . ($changed ? '' : ' class="text-muted"') . '>'
            . '<td>' . ($changed ? '<input type="checkbox" class="form-check-input work-target-select" name="usr_id[]" value="' . (int) $usrId . '" checked="checked" />' : '') . '</td>'
            . '<td>' . SecurityUtils::encodeHTML($row['name']) . '</td>'
            . '<td style="text-align: right;">' . $formatValue($row['current']) . '</td>'
            . '<td style="text-align: right;"><strong>' . $formatValue($row['new']) . '</strong></td>'
            . '<td>' . SecurityUtils::encodeHTML($row['target']->reason) . '</td>'
            . '</tr>';
    }
    $buttons = '<div class="my-2"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_APPLY') . '</button></div>';
    $page->addHtml('<form method="post" action="' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'apply', 'year' => $getYear)) . '">'
        . '<input type="hidden" name="adm_csrf_token" value="' . SecurityUtils::encodeHTML($gCurrentSession->getCsrfToken()) . '" />'
        . (count($changes) > 0 ? $buttons : '')
        . '<div class="table-responsive"><table class="table table-condensed table-hover"><thead><tr>'
        . '<th>' . (count($changes) > 0 ? '<input type="checkbox" class="form-check-input" id="work_target_all" checked="checked" title="' . $gL10n->get('SYS_ALL') . '" />' : '') . '</th>'
        . '<th>' . $gL10n->get('SYS_NAME') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_CURRENT') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_NEW') . '</th>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_REASON') . '</th>'
        . '</tr></thead><tbody>' . $tableRows . '</tbody></table></div>'
        . (count($changes) > 0 ? $buttons : '')
        . '</form>');
    $page->addJavascript('
        $("#work_target_all").change(function() {
            $("input.work-target-select").prop("checked", $(this).prop("checked"));
        });', true);

    $page->show();
} catch (Exception $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
}
