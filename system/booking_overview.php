<?php
/**
 ***********************************************************************************************
 * Gesamtuebersicht der Buchungshistorie (ersetzt die alte history.php)
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode     : html (Default) | csv-ms | csv-oo | xlsx
 * year     : Beitragsjahr (0 = alle)
 * status   : offen | exportiert | eingezogen | storniert (leer = alle)
 * method   : SEPA | MAN (leer = alle)
 * rol_name : Beitragsrolle / Bezeichnung (leer = alle)
 * name     : Teil des Namens (leer = alle)
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Bookings\BookingStore;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/bookings_function.php');

    if (! isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'html', 'validValues' => array('html', 'csv-ms', 'csv-oo', 'xlsx')));
    $getYear = admFuncVariableIsValid($_GET, 'year', 'int', array('defaultValue' => getContributionYear()));
    $getStatus = admFuncVariableIsValid($_GET, 'status', 'string', array('validValues' => array('', BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED, BookingStore::STATUS_CANCELLED)));
    $getMethod = admFuncVariableIsValid($_GET, 'method', 'string', array('validValues' => array('', BookingStore::METHOD_SEPA, BookingStore::METHOD_MANUAL)));
    $getRoleName = admFuncVariableIsValid($_GET, 'rol_name', 'string');
    $getName = admFuncVariableIsValid($_GET, 'name', 'string');

    BookingStore::ensureTables();

    $bookings = BookingStore::search(array(
        'year' => $getYear,
        'status' => $getStatus,
        'method' => $getMethod,
        'rol_name' => $getRoleName
    ));
    // nur Positionen des gewaehlten Buchungskreises (Umschaltung Mitgliedsbeitrag/Arbeitsdienst ueber der Liste)
    $bookings = array_values(mf_collection_scope()->filterPositions($bookings));

    // Namen der Mitglieder
    $names = array();
    $userIds = array_unique(array_column($bookings, 'mfb_usr_id'));
    if (count($userIds) > 0) {
        $sql = 'SELECT usr_id, usr_uuid, last_name.usd_value AS last_name, first_name.usd_value AS first_name
                  FROM ' . TBL_USERS . '
             LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
                    ON last_name.usd_usr_id = usr_id
                   AND last_name.usd_usf_id = ?
             LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
                    ON first_name.usd_usr_id = usr_id
                   AND first_name.usd_usf_id = ?
                 WHERE usr_id IN (' . implode(',', array_map('intval', $userIds)) . ')';
        $statement = $gDb->queryPrepared($sql, array((int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'), (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id')));
        while ($row = $statement->fetch()) {
            $names[(int) $row['usr_id']] = $row;
        }
    }

    // Namensfilter und Sortierung nach Name
    $rows = array();
    foreach ($bookings as $booking) {
        $nameRow = $names[$booking['mfb_usr_id']] ?? array('last_name' => '', 'first_name' => '', 'usr_uuid' => '');
        $fullName = trim((string) $nameRow['last_name'] . ', ' . (string) $nameRow['first_name'], ', ');
        if ($getName !== '' && mb_stripos($fullName, $getName) === false) {
            continue;
        }
        $booking['name'] = $fullName;
        $booking['usr_uuid'] = (string) $nameRow['usr_uuid'];
        $rows[] = $booking;
    }
    usort($rows, static function (array $a, array $b): int {
        $c = strcasecmp($a['name'], $b['name']);
        return $c !== 0 ? $c : ($a['mfb_id'] <=> $b['mfb_id']);
    });

    $header = array(
        $gL10n->get('SYS_NAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_DATE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_METHOD'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_SEPA_FILE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ROLE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_AMOUNT'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_TEXT'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REFERENCE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_STATUS')
    );

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------
    if ($getMode !== 'html') {
        $filename = FileSystemUtils::getSanitizedPathEntry('buchungen' . ($getYear > 0 ? '_' . $getYear : ''));
        $data = array();
        foreach ($rows as $row) {
            $data[] = array(
                $row['name'],
                (int) $row['mfb_year'],
                mf_format_date($row['mfb_booking_date']),
                mf_format_date($row['mfb_due_date']),
                (string) $row['mfb_method'],
                (string) ($row['mfr_file_name'] ?? ''),
                (string) $row['mfb_rol_name'],
                number_format((float) $row['mfb_amount'], 2, ($getMode === 'csv-oo' || $getMode === 'xlsx') ? '.' : ',', ''),
                (string) $row['mfb_text'],
                (string) $row['mfb_reference'],
                mf_booking_label((string) $row['mfb_status']) . ($row['mfb_status'] === BookingStore::STATUS_CANCELLED ? ' ' . mf_format_date($row['mfb_storno_date']) . ' ' . $row['mfb_storno_reason'] : '')
            );
        }

        header('Cache-Control: private');
        header('Pragma: public');

        if ($getMode === 'xlsx') {
            include_once (__DIR__ . '/../libs/PHP_XLSXWriter/xlsxwriter.class.php');
            $filename .= '.xlsx';
            header('Content-disposition: attachment; filename="' . XLSXWriter::sanitize_filename($filename) . '"');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Transfer-Encoding: binary');
            $writer = new XLSXWriter();
            $writer->setTitle($filename);
            $writer->setSubject($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_OVERVIEW'));
            $writer->setCompany($gCurrentOrganization->getValue('org_longname'));
            $writer->writeSheet(array_merge(array($header), $data), '', array());
            $writer->writeToStdOut();
        } else {
            $separator = ($getMode === 'csv-ms') ? ';' : ',';
            $charset = ($getMode === 'csv-ms') ? 'iso-8859-1' : 'utf-8';
            $csv = '';
            foreach (array_merge(array($header), $data) as $line) {
                $csv .= implode($separator, array_map(static function ($value) {
                    return '"' . str_replace('"', '""', (string) $value) . '"';
                }, $line)) . "\n";
            }
            $filename .= '.csv';
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Type: text/comma-separated-values; charset=' . $charset);
            echo ($charset === 'iso-8859-1') ? mb_convert_encoding($csv, 'ISO-8859-1', 'UTF-8') : $csv;
        }
        exit();
    }

    // ------------------------------------------------------------------
    // HTML
    // ------------------------------------------------------------------
    $headline = mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_OVERVIEW'));
    $gNavigation->addUrl(CURRENT_URL, $headline);

    $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-booking-overview', $headline);
    $page->setContentFullWidth();

    $filterParams = array('year' => $getYear, 'status' => $getStatus, 'method' => $getMethod, 'rol_name' => $getRoleName, 'name' => $getName);
    $selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/booking_overview.php';
    foreach (array('xlsx' => 'Excel (XLSX)', 'csv-ms' => 'CSV (MS Excel)', 'csv-oo' => 'CSV (UTF-8)') as $mode => $label) {
        $page->addPageFunctionsMenuItem('menu_item_export_' . str_replace('-', '_', $mode), $gL10n->get('SYS_EXPORT') . ' ' . $label, SecurityUtils::encodeUrl($selfUrl, array_merge($filterParams, array('mode' => $mode))), 'bi-download');
    }

    $filterValues = BookingStore::getFilterValues();
    $years = array(0 => $gL10n->get('SYS_ALL'));
    foreach (array_unique(array_merge(array(getContributionYear()), $filterValues['years'])) as $year) {
        $years[$year] = (string) $year;
    }
    $roleNames = array('' => $gL10n->get('SYS_ALL'));
    foreach ($filterValues['names'] as $name) {
        $roleNames[$name] = $name;
    }

    $form = new HtmlForm('booking_overview_filter', $selfUrl, $page, array('type' => 'navbar', 'setFocus' => false, 'method' => 'get'));
    $form->addSelectBox('year', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR'), $years, array('defaultValue' => $getYear, 'showContextDependentFirstEntry' => false));
    $form->addSelectBox('status', $gL10n->get('PLG_MEMBERSHIPFEE_STATUS'), array(
        '' => $gL10n->get('SYS_ALL'),
        BookingStore::STATUS_OPEN => mf_booking_label(BookingStore::STATUS_OPEN),
        BookingStore::STATUS_EXPORTED => mf_booking_label(BookingStore::STATUS_EXPORTED),
        BookingStore::STATUS_COLLECTED => mf_booking_label(BookingStore::STATUS_COLLECTED),
        BookingStore::STATUS_CANCELLED => mf_booking_label(BookingStore::STATUS_CANCELLED)
    ), array('defaultValue' => $getStatus, 'showContextDependentFirstEntry' => false));
    $form->addSelectBox('method', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_METHOD'), array('' => $gL10n->get('SYS_ALL'), 'SEPA' => 'SEPA', 'MAN' => 'MAN'), array('defaultValue' => $getMethod, 'showContextDependentFirstEntry' => false));
    $form->addSelectBox('rol_name', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ROLE'), $roleNames, array('defaultValue' => $getRoleName, 'showContextDependentFirstEntry' => false));
    $form->addInput('name', $gL10n->get('SYS_NAME'), $getName);
    $form->addSubmitButton('btn_filter', $gL10n->get('SYS_OK'), array('icon' => 'bi-funnel'));
    $page->addHtml($form->show(false));

    $table = new HtmlTable('table_booking_overview', $page, true, true, 'table table-condensed');
    $table->setColumnAlignByArray(array('left', 'left', 'left', 'left', 'left', 'left', 'left', 'right', 'left', 'left', 'left'));
    $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
    $table->addRowHeadingByArray($header);

    $sums = array();
    foreach ($rows as $row) {
        $sums[$row['mfb_status']] = ($sums[$row['mfb_status']] ?? 0) + (float) $row['mfb_amount'];
        $historyUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bookings.php', array('user_uuid' => $row['usr_uuid']));
        $runUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/runs.php', array('run_id' => $row['mfb_run_id']));
        $style = ($row['mfb_status'] === BookingStore::STATUS_CANCELLED) ? ' style="text-decoration: line-through;"' : '';
        $table->addRowByArray(array(
            '<a href="' . $historyUrl . '">' . SecurityUtils::encodeHTML($row['name']) . '</a>',
            (int) $row['mfb_year'],
            mf_format_date($row['mfb_booking_date']),
            mf_format_date($row['mfb_due_date']),
            SecurityUtils::encodeHTML((string) $row['mfb_method']),
            ($row['mfb_run_id'] !== null ? '<a href="' . $runUrl . '">' . SecurityUtils::encodeHTML((string) $row['mfr_file_name']) . '</a>' : ''),
            SecurityUtils::encodeHTML((string) $row['mfb_rol_name']),
            '<span' . $style . '>' . mf_format_amount($row['mfb_amount']) . '</span>',
            '<span' . $style . '>' . SecurityUtils::encodeHTML((string) $row['mfb_text']) . '</span>',
            SecurityUtils::encodeHTML((string) $row['mfb_reference']),
            mf_booking_label((string) $row['mfb_status']) . ($row['mfb_status'] === BookingStore::STATUS_CANCELLED ? ' ' . mf_format_date($row['mfb_storno_date']) : '')
        ));
    }
    $page->addHtml($table->show(false));

    $summary = array();
    foreach (array(BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED, BookingStore::STATUS_CANCELLED) as $status) {
        if (isset($sums[$status])) {
            $summary[] = mf_booking_label($status) . ': <strong>' . mf_format_amount($sums[$status]) . '</strong>';
        }
    }
    if (count($summary) > 0) {
        $page->addHtml('<p class="small mt-2">' . implode(' &middot; ', $summary) . '</p>');
    }

    $page->show();
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}
