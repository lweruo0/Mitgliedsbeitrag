<?php
/**
 ***********************************************************************************************
 * Modul Vorabinformation fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode             : html       - Standardmodus zun Anzeigen einer html-Liste
 *                    prepare    - user in einem CheckedArray setzen bzw loeschen
 *                    export     - erzeugt eine Exportdatei
 *                    mail       - nur zur Pruefung, ob user im CheckedArray markiert sind
 * usr_id           : <>0        - Id des Benutzers, fuer der im CheckedArray gesetzt/geloescht wird
 *                    leer       - alle user im CheckedArray aendern von gesetzt->geloescht bzw geloescht->gesetzt
 * checked          : true  - Der Haken beim Benutzer wurde gesetzt
 *                    false - Der Haken beim Benutzer wurde entfernt
 * duedate          : Das uebergebene Faelligkeitsdatum zur Filterung
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Utils\StringUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;
use Plugins\MembershipFee\classes\Invoices\InvoicePlanner;

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

$user = new User($gDb, $gProfileFields);

if (isset($_GET['mode']) && ($_GET['mode'] == 'export' || $_GET['mode'] == 'mail' || $_GET['mode'] == 'prepare')) {
    // ajax mode then only show text if error occurs
    $gMessage->showTextOnly(true);
}

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
    'defaultValue' => 'html',
    'validValues' => array(
        'html',
        'export',
        'mail',
        'prepare'
    )
));
$getUserId = admFuncVariableIsValid($_GET, 'usr_id', 'numeric', array(
    'defaultValue' => 0,
    'directOutput' => true
));
$getChecked = admFuncVariableIsValid($_GET, 'checked', 'string');
// all: select = alle angezeigten Mitglieder auswaehlen, deselect = alle abwaehlen (Schaltflaechen ueber der Tabelle)
$getAll = admFuncVariableIsValid($_GET, 'all', 'string', array(
    'defaultValue' => '',
    'validValues' => array('', 'select', 'deselect')
));
$getDueDate = admFuncVariableIsValid($_GET, 'duedate', 'string', array(
    'defaultValue' => 0
));
// nur ein Datum (Y-m-d) wird als Filter verwendet, alles andere bedeutet "alle Faelligkeiten"
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $getDueDate) !== 1) {
    $getDueDate = 0;
}
// Tabelle, auf die sich prepare/mail/export beziehen: sepa = Vorabinformation an Lastschrift-Zahler,
// transfer = Rechnungs-E-Mail an Ueberweiser. Die Seite zeigt beide Tabellen untereinander.
$getMethod = admFuncVariableIsValid($_GET, 'method', 'string', array(
    'defaultValue' => 'sepa',
    'validValues' => array('sepa', 'transfer')
));

// add current url to navigation stack if last url was not the same page
if (strpos($gNavigation->getUrl(), 'pre_notification.php') === false) {
    $_SESSION['pMembershipFee']['checkedArray'] = array();
    $_SESSION['pMembershipFee']['mailArray'] = array();
}

/**
 * SQL der Mitgliederliste einer Tabelle (sepa: Mandat und Faelligkeit; transfer: offene Rechnung ohne Lastschrift).
 *
 * @return array{0:string, 1:array<int, mixed>}
 */
