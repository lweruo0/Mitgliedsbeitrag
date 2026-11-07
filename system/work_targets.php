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
 * Zusaetzlich wie in der Jahresuebersicht: Ist (erfasste Stunden im Leistungsjahr), Fehlstunden,
 * Ersatzbeitrag und Stand (bezogen auf das eingetragene Soll), Filter nach Stand und CSV-Export
 * (ueber work_overview.php?mode=csv).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode : show (Standard) | apply (POST usr_id[]) | set (AJAX, POST usr_id, hours)
 * year : Leistungsjahr (Standard: aktuelles Jahr)
 * all  : 1 = auch Mitglieder ohne Abweichung anzeigen
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Work\WorkHoursStore;
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

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'show', 'validValues' => array('show', 'apply', 'set')));
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
    WorkHoursStore::ensureTable();
    $targetField = WorkProfileFields::targetField($orgId);
    $targetUsfId = (int) $gProfileFields->getProperty($targetField, 'usf_id');

    /**
     * Soll aller Mitglieder im Leistungsjahr und bisheriger Feldwert.
     * Dazu die im Leistungsjahr erfassten Stunden (Ist); Mitglieder mit Stunden, aber ohne Soll, werden
     * ebenfalls aufgefuehrt (wie in der Jahresuebersicht).
     * @return array<int, array{name:string, target:WorkTarget, new:string, current:string, done:float}>
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

        $done = WorkHoursStore::sumByUser($year);

        $result = array();
        foreach (array_unique(array_merge(array_keys($roles), array_keys($done), array_keys(array_filter($data, static fn (array $d): bool => ($d['target'] ?? '') !== '')))) as $usrId) {
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
            if ($new === '' && $current === '' && $target->status === WorkTarget::NONE && !isset($done[$usrId])) {
                continue;   // weder pflichtig noch bisher ein Wert noch Stunden: nicht anzeigen
            }
            $result[$usrId] = array(
                'name' => trim(($data[$usrId]['last'] ?? '') . ', ' . ($data[$usrId]['first'] ?? ''), ', '),
                'target' => $target,
                'new' => $new,
                'current' => $current,
                'done' => (float) ($done[$usrId] ?? 0.0)
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

    /**
     * Stand wie in der Jahresuebersicht, bezogen auf das eingetragene Soll (Spalte "Bisher"):
     * none = kein Soll, exempt = Soll 0, missing = Fehlstunden, done = erfuellt
     * @return array{status:string, missing:float, fee:float}
     */
    $workState = static function (array $row) use ($rules): array {
        if ($row['current'] === '') {
            return array('status' => 'none', 'missing' => 0.0, 'fee' => 0.0);
        }
        $target = (float) str_replace(',', '.', $row['current']);
        $missing = WorkRules::missingHours($target, $row['done']);
        return array(
            'status' => $target <= 0.0 ? 'exempt' : ($missing > 0.0 ? 'missing' : 'done'),
            'missing' => $missing,
            'fee' => $rules->replacementFee($target, $row['done'])
        );
    };

    // ------------------------------------------------------------------
    // Sollstunden eines Mitglieds von Hand setzen (AJAX, Stift-Symbol in der Spalte "Bisher")
    // ------------------------------------------------------------------
    if ($getMode === 'set') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
            $usrId = (int) ($_POST['usr_id'] ?? 0);
            $value = trim(str_replace(',', '.', (string) ($_POST['hours'] ?? '')));
            if ($value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 999)) {
                throw new Exception($gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_SET_INVALID'));
            }
            $user = new User($gDb, $gProfileFields, $usrId);
            if ($usrId <= 0 || (int) $user->getValue('usr_id') !== $usrId) {
                throw new Exception('SYS_NO_RIGHTS');
            }
            $value = $value === '' ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
            $user->setValue($targetField, $value);
            $user->save();
            echo json_encode(array(
                'ok' => true,
                'value' => $value,
                'formatted' => $value === '' ? $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_EMPTY') : WorkRules::formatHours((float) $value)
            ));
        } catch (\Throwable $e) {
            echo json_encode(array('ok' => false, 'message' => $e->getMessage()));
        }
        exit();
    }

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
    $headline = mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS'));
    $gNavigation->addUrl(CURRENT_URL, $headline);
    $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-work-targets', $headline);
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
    $page->addHtml(mf_year_switch_html($getYear, array((int) date('Y') + 1, (int) date('Y'), (int) date('Y') - 1), $selfUrl, array(),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_DESC', array($getYear))));

    $rows = $calculate($getYear);
    $countRequired = 0;
    $countExempt = 0;
    $sumTarget = 0.0;
    $changes = array();
    $states = array();
    $stats = array('done' => 0, 'missing' => 0, 'missingHours' => 0.0, 'fee' => 0.0);
    $statusCount = array('missing' => 0, 'done' => 0, 'exempt' => 0, 'none' => 0);
    foreach ($rows as $usrId => $row) {
        $states[$usrId] = $workState($row);
        $statusCount[$states[$usrId]['status']]++;
        if ($states[$usrId]['status'] === 'done') {
            $stats['done']++;
        } elseif ($states[$usrId]['status'] === 'missing') {
            $stats['missing']++;
            $stats['missingHours'] += $states[$usrId]['missing'];
            $stats['fee'] += $states[$usrId]['fee'];
        }
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
        . '<div class="vr d-none d-md-block"></div>'
        . $stat((string) $stats['done'], $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STATUS_DONE'))
        . $stat((string) $stats['missing'], $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_WITH_MISSING'))
        . $stat(substr(WorkRules::formatHours($stats['missingHours']), 0, -2), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_MISSING'))
        . $stat(mf_format_amount($stats['fee']), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_FEE'))
        . '</div></div>');
    if ($getYear < (int) date('Y')) {
        $page->addHtml('<div class="alert alert-info" role="alert"><i class="bi bi-info-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_OVERVIEW_OTHER_YEAR') . '</div>');
    }

    // Filter "Nur Abweichungen" und Suche wirken auf der Seite (alle Zeilen werden ausgegeben und per JavaScript
    // ein- oder ausgeblendet); ausgeblendete angehakte Zeilen werden beim Uebernehmen mitgesendet
    $visible = $rows;
    $page->addHtml('<div class="d-flex flex-wrap align-items-center gap-3 mb-2">'
        . '<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="work_target_only_changes"' . ($getAll ? '' : ' checked="checked"') . ' />'
        . '<label class="form-check-label" for="work_target_only_changes">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_ONLY_CHANGES') . '</label></div>'
        . '</div>'
        . '<div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="work_target_status_filter">'
        . implode('', array_map(static fn (string $status): string => '<button type="button" class="btn btn-sm ' . ($status === 'all' ? 'btn-primary' : 'btn-secondary') . '" data-status="' . $status . '">'
            . $gL10n->get($status === 'all' ? 'SYS_ALL' : 'PLG_MEMBERSHIPFEE_WORK_STATUS_' . strtoupper($status))
            . ' (<span class="wt-count">' . ($status === 'all' ? count($rows) : $statusCount[$status]) . '</span>)</button>', array('all', 'missing', 'done', 'exempt', 'none')))
        . '<a id="work_target_export" class="btn btn-secondary btn-sm ms-auto" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/work_overview.php', array('mode' => 'csv', 'year' => $getYear, 'filter' => 'all')) . '"><i class="bi bi-filetype-csv"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_EXPORT') . '</a>'
        . '</div>'
        . '<div id="work_target_no_changes" class="alert alert-success d-none" role="alert"><i class="bi bi-check-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_NO_CHANGES') . '</div>');

    $formatValue = static fn (string $value): string => $value === '' ? '<span class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_EMPTY') . '</span>' : WorkRules::formatHours((float) str_replace(',', '.', $value));
    $badge = array('done' => 'bg-success', 'missing' => 'bg-warning text-dark', 'exempt' => 'bg-secondary', 'none' => 'bg-info text-dark');
    $statusLabel = static fn (string $status): string => $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STATUS_' . strtoupper($status));
    $tableRows = '';
    foreach ($visible as $usrId => $row) {
        $changed = isset($changes[$usrId]);
        $state = $states[$usrId];
        $tableRows .= '<tr data-changed="' . ($changed ? '1' : '0') . '" data-usr="' . (int) $usrId . '" data-new="' . SecurityUtils::encodeHTML($row['new']) . '"'
            . ' data-done="' . number_format($row['done'], 2, '.', '') . '" data-status="' . $state['status'] . '"' . ($changed ? '' : ' class="text-muted"') . '>'
            . '<td class="wt-select">' . ($changed ? '<input type="checkbox" class="form-check-input work-target-select" name="usr_id[]" value="' . (int) $usrId . '" checked="checked" />' : '') . '</td>'
            . '<td>' . SecurityUtils::encodeHTML($row['name']) . '</td>'
            . '<td class="wt-current-cell text-nowrap" style="text-align: right;" data-value="' . SecurityUtils::encodeHTML($row['current']) . '">'
            . '<span class="wt-current">' . $formatValue($row['current']) . '</span> '
            . '<a href="#" class="admidio-icon-link wt-edit" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_EDIT') . '"><i class="bi bi-pencil-square"></i></a></td>'
            . '<td style="text-align: right;"><strong>' . $formatValue($row['new']) . '</strong></td>'
            . '<td style="text-align: right;" class="text-nowrap">' . WorkRules::formatHours($row['done']) . '</td>'
            . '<td style="text-align: right;" class="text-nowrap wt-missing">' . ($state['missing'] > 0 ? '<strong>' . WorkRules::formatHours($state['missing']) . '</strong>' : '&ndash;') . '</td>'
            . '<td style="text-align: right;" class="text-nowrap wt-fee">' . ($state['fee'] > 0 ? mf_format_amount($state['fee']) : '&ndash;') . '</td>'
            . '<td class="wt-status"><span class="badge ' . $badge[$state['status']] . '">' . $statusLabel($state['status']) . '</span></td>'
            . '<td>' . SecurityUtils::encodeHTML($row['target']->reason) . '</td>'
            . '</tr>';
    }
    $buttons = '<div class="my-2"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_APPLY') . '</button></div>';
    $page->addHtml('<form method="post" action="' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'apply', 'year' => $getYear)) . '">'
        . '<input type="hidden" name="adm_csrf_token" value="' . SecurityUtils::encodeHTML($gCurrentSession->getCsrfToken()) . '" />'
        . (count($changes) > 0 ? $buttons : '')
        . '<div class="d-flex justify-content-end align-items-center gap-2 mb-1"><label for="work_target_search" class="mb-0">' . $gL10n->get('PLG_MEMBERSHIPFEE_SEARCH') . ':</label>'
        . '<input type="search" id="work_target_search" class="form-control form-control-sm" style="width: auto; min-width: 12rem;" placeholder="' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_SEARCH_PLACEHOLDER') . '" autocomplete="off" />'
        . '<small id="work_target_info" class="text-muted text-nowrap"></small></div>'
        . '<div class="table-responsive"><table id="work_target_table" class="table table-condensed table-hover"><thead><tr>'
        . '<th>' . (count($changes) > 0 ? '<input type="checkbox" class="form-check-input" id="work_target_all" checked="checked" title="' . $gL10n->get('SYS_ALL') . '" />' : '') . '</th>'
        . '<th>' . $gL10n->get('SYS_NAME') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_CURRENT') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_NEW') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_DONE') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_MISSING') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_FEE') . '</th>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_STATUS') . '</th>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TARGETS_REASON') . '</th>'
        . '</tr></thead><tbody>' . $tableRows . '</tbody></table></div>'
        . (count($changes) > 0 ? $buttons : '')
        . '</form>'
        . '<p class="small text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_OVERVIEW_NOTE', array(mf_format_amount($rules->rate()))) . '</p>');
    $page->addJavascript('
        // "Alle" wirkt nur auf die angezeigten Zeilen (Filter "Nur Abweichungen" und Suche beachten)
        $("#work_target_all").change(function() {
            $("#work_target_table tbody tr:visible input.work-target-select").prop("checked", $(this).prop("checked"));
        });
        var wtStatus = "all";
        var wtExportUrl = $("#work_target_export").attr("href");
        $("#work_target_status_filter").on("click", "button", function() {
            wtStatus = $(this).data("status");
            $("#work_target_status_filter button").removeClass("btn-primary").addClass("btn-secondary");
            $(this).removeClass("btn-secondary").addClass("btn-primary");
            $("#work_target_export").attr("href", wtExportUrl.replace(/filter=all/, "filter=" + wtStatus));
            workTargetFilter();
        });
        function workTargetFilter() {
            var onlyChanges = $("#work_target_only_changes").prop("checked");
            var needle = $("#work_target_search").val().toLowerCase().trim();
            var rows = $("#work_target_table tbody tr");
            var shown = 0;
            var counts = {all: rows.length, missing: 0, done: 0, exempt: 0, none: 0};
            rows.each(function() {
                counts[$(this).attr("data-status")]++;
                var visible = (!onlyChanges || $(this).data("changed") == 1)
                    && (wtStatus === "all" || $(this).attr("data-status") === wtStatus)
                    && (needle === "" || $(this).text().toLowerCase().indexOf(needle) !== -1);
                $(this).toggle(visible);
                if (visible) { shown++; }
            });
            $("#work_target_info").text(shown + " / " + rows.length);
            $("#work_target_status_filter button").each(function() {
                $(this).find(".wt-count").text(counts[$(this).data("status")]);
            });
            $("#work_target_no_changes").toggleClass("d-none", !(onlyChanges && wtStatus === "all" && needle === "" && shown === 0));
        }
        $("#work_target_only_changes").on("change", workTargetFilter);
        $("#work_target_search").on("input", workTargetFilter).on("keydown", function(event) {
            // Enter im Suchfeld darf das Formular (Uebernehmen) nicht absenden
            if (event.key === "Enter") { event.preventDefault(); }
        });
        workTargetFilter();

        // Sollstunden von Hand setzen: Stift-Symbol in der Spalte "Bisher"
        var wtSetUrl = ' . json_encode(SecurityUtils::encodeUrl($selfUrl, array('mode' => 'set', 'year' => $getYear))) . ';
        var wtToken = ' . json_encode($gCurrentSession->getCsrfToken()) . ';
        var wtRate = ' . json_encode($rules->rate()) . ';
        var wtCurrency = ' . json_encode($gSettingsManager->getString('system_currency')) . ';
        var wtBadge = ' . json_encode($badge) . ';
        var wtLabel = ' . json_encode(array_combine(array_keys($badge), array_map($statusLabel, array_keys($badge)))) . ';
        function wtHours(value) {
            return value.toFixed(2).replace(".", ",").replace(/0+$/, "").replace(/,$/, "") + " h";
        }
        function wtAmount(value) {
            var parts = value.toFixed(2).split(".");
            return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".") + "," + parts[1] + " " + wtCurrency;
        }
        // Fehlstunden, Ersatzbeitrag und Stand einer Zeile nach dem Aendern des Solls neu bestimmen
        function wtUpdateState(row, value) {
            var done = parseFloat(row.attr("data-done")) || 0;
            var status = "none", missing = 0;
            if (value !== "") {
                var target = parseFloat(String(value).replace(",", "."));
                missing = Math.max(0, Math.round((target - done) * 100) / 100);
                status = target <= 0 ? "exempt" : (missing > 0 ? "missing" : "done");
            }
            var fee = Math.round(missing * wtRate * 100) / 100;
            row.attr("data-status", status);
            row.find(".wt-missing").html(missing > 0 ? "<strong>" + wtHours(missing) + "</strong>" : "&ndash;");
            row.find(".wt-fee").html(fee > 0 ? wtAmount(fee) : "&ndash;");
            row.find(".wt-status").html("<span class=\"badge " + wtBadge[status] + "\">" + wtLabel[status] + "</span>");
        }
        function wtSameValue(a, b) {
            if (a === "" || b === "") { return a === b; }
            return Math.abs(parseFloat(a.replace(",", ".")) - parseFloat(b)) < 0.005;
        }
        $("#work_target_table").on("click", ".wt-edit", function(event) {
            event.preventDefault();
            var cell = $(this).closest("td");
            if (cell.find("input.wt-input").length) { return; }
            cell.data("html", cell.html());
            cell.html("<div class=\\"input-group input-group-sm justify-content-end flex-nowrap\\">"
                + "<input type=\\"number\\" class=\\"form-control wt-input\\" style=\\"max-width: 6rem;\\" min=\\"0\\" max=\\"999\\" step=\\"0.5\\" />"
                + "<button type=\\"button\\" class=\\"btn btn-primary wt-save\\" title=\\"' . $gL10n->get('SYS_SAVE') . '\\"><i class=\\"bi bi-check-lg me-0\\"></i></button>"
                + "<button type=\\"button\\" class=\\"btn btn-secondary wt-cancel\\" title=\\"' . $gL10n->get('SYS_CANCEL') . '\\"><i class=\\"bi bi-x-lg me-0\\"></i></button></div>");
            cell.find("input.wt-input").val(String(cell.data("value")).replace(",", ".")).focus().select();
        });
        $("#work_target_table").on("click", ".wt-cancel", function() {
            var cell = $(this).closest("td");
            cell.html(cell.data("html"));
        });
        $("#work_target_table").on("keydown", "input.wt-input", function(event) {
            if (event.key === "Enter") { event.preventDefault(); $(this).closest("td").find(".wt-save").click(); }
            if (event.key === "Escape") { $(this).closest("td").find(".wt-cancel").click(); }
        });
        $("#work_target_table").on("click", ".wt-save", function() {
            var cell = $(this).closest("td");
            var row = cell.closest("tr");
            var hours = cell.find("input.wt-input").val();
            $.post(wtSetUrl, {adm_csrf_token: wtToken, usr_id: row.data("usr"), hours: hours}, function(data) {
                if (!data || !data.ok) { alert(data && data.message ? data.message : "Fehler"); return; }
                cell.html(cell.data("html"));
                cell.data("value", data.value).attr("data-value", data.value);
                cell.find(".wt-current").text(data.formatted);
                // Abweichung zur Regel neu bestimmen; von Hand gesetzte Sonderfaelle werden nicht vorausgewaehlt
                var changed = !wtSameValue(String(data.value), String(row.data("new")));
                row.data("changed", changed ? 1 : 0).attr("data-changed", changed ? "1" : "0").toggleClass("text-muted", !changed);
                var select = row.find("td.wt-select");
                if (changed && !select.find("input").length) {
                    select.html("<input type=\\"checkbox\\" class=\\"form-check-input work-target-select\\" name=\\"usr_id[]\\" value=\\"" + row.data("usr") + "\\" />");
                } else if (!changed) {
                    select.empty();
                }
                wtUpdateState(row, String(data.value));
                workTargetFilter();
                row.show();
                row.addClass("table-success");
                setTimeout(function() { row.removeClass("table-success"); }, 1500);
            }, "json");
        });', true);

    $page->show();
} catch (Exception $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
}
