<?php
/**
 ***********************************************************************************************
 * Arbeitsdienst: Jahresuebersicht
 *
 * Je Mitglied Soll (Profilfeld "Sollstunden Arbeitsdienst"), Ist (Summe der erfassten Stunden im
 * Leistungsjahr), Fehlstunden und der Ersatzbeitrag daraus (Stundensatz aus Einstellungen -> Arbeitsdienst).
 * Nur fuer die Zugriffsrolle des Plugins (enthaelt Betraege).
 *
 * Das Profilfeld enthaelt immer das Soll des laufenden Leistungsjahres; fuer fruehere Jahre ist die
 * Uebersicht deshalb nur ein Anhalt.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode   : show (Standard) | csv (Export der angezeigten Zeilen)
 * year   : Leistungsjahr (Standard: aktuelles Jahr)
 * filter : all (Standard) | missing (mit Fehlstunden) | done (erfuellt) | exempt (Soll 0) | none (ohne Soll)
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Work\WorkHoursStore;
use Plugins\MembershipFee\classes\Work\WorkProfileFields;
use Plugins\MembershipFee\classes\Work\WorkRules;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    if (!isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'show', 'validValues' => array('show', 'csv')));
    $getYear = admFuncVariableIsValid($_GET, 'year', 'int', array('defaultValue' => (int) date('Y')));
    if ($getYear < 2000 || $getYear > 2100) {
        $getYear = (int) date('Y');
    }
    $getFilter = admFuncVariableIsValid($_GET, 'filter', 'string', array('defaultValue' => 'all', 'validValues' => array('all', 'missing', 'done', 'exempt', 'none')));

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    $rules = WorkRules::fromConfig($pPreferences->config);
    $orgId = (int) $gCurrentOrgId;
    $selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/work_overview.php';

    WorkProfileFields::ensure($orgId);
    WorkHoursStore::ensureTable();
    $targetUsfId = (int) $gProfileFields->getProperty(WorkProfileFields::targetField($orgId), 'usf_id');

    // Soll aus dem Profilfeld, Namen
    $usfIds = array(
        'last' => (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'first' => (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'target' => $targetUsfId
    );
    $data = array();
    $statement = $gDb->queryPrepared('SELECT usd.usd_usr_id, usd.usd_usf_id, usd.usd_value
          FROM ' . TBL_USER_DATA . ' AS usd
    INNER JOIN ' . TBL_USERS . ' AS usr ON usr.usr_id = usd.usd_usr_id AND usr.usr_valid = true
         WHERE usd.usd_usf_id IN (?, ?, ?)', array_values($usfIds));
    $keyByUsf = array_flip($usfIds);
    while ($row = $statement->fetch()) {
        $data[(int) $row['usd_usr_id']][$keyByUsf[(int) $row['usd_usf_id']]] = (string) $row['usd_value'];
    }
    $done = WorkHoursStore::sumByUser($getYear);

    // Zeilen: alle mit Soll (auch 0) oder mit erfassten Stunden
    $rows = array();
    foreach ($data as $usrId => $values) {
        $hasTarget = isset($values['target']) && $values['target'] !== '';
        if (!$hasTarget && !isset($done[$usrId])) {
            continue;
        }
        $target = $hasTarget ? (float) str_replace(',', '.', $values['target']) : null;
        $ist = $done[$usrId] ?? 0.0;
        $missing = ($target !== null) ? WorkRules::missingHours($target, $ist) : 0.0;
        if ($target === null) {
            $status = 'none';
        } elseif ($target <= 0.0) {
            $status = 'exempt';
        } elseif ($missing > 0.0) {
            $status = 'missing';
        } else {
            $status = 'done';
        }
        $rows[$usrId] = array(
            'name' => trim(($values['last'] ?? '') . ', ' . ($values['first'] ?? ''), ', '),
            'target' => $target,
            'done' => $ist,
            'missing' => $missing,
            'fee' => ($target !== null) ? $rules->replacementFee($target, $ist) : 0.0,
            'status' => $status
        );
    }
    uasort($rows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    // Kennzahlen ueber alle Zeilen
    $stats = array('required' => 0, 'done' => 0, 'missing' => 0, 'missingHours' => 0.0, 'fee' => 0.0, 'hours' => 0.0);
    foreach ($rows as $row) {
        $stats['hours'] += $row['done'];
        if ($row['status'] === 'done' || $row['status'] === 'missing') {
            $stats['required']++;
        }
        if ($row['status'] === 'done') {
            $stats['done']++;
        }
        if ($row['status'] === 'missing') {
            $stats['missing']++;
            $stats['missingHours'] += $row['missing'];
            $stats['fee'] += $row['fee'];
        }
    }

    $visible = ($getFilter === 'all') ? $rows : array_filter($rows, static fn (array $row): bool => $row['status'] === $getFilter);
    $statusLabel = static fn (string $status): string => $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STATUS_' . strtoupper($status));
    $hoursText = static fn (?float $hours): string => $hours === null ? '' : substr(WorkRules::formatHours($hours), 0, -2);

    // ------------------------------------------------------------------
    // CSV-Export
    // ------------------------------------------------------------------
    if ($getMode === 'csv') {
        $lines = array(implode(';', array(
            $gL10n->get('SYS_LASTNAME') . ', ' . $gL10n->get('SYS_FIRSTNAME'),
            $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_TARGET'),
            $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_DONE'),
            $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_MISSING'),
            $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_FEE'),
            $gL10n->get('PLG_MEMBERSHIPFEE_STATUS')
        )));
        foreach ($visible as $row) {
            $lines[] = implode(';', array(
                '"' . str_replace('"', '""', $row['name']) . '"',
                $hoursText($row['target']),
                $hoursText($row['done']),
                $hoursText($row['missing']),
                number_format($row['fee'], 2, ',', ''),
                '"' . $statusLabel($row['status']) . '"'
            ));
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="arbeitsdienst_' . $getYear . '_' . $getFilter . '.csv"');
        echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
        exit();
    }

    // ------------------------------------------------------------------
    // Anzeige
    // ------------------------------------------------------------------
    $headline = $gL10n->get('PLG_MEMBERSHIPFEE_WORK_OVERVIEW');
    $gNavigation->addUrl(CURRENT_URL, $headline);
    $page = new HtmlPage('plg-mitgliedsbeitrag-work-overview', $headline);
    $page->setContentFullWidth();

    // Leistungsjahr
    $years = WorkHoursStore::getYears();
    foreach (array((int) date('Y'), (int) date('Y') - 1, $getYear) as $year) {
        if (!in_array($year, $years, true)) {
            $years[] = $year;
        }
    }
    rsort($years);
    $yearLinks = array();
    foreach ($years as $year) {
        $yearLinks[] = ($year === $getYear)
            ? '<span class="btn btn-primary btn-sm disabled">' . $year . '</span>'
            : '<a class="btn btn-secondary btn-sm" href="' . SecurityUtils::encodeUrl($selfUrl, array('year' => $year, 'filter' => $getFilter)) . '">' . $year . '</a>';
    }
    $page->addHtml('<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_YEAR') . ': ' . implode(' ', $yearLinks) . '</p>');
    if ($getYear !== (int) date('Y')) {
        $page->addHtml('<div class="alert alert-info" role="alert"><i class="bi bi-info-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_OVERVIEW_OTHER_YEAR') . '</div>');
    }

    // Kennzahlen
    $currency = $gSettingsManager->getString('system_currency');
    $stat = static fn (string $value, string $label): string => '<div class="text-center px-2"><div class="fs-4 fw-bold lh-1">' . $value . '</div><div class="small text-muted">' . $label . '</div></div>';
    $page->addHtml('<div class="card mb-3 border-primary"><div class="card-body d-flex flex-wrap align-items-center gap-3">'
        . '<h4 class="mb-0 me-auto"><i class="bi bi-clipboard-data"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_OVERVIEW_HEADING', array($getYear)) . '</h4>'
        . $stat((string) $stats['required'], $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_REQUIRED'))
        . $stat((string) $stats['done'], $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STATUS_DONE'))
        . $stat((string) $stats['missing'], $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_WITH_MISSING'))
        . $stat($hoursText($stats['missingHours']), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_MISSING'))
        . $stat(mf_format_amount($stats['fee']), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_FEE'))
        . '</div></div>');

    // Filter und Export
    $filterLinks = array();
    foreach (array('all', 'missing', 'done', 'exempt', 'none') as $filter) {
        $count = ($filter === 'all') ? count($rows) : count(array_filter($rows, static fn (array $row): bool => $row['status'] === $filter));
        $label = $gL10n->get($filter === 'all' ? 'SYS_ALL' : 'PLG_MEMBERSHIPFEE_WORK_STATUS_' . strtoupper($filter)) . ' (' . $count . ')';
        $filterLinks[] = ($filter === $getFilter)
            ? '<span class="btn btn-primary btn-sm disabled">' . $label . '</span>'
            : '<a class="btn btn-secondary btn-sm" href="' . SecurityUtils::encodeUrl($selfUrl, array('year' => $getYear, 'filter' => $filter)) . '">' . $label . '</a>';
    }
    $page->addHtml('<div class="d-flex flex-wrap gap-2 mb-3">' . implode(' ', $filterLinks)
        . '<a class="btn btn-secondary btn-sm ms-auto" href="' . SecurityUtils::encodeUrl($selfUrl, array('year' => $getYear, 'filter' => $getFilter, 'mode' => 'csv')) . '"><i class="bi bi-filetype-csv"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_EXPORT') . '</a>'
        . '</div>');

    if (count($rows) === 0) {
        $page->addHtml('<div class="alert alert-warning" role="alert">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_OVERVIEW_EMPTY') . '</div>');
        $page->show();
        exit();
    }

    $badge = array('done' => 'bg-success', 'missing' => 'bg-warning text-dark', 'exempt' => 'bg-secondary', 'none' => 'bg-info text-dark');
    $table = new HtmlTable('table_work_overview', $page, true, true, 'table table-condensed table-hover');
    $table->setColumnAlignByArray(array('left', 'right', 'right', 'right', 'right', 'left'));
    $table->addRowHeadingByArray(array(
        $gL10n->get('SYS_NAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_TARGET'),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_DONE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_MISSING'),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_FEE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_STATUS')
    ));
    $table->setDatatablesRowsPerPage(50);
    foreach ($visible as $row) {
        $table->addRowByArray(array(
            SecurityUtils::encodeHTML($row['name']),
            array('value' => $row['target'] === null ? '&ndash;' : WorkRules::formatHours($row['target']), 'order' => $row['target'] ?? -1),
            array('value' => WorkRules::formatHours($row['done']), 'order' => $row['done']),
            array('value' => $row['missing'] > 0 ? '<strong>' . WorkRules::formatHours($row['missing']) . '</strong>' : '&ndash;', 'order' => $row['missing']),
            array('value' => $row['fee'] > 0 ? mf_format_amount($row['fee']) : '&ndash;', 'order' => $row['fee']),
            '<span class="badge ' . $badge[$row['status']] . '">' . $statusLabel($row['status']) . '</span>'
        ));
    }
    $page->addHtml($table->show(false));
    $page->addHtml('<p class="small text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_OVERVIEW_NOTE', array(mf_format_amount($rules->rate()))) . '</p>');

    $page->show();
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}