$prenotSql = static function (string $method) use ($gProfileFields, $gCurrentOrgId, $mfScope, $getDueDate): array {
    $memberCondition = '';

    // Filter zusammensetzen
    $memberCondition = ' EXISTS
        (SELECT 1
           FROM ' . TBL_MEMBERS . ', ' . TBL_ROLES . ', ' . TBL_CATEGORIES . ',' . TBL_USER_DATA . '
          WHERE mem_usr_id = usr_id
            AND mem_rol_id = rol_id
            AND ' . getContributionYearCondition() . '
            AND usd_usr_id = usr_id
            AND usd_usf_id = ' . $gProfileFields->getProperty($mfScope->field(CollectionScope::FIELD_DUEDATE), 'usf_id') . '
            AND rol_valid  = true
            AND rol_cat_id = cat_id
            AND (  cat_org_id = ' . $gCurrentOrgId . '
                OR cat_org_id IS NULL ) ';

    if ($getDueDate != 0) // nur Benutzer mit Faelligkeitsdatum anzeigen ("Mit Faelligkeitsdatum" wurde gewaehlt)
    {
        $memberCondition .= 'AND usd_value = \'' . $getDueDate . '\'   )';
    } else {
        $memberCondition .= 'AND usd_value IS NOT NULL )';
    }

    if ($method === 'transfer') {
        // Ueberweiser: Mitglieder mit offener Rechnung ohne Lastschrift (Rechnungen aus 2.0)
        $transferUserIds = array();
        foreach (\Plugins\MembershipFee\classes\Invoices\InvoiceStore::search(array('kind' => $mfScope->key())) as $invoice) {
            if ($invoice['status'] === \Plugins\MembershipFee\classes\Invoices\InvoiceStatus::OPEN
                && $invoice['mfi_method'] !== \Plugins\MembershipFee\classes\Invoices\InvoicePlanner::METHOD_SEPA) {
                $transferUserIds[(int) $invoice['mfi_usr_id']] = true;
            }
        }
        $methodCondition = count($transferUserIds) > 0 ? 'usr_id IN (' . implode(',', array_keys($transferUserIds)) . ')' : '1 = 0';
    } else {
        $methodCondition = 'iban.usd_value IS NOT NULL
         AND mandatedate.usd_value IS NOT NULL
         AND mandateid.usd_value IS NOT NULL
         AND ' . $memberCondition;
    }

    $sql = 'SELECT DISTINCT usr_id, usr_uuid, last_name.usd_value AS last_name, first_name.usd_value AS first_name, birthday.usd_value AS birthday,
               city.usd_value AS city, street.usd_value AS street, zip_code.usd_value AS zip_code, country.usd_value AS country,
               faelligkeitsdatum.usd_value AS faelligkeitsdatum, beitrag.usd_value AS beitrag, lastschrifttyp.usd_value AS lastschrifttyp,
               mandateid.usd_value AS mandateid, mandatedate.usd_value AS mandatedate, iban.usd_value AS iban, debtor.usd_value AS debtor, 
               debtorstreet.usd_value AS debtorstreet, debtorpostcode.usd_value AS debtorpostcode, debtorcity.usd_value AS debtorcity, 
               debtoremail.usd_value AS debtoremail, email.usd_value AS email
        FROM ' . TBL_USERS . '
        LEFT JOIN ' . TBL_USER_DATA . ' AS last_name
          ON last_name.usd_usr_id = usr_id
         AND last_name.usd_usf_id = ? -- $gProfileFields->getProperty(\'LAST_NAME\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS first_name
          ON first_name.usd_usr_id = usr_id
         AND first_name.usd_usf_id = ? -- $gProfileFields->getProperty(\'FIRST_NAME\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS birthday
          ON birthday.usd_usr_id = usr_id
         AND birthday.usd_usf_id = ? -- $gProfileFields->getProperty(\'BIRTHDAY\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS city
          ON city.usd_usr_id = usr_id
         AND city.usd_usf_id = ? -- $gProfileFields->getProperty(\'CITY\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS street
          ON street.usd_usr_id = usr_id
         AND street.usd_usf_id = ? -- $gProfileFields->getProperty(\'STREET\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS mandateid
          ON mandateid.usd_usr_id = usr_id
         AND mandateid.usd_usf_id = ? -- $gProfileFields->getProperty(\'MANDATEID\'.$gCurrentOrgId, \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS mandatedate
          ON mandatedate.usd_usr_id = usr_id
         AND mandatedate.usd_usf_id = ? -- $gProfileFields->getProperty(\'MANDATEDATE\'.$gCurrentOrgId, \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS iban
          ON iban.usd_usr_id = usr_id
         AND iban.usd_usf_id = ? -- $gProfileFields->getProperty(\'IBAN\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS faelligkeitsdatum
          ON faelligkeitsdatum.usd_usr_id = usr_id
         AND faelligkeitsdatum.usd_usf_id = ? -- $gProfileFields->getProperty(\'DUEDATE\'.$gCurrentOrgId, \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS lastschrifttyp
          ON lastschrifttyp.usd_usr_id = usr_id
         AND lastschrifttyp.usd_usf_id = ? -- $gProfileFields->getProperty(\'SEQUENCETYPE\'.$gCurrentOrgId, \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS beitrag
          ON beitrag.usd_usr_id = usr_id
         AND beitrag.usd_usf_id = ? -- $gProfileFields->getProperty(\'FEE\'.$gCurrentOrgId, \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS zip_code
          ON zip_code.usd_usr_id = usr_id
         AND zip_code.usd_usf_id = ? -- $gProfileFields->getProperty(\'POSTCODE\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS debtor
          ON debtor.usd_usr_id = usr_id
         AND debtor.usd_usf_id = ? -- $gProfileFields->getProperty(\'DEBTOR\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS debtorstreet
          ON debtorstreet.usd_usr_id = usr_id
         AND debtorstreet.usd_usf_id = ? -- $gProfileFields->getProperty(\'DEBTOR_STREET\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS debtoremail
          ON debtoremail.usd_usr_id = usr_id
         AND debtoremail.usd_usf_id = ? -- $gProfileFields->getProperty(\'DEBTOR_EMAIL\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS email
          ON email.usd_usr_id = usr_id
         AND email.usd_usf_id = ? -- $gProfileFields->getProperty(\'EMAIL\', \'usf_id\')
         LEFT JOIN ' . TBL_USER_DATA . ' AS debtorpostcode
          ON debtorpostcode.usd_usr_id = usr_id
         AND debtorpostcode.usd_usf_id = ? -- $gProfileFields->getProperty(\'DEBTOR_POSTCODE\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS debtorcity
          ON debtorcity.usd_usr_id = usr_id
         AND debtorcity.usd_usf_id = ? -- $gProfileFields->getProperty(\'DEBTOR_CITY\', \'usf_id\')
        LEFT JOIN ' . TBL_USER_DATA . ' AS country
          ON country.usd_usr_id = usr_id
         AND country.usd_usf_id = ? -- $gProfileFields->getProperty(\'COUNTRY\', \'usf_id\')

        LEFT JOIN ' . TBL_MEMBERS . ' mem
          ON  ' . getContributionYearCondition('mem.') . '
         AND mem.mem_usr_id  = usr_id
       WHERE ' . $methodCondition . '
    ORDER BY last_name, first_name ';

    $queryParams = array(
        $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        $gProfileFields->getProperty('BIRTHDAY', 'usf_id'),
        $gProfileFields->getProperty('CITY', 'usf_id'),
        $gProfileFields->getProperty('STREET', 'usf_id'),
        $gProfileFields->getProperty('MANDATEID' . $gCurrentOrgId, 'usf_id'),
        $gProfileFields->getProperty('MANDATEDATE' . $gCurrentOrgId, 'usf_id'),
        $gProfileFields->getProperty('IBAN', 'usf_id'),
        $gProfileFields->getProperty($mfScope->field(CollectionScope::FIELD_DUEDATE), 'usf_id'),
        $gProfileFields->getProperty($mfScope->field(CollectionScope::FIELD_SEQUENCETYPE), 'usf_id'),
        $gProfileFields->getProperty($mfScope->field(CollectionScope::FIELD_FEE), 'usf_id'),
        $gProfileFields->getProperty('POSTCODE', 'usf_id'),
        $gProfileFields->getProperty('DEBTOR', 'usf_id'),
        $gProfileFields->getProperty('DEBTOR_STREET', 'usf_id'),
        $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_id'),
        $gProfileFields->getProperty('EMAIL', 'usf_id'),
        $gProfileFields->getProperty('DEBTOR_POSTCODE', 'usf_id'),
        $gProfileFields->getProperty('DEBTOR_CITY', 'usf_id'),
        $gProfileFields->getProperty('COUNTRY', 'usf_id')
    );
    return array($sql, $queryParams);
};

if ($getMode == 'mail' || $getMode == 'export') {
    // nur die markierten Mitglieder der Tabelle, deren Schaltflaeche gedrueckt wurde
    list($sql, $queryParams) = $prenotSql($getMode === 'export' ? 'sepa' : $getMethod);
    $listed = array();
    $statement = $gDb->queryPrepared($sql, $queryParams);
    while ($usr = $statement->fetch()) {
        $listed[(int) $usr['usr_id']] = true;
    }
    $selected = array_intersect_key($_SESSION['pMembershipFee']['checkedArray'], $listed);
    if (count($selected) === 0) {
        // Auswahl der anderen Tabelle bleibt erhalten
        echo 'marker_empty';
    } else {
        $_SESSION['pMembershipFee']['checkedArray'] = $selected;
        $_SESSION['pMembershipFee']['dispatchMethod'] = $getMode === 'export' ? 'sepa' : $getMethod;
    }
} elseif ($getMode == 'prepare' && $getUserId != 0) {
    // einzelner Haken: ohne Mitgliederliste (schnell)
    if ($getChecked == 'false') {
        unset($_SESSION['pMembershipFee']['checkedArray'][$getUserId]);
    } else {
        $_SESSION['pMembershipFee']['checkedArray'][$getUserId] = $_SESSION['pMembershipFee']['mailArray'][$getUserId] ?? '';
    }
    echo 'success';
} else {
    list($sql, $queryParams) = $prenotSql($getMethod);
    $statement = $gDb->queryPrepared($sql, $queryParams);

    if ($getMode == 'prepare') {
        $ret_text = 'ERROR';
        if ($getUserId != 0) // ein einzelner User wurde selektiert
        {
            if ($getChecked == 'false') // der Haken wurde geloescht
            {
                unset($_SESSION['pMembershipFee']['checkedArray'][$getUserId]);
                $ret_text = 'success';
            } elseif ($getChecked == 'true') // der Haken wurde gesetzt
            {
                $_SESSION['pMembershipFee']['checkedArray'][$getUserId] = isset($_SESSION['pMembershipFee']['mailArray'][$getUserId]) ? $_SESSION['pMembershipFee']['mailArray'][$getUserId] : '';
                $ret_text = 'success';
            }
        } elseif ($getAll !== '') // Schaltflaechen „Alle auswählen“ / „Alle abwählen“
        {
            while ($usr = $statement->fetch()) {
                if ($getAll === 'select') {
                    if (!array_key_exists($usr['usr_id'], $_SESSION['pMembershipFee']['checkedArray'])) {
                        $_SESSION['pMembershipFee']['checkedArray'][$usr['usr_id']] = isset($_SESSION['pMembershipFee']['mailArray'][$usr['usr_id']]) ? $_SESSION['pMembershipFee']['mailArray'][$usr['usr_id']] : '';
                    }
                } else {
                    unset($_SESSION['pMembershipFee']['checkedArray'][$usr['usr_id']]);
                }
            }
            $ret_text = 'success';
        } else // Alle aendern wurde gewaehlt
        {
            while ($usr = $statement->fetch()) {
                if (array_key_exists($usr['usr_id'], $_SESSION['pMembershipFee']['checkedArray'])) {
                    unset($_SESSION['pMembershipFee']['checkedArray'][$usr['usr_id']]);
                } else {
                    $_SESSION['pMembershipFee']['checkedArray'][$usr['usr_id']] = isset($_SESSION['pMembershipFee']['mailArray'][$usr['usr_id']]) ? $_SESSION['pMembershipFee']['mailArray'][$usr['usr_id']] : '';
                }
            }
            $ret_text = 'success';
        }
        echo $ret_text;
    } else {
        // set headline of the script
        $headline = mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_MENU_DISPATCH'));

        $gNavigation->addUrl(CURRENT_URL, $headline);

        $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-pre-notification', $headline);
        $page->setContentFullWidth();

        $page->addJavascript('
            function prenotexport(){
                //var duedate = $("#duedate").val() || 0;
                $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification.php', array(
            'mode' => 'export'
        )) . '",
                    function(data){
                        // check if error occurs
                        if(data == "marker_empty") {
                            alert("' . $gL10n->get('PLG_MEMBERSHIPFEE_EXPORT_EMPTY') . '");
                            return false;
                        }
                        else if(data == "success") {
                            alert("file OK");
                        }
                        else {
                            //alert("jetzt gehts zu export");
                            window.location.href = "' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification_export.php') . '" ;
                        }
                        return true;
                    }
                );
            };

            function massmail(method){
                $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification.php', array(
            'mode' => 'mail'
        )) . '&method=" + method,
                    function(data){
                        // check if error occurs
                        if(data == "marker_empty") {
                            alert("' . $gL10n->get('PLG_MEMBERSHIPFEE_EMAIL_EMPTY') . '");
                            return false;
                        }
                        else {
                            //alert("jetzt gehts zu mail");
                            window.location.href = "' . ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/message_write.php" ;
                        }
                        return true;
                    }
                );
            };
        '); // !!!: ohne true

        $javascriptCode = '
        function mfPrenotReload() {
            var duedate = $("#duedate").val() || 0;
            window.location.replace("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification.php') . '?duedate=" + duedate);
        }

        // Schaltflaechen „Alle auswählen“ / „Alle abwählen“ je Tabelle
        $(".mf-prenot-all").click(function(){
            var duedate = $("#duedate").val() || 0;
            $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification.php', array('mode' => 'prepare')) . '&method=" + $(this).data("method") + "&all=" + $(this).data("all") + "&duedate=" + duedate,
                function(data){
                    if(data == "success") { mfPrenotReload(); } else { alert(data); }
                }
            );
        });

        // Kopf-Checkbox: alle Mitglieder der Tabelle umschalten
        $("input[type=checkbox].change_checkbox").click(function(){
            var duedate = $("#duedate").val() || 0;
            $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification.php', array('mode' => 'prepare')) . '&method=" + $(this).data("method") + "&duedate=" + duedate,
                function(data){
                    if(data == "success") { mfPrenotReload(); } else { alert(data); }
                }
            );
        });

        $("#duedate").change(function () {
            if($(this).val().length > 0) {
                window.location.replace("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification.php') . '?duedate=" + $(this).val());
            }
        });

        // Haken eines Mitglieds (gilt fuer beide Tabellen, falls das Mitglied in beiden steht)
        $("input[type=checkbox].memlist_checkbox").click(function(e){
            e.stopPropagation();
            var userid = $(this).data("usr");
            var member_checked = $(this).prop("checked");
            $("input[type=checkbox].memlist_checkbox[data-usr=" + userid + "]").prop("checked", member_checked);
            $.post("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pre_notification.php', array('mode' => 'prepare')) . '&checked=" + member_checked + "&usr_id=" + userid + "&method=" + $(this).data("method"),
                function(data){
                   if(data != "success") {
                        alert(data);
                        return false;
                    }
                    return true;
                }
            );
        });
        ';

        $page->addJavascript($javascriptCode, true);

        $form = new HtmlForm('pre_notification_filter_form', '', $page, array(
            'type' => 'navbar',
            'setFocus' => false
        ));

        // alle Faelligkeitsdaten einlesen
        $sql = 'SELECT DISTINCT usd_value
                FROM ' . TBL_USER_DATA . ',' . TBL_MEMBERS . ', ' . TBL_ROLES . ', ' . TBL_CATEGORIES . '
                WHERE usd_usf_id =  ? -- $gProfileFields->getProperty(\'DUEDATE\'.$gCurrentOrgId, \'usf_id\')
                AND   ' . getContributionYearCondition() . '
                AND   usd_usr_id = mem_usr_id
                AND   mem_rol_id = rol_id
                AND   rol_valid = true
                AND   rol_cat_id = cat_id
                AND (  cat_org_id = ? -- $gCurrentOrgId
                 OR cat_org_id IS NULL ) ';

        $queryParams = array(
            $gProfileFields->getProperty($mfScope->field(CollectionScope::FIELD_DUEDATE), 'usf_id'),
            $gCurrentOrgId
        );

        $duedateStatement = $gDb->queryPrepared($sql, $queryParams);

        $selectBoxEntries = array(
            '0' => '- ' . $gL10n->get('PLG_MEMBERSHIPFEE_SHOW_ALL') . ' -'
        );
        while ($row = $duedateStatement->fetch()) {
            $DueDate = \DateTime::createFromFormat('Y-m-d', $row['usd_value']);
            $selectBoxEntries[$row['usd_value']] = $DueDate->format($gSettingsManager->getString('system_date'));
        }
        // Filter Faelligkeit gilt nur fuer die Tabelle SEPA-Einzug
        $form->addSelectBox('duedate', $gL10n->get('PLG_MEMBERSHIPFEE_DISPATCH_DUEDATE_FILTER'), $selectBoxEntries, array(
            'defaultValue' => $getDueDate,
            'helpTextId' => 'PLG_MEMBERSHIPFEE_FILTER_DESC',
            'showContextDependentFirstEntry' => false
        ));

        // Reihenfolge der Ausgabe (Zusammenfassung, Filter, Schaltflaechen, Tabelle) siehe Ende der Datei
        $filterHtml = $form->show();
        $buttonsHtmlFor = static function (string $method): string {
            return '<div class="mb-3">'
                . '<button type="button" class="btn btn-primary me-2 mb-2 mf-prenot-all" data-method="' . $method . '" data-all="select"><i class="bi bi-check2-square"></i> Alle auswählen</button>'
                . '<button type="button" class="btn btn-secondary me-2 mb-2 mf-prenot-all" data-method="' . $method . '" data-all="deselect"><i class="bi bi-square"></i> Alle abwählen</button>'
                . ($method === 'sepa' ? '<a class="btn btn-primary me-2 mb-2" href="javascript:prenotexport()"><i class="bi bi-filetype-csv"></i> Exportiere Auswahl</a>' : '')
                . '<a class="btn btn-primary mb-2" href="javascript:massmail(\'' . $method . '\')"><i class="bi bi-envelope"></i> '
                . ($method === 'sepa' ? 'Vorabinformation per E-Mail versenden' : 'Rechnung per E-Mail versenden') . '</a>'
                . '<div class="form-text">„Alle auswählen“ und „Alle abwählen“ gelten für alle Mitglieder dieser Tabelle'
                . ($method === 'sepa' ? ' (Filter Fälligkeit beachten)' : '') . ', nicht nur für die aktuelle Tabellenseite. Versendet werden nur die markierten Mitglieder dieser Tabelle.</div>'
                . '</div>';
        };

        $sectionsHtml = '';
        foreach (array('transfer' => array('PLG_MEMBERSHIPFEE_PAYMETHOD_TABLE_MAN', 'bi-bank2'), 'sepa' => array('PLG_MEMBERSHIPFEE_PAYMETHOD_TABLE_SEPA', 'bi-arrow-repeat')) as $method => $sectionHeading) {
            // create table object
            $table = new HtmlTable('tbl_prenot_' . $method, $page, true, true, 'table table-condensed');
            $table->setMessageIfNoRowsFound('SYS_NO_ENTRIES');

            // create array with all column heading values
            $columnHeading = array(
                '<input type="checkbox" id="change_' . $method . '" data-method="' . $method . '" class="change_checkbox admidio-icon-help" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_CHANGE_ALL') . '"/>',
                $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'),
                '<i class="bi bi-chat-fill admidio-info-icon" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_SEQUENCETYPE_DESC') . '"></i>',
                $gL10n->get('PLG_MEMBERSHIPFEE_FEE'),
                $gL10n->get('SYS_LASTNAME'),
                $gL10n->get('SYS_FIRSTNAME'),
                '<i class="bi bi-map-fill admidio-info-icon" title="' . $gL10n->get('SYS_ADDRESS') . '"></i>',
                $gL10n->get('SYS_STREET'),
                '<i class="bi bi-info-circle admidio-info-icon" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_DEBTOR') . '"></i>',
                $gL10n->get('PLG_MEMBERSHIPFEE_DEBTOR'),
                '<i class="bi bi-envelope admidio-info-icon" title="' . $gL10n->get('SYS_EMAIL') . '"></i>',
                $gL10n->get('SYS_EMAIL'),
                $gL10n->get('PLG_MEMBERSHIPFEE_MANDATEID'),
                $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_EXPORT_NO'),
                $gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_MOVE')
            );

            $table->setColumnAlignByArray(array(
                'left',
                'left',
                'center',
                'right',
                'left',
                'left',
                'center',
                'left',
                'center',
                'left',
                'center',
                'left',
                'left',
                'left',
                'center'
            ));
            $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
            $table->setDatatablesOrderColumns(array(
                5,
                6
            ));
            $table->addRowHeadingByArray($columnHeading);
            $table->disableDatatablesColumnsSort(array(
                1
            ));
            $table->setDatatablesAlternativeOrderColumns(7, 8);
            $table->setDatatablesAlternativeOrderColumns(9, 10);
            $table->setDatatablesAlternativeOrderColumns(11, 12);
            $table->setDatatablesColumnsHide(array(
                8,
                10,
                12
            ));

            // fuer die Zusammenfassung: angezeigte Mitglieder und Summe des Beitragsfelds
            $summaryUserIds = array();
            $summaryFeeTotal = 0.0;

            list($sql, $queryParams) = $prenotSql($method);
            $statement = $gDb->queryPrepared($sql, $queryParams);

            // show rows with all organization users
            while ($usr = $statement->fetch()) {
                $summaryUserIds[] = (int) $usr['usr_id'];
                $summaryFeeTotal += normalize_fee($usr['beitrag']);
                $addressText = ' ';
                $htmlAddress = '&nbsp;';
                $htmlBirthday = '&nbsp;';
                $htmlBeitrag = '&nbsp;';
                $htmlDueDate = '&nbsp;';
                $email = '';
                $htmlMail = '&nbsp;';
                $debtor_text = ' ';
                $htmlDebtorText = '&nbsp;';
                $htmlMandateID = '&nbsp;';
                $htmlLastschrifttyp = '&nbsp;';
                $lastschrifttyp = '';

                // 1. Spalte ($htmlDueDateStatus)
                $htmlDueDateStatus = '<input type="checkbox" id="member_' . $method . '_' . $usr['usr_id'] . '" data-usr="' . (int) $usr['usr_id'] . '" data-method="' . $method . '" class="memlist_checkbox"'
                    . (array_key_exists($usr['usr_id'], $_SESSION['pMembershipFee']['checkedArray']) ? ' checked="checked"' : '') . ' />';

                // aktuelle Rechnung (offen oder im Einzug) fuer die letzte Spalte und die Faelligkeit der Ueberweiser
                $currentInvoice = mf_current_invoice((int) $usr['usr_id'], $mfScope);

                // 2. Spalte ($htmlDueDate): Lastschrift = Faelligkeitsdatum, Ueberweisung = Zahlungsziel der Rechnung
                $dueValue = $method === 'transfer' ? (string) ($currentInvoice['mfi_due_date'] ?? '') : (string) $usr['faelligkeitsdatum'];
                if ($dueValue !== '') {
                    $DueDate = \DateTime::createFromFormat('Y-m-d', $dueValue);
                    if ($DueDate !== false) {
                        $htmlDueDate = $DueDate->format($gSettingsManager->getString('system_date'));
                    }
                }

                // 3. Spalte ($htmlLastschrifttyp)
                switch ($usr['lastschrifttyp']) {
                    case 'RCUR':
                        $lastschrifttyp = 'R';
                        break;
                    case 'FNAL':
                        $lastschrifttyp = 'F';
                        break;
                    case 'OOFF':
                        $lastschrifttyp = 'O';
                        break;
                }

                if (strlen($lastschrifttyp) > 0 && $method === 'sepa') {
                    $htmlLastschrifttyp = $lastschrifttyp;
                }

                // 4. Spalte ($htmlBeitrag)
                if ($usr['beitrag'] > 0) {
                    $htmlBeitrag = $usr['beitrag'] . ' ' . $gSettingsManager->getString('system_currency');
                }

                // 5. Spalte (Nachname)

                // 6. Spalte (Vorname)

                // 7. Spalte ($htmlAddress)
                if (strlen((string) $usr['zip_code']) > 0 || strlen((string) $usr['city']) > 0) {
                    $addressText .= $usr['zip_code'] . ' ' . $usr['city'];
                }
                if (strlen((string) $usr['street']) > 0) {
                    $addressText .= ' - ' . $usr['street'];
                }
                if (strlen($addressText) > 1) {
                    $htmlAddress = '<i class="bi bi-map-fill admidio-info-icon" title="' . $addressText . '"></i>';
                }

                // 8. Spalte ($addressText)

                // 10. Spalte ($htmlDebtorText)
                if (strlen((string) $usr['debtor']) > 0) {
                    $debtor_text = $usr['debtor'];
                }
                if (strlen((string) $usr['debtorstreet']) > 0) {
                    $debtor_text = $debtor_text . ' - ' . $usr['debtorstreet'];
                }
                if (strlen((string) $usr['debtorpostcode']) > 0 || strlen((string) $usr['debtorcity']) > 0) {
                    $debtor_text = $debtor_text . ' - ' . $usr['debtorpostcode'] . ' ' . $usr['debtorcity'];
                }

                if (strlen($debtor_text) > 1) {
                    $htmlDebtorText = '<i class="bi bi-info-circle admidio-info-icon" title="' . $debtor_text . '"></i>';
                }

                // 11. Spalte ($htmlMail)
                if (StringUtils::strValidCharacters((string) $usr['debtoremail'], 'email')) {
                    $email = $usr['debtoremail'];
                    $usf_uuid = $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_uuid');
                } elseif (strlen((string) $usr['email']) > 0) {
                    $email = $usr['email'];
                    $usf_uuid = $gProfileFields->getProperty('EMAIL', 'usf_uuid');
                }
                if (strlen((string) $email) > 0) {
                    $_SESSION['pMembershipFee']['mailArray'][$usr['usr_id']] = $usf_uuid;
                    if ($gSettingsManager->getInt('mail_module_enabled') != 1) {
                        $mail_link = 'mailto:' . $email;
                    } else {
                        $mail_link = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/message_write.php', array(
                            'user_uuid' => $usr['usr_uuid'],
                            'usf_uuid' => $usf_uuid,
                            'dispatch' => $method
                        ));
                    }
                    $htmlMail = '<a class="admidio-icon-link" href="' . $mail_link . '"><i class="bi bi-envelope" title="' . $gL10n->get('SYS_SEND_EMAIL_TO', array(
                        $email
                    )) . '"></i>';
                }

                // 12. Spalte ($email)
                if (strlen((string) $usr['mandateid']) > 0 && $method === 'sepa') {
                    $htmlMandateID = $usr['mandateid'];
                }

                // create array with all column values
                $columnValues = array(
                    $htmlDueDateStatus,
                    $htmlDueDate,
                    $htmlLastschrifttyp,
                    $htmlBeitrag,
                    '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                        'user_uuid' => $usr['usr_uuid']
                    )) . '">' . $usr['last_name'] . '</a>',
                    '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                        'user_uuid' => $usr['usr_uuid']
                    )) . '">' . $usr['first_name'] . '</a>',
                    $htmlAddress,
                    $addressText,
                    $htmlDebtorText,
                    $debtor_text,
                    $htmlMail,
                    $email,
                    $htmlMandateID,
                    $currentInvoice !== null ? SecurityUtils::encodeHTML((string) $currentInvoice['mfi_invoice_no']) : '&nbsp;',
                    // Verschieben nur mit Rechnung (Beitragsjahr der Rechnung)
                    $currentInvoice !== null ? mf_paymethod_move_button((int) $usr['usr_id'], (int) $currentInvoice['mfi_year'],
                        $method === 'sepa' ? 'SEPA' : 'MAN', InvoicePlanner::method((string) $usr['iban'], (string) $usr['mandateid']), (string) $currentInvoice['status']) : ''
                );

                $table->addRowByArray($columnValues, 'userid_' . $method . '_' . $usr['usr_id']);
            } // End While

            // Zusammenfassung je Beitragsrolle: offene und exportierte Positionen der angezeigten Mitglieder
            $currency = $gSettingsManager->getString('system_currency');
            $formatAmount = static function (float $amount) use ($currency): string {
                return number_format($amount, 2, ',', '.') . ' ' . $currency;
            };
            $summary = array();
            $positionsTotal = 0.0;
            if (count($summaryUserIds) > 0) {
                foreach (BookingStore::getByUsers($summaryUserIds, array(BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED)) as $positions) {
                    foreach ($mfScope->filterPositions($positions) as $position) {
                        $name = trim((string) $position['mfb_rol_name']) !== '' ? (string) $position['mfb_rol_name'] : (string) $position['mfb_text'];
                        if (!isset($summary[$name])) {
                            $summary[$name] = array('count' => 0, 'members' => array(), 'amount' => 0.0);
                        }
                        $summary[$name]['count']++;
                        $summary[$name]['members'][(int) $position['mfb_usr_id']] = true;
                        $summary[$name]['amount'] += (float) $position['mfb_amount'];
                        $positionsTotal += (float) $position['mfb_amount'];
                    }
                }
            }
            ksort($summary, SORT_NATURAL | SORT_FLAG_CASE);

            $summaryHtml = '<div class="card admidio-blog mb-4"><div class="card-header">Zusammenfassung '
                . ($method === 'transfer' ? 'Überweisung mit offener Rechnung'
                    : ($getDueDate != 0 ? 'Fälligkeit ' . htmlspecialchars((string) ($selectBoxEntries[$getDueDate] ?? $getDueDate), ENT_QUOTES, 'UTF-8') : 'aller Fälligkeiten'))
                . ' (' . count($summaryUserIds) . ' Mitglieder)</div><div class="card-body p-0"><div class="table-responsive mb-0">'
                . '<table class="table table-sm table-striped mb-0"><thead><tr><th>Beitragsrolle</th><th class="text-end">Anzahl</th><th class="text-end">Summe</th></tr></thead><tbody>';
            if (count($summary) === 0) {
                $summaryHtml .= '<tr><td colspan="3" class="text-muted">Keine offenen Beitragspositionen gefunden.</td></tr>';
            }
            foreach ($summary as $name => $data) {
                $summaryHtml .= '<tr><td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td class="text-end">' . $data['count'] . '</td>'
                    . '<td class="text-end">' . $formatAmount($data['amount']) . '</td></tr>';
            }
            $summaryHtml .= '</tbody><tfoot><tr class="fw-bold"><td>Gesamt</td><td class="text-end">' . array_sum(array_column($summary, 'count')) . '</td>'
                . '<td class="text-end">' . $formatAmount($positionsTotal) . '</td></tr>';
            if (abs($positionsTotal - $summaryFeeTotal) >= 0.005) {
                $summaryHtml .= '<tr class="text-danger"><td colspan="2">Abweichung: Summe des Profilfelds Beitrag der angezeigten Mitglieder</td>'
                    . '<td class="text-end">' . $formatAmount($summaryFeeTotal) . '</td></tr>';
            }
            $summaryHtml .= '</tfoot></table></div></div></div>';

            $sectionsHtml .= '<h4 class="mt-4"><i class="bi ' . $sectionHeading[1] . '"></i> ' . $gL10n->get($sectionHeading[0])
                . ' <span class="badge bg-secondary">' . count($summaryUserIds) . '</span></h4>'
                . '<p class="small text-muted">' . $gL10n->get($method === 'sepa' ? 'PLG_MEMBERSHIPFEE_DISPATCH_SEPA_DESC' : 'PLG_MEMBERSHIPFEE_DISPATCH_TRANSFER_DESC') . '</p>'
                . $summaryHtml . $buttonsHtmlFor($method) . $table->show(false);
        }

        // Rechnungen entstehen vor der Vorabinformation: Hinweis auf offene Positionen ohne Rechnung
        if (\Plugins\MembershipFee\classes\Invoices\PdfBridge::isAvailable()) {
            $withoutInvoice = array();
            foreach (BookingStore::getByUsers(array(), array(BookingStore::STATUS_OPEN)) as $openUsrId => $openPositions) {
                if (count(\Plugins\MembershipFee\classes\Invoices\InvoicePlanner::plan($mfScope->filterPositions($openPositions))) > 0) {
                    $withoutInvoice[$openUsrId] = true;
                }
            }
            if (count($withoutInvoice) > 0) {
                $page->addHtml('<div class="alert alert-info" role="alert"><i class="bi bi-receipt"></i> '
                    . $gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_MISSING_HINT', array(count($withoutInvoice),
                        '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bill.php', array('invoice_filter' => 'pending', 'menu' => 1)) . '">', '</a>'))
                    . '</div>');
            }
        }
        $page->addHtml($filterHtml);
        mf_paymethod_move_assets($page, $mfScope);
        $page->addHtml($sectionsHtml);
        $page->show();
    }
}
