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
 *                    mail            - nur Pruefung, ob Benutzer markiert sind (danach message_write.php)
 *                    invoice_preview - (POST, JSON) Vorschau "Rechnungen erstellen" fuer die markierten Mitglieder
 *                    invoice_create  - (POST, JSON) Rechnungen fuer usr_id[] anlegen (ein Paket)
 * usr_id           : <>0 - Benutzer, der im checkedArray gesetzt/geloescht wird; leer - alle angezeigten umschalten
 * mem_show_choice  : 0 - alle, 1 - nur mit E-Mail, 2 - nur ohne E-Mail
 * invoice_filter   : open (Standard: ohne Rechnung, offen, im Einzug) | pending | invoiced | overdue | paid | cancelled | all
 * year             : Beitragsjahr (0 = alle)
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

if (isset($_GET['mode']) && in_array($_GET['mode'], array('export', 'mail', 'prepare'), true)) {
    // ajax mode then only show text if error occurs
    $gMessage->showTextOnly(true);
}

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
    'defaultValue' => 'html',
    'validValues' => array('html', 'export', 'mail', 'prepare', 'invoice_preview', 'invoice_create')
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
                'warn_address' => 0, 'warn_duedate' => 0);
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
                }
            }
            $result['users'] = array_values($result['users']);
            $result['amount_text'] = mf_format_amount($result['amount']);
            echo json_encode($result);
        } else {
            // nur markierte Mitglieder; Paketgroesse wird vom Browser bestimmt (20)
            $usrIds = array_values(array_filter(array_map('intval', (array) ($_POST['usr_id'] ?? array())),
                static fn (int $usrId): bool => isset($_SESSION['pMembershipFee']['checkedArray'][$usrId])));
            $result = $invoiceService->create(array_slice($usrIds, 0, 50), DATE_NOW);
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

if ($getMode == 'mail' || $getMode == 'export') {
    if (count($_SESSION['pMembershipFee']['checkedArray']) === 0) {
        echo 'marker_empty';
    }
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
        // "Alle" schaltet alle angezeigten Mitglieder um
        foreach ($membersList as $member => $memberData) {
            if (array_key_exists($member, $_SESSION['pMembershipFee']['checkedArray'])) {
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

    function massmail(){
        $.post("' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'mail')) . '",
            function(data){
                if(data == "marker_empty") {
                    alert("' . $gL10n->get('PLG_MEMBERSHIPFEE_EMAIL_EMPTY') . '");
                    return false;
                }
                window.location.href = "' . ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/message_write.php";
                return true;
            }
        );
    };

    function select_all() {
        $.post("' . SecurityUtils::encodeUrl($selfUrl, array_merge($filterParams, array('mode' => 'prepare'))) . '",
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

    // Haken gilt je Mitglied: alle Zeilen des Mitglieds mitschalten
    $("input[type=checkbox].memlist_checkbox").click(function(e){
        e.stopPropagation();
        var userid = $(this).data("usr");
        var checked = $(this).prop("checked");
        $("input[type=checkbox].memlist_checkbox[data-usr=" + userid + "]").prop("checked", checked);
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
    var mfToken = ' . json_encode($gCurrentSession->getCsrfToken()) . ';
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
                if (data.warn_duedate > 0) {
                    html += "<br/><i class=\"bi bi-exclamation-triangle\"></i> " + ' . json_encode($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_WARN_DUEDATE')) . '.replace("#VAR1#", data.warn_duedate);
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
            var body = {adm_csrf_token: mfToken, "usr_id[]": chunk};
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

// Aktionen fuer die markierten Mitglieder
$page->addHtml('<div class="mb-3 d-flex flex-wrap gap-2">'
    . '<button type="button" id="btn_invoices" class="btn btn-primary"><i class="bi bi-receipt"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_CREATE') . '</button>'
    . '<a id="btn_mailen" class="btn btn-secondary" href="javascript:massmail()"><i class="bi bi-envelope"></i> ' . $gL10n->get('SYS_EMAIL') . '</a>'
    . '<a id="btn_exportieren" class="btn btn-secondary" href="javascript:billexport()"><i class="bi bi-filetype-csv"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_EXPORT') . '</a>'
    . '</div>'
    . '<div id="mf_invoice_panel" class="alert d-none" role="status"></div>'
    . ($pdfReason !== '' ? '<p class="small text-muted"><i class="bi bi-info-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_UNAVAILABLE', array(SecurityUtils::encodeHTML($pdfReason))) . '</p>' : '')
    . '<p class="small text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PAGE_DESC') . '</p>');

// Tabelle
$table = new HtmlTable('tbl_bill', $page, true, true, 'table table-condensed');
$table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
$table->setMessageIfNoRowsFound('SYS_NO_ENTRIES');

$columnAlign = array('center');
$columnValues = array('<a class="icon-text-link" href="javascript:select_all()">' . $gL10n->get('SYS_ALL') . '</a>');
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
    array('PLG_MEMBERSHIPFEE_INVOICE_METHOD', 'left'),
    array('PLG_MEMBERSHIPFEE_STATUS', 'left'),
    array('PLG_MEMBERSHIPFEE_INVOICE_PDF', 'center')
) as $column) {
    $columnValues[] = $gL10n->get($column[0]);
    $columnAlign[] = $column[1];
}
$table->setColumnAlignByArray($columnAlign);
$table->addRowHeadingByArray($columnValues);
$table->disableDatatablesColumnsSort(array(1));

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
        $user->readDataById($member);
        $usf_uuid = '';
        if (strlen($user->getValue('DEBTOR_EMAIL')) > 0) {
            $usf_uuid = $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_uuid');
        }
        if (strlen($user->getValue('EMAIL')) > 0) {
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
            $userField->readDataById($usfId);
            $htmlValue = $gProfileFields->getHtmlValue($gProfileFields->getPropertyById($usfId, 'usf_name_intern'), $content, $user->getValue('usr_uuid'));

            if ($usfId === (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id') || $usfId === (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id')) {
                $cells[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid'))) . '">' . $htmlValue . '</a>';
            } elseif ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'EMAIL' || $usfId === (int) $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_id')) {
                if (strlen($content) > 0) {
                    $mail_link = ($gSettingsManager->getInt('mail_module_enabled') != 1)
                        ? 'mailto:' . $content
                        : SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/message_write.php', array(
                            'user_uuid' => $user->getValue('usr_uuid'),
                            'usf_uuid' => $userField->getValue('usf_uuid')
                        ));
                    $cells[] = '<a class="admidio-icon-link" href="' . $mail_link . '"><i class="bi bi-envelope" title="' . $gL10n->get('SYS_SEND_EMAIL_TO', array($content)) . '"></i></a>';
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
    $columnValues[] = $row['method'] === '' ? '' : $gL10n->get($row['method'] === BookingStore::METHOD_SEPA ? 'PLG_MEMBERSHIPFEE_INVOICE_METHOD_SEPA' : 'PLG_MEMBERSHIPFEE_INVOICE_METHOD_MAN');
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

    $table->addRowByArray($columnValues, 'bill_row_' . $index, array('nobr' => 'true'));
}

$page->addHtml($table->show(false));
$page->show();
