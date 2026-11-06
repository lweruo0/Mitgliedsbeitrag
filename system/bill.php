<?php
/**
 ***********************************************************************************************
 * 3.0 Rechnungen erstellen (Admidio-Plugin Mitgliedsbeitrag)
 *
 * Eine Zeile je Rechnung bzw. je Mitglied und Beitragsjahr mit offenen Positionen, die noch keine
 * Rechnung haben. Rechnungen erhalten eine feste Nummer (JAHR-B-NNNN / JAHR-AD-NNNN), siehe
 * docs/konzept-rechnungen-pdf.md. Die Markierung gilt je Mitglied (Session checkedArray) und wird
 * fuer "Rechnungen erstellen", E-Mail und Export verwendet.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode             : html            - Liste anzeigen (Standard)
 *                    prepare         - Benutzer im checkedArray setzen bzw. loeschen
 *                    export          - nur Pruefung, ob Benutzer markiert sind (danach bill_export.php)
 *                    invoice_preview - (POST, JSON) Vorschau "Rechnungen erstellen" fuer die markierten Mitglieder
 *                    invoice_create  - (POST, JSON) Rechnungen fuer usr_id[] anlegen (ein Paket)
 * usr_id           : <>0 - Benutzer, der im checkedArray gesetzt/geloescht wird; leer - alle angezeigten umschalten
 * mem_show_choice  : 0 - alle, 1 - nur mit E-Mail, 2 - nur ohne E-Mail
 * invoice_filter   : open (Standard: ohne Rechnung, offen, im Einzug) | pending | invoiced | overdue | paid | cancelled | all
 * year             : Beitragsjahr (0 = alle)
 * group            : bei prepare ohne usr_id: nur die Mitglieder der Tabelle MAN (Ueberweisung) bzw. SEPA
 * all              : bei prepare ohne usr_id: select = alle markieren, deselect = alle abwaehlen (leer = umschalten)
 * checked          : true/false - Haken gesetzt bzw. entfernt
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;
use Plugins\MembershipFee\classes\Invoices\InvoicePlanner;
use Plugins\MembershipFee\classes\Invoices\InvoiceService;
use Plugins\MembershipFee\classes\Invoices\InvoiceStatus;
use Plugins\MembershipFee\classes\Invoices\InvoiceStore;
use Plugins\MembershipFee\classes\Invoices\PaymentMethodStore;
use Plugins\MembershipFee\classes\Invoices\PdfBridge;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

// Buchungskreis (Mitgliedsbeitrag oder Arbeitsdienst), siehe mf_collection_scope()
$mfScope = mf_collection_scope();

// only authorized user are allowed to start this module
if (! isUserAuthorized()) {
    throw new Exception('SYS_NO_RIGHTS');
}

$pPreferences = new ConfigTable();
$pPreferences->read();
BookingStore::ensureTables();
InvoiceStore::ensureTable();
$invoiceService = new InvoiceService($pPreferences->config, $mfScope);

$user = new User($gDb, $gProfileFields);
$userField = new Entity($gDb, TBL_USER_FIELDS, 'usf');

$selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bill.php';

if (isset($_GET['mode']) && in_array($_GET['mode'], array('export', 'prepare'), true)) {
    // ajax mode then only show text if error occurs
    $gMessage->showTextOnly(true);
}

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
    'defaultValue' => 'html',
    'validValues' => array('html', 'export', 'prepare', 'invoice_preview', 'invoice_create')
));
$getUserId = admFuncVariableIsValid($_GET, 'usr_id', 'numeric', array(
    'defaultValue' => 0,
    'directOutput' => true
));
$getChecked = admFuncVariableIsValid($_GET, 'checked', 'string');
$getMembersShow = admFuncVariableIsValid($_GET, 'mem_show_choice', 'numeric', array(
    'defaultValue' => 0
));
if ($getMembersShow > 2) {
    $getMembersShow = 0;
}
$invoiceFilters = array('open', 'pending', 'invoiced', 'overdue', 'paid', 'cancelled', 'all');
$getInvoiceFilter = admFuncVariableIsValid($_GET, 'invoice_filter', 'string', array(
    'defaultValue' => 'open',
    'validValues' => $invoiceFilters
));
$getYear = admFuncVariableIsValid($_GET, 'year', 'int', array('defaultValue' => 0));
$getGroup = admFuncVariableIsValid($_GET, 'group', 'string', array('defaultValue' => '', 'validValues' => array('', 'MAN', 'SEPA')));
$getAll = admFuncVariableIsValid($_GET, 'all', 'string', array('defaultValue' => '', 'validValues' => array('', 'select', 'deselect')));

// add current url to navigation stack if last url was not the same page
if (strpos($gNavigation->getUrl(), 'bill.php') === false) {
    $_SESSION['pMembershipFee']['checkedArray'] = array();
    $_SESSION['pMembershipFee']['mailArray'] = array();
}
$_SESSION['pMembershipFee']['checkedArray'] ??= array();
$_SESSION['pMembershipFee']['mailArray'] ??= array();

// ------------------------------------------------------------------
// Rechnungen erstellen: Vorschau und Pakete (JSON)
// ------------------------------------------------------------------
if ($getMode === 'invoice_preview' || $getMode === 'invoice_create') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));

        if ($getMode === 'invoice_preview') {
            $usrIds = array_map('intval', array_keys($_SESSION['pMembershipFee']['checkedArray']));
            $preview = $invoiceService->preview($usrIds, DATE_NOW);
            $result = array('ok' => true, 'users' => array(), 'count' => 0, 'amount' => 0.0, 'years' => array(), 'errors' => array(),
                'warn_address' => 0, 'warn_duedate' => 0, 'sepa_members' => 0, 'transfer_members' => 0);
            $warned = array();
            foreach ($preview as $entry) {
                if ($entry['error'] !== '') {
                    $user->readDataById($entry['usr_id']);
                    $result['errors'][] = $user->getValue('LAST_NAME') . ', ' . $user->getValue('FIRST_NAME') . ' (' . $entry['year'] . '): '
                        . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_SKIP_' . strtoupper($entry['error']));
                    continue;
                }
                $result['users'][$entry['usr_id']] = $entry['usr_id'];
                $result['count']++;
                $result['amount'] += $entry['amount'];
                $result['years'][$entry['year']] = ($result['years'][$entry['year']] ?? 0) + 1;
                if (!isset($warned[$entry['usr_id']])) {
                    $warned[$entry['usr_id']] = true;
                    $result['warn_address'] += in_array('address', $entry['warnings'], true) ? 1 : 0;
                    $result['warn_duedate'] += in_array('duedate', $entry['warnings'], true) ? 1 : 0;
                    $result[$entry['method'] === InvoicePlanner::METHOD_SEPA ? 'sepa_members' : 'transfer_members']++;
                }
            }
            $result['users'] = array_values($result['users']);
            $result['amount_text'] = mf_format_amount($result['amount']);
            echo json_encode($result);
        } else {
            // nur markierte Mitglieder; Paketgroesse wird vom Browser bestimmt (20)
            $usrIds = array_values(array_filter(array_map('intval', (array) ($_POST['usr_id'] ?? array())),
                static fn (int $usrId): bool => isset($_SESSION['pMembershipFee']['checkedArray'][$usrId])));
            // Einzugsdatum fuer Lastschrift-Zahler ohne Faelligkeit (ersetzt den frueheren Schritt Faelligkeit)
            $debitDate = (string) ($_POST['debit_date'] ?? '');
            if ($debitDate !== '') {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $debitDate) !== 1 || $debitDate < DATE_NOW) {
                    throw new \RuntimeException($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_DEBIT_DATE_INVALID'));
                }
                $problem = mf_duedate_problem($debitDate);
                if ($problem !== null) {
                    throw new \RuntimeException($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_DEBIT_DATE_PROBLEM', array(
                        date('d.m.Y', (int) strtotime($debitDate)), $problem, date('d.m.Y', (int) strtotime(mf_next_valid_duedate($debitDate))))));
                }
            }
            $sequenceType = (string) ($_POST['sequencetype'] ?? 'AUTO');
            if (!in_array($sequenceType, \Plugins\MembershipFee\classes\Invoices\InvoiceService::SEQUENCE_TYPES, true)) {
                $sequenceType = 'AUTO';
            }
            // Zahlungsziel fuer Ueberweiser
            $transferDue = (string) ($_POST['transfer_due'] ?? '');
            if ($transferDue !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDue) !== 1 || $transferDue < DATE_NOW)) {
                throw new \RuntimeException($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_TRANSFER_DUE_INVALID'));
            }
            $result = $invoiceService->create(array_slice($usrIds, 0, 50), DATE_NOW, $debitDate, $sequenceType, $transferDue,
                ($_POST['overwrite_due'] ?? '') === '1');
            $skipped = array();
            foreach ($result['skipped'] as $skip) {
                $user->readDataById($skip['usr_id']);
                $skipped[] = $user->getValue('LAST_NAME') . ', ' . $user->getValue('FIRST_NAME') . ' (' . $skip['year'] . '): '
                    . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_SKIP_' . strtoupper($skip['reason']));
            }
            foreach ($result['created'] as $created) {
                if ($created['pdf_error'] !== '') {
                    $skipped[] = $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_ERROR', array($created['invoice_no'], $created['pdf_error']));
                }
            }
            echo json_encode(array('ok' => true, 'created' => count($result['created']), 'skipped' => $skipped));
        }
    } catch (\Throwable $e) {
        if (isset($gDb)) {
            $gDb->rollback();
        }
        echo json_encode(array('ok' => false, 'message' => $e->getMessage()));
    }
    exit();
}

if ($getMode == 'export') {
    if (count($_SESSION['pMembershipFee']['checkedArray']) === 0) {
        echo 'marker_empty';
    }
    exit();
}

// einzelner Haken: braucht die Zeilen nicht (schnell)
if ($getMode == 'prepare' && $getUserId != 0) {
    if ($getChecked == 'false') {
        unset($_SESSION['pMembershipFee']['checkedArray'][$getUserId]);
    } else {
        $_SESSION['pMembershipFee']['checkedArray'][$getUserId] = $_SESSION['pMembershipFee']['mailArray'][$getUserId] ?? '';
    }
    echo 'success';
    exit();
}

// ------------------------------------------------------------------
// Zeilen: Rechnungen des Buchungskreises und offene Positionen ohne Rechnung
// ------------------------------------------------------------------
$rows = array();
$years = array();
foreach (InvoiceStore::search(array('kind' => $mfScope->key())) as $invoice) {
    $status = $invoice['status'];
    $overdue = InvoiceStatus::isOverdue($status, $invoice['mfi_due_date'], $invoice['mfi_dunning_date'], DATE_NOW);
    $years[(int) $invoice['mfi_year']] = true;
    $rows[] = array(
        'type' => 'invoice',
        'usr_id' => (int) $invoice['mfi_usr_id'],
        'year' => (int) $invoice['mfi_year'],
        'invoice_no' => (string) $invoice['mfi_invoice_no'],
        'date' => (string) $invoice['mfi_invoice_date'],
        'due' => (string) $invoice['mfi_due_date'],
        'amount' => (float) $invoice['mfi_amount'],
        'open_amount' => (float) $invoice['open_amount'],
        'method' => (string) $invoice['mfi_method'],
        'status' => $status,
        'overdue' => $overdue,
        'dunning' => (string) ($invoice['mfi_dunning_date'] ?? ''),
        'pdf' => (string) ($status === InvoiceStatus::CANCELLED ? ($invoice['mfi_cancel_file'] ?? '') : ($invoice['mfi_pdf_file'] ?? ''))
    );
}
foreach (BookingStore::getByUsers(array(), array(BookingStore::STATUS_OPEN)) as $usrId => $positions) {
    foreach (InvoicePlanner::plan($mfScope->filterPositions($positions)) as $entry) {
        $years[$entry['year']] = true;
        $rows[] = array(
            'type' => 'pending',
            'usr_id' => (int) $usrId,
            'year' => $entry['year'],
            'invoice_no' => '',
            'date' => '',
            'due' => '',
            'amount' => $entry['amount'],
            'open_amount' => $entry['amount'],
            'method' => '',
            'status' => 'pending',
            'overdue' => false,
            'dunning' => '',
            'pdf' => '',
            'error' => $entry['error']
        );
    }
}
krsort($years);

$matchesFilter = static function (array $row) use ($getInvoiceFilter, $getYear): bool {
    if ($getYear > 0 && $row['year'] !== $getYear) {
        return false;
    }
    switch ($getInvoiceFilter) {
        case 'pending':
            return $row['type'] === 'pending';
        case 'invoiced':
            return $row['type'] === 'invoice' && in_array($row['status'], array(InvoiceStatus::OPEN, InvoiceStatus::IN_COLLECTION), true);
        case 'overdue':
            return $row['overdue'];
        case 'paid':
            return $row['status'] === InvoiceStatus::PAID;
        case 'cancelled':
            return $row['status'] === InvoiceStatus::CANCELLED;
        case 'all':
            return true;
        default:    // open
            return $row['type'] === 'pending' || in_array($row['status'], array(InvoiceStatus::OPEN, InvoiceStatus::IN_COLLECTION), true);
    }
};
// Anzahl der Rechnungen je Mitglied (je Beitragsjahr ohne Rechnung eine) fuer die Beschriftung "x Rechnungen erstellen";
// vor dem Filter gezaehlt, weil "Rechnungen erstellen" alle offenen Positionen der markierten Mitglieder abrechnet
$pendingByUser = array();
foreach ($rows as $row) {
    if ($row['type'] === 'pending') {
        $pendingByUser[$row['usr_id']] = ($pendingByUser[$row['usr_id']] ?? 0) + 1;
    }
}
$rows = array_values(array_filter($rows, $matchesFilter));
$rowUserIds = array_values(array_unique(array_column($rows, 'usr_id')));

// Mitgliedsdaten (Spalten aus den Einstellungen) der betroffenen Mitglieder, Filter E-Mail
$membersList = array();
if (count($rowUserIds) > 0) {
    $membersListFields = array_filter($pPreferences->config['columnconfig']['bill_fields']); // array_filter: löschen leerer Einträge, falls das Setup fehlgeschlagen ist
    $membersListSqlCondition = 'AND mem_usr_id IN (SELECT DISTINCT usr_id
        FROM ' . TBL_USERS . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS debtoremail
          ON debtoremail.usd_usr_id = usr_id
         AND debtoremail.usd_usf_id = ' . (int) $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_id') . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS email
          ON email.usd_usr_id = usr_id
         AND email.usd_usf_id = ' . (int) $gProfileFields->getProperty('EMAIL', 'usf_id') . '
       WHERE usr_id IN (' . implode(',', array_map('intval', $rowUserIds)) . ') ';
    if ($getMembersShow == 1) {
        $membersListSqlCondition .= 'AND (email.usd_value IS NOT NULL OR debtoremail.usd_value IS NOT NULL)) ';
    } elseif ($getMembersShow == 2) {
        $membersListSqlCondition .= 'AND email.usd_value IS NULL AND debtoremail.usd_value IS NULL) ';
    } else {
        $membersListSqlCondition .= ') ';
    }
    // 2 = alle Mitglieder (auch ehemalige, damit Rechnungen frueherer Jahre erscheinen)
    $membersList = list_members($membersListFields, 2, $membersListSqlCondition);
}
$rows = array_values(array_filter($rows, static fn (array $row): bool => isset($membersList[$row['usr_id']])));

// Tabelle je Zeile: Rechnung nach ihrer Zahlart, ohne Rechnung nach Profil (IBAN + Mandat) und Verschiebung des Jahres
$memberMethods = array();
$overrides = PaymentMethodStore::all($mfScope->key());
// Profilwerte aller Zeilen-Mitglieder in wenigen Abfragen (fuer Zahlart und E-Mail)
$profileValues = mf_profile_values(array_column($rows, 'usr_id'), array('IBAN', 'MANDATEID' . (int) $gCurrentOrgId, 'EMAIL', 'DEBTOR_EMAIL'));
foreach ($rows as $index => $row) {
    $member = $row['usr_id'];
    if (!isset($memberMethods[$member])) {
        $memberMethods[$member] = InvoicePlanner::method($profileValues[$member]['IBAN'] ?? '', $profileValues[$member]['MANDATEID' . (int) $gCurrentOrgId] ?? '');
    }
    $rows[$index]['auto_method'] = $memberMethods[$member];
    $rows[$index]['group'] = $row['type'] === 'invoice'
        ? ($row['method'] === BookingStore::METHOD_SEPA ? InvoicePlanner::METHOD_SEPA : InvoicePlanner::METHOD_MANUAL)
        : InvoicePlanner::effectiveMethod($memberMethods[$member], $overrides[$member][$row['year']] ?? null);
}

if ($getMode == 'prepare') {
    $ret_text = 'ERROR';
    if ($getUserId != 0) {
        if ($getChecked == 'false') {
            unset($_SESSION['pMembershipFee']['checkedArray'][$getUserId]);
            $ret_text = 'success';
        } elseif ($getChecked == 'true') {
            $_SESSION['pMembershipFee']['checkedArray'][$getUserId] = $_SESSION['pMembershipFee']['mailArray'][$getUserId] ?? '';
            $ret_text = 'success';
        }
    } else {
        // "Alle" schaltet alle angezeigten Mitglieder um (mit group nur die einer Tabelle)
        $groupMembers = array();
        foreach ($rows as $row) {
            if ($getGroup === '' || $row['group'] === $getGroup) {
                $groupMembers[$row['usr_id']] = true;
            }
        }
        foreach (array_intersect_key($membersList, $groupMembers) as $member => $memberData) {
            if ($getAll === 'select') {
                $_SESSION['pMembershipFee']['checkedArray'][$member] = $_SESSION['pMembershipFee']['mailArray'][$member] ?? '';
            } elseif ($getAll === 'deselect') {
                unset($_SESSION['pMembershipFee']['checkedArray'][$member]);
            } elseif (array_key_exists($member, $_SESSION['pMembershipFee']['checkedArray'])) {
                unset($_SESSION['pMembershipFee']['checkedArray'][$member]);
            } else {
                $_SESSION['pMembershipFee']['checkedArray'][$member] = $_SESSION['pMembershipFee']['mailArray'][$member] ?? '';
            }
        }
        $ret_text = 'success';
    }
    echo $ret_text;
    exit();
}

// ------------------------------------------------------------------
// Anzeige
// ------------------------------------------------------------------
$headline = mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_BILL_EDIT'));
$gNavigation->addUrl(CURRENT_URL, $headline);

$page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-bill', $headline);
$page->setContentFullWidth();

if (!empty($_SESSION['pMembershipFee']['bill_message'])) {
    $page->addHtml('<div class="alert alert-success" role="alert"><i class="bi bi-check-lg"></i> ' . $_SESSION['pMembershipFee']['bill_message'] . '</div>');
    unset($_SESSION['pMembershipFee']['bill_message']);
}

$filterParams = array('mem_show_choice' => $getMembersShow, 'invoice_filter' => $getInvoiceFilter, 'year' => $getYear);

$page->addJavascript('
    function billexport(){
        $.post("' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'export')) . '",
            function(data){
                if(data == "marker_empty") {
                    alert("' . $gL10n->get('PLG_MEMBERSHIPFEE_EXPORT_EMPTY') . '");
                    return false;
                }
                window.location.href = "' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bill_export.php') . '";
                return true;
            }
        );
    };

    // Schaltflaechen „Alle auswählen“ / „Alle abwählen“ und Kopf-Kästchen je Tabelle (wie 2.1)
    $(document).on("click", ".mf-bill-all", function() { select_all($(this).data("group"), $(this).data("all")); });
    $(document).on("click", "input[type=checkbox].mf-bill-head", function(e) { e.stopPropagation(); select_all($(this).data("group"), $(this).prop("checked") ? "select" : "deselect"); });
    function select_all(group, all) {
        $.post("' . SecurityUtils::encodeUrl($selfUrl, array_merge($filterParams, array('mode' => 'prepare'))) . '&group=" + (group || "") + "&all=" + (all || ""),
            function(data){
                if(data == "success") {
                    window.location.replace("' . SecurityUtils::encodeUrl($selfUrl, $filterParams) . '");
                } else {
                    alert(data);
                }
            }
        );
    }
');

// Einzugsdatum im Dialog "Rechnungen erstellen": gesperrte Tage fuer die Pruefung im Browser und Sequenztypen
$blockedDays = array();
for ($holidayYear = (int) date('Y'); $holidayYear <= (int) date('Y') + 1; $holidayYear++) {
    $blockedDays = array_merge($blockedDays, mf_bank_holidays($holidayYear));
}
$sequenceOptionsHtml = '';
foreach (array(
    'AUTO' => 'PLG_MEMBERSHIPFEE_AUTO_DIRECT_DEBIT',
    'RCUR' => 'PLG_MEMBERSHIPFEE_FOLLOW_DIRECT_DEBIT',
    'FRST' => 'PLG_MEMBERSHIPFEE_FIRST_DIRECT_DEBIT',
    'OOFF' => 'PLG_MEMBERSHIPFEE_ONETIMES_DIRECT_DEBIT',
    'FNAL' => 'PLG_MEMBERSHIPFEE_FINAL_DIRECT_DEBIT'
) as $sequenceValue => $sequenceLabel) {
    $sequenceOptionsHtml .= '<option value="' . $sequenceValue . '">' . SecurityUtils::encodeHTML($gL10n->get($sequenceLabel)) . '</option>';
}

$page->addJavascript('
    // Filter: Seite mit neuer Auswahl laden
    $("#mem_show, #invoice_filter, #invoice_year").change(function () {
        var params = new URLSearchParams({
            mem_show_choice: $("#mem_show").val(),
            invoice_filter: $("#invoice_filter").val(),
            year: $("#invoice_year").val()
        });
        window.location.replace("' . $selfUrl . '?" + params.toString());
    });

    // Beschriftung "x Rechnungen erstellen": Rechnungen der markierten Mitglieder (je Beitragsjahr ohne Rechnung eine)
    var mfPendingByUser = ' . json_encode((object) $pendingByUser) . ';
    var mfCheckedUsers = ' . json_encode((object) array_fill_keys(array_map('strval', array_keys(array_intersect_key($_SESSION['pMembershipFee']['checkedArray'], $pendingByUser))), true)) . ';
    var mfInvoiceLabel = ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_CREATE_COUNT')) . ';
    function mfUpdateInvoiceButton() {
        var count = 0;
        Object.keys(mfCheckedUsers).forEach(function(usr) { count += mfPendingByUser[usr] || 0; });
        $("#btn_invoices_label").text(mfInvoiceLabel.replace("#VAR1#", count));
        $("#btn_invoices").prop("disabled", count === 0);
    }
    $(function() { mfUpdateInvoiceButton(); });

    // Haken gilt je Mitglied: alle Zeilen des Mitglieds mitschalten
    $("input[type=checkbox].memlist_checkbox").click(function(e){
        e.stopPropagation();
        var userid = $(this).data("usr");
        var checked = $(this).prop("checked");
        $("input[type=checkbox].memlist_checkbox[data-usr=" + userid + "]").prop("checked", checked);
        if (checked) { mfCheckedUsers[String(userid)] = true; } else { delete mfCheckedUsers[String(userid)]; }
        mfUpdateInvoiceButton();
        $.post("' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'prepare')) . '&checked=" + checked + "&usr_id=" + userid,
            function(data){
                if(data != "success") {
                    alert(data);
                }
            }
        );
    });

    // Rechnungen erstellen: Vorschau, dann in Paketen zu 20 Mitgliedern
    var mfInvoiceUsers = [];
    var mfNeedDebitDate = false;
    var mfToken = ' . json_encode($gCurrentSession->getCsrfToken()) . ';
    var mfHolidays = ' . json_encode($blockedDays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';
    var mfToday = ' . json_encode(DATE_NOW) . ';
    // Samstag, Sonntag, Bankfeiertag oder Vergangenheit? Liefert den Grund oder ""
    function mfDebitDateProblem(value) {
        if (!/^\\d{4}-\\d{2}-\\d{2}$/.test(value)) { return "kein gültiges Datum"; }
        if (value < mfToday) { return "liegt in der Vergangenheit"; }
        var parts = value.split("-");
        var day = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).getDay();
        if (day === 6) { return "Samstag"; }
        if (day === 0) { return "Sonntag"; }
        return mfHolidays[value] || "";
    }
    function mfCheckDebitDate() {
        var value = $("#mf_debit_date").val() || "";
        var problem = mfDebitDateProblem(value);
        $("#mf_debit_date").toggleClass("is-invalid", problem !== "");
        $("#mf_debit_problem").text(problem !== "" ? value.split("-").reverse().join(".") + " ist als Einzugsdatum nicht möglich (" + problem + ")." : "");
        return problem;
    }
    $(document).on("change input", "#mf_debit_date", mfCheckDebitDate);
    $(document).on("change input", "#mf_transfer_due", function() { $(this).toggleClass("is-invalid", ($(this).val() || "") < mfToday); });
    // Aenderung an Datum oder Option: Vorschau schliessen, damit sie neu erstellt wird
    $(document).on("change", "#mf_debit_date, #mf_sequencetype, #mf_transfer_due, #mf_overwrite_due", function() { $("#mf_invoice_panel").addClass("d-none"); });
    function mfFormatDate(value) { return (value || "").split("-").reverse().join("."); }
    function mfInvoicePanel(html, cls) {
        $("#mf_invoice_panel").removeClass("d-none alert-info alert-success alert-danger alert-warning").addClass(cls || "alert-info").html(html);
    }
    $("#btn_invoices").click(function() {
        $.post("' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'invoice_preview')) . '", {adm_csrf_token: mfToken}, function(data) {
            if (!data.ok) { mfInvoicePanel($("<div>").text(data.message).html(), "alert-danger"); return; }
            mfInvoiceUsers = data.users;
            var html = "";
            if (data.count === 0) {
                html = ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_NONE_SELECTED')) . ';
            } else {
                var years = Object.keys(data.years).map(function(y) { return y + ": " + data.years[y]; }).join(", ");
                html = "<strong>" + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PREVIEW')) . '.replace("#VAR1#", data.count).replace("#VAR2#", data.amount_text) + "</strong>"
                    + " <small class=\"text-muted\">(" + years + ")</small>";
                if (data.warn_address > 0) {
                    html += "<br/><i class=\"bi bi-exclamation-triangle\"></i> " + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_WARN_ADDRESS')) . '.replace("#VAR1#", data.warn_address);
                }
                // Faelligkeiten aus der Leiste ueber den Tabellen
                mfNeedDebitDate = data.sepa_members > 0;
                var keep = data.sepa_members - data.warn_duedate;
                if (data.sepa_members > 0) {
                    html += "<div class=\"mt-2\"><i class=\"bi bi-arrow-repeat\"></i> " + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PREVIEW_SEPA')) . '.replace("#VAR1#", data.sepa_members).replace("#VAR2#", mfFormatDate($("#mf_debit_date").val()))
                        + (keep > 0 ? " " + ($("#mf_overwrite_due").prop("checked") ? ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PREVIEW_SEPA_OVERWRITE')) . ' : ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PREVIEW_SEPA_KEEP')) . ').replace("#VAR1#", keep) : "") + "</div>";
                }
                if (data.transfer_members > 0) {
                    html += "<div><i class=\"bi bi-bank2\"></i> " + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PREVIEW_TRANSFER')) . '.replace("#VAR1#", data.transfer_members).replace("#VAR2#", mfFormatDate($("#mf_transfer_due").val())) + "</div>";
                }
                html += "<div class=\"mt-2\"><button type=\"button\" class=\"btn btn-primary\" id=\"btn_invoices_confirm\"><i class=\"bi bi-check-lg\"></i> "
                    + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_CREATE')) . ' + "</button> "
                    + "<button type=\"button\" class=\"btn btn-secondary\" id=\"btn_invoices_cancel\">" + ' . json_encode($gL10n->get('SYS_CANCEL')) . ' + "</button></div>";
            }
            if (data.errors.length > 0) {
                html += "<details class=\"mt-2\"><summary>" + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_SKIPPED')) . '.replace("#VAR1#", data.errors.length) + "</summary><ul class=\"mb-0\">"
                    + data.errors.map(function(e) { return "<li>" + $("<div>").text(e).html() + "</li>"; }).join("") + "</ul></details>";
            }
            mfInvoicePanel(html, data.count === 0 ? "alert-warning" : "alert-info");
        }, "json");
    });
    $(document).on("click", "#btn_invoices_cancel", function() { $("#mf_invoice_panel").addClass("d-none"); });
    $(document).on("click", "#btn_invoices_confirm", function() {
        var debitDate = "", sequenceType = "AUTO";
        if (mfNeedDebitDate) {
            if (mfCheckDebitDate() !== "") { $("#mf_debit_date").focus(); return; }
            debitDate = $("#mf_debit_date").val();
            sequenceType = $("#mf_sequencetype").val();
        }
        var transferDue = $("#mf_transfer_due").val() || "";
        if (transferDue !== "" && transferDue < mfToday) { $("#mf_transfer_due").addClass("is-invalid").focus(); return; }
        var overwriteDue = $("#mf_overwrite_due").prop("checked") ? "1" : "";
        var queue = mfInvoiceUsers.slice();
        var total = queue.length, created = 0, skipped = [];
        function next() {
            var done = total - queue.length;
            mfInvoicePanel("<div class=\"progress\" role=\"progressbar\"><div class=\"progress-bar\" style=\"width: " + Math.round(done * 100 / Math.max(total, 1)) + "%\">"
                + done + " / " + total + "</div></div>", "alert-info");
            if (queue.length === 0) {
                var target = ' . json_encode(SecurityUtils::encodeUrl($selfUrl, array_merge($filterParams, array('invoice_filter' => 'invoiced')))) . ' + "&created=" + created + "&skipped=" + skipped.length;
                if (skipped.length === 0) {
                    window.location.replace(target);
                    return;
                }
                // uebersprungene Mitglieder anzeigen, bevor die Seite neu geladen wird
                mfInvoicePanel("<strong>" + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_CREATED')) . '.replace("#VAR1#", created) + "</strong> "
                    + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_SKIPPED')) . '.replace("#VAR1#", skipped.length)
                    + "<ul class=\"mb-2\">" + skipped.map(function(e) { return "<li>" + $("<div>").text(e).html() + "</li>"; }).join("") + "</ul>"
                    + "<a class=\"btn btn-primary\" href=\"" + target + "\">" + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_RELOAD')) . ' + "</a>", "alert-warning");
                return;
            }
            var chunk = queue.splice(0, 20);
            var body = {adm_csrf_token: mfToken, "usr_id[]": chunk, debit_date: debitDate, sequencetype: sequenceType, transfer_due: transferDue, overwrite_due: overwriteDue};
            $.post("' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'invoice_create')) . '", body, function(data) {
                if (!data.ok) { mfInvoicePanel($("<div>").text(data.message).html(), "alert-danger"); return; }
                created += data.created;
                skipped = skipped.concat(data.skipped);
                next();
            }, "json").fail(function() { mfInvoicePanel(' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_FAILED')) . ', "alert-danger"); });
        }
        next();
    });
', true);

// Ergebnis nach "Rechnungen erstellen"
if (isset($_GET['created'])) {
    $page->addHtml('<div class="alert alert-success" role="alert"><i class="bi bi-check-lg"></i> '
        . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_CREATED', array((int) $_GET['created']))
        . ((int) ($_GET['skipped'] ?? 0) > 0 ? ' ' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_SKIPPED', array((int) $_GET['skipped'])) : '')
        . '</div>');
}

$form = new HtmlForm('bill_filter_form', '', $page, array(
    'type' => 'navbar',
    'setFocus' => false
));
$form->addSelectBox('invoice_filter', $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE'), array(
    'open' => $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_FILTER_OPEN'),
    'pending' => $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_FILTER_PENDING'),
    'invoiced' => $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_FILTER_INVOICED'),
    'overdue' => $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_STATUS_OVERDUE'),
    'paid' => $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_STATUS_BEZAHLT'),
    'cancelled' => $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_STATUS_STORNIERT'),
    'all' => $gL10n->get('SYS_ALL')
), array(
    'defaultValue' => $getInvoiceFilter,
    'showContextDependentFirstEntry' => false
));
$yearEntries = array('0' => $gL10n->get('SYS_ALL'));
foreach (array_keys($years) as $year) {
    $yearEntries[(string) $year] = (string) $year;
}
$form->addSelectBox('invoice_year', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR'), $yearEntries, array(
    'defaultValue' => (string) $getYear,
    'showContextDependentFirstEntry' => false
));
$form->addSelectBox('mem_show', $gL10n->get('SYS_EMAIL'), array(
    '0' => $gL10n->get('SYS_ALL'),
    '1' => $gL10n->get('PLG_MEMBERSHIPFEE_WITH_EMAIL'),
    '2' => $gL10n->get('PLG_MEMBERSHIPFEE_WITHOUT_EMAIL')
), array(
    'defaultValue' => $getMembersShow,
    'helpTextId' => 'PLG_MEMBERSHIPFEE_FILTER_DESC',
    'showContextDependentFirstEntry' => false
));
$page->addHtml($form->show());

$pdfReason = PdfBridge::unavailableReason();

// Faelligkeiten fuer "Rechnungen erstellen": Einzugsdatum (SEPA) und Zahlungsziel (Ueberweisung)
$debitDefault = mf_next_valid_duedate(date('Y-m-d', (int) strtotime(DATE_NOW . ' +14 days')));
$transferDefault = date('Y-m-d', (int) strtotime(DATE_NOW . ' +' . $invoiceService->getTermDays() . ' days'));
$page->addHtml('<div class="card mb-3"><div class="card-body py-2"><div class="row g-3 align-items-end">'
    . '<div class="col-auto"><label class="form-label mb-0" for="mf_debit_date"><i class="bi bi-arrow-repeat"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_DEBIT_DATE_SEPA') . '</label>'
    . '<input type="date" class="form-control" id="mf_debit_date" min="' . DATE_NOW . '" value="' . $debitDefault . '"></div>'
    . '<div class="col-auto"><label class="form-label mb-0" for="mf_sequencetype">' . $gL10n->get('PLG_MEMBERSHIPFEE_SEQUENCETYPE') . '</label>'
    . '<select class="form-select" id="mf_sequencetype">' . $sequenceOptionsHtml . '</select></div>'
    . '<div class="col-auto"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mf_overwrite_due">'
    . '<label class="form-check-label" for="mf_overwrite_due">' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_OVERWRITE_DUE') . '</label></div></div>'
    . '<div class="col-auto"><label class="form-label mb-0" for="mf_transfer_due"><i class="bi bi-bank2"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_TRANSFER_DUE') . '</label>'
    . '<input type="date" class="form-control" id="mf_transfer_due" min="' . DATE_NOW . '" value="' . $transferDefault . '"></div>'
    . '</div><div id="mf_debit_problem" class="text-danger fw-bold"></div>'
    . '<div class="form-text">' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_DUE_DESC') . '</div></div></div>');

// Aktionen fuer die markierten Mitglieder
$page->addHtml('<div class="mb-3 d-flex flex-wrap gap-2">'
    . '<button type="button" id="btn_invoices" class="btn btn-primary"><i class="bi bi-receipt"></i> <span id="btn_invoices_label">'
    . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_CREATE_COUNT', array(array_sum(array_intersect_key($pendingByUser, $_SESSION['pMembershipFee']['checkedArray'])))) . '</span></button>'
    . '<a id="btn_exportieren" class="btn btn-secondary" href="javascript:billexport()"><i class="bi bi-filetype-csv"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_EXPORT') . '</a>'
    . '</div>'
    . '<div id="mf_invoice_panel" class="alert d-none" role="status"></div>'
    . ($pdfReason !== '' ? '<p class="small text-muted"><i class="bi bi-info-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_UNAVAILABLE', array(SecurityUtils::encodeHTML($pdfReason))) . '</p>' : '')
    . '<p class="small text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PAGE_DESC') . '</p>');

// zwei Tabellen: Ueberweisung und SEPA-Einzug (Verschieben gilt fuer das Beitragsjahr der Zeile)
$columnAlign = array('center');
$columnValues = array('#SELECT_ALL#');
$usfIds = array();
foreach ($membersList as $memberData) {
    foreach ($memberData as $usfId => $dummy) {
        if (!is_int($usfId)) {
            continue;
        }
        $usfIds[] = $usfId;
        if ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'CHECKBOX' || $gProfileFields->getPropertyById($usfId, 'usf_name_intern') === 'GENDER' || $gProfileFields->getPropertyById($usfId, 'usf_type') === 'EMAIL' || $usfId === (int) $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_id')) {
            $columnAlign[] = 'center';
        } elseif ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'NUMBER' || $gProfileFields->getPropertyById($usfId, 'usf_type') === 'DECIMAL') {
            $columnAlign[] = 'right';
        } else {
            $columnAlign[] = 'left';
        }
        $columnValues[] = $gProfileFields->getPropertyById($usfId, 'usf_name');
    }
    break; // die usfIds eines Mitglieds genuegen fuer die Ueberschriften
}
foreach (array(
    array('PLG_MEMBERSHIPFEE_INVOICE', 'left'),
    array('PLG_MEMBERSHIPFEE_CONTRIBUTION_YEAR', 'center'),
    array('PLG_MEMBERSHIPFEE_INVOICE_DATE', 'left'),
    array('PLG_MEMBERSHIPFEE_INVOICE_DUE', 'left'),
    array('PLG_MEMBERSHIPFEE_INVOICE_AMOUNT', 'right'),
    array('PLG_MEMBERSHIPFEE_STATUS', 'left'),
    array('PLG_MEMBERSHIPFEE_INVOICE_PDF', 'center'),
    array('PLG_MEMBERSHIPFEE_PAYMETHOD_MOVE', 'center')
) as $column) {
    $columnValues[] = $gL10n->get($column[0]);
    $columnAlign[] = $column[1];
}
$tables = array();
foreach (array(InvoicePlanner::METHOD_MANUAL => 'tbl_bill_man', InvoicePlanner::METHOD_SEPA => 'tbl_bill_sepa') as $group => $tableId) {
    $tables[$group] = new HtmlTable($tableId, $page, true, true, 'table table-condensed');
    $tables[$group]->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
    $tables[$group]->setMessageIfNoRowsFound('SYS_NO_ENTRIES');
    $tables[$group]->setColumnAlignByArray($columnAlign);
    $headings = $columnValues;
    $headings[0] = '<input type="checkbox" class="mf-bill-head" data-group="' . $group . '" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_CHANGE_ALL') . '"#HEADCHECKED_' . $group . '# />';
    $tables[$group]->addRowHeadingByArray($headings);
    $tables[$group]->disableDatatablesColumnsSort(array(1, count($columnValues)));
}
$groupCounts = array(InvoicePlanner::METHOD_MANUAL => 0, InvoicePlanner::METHOD_SEPA => 0);
$groupMembersSeen = array(InvoicePlanner::METHOD_MANUAL => array(), InvoicePlanner::METHOD_SEPA => array());

$badges = array(
    'pending' => 'bg-secondary',
    InvoiceStatus::OPEN => 'bg-primary',
    InvoiceStatus::IN_COLLECTION => 'bg-info text-dark',
    InvoiceStatus::PAID => 'bg-success',
    InvoiceStatus::CANCELLED => 'bg-dark',
    'overdue' => 'bg-danger'
);

// Profildaten nur einmal je Mitglied aufbereiten
$memberCells = array();
foreach ($rows as $index => $row) {
    $member = $row['usr_id'];
    if (!isset($memberCells[$member])) {
        $memberUuid = (string) ($profileValues[$member]['usr_uuid'] ?? '');
        $usf_uuid = '';
        if (($profileValues[$member]['DEBTOR_EMAIL'] ?? '') !== '') {
            $usf_uuid = $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_uuid');
        }
        if (($profileValues[$member]['EMAIL'] ?? '') !== '') {
            $usf_uuid = $gProfileFields->getProperty('EMAIL', 'usf_uuid');
        }
        $_SESSION['pMembershipFee']['mailArray'][$member] = $usf_uuid;

        $cells = array();
        foreach ($membersList[$member] as $usfId => $content) {
            if (!is_int($usfId)) {
                continue;
            }
            if ($usfId === (int) $gProfileFields->getProperty('COUNTRY', 'usf_id')) {
                $content = $gL10n->getCountryByCode($content);
            }
            $htmlValue = $gProfileFields->getHtmlValue($gProfileFields->getPropertyById($usfId, 'usf_name_intern'), $content, $memberUuid);

            if ($usfId === (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id') || $usfId === (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id')) {
                $cells[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $memberUuid)) . '">' . $htmlValue . '</a>';
            } elseif ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'EMAIL' || $usfId === (int) $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_id')) {
                // E-Mails gehen nur aus 2.1 Rechnungen versenden; hier nur die Adresse anzeigen
                if (strlen($content) > 0) {
                    $cells[] = '<i class="bi bi-envelope" title="' . SecurityUtils::encodeHTML($content) . '"></i> ' . SecurityUtils::encodeHTML($content);
                } else {
                    $cells[] = '';
                }
            } else {
                $cells[] = $htmlValue;
            }
        }
        $memberCells[$member] = $cells;
    }

    $checked = array_key_exists($member, $_SESSION['pMembershipFee']['checkedArray']) ? ' checked="checked"' : '';
    $columnValues = array('<input type="checkbox" id="member_' . $index . '" data-usr="' . (int) $member . '" class="memlist_checkbox memlist_member"' . $checked . ' />');
    $columnValues = array_merge($columnValues, $memberCells[$member]);

    if ($row['type'] === 'pending') {
        $statusKey = 'pending';
        $statusText = $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_STATUS_PENDING');
        if (($row['error'] ?? '') !== '') {
            $statusText .= ' <i class="bi bi-exclamation-triangle text-danger" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_SKIP_AMOUNT') . '"></i>';
        }
    } else {
        $statusKey = $row['overdue'] ? 'overdue' : $row['status'];
        $statusText = $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_STATUS_' . strtoupper($statusKey));
    }
    $columnValues[] = $row['invoice_no'] !== '' ? '<span class="text-nowrap">' . SecurityUtils::encodeHTML($row['invoice_no']) . '</span>' : '&ndash;';
    $columnValues[] = $row['year'];
    $columnValues[] = $row['date'] !== '' ? mf_format_date($row['date']) : '';
    $columnValues[] = $row['type'] === 'invoice'
        ? ($row['method'] === BookingStore::METHOD_SEPA && $row['due'] === '' ? '<small class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_NEXT_DEBIT') . '</small>' : mf_format_date($row['due']))
        : '';
    $columnValues[] = array('value' => '<span class="text-nowrap">' . mf_format_amount($row['amount']) . '</span>'
        . ($row['type'] === 'invoice' && $row['status'] === InvoiceStatus::OPEN && abs($row['open_amount'] - $row['amount']) >= 0.005
            ? '<br/><small class="text-muted text-nowrap">' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_OPEN_AMOUNT', array(mf_format_amount($row['open_amount']))) . '</small>' : ''),
        'order' => $row['amount']);
    $columnValues[] = '<span class="badge ' . $badges[$statusKey] . '">' . $statusText . '</span>';
    // PDF: abgelegte Datei anzeigen; fehlt sie, wird sie beim Aufruf erzeugt
    $pdfCell = '';
    if ($row['type'] === 'invoice' && $pdfReason === '') {
        $fileUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/invoice_file.php', array('nr' => $row['invoice_no']));
        $pdfCell = $row['pdf'] !== ''
            ? '<a class="admidio-icon-link" href="' . $fileUrl . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_SHOW') . '"></i></a>'
            : '<a class="admidio-icon-link" href="' . $fileUrl . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark-plus text-warning" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_CREATE') . '"></i></a>';
        if ($gCurrentUser->isAdministrator()) {
            $pdfCell .= ' <a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/invoice_file.php',
                array('nr' => $row['invoice_no'], 'mode' => 'regenerate', 'adm_csrf_token' => $gCurrentSession->getCsrfToken())) . '" target="_blank" rel="noopener"><i class="bi bi-arrow-clockwise" title="'
                . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_REGENERATE') . '"></i></a>';
        }
    }
    $columnValues[] = $pdfCell;
    $columnValues[] = mf_paymethod_move_button($row['usr_id'], $row['year'], $row['group'], $row['auto_method'],
        $row['type'] === 'invoice' ? $row['status'] : InvoiceStatus::OPEN);

    $tables[$row['group']]->addRowByArray($columnValues, 'bill_row_' . $index, array('nobr' => 'true'));
    $groupCounts[$row['group']]++;
    $groupMembersSeen[$row['group']][$row['usr_id']] = true;
}

mf_paymethod_move_assets($page, $mfScope);
$groupMemberCount = array_map('count', $groupMembersSeen);
$groupCheckedCount = array_map(static fn (array $members): int => count(array_intersect_key($members, $_SESSION['pMembershipFee']['checkedArray'])), $groupMembersSeen);
foreach (array(InvoicePlanner::METHOD_MANUAL => array('PLG_MEMBERSHIPFEE_PAYMETHOD_TABLE_MAN', 'bi-bank2'),
               InvoicePlanner::METHOD_SEPA => array('PLG_MEMBERSHIPFEE_PAYMETHOD_TABLE_SEPA', 'bi-arrow-repeat')) as $group => $heading) {
    $page->addHtml('<h4 class="mt-4"><i class="bi ' . $heading[1] . '"></i> ' . $gL10n->get($heading[0])
        . ' <span class="badge bg-secondary">' . $groupCounts[$group] . '</span></h4>'
        . '<div class="mb-3">'
        . '<button type="button" class="btn btn-primary me-2 mb-2 mf-bill-all" data-group="' . $group . '" data-all="select"><i class="bi bi-check2-square"></i> Alle auswählen</button>'
        . '<button type="button" class="btn btn-secondary me-2 mb-2 mf-bill-all" data-group="' . $group . '" data-all="deselect"><i class="bi bi-square"></i> Alle abwählen</button>'
        . '<div class="form-text">„Alle auswählen“ und „Alle abwählen“ gelten für alle Mitglieder dieser Tabelle, nicht nur für die aktuelle Tabellenseite.</div>'
        . '</div>');
    // Kopf-Kaestchen angehakt, wenn alle Mitglieder der Tabelle markiert sind
    $page->addHtml(str_replace('#HEADCHECKED_' . $group . '#', ($groupMemberCount[$group] > 0 && $groupCheckedCount[$group] === $groupMemberCount[$group]) ? ' checked="checked"' : '',
        $tables[$group]->show(false)));
}
$page->show();
