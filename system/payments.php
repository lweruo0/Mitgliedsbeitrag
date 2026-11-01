<?php
/**
 ***********************************************************************************************
 * Setzen eines Bezahlt-Datums fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Hinweis:   payments.php ist eine modifizierte members_assignment.php
 *
 * Parameters:
 *
 * mode             : html        - Standardmodus zun Anzeigen einer html-Liste aller Benutzer mit Beitraegen
 *                    assign_date - Setzen eines Bezahlt-Datums
 *                    delete_date - Löschen eines Bezahlt-Datums
 *                    prepare     - eine einzelne Checkbox oder "Alle" wurde angeklickt 
 * usr_id           : Id des Benutzers, fuer den das Bezahlt-Datum gesetzt wird
 * datum_neu        : das neue Bezahlt-Datum
 * mem_show_choice  : 3 - (Default) Nur Benutzer mit offenem Betrag (offene oder exportierte Positionen)
 *                    0 - Alle Benutzer anzeigen
 *                    1 - Nur Benutzer anzeigen, bei denen ein Bezahlt-Datum vorhanden ist
 *                    2 - Nur Benutzer anzeigen, bei denen kein Bezahlt-Datum vorhanden ist
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
use Admidio\Roles\Entity\Role;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;

require_once(__DIR__ . '/../../../system/common.php');
require_once(__DIR__ . '/common_function.php');

// only authorized user are allowed to start this module
if (!isUserAuthorized())
{
    throw new Exception('SYS_NO_RIGHTS');   
}

$pPreferences = new ConfigTable();
$pPreferences->read();

$user = new User($gDb, $gProfileFields);
$userField = new Entity($gDb, TBL_USER_FIELDS, 'usf'); 
$bookingService = new BookingService($pPreferences->config);
$revertMessages = array();

if(isset($_GET['mode']) && ($_GET['mode'] == 'assign_date' || $_GET['mode'] == 'delete_date' || $_GET['mode'] == 'prepare'))
{
    // ajax mode then only show text if error occurs
    $gMessage->showTextOnly(true);
}

// Initialize and check the parameters
$getMode        = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'html', 'validValues' => array('html', 'assign_date', 'delete_date', 'prepare')));
$getUserId      = admFuncVariableIsValid($_GET, 'usr_id', 'numeric', array('defaultValue' => 0, 'directOutput' => true));
$getDatumNeu    = admFuncVariableIsValid($_GET, 'datum_neu', 'date');
$getChecked     = admFuncVariableIsValid($_GET, 'checked', 'string');
$getMembersShow = admFuncVariableIsValid($_GET, 'mem_show_choice', 'numeric', array('defaultValue' => 3));
$paidDate       = ($getDatumNeu !== '') ? mf_parse_date($getDatumNeu) : '';
if ($paidDate === '') {
    $paidDate = $getDatumNeu;
}

// write role selection in session
if (strpos($gNavigation->getUrl(), 'membership_fee.php') !== false)
{
    $_SESSION['pMembershipFee']['checkedArray'] = array();
    $_SESSION['pMembershipFee']['selectAll'] = true;
    
	if (isset($_POST['payments_roleselection']) )
	{
		$_SESSION['pMembershipFee']['payments_rol_sel'] = $_POST['payments_roleselection'];
	}
	else
	{
		unset($_SESSION['pMembershipFee']['payments_rol_sel']);
	}
}

// Seite direkt (z. B. per Lesezeichen oder nach Sitzungsablauf) aufgerufen: Auswahl initialisieren
if (!isset($_SESSION['pMembershipFee']['checkedArray']) || !is_array($_SESSION['pMembershipFee']['checkedArray'])) {
    $_SESSION['pMembershipFee']['checkedArray'] = array();
}
if (!isset($_SESSION['pMembershipFee']['selectAll'])) {
    $_SESSION['pMembershipFee']['selectAll'] = true;
}

if ($getMode == 'assign_date')
{
    $ret_text = 'ERROR';
    
    if (count($_SESSION['pMembershipFee']['checkedArray']) === 0)
    {
        $ret_text = 'nothing_selected';
    }
    else
    {
        try
        {
            $workArray = $_SESSION['pMembershipFee']['checkedArray'];

            foreach ($workArray as $userId => $dummy)
            {
                $user->readDataById($userId);
            
                // Bezahlt-Datum setzen und die Positionen der Buchungshistorie auf "eingezogen" setzen
                $bookingService->markPaid($user, $paidDate);
                unset($_SESSION['pMembershipFee']['checkedArray'][$userId]);
            }
            $_SESSION['pMembershipFee']['selectAll'] = true;
            $ret_text = 'success';
        }
        catch(AdmException $e)
        {
            $e->showText();
        }
    }
    echo $ret_text;
}
elseif ($getMode == 'delete_date')
{
    $ret_text = 'ERROR';
    
    if (count($_SESSION['pMembershipFee']['checkedArray']) === 0)
    {
        $ret_text = 'nothing_selected';
    }
    else
    {
        try
        {
            $workArray = $_SESSION['pMembershipFee']['checkedArray'];

            foreach ($workArray as $userId => $dummy)
            {
                $user->readDataById($userId);
            
                if (strlen($user->getValue('PAID'.$gCurrentOrgId)) > 0)
                {
                    // Bezahlt-Datum loeschen, eingezogene Positionen zuruecksetzen und Profilfelder wiederherstellen
                    $notReverted = $bookingService->revertPaid($user);
                    if (count($notReverted) > 0)
                    {
                        $revertMessages[] = $user->getValue('LAST_NAME').', '.$user->getValue('FIRST_NAME').': '.implode(', ', $notReverted);
                    }
                }
                unset($_SESSION['pMembershipFee']['checkedArray'][$userId]);
            }
            $_SESSION['pMembershipFee']['selectAll'] = true;
            $ret_text = 'success';
            if (count($revertMessages) > 0)
            {
                $ret_text = $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_NOT_REVERTED')."\n".implode("\n", $revertMessages);
            }
        }
        catch(AdmException $e)
        {
            $e->showText();
        }
    }
    echo $ret_text;
}
else
{
    $membersList = array();
  
    if (isset($_SESSION['pMembershipFee']['payments_rol_sel']) )
    {
    	// Rollenwahl ist vorhanden, deshalb Daten aufbereiten fuer list_members
    	$membersListRols = array();
    	$role = new Role($gDb);
    	foreach ($_SESSION['pMembershipFee']['payments_rol_sel']as $rol_id)
    	{
    		$role->readDataById($rol_id);
    		$membersListRols[$role->getValue('rol_name')] = 0;
    	}
    }
    else
    {
    	$membersListRols = 0;
    }
    
    $membersListFields = array_filter($pPreferences->config['columnconfig']['payments_fields']);            //array_filter: löschen leerer Einträge, falls das Setup fehlgeschlagen ist 

    $membersListSqlCondition = 'AND mem_usr_id IN (SELECT DISTINCT usr_id
        FROM '. TBL_USERS. '
        LEFT JOIN '. TBL_USER_DATA. ' AS paid
          ON paid.usd_usr_id = usr_id
         AND paid.usd_usf_id = '. $gProfileFields->getProperty('PAID'.$gCurrentOrgId, 'usf_id'). '
        LEFT JOIN '. TBL_USER_DATA. ' AS fee
          ON fee.usd_usr_id = usr_id
         AND fee.usd_usf_id = '. $gProfileFields->getProperty('FEE'.$gCurrentOrgId, 'usf_id'). '
         		
        LEFT JOIN '. TBL_MEMBERS. ' AS mem
          ON '.getContributionYearCondition('mem.').'
         AND mem.mem_usr_id  = usr_id
         		
       WHERE fee.usd_value IS NOT NULL ';
    
    if ($getMembersShow == 1)                   // Nur Benutzer anzeigen, bei denen ein Bezahlt-Datum vorhanden ist
    {
    	$membersListSqlCondition .= ' AND paid.usd_value IS NOT NULL ) ';
    }
    elseif ($getMembersShow == 2)				// Nur Benutzer anzeigen, bei denen kein Bezahlt-Datum vorhanden ist
    {
    	$membersListSqlCondition .= ' AND paid.usd_value IS NULL ) ';
    }
    elseif ($getMembersShow == 3)				// Nur Benutzer mit offenem Betrag (offene oder exportierte Positionen)
    {
        BookingStore::ensureTables();
        $membersListSqlCondition .= ' AND usr_id IN (SELECT mfb_usr_id FROM ' . BookingStore::bookingsTable() . '
            WHERE mfb_org_id = ' . (int) $gCurrentOrgId . '
              AND mfb_status IN (\'' . BookingStore::STATUS_OPEN . '\', \'' . BookingStore::STATUS_EXPORTED . '\')) ) ';
    }
    else 										// Alle Benutzer anzeigen
    {
    	$membersListSqlCondition .= ' ) ';
    }
    
    $membersList = list_members($membersListFields, $membersListRols, $membersListSqlCondition);

    // offene Betraege, Status und Zahlart je Mitglied (fuer die festen Spalten und die Zusammenfassung)
    $paymentInfo = array();
    if ($getMode === 'html' && count($membersList) > 0) {
        $extraFields = list_members(array('PAID' . $gCurrentOrgId, 'IBAN'), $membersListRols, $membersListSqlCondition);
        $openByUser = BookingStore::getByUsers(array_keys($membersList), array(BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED));
        foreach ($membersList as $member => $dummy) {
            $open = 0.0;
            $exported = 0.0;
            foreach ($openByUser[$member] ?? array() as $position) {
                if ($position['mfb_status'] === BookingStore::STATUS_EXPORTED) {
                    $exported += (float) $position['mfb_amount'];
                } else {
                    $open += (float) $position['mfb_amount'];
                }
            }
            $paymentInfo[$member] = array(
                'open' => round($open, 2),
                'exported' => round($exported, 2),
                'paid' => (string) ($extraFields[$member]['PAID' . $gCurrentOrgId] ?? ''),
                'iban' => (string) ($extraFields[$member]['IBAN'] ?? '') !== ''
            );
        }
    }

    if ($getMode == 'prepare')
    {
        $ret_text = 'ERROR';
        if($getUserId != 0)           // ein einzelner User wurde selektiert
        {
            if ($getChecked == 'false')            // der Haken wurde geloescht
            {
                unset($_SESSION['pMembershipFee']['checkedArray'][$getUserId]);
            }
            elseif ($getChecked == 'true')        // der Haken wurde gesetzt
            {
                $_SESSION['pMembershipFee']['checkedArray'][$getUserId] = 'checked';
            }
            $ret_text = 'success';
        }
        else                        // "Alle" wurde angeklickt
        {
            foreach ($membersList as $member => $memberData)
   	        {
            
                if ($_SESSION['pMembershipFee']['selectAll'])
                {
                    $_SESSION['pMembershipFee']['checkedArray'][$member] = 'checked';
                }
                else
                {
                    unset($_SESSION['pMembershipFee']['checkedArray'][$member]);
                }
            }
            $_SESSION['pMembershipFee']['selectAll'] = !$_SESSION['pMembershipFee']['selectAll'];
            $ret_text = 'success';
        }
        echo $ret_text;
    }
    else
    {
        // set headline of the script
        $headline = $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS');

        $gNavigation->addUrl(CURRENT_URL, $headline);

        $page = new HtmlPage('plg-mitgliedsbeitrag-payments', $headline);
        $page->setContentFullWidth(); 
        
        $page->addJavascript('

        function assign_date() {
            var datum = $("#datum").val();
            $.post("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php', array('mode' => 'assign_date')) .'&datum_neu=" + datum,
                function(data){
                    // check if error occurs
                    if(data == "nothing_selected") {
                        alert("'.$gL10n->get('PLG_MEMBERSHIPFEE_NOTHING_SELECTED').'");
                        return false;
                    }
                    else if(data == "success") {
                        var mem_show = $("#mem_show").val();
                        window.location.replace("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php').'?mem_show_choice=" + mem_show);
                    }
                    else {
                        alert(data);
                        return false;
                    }
                    return true;
                }
            );
        }

        function delete_date() {
            $.post("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php', array('mode' => 'delete_date')) .'",
                function(data){
                    // check if error occurs
                    if(data == "nothing_selected") {
                        alert("'.$gL10n->get('PLG_MEMBERSHIPFEE_NOTHING_SELECTED').'");
                        return false;
                    }
                    else if(data == "success") {
                        var mem_show = $("#mem_show").val();
                        window.location.replace("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php').'?mem_show_choice=" + mem_show);
                    }
                    else {
                        alert(data);
                        var mem_show = $("#mem_show").val();
                        window.location.replace("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php').'?mem_show_choice=" + mem_show);
                        return false;
                    }
                    return true;
                }
            );
        }
        
        function select_all() {
            var mem_show = $("#mem_show").val();
            $.post("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php', array('mode' => 'prepare')) .'&mem_show_choice=" + mem_show,
                function(data){
                    // check if error occurs
                    if(data == "success") {
                        window.location.replace("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php').'?mem_show_choice=" + mem_show);
                    }
                    else {
                        alert(data);
                        return false;
                    }
                    return true;
                }
            );
        }
        ');

        $javascriptCode = '
        // Anzeige abhaengig vom gewaehlten Filter
        $("#mem_show").change(function () {
            if($(this).val().length > 0) {
                window.location.replace("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php').'?mem_show_choice="+$(this).val());
            }
        });
                                  
        // if checkbox of user is clicked then change data
        $("input[type=checkbox].memlist_checkbox").click(function(e){
            e.stopPropagation();
            var checkbox = $(this);
            var row_id = $(this).parent().parent().attr("id");
            var pos = row_id.search("_");
            var userid = row_id.substring(pos+1);
            var member_checked = $("input[type=checkbox]#member_"+userid).prop("checked");
      
            // change data in database
            $.post("'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/payments.php', array('mode' => 'prepare')) .'&checked=" + member_checked + "&usr_id=" + userid,
                function(data){
                    // check if error occurs
                    if(data !== "success") {
                        alert(data);
                        return false;
                    }
                    return true;
                }
            );
        });
        ';
 
        $page->addJavascript($javascriptCode, true);
    
        if (isset($_SESSION['pMembershipFee']['payments_rol_sel']))
        {
            $page->addHtml('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_ROLLQUERY_ACTIV').'</strong>');
        }

        $form = new HtmlForm('payments_filter_form', '', $page, array('type' => 'navbar', 'setFocus' => false));
    
        $selectBoxEntries = array('3' => $gL10n->get('PLG_MEMBERSHIPFEE_WITH_OPEN_AMOUNT'), '2' => $gL10n->get('PLG_MEMBERSHIPFEE_WITHOUT_PAID'), '1' => $gL10n->get('PLG_MEMBERSHIPFEE_WITH_PAID'), '0' => $gL10n->get('SYS_SHOW_ALL_CONTACTS'));
        $form->addSelectBox('mem_show', $gL10n->get('PLG_MEMBERSHIPFEE_FILTER'), $selectBoxEntries, array('defaultValue' => $getMembersShow, 'helpTextId' => 'PLG_MEMBERSHIPFEE_FILTER_DESC', 'showContextDependentFirstEntry' => false));
 
        $page->addHtml($form->show(false));

        // Aktionen unterhalb des Filters: Datum und Buttons
        $page->addHtml('<div class="d-flex flex-wrap align-items-center gap-2 mb-3">'
            . '<label for="datum" class="form-label mb-0">' . $gL10n->get('PLG_MEMBERSHIPFEE_PAID_ON') . '</label>'
            . '<input type="date" id="datum" name="datum" class="form-control" style="width: auto;" value="' . DATE_NOW . '" />'
            . '<a id="btn_save_date" class="btn btn-primary" href="javascript:assign_date()"><i class="bi bi-check-lg"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_MARK_PAID') . '</a>'
            . '<a id="btn_delete_date" class="btn btn-secondary" href="javascript:delete_date()"><i class="bi bi-arrow-counterclockwise"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_UNDO_PAID') . '</a>'
            . '<a id="btn_add_position" class="btn btn-secondary" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/booking_edit.php', array('mode' => 'add', 'scope' => 'payments')) . '"><i class="bi bi-plus-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ADD') . '</a>'
            . '</div>');

        // Zusammenfassung der offenen Betraege der angezeigten Mitglieder
        $sumOpen = 0.0;
        $sumExported = 0.0;
        $countOpen = 0;
        foreach ($paymentInfo as $info) {
            $sumOpen += $info['open'];
            $sumExported += $info['exported'];
            if ($info['open'] + $info['exported'] > 0.004) {
                $countOpen++;
            }
        }
        $page->addHtml('<div class="alert alert-info" role="alert"><i class="bi bi-cash-coin"></i> '
            . $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENTS_OPEN_SUMMARY', array($countOpen, mf_format_amount($sumOpen + $sumExported)))
            . ($sumExported > 0.004 ? ' ' . $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENTS_SUMMARY_EXPORTED', array(mf_format_amount($sumExported))) : '')
            . '<br/><small>' . $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENTS_HINT') . '</small></div>');

        // create table object
        $table = new HtmlTable('tbl_payments', $page, true, true, 'table table-condensed');
        $table->setMessageIfNoRowsFound('SYS_NO_ENTRIES');

        $columnAlign  = array('center');
        
        $columnValues = array();
        $columnValues[] = '<a class="icon-text-link" href="javascript:select_all()">'.$gL10n->get('SYS_ALL').'</a>';
        $columnAlign[] = 'right';
        $columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_OPEN_AMOUNT');
        $columnAlign[] = 'left';
        $columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_STATUS');
        $columnAlign[] = 'left';
        $columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_METHOD');

        // headlines for columns
   	    foreach ($membersList as $member => $memberData)
   	    {
            foreach ($memberData as $usfId => $dummy)
            {
                if (!is_int($usfId))
                {
                    continue;
                }
 
                // Find name of the field
                $columnHeader = $gProfileFields->getPropertyById($usfId, 'usf_name');
 
                if ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'CHECKBOX'
                    ||  $gProfileFields->getPropertyById($usfId, 'usf_name_intern') === 'GENDER')
                {
                    $columnAlign[] = 'center';
                }
                elseif ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'NUMBER'
                    ||   $gProfileFields->getPropertyById($usfId, 'usf_type') === 'DECIMAL')
                {
                    $columnAlign[] = 'right';
                }
                else
                {
                    $columnAlign[] = 'left';
                }
                $columnValues[] = $columnHeader;
            }  
            break;							// Abbruch nach dem ersten Mitglied, da nur die usfIds eines Mitglieds benoetigt werden um die headlines zu erzeugen
        }
   
        $columnAlign[] = 'center';
        $columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_HISTORY');
        $table->setColumnAlignByArray($columnAlign);
        $table->addRowHeadingByArray($columnValues);
        $table->disableDatatablesColumnsSort(array(1));
        $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
    
        //user data
        foreach ($membersList as $member => $memberData)
        {
            $columnValues = array();
    
            if (array_key_exists($member, $_SESSION['pMembershipFee']['checkedArray']))
            {
                $content= '<input type="checkbox" id="member_'.$member.'" name="member_'.$member.'" checked="checked" class="memlist_checkbox memlist_member" /><b id="loadindicator_member_'.$member.'"></b>';
            }
            else
            {
                $content= '<input type="checkbox" id="member_'.$member.'" name="member_'.$member.'" class="memlist_checkbox memlist_member" /><b id="loadindicator_member_'.$member.'"></b>';
            }
            $columnValues[] = $content;

            $info = $paymentInfo[$member] ?? array('open' => 0.0, 'exported' => 0.0, 'paid' => '', 'iban' => false);
            $openTotal = $info['open'] + $info['exported'];
            $columnValues[] = array('value' => ($openTotal > 0.004 ? '<strong>' . mf_format_amount($openTotal) . '</strong>' : '&ndash;'), 'order' => $openTotal);
            if ($info['exported'] > 0.004) {
                $statusText = $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_STATUS_IN_RUN');
            } elseif ($info['open'] > 0.004) {
                $statusText = mf_booking_label(BookingStore::STATUS_OPEN);
            } elseif ($info['paid'] !== '') {
                $statusText = $gL10n->get('PLG_MEMBERSHIPFEE_PAID') . ' ' . mf_format_date(mf_parse_date($info['paid']) ?: $info['paid']);
            } else {
                $statusText = '&ndash;';
            }
            $columnValues[] = $statusText;
            $columnValues[] = $info['iban'] ? $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_DIRECT_DEBIT') : '<span class="text-warning">' . $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_TRANSFER') . '</span>';

            $user->readDataById($member);
    	
    	    foreach ($memberData as $usfId => $content)
    	    {
                if (!is_int($usfId))
                {
                    continue;
                }

                //*****************************************************************/
                // in some cases the content must have a special output format
                //*****************************************************************/
                if ($usfId === (int) $gProfileFields->getProperty('COUNTRY', 'usf_id'))
                {
                    $content = $gL10n->getCountryByCode($content);
                }
    	
                $userField->readDataById($usfId);

                $htmlValue = $gProfileFields->getHtmlValue($gProfileFields->getPropertyById($usfId, 'usf_name_intern'), $content, $user->getValue('usr_uuid'));

                if (($usfId === (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id') || $usfId === (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id')))
                {
                    $columnValues[] = '<a href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid'))).'">'.$htmlValue.'</a>';
                }
                elseif ($gProfileFields->getPropertyById($usfId, 'usf_type') === 'EMAIL' || $usfId === (int) $gProfileFields->getProperty('DEBTOR_EMAIL', 'usf_id'))
                {
                    $columnValues[] = getEmailLink($content, $user->getValue('usr_uuid'), $userField->getValue('usf_uuid'));      // hier $content, nicht $htmlValue
                }
                else
                {
                    $columnValues[] = $htmlValue;
                }
            }
            $columnValues[] = '<a class="admidio-icon-link" href="'.SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/bookings.php', array('user_uuid' => $user->getValue('usr_uuid'))).'"><i class="bi bi-clock-history" title="'.$gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_HISTORY').'"></i></a>';
            $table->addRowByArray($columnValues, 'userid_'.$member, array('nobr' => 'true'));
        }  // end foreach user

        $page->addHtml($table->show(false));
        $page->show();
    }
}
