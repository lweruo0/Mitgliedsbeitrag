<?php
/**
 ***********************************************************************************************
 * Berechnung von Individualbeiträgen
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 * 
 ***********************************************************************************************
 */

/******************************************************************************
 * Parameters:
 *
 * mode       : preview - preview of the new individual contributions
 *              write   - save the new individual contributions
 *              print   - preview for printing  
 *
 *****************************************************************************/

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
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

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'preview', 'validValues' => array('preview', 'write', 'print')));

$pPreferences = new ConfigTable();
$pPreferences->read();

$user = new User($gDb, $gProfileFields);
$bookingService = new BookingService($pPreferences->config);

/**
 * Beschreibt die geplanten Aenderungen der Individualbeitraege eines Mitglieds.
 *
 * @param array<int, array<string, mixed>> $ops
 * @return string
 */
function describeIndividualOps(array $ops): string
{
    $lines = array();
    foreach ($ops as $op) {
        if ($op['op'] === 'insert') {
            $lines[] = '<span class="text-success"><i class="bi bi-plus-circle"></i> '.SecurityUtils::encodeHTML($op['data']['mfb_rol_name']).': '.mf_format_amount($op['data']['mfb_amount']).'</span>';
        } elseif ($op['op'] === 'update') {
            $lines[] = '<span class="text-primary"><i class="bi bi-arrow-repeat"></i> '.SecurityUtils::encodeHTML($op['data']['mfb_rol_name']).': '.mf_format_amount($op['before']['mfb_amount']).' &rarr; '.mf_format_amount($op['data']['mfb_amount']).'</span>';
        } elseif ($op['op'] === 'delete') {
            $lines[] = '<span class="text-danger"><i class="bi bi-dash-circle"></i> '.SecurityUtils::encodeHTML($op['before']['mfb_rol_name']).': '.mf_format_amount($op['before']['mfb_amount']).'</span>';
        }
    }
    return implode('<br />', $lines);
}

// set headline of the script
$headline = $gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS');

$gNavigation->addUrl(CURRENT_URL, $headline);

for ($i = 0; $i < count($pPreferences->config['individual_contributions']['desc']); $i++)
{
    if (($pPreferences->config['individual_contributions']['role'][$i] == 0) || ($pPreferences->config['individual_contributions']['amount'][$i] == ''))
    {
     	$getMode = 'error';
    }      
} 

if ($getMode == 'preview')     //Default
{
    $page = new HtmlPage('plg-mitgliedsbeitrag-individualcontributions-preview', $headline);
    $page->setContentFullWidth();

	// Mitglieder der Rollen der Individualbeitraege im Beitragsjahr
	$roleMembers = array();
	$memberIds = array();
	foreach ($pPreferences->config['individual_contributions']['role'] as $roleId)
	{
	    if (!isset($roleMembers[$roleId]))
	    {
	        $roleMembers[$roleId] = list_members_in_year((int) $roleId, getContributionYear());
	        $memberIds += $roleMembers[$roleId];
	    }
	}

	$configuredKeys = array();
	for ($i = 0; $i < count($pPreferences->config['individual_contributions']['desc']); $i++)
	{
	    $configuredKeys[] = trim($pPreferences->config['individual_contributions']['desc'][$i]);
	}

	// auch Mitglieder mit offenen Positionen aus Individualbeitraegen (Rollenmitgliedschaft evtl. beendet)
	foreach (BookingStore::getByUsers(array(), array(BookingStore::STATUS_OPEN), getContributionYear()) as $usrId => $positions)
	{
	    foreach ($positions as $position)
	    {
	        if ($position['mfb_source'] === BookingStore::SOURCE_INDIVIDUAL)
	        {
	            $memberIds[$usrId] = $usrId;
	        }
	    }
	}

	$plans = array();
	foreach ($memberIds as $member => $dummy)
	{
 	    $user->readDataById($member);
	    $desired = array();

	    for ($i = 0; $i < count($pPreferences->config['individual_contributions']['desc']); $i++)
	    {
	        if (!isset($roleMembers[$pPreferences->config['individual_contributions']['role'][$i]][$member]))
	        {
	            continue;
	        }

	        $multiplikator = 1;
	        if ($pPreferences->config['individual_contributions']['profilefield'][$i] <> '')
	        {
	            $usfid = $pPreferences->config['individual_contributions']['profilefield'][$i];
	            $multiplikator = $user->getValue($gProfileFields->getPropertyById((int) $usfid, 'usf_name_intern'));

	            //wenn das Profilfeld leer ist, dann wäre $multiplikator = ""; mit "" kann aber nicht multipliziert werden
	            $multiplikator = ($multiplikator === "") ?  0 : (float) str_replace(',', '.', $multiplikator);
	        }

	        // Einzelbeträge auf 2 Nachkommastellen runden, ggf. abrunden
	        $amount = round((float) str_replace(',', '.', $pPreferences->config['individual_contributions']['amount'][$i]) * $multiplikator, 2);
	        if ($pPreferences->config['Beitrag']['beitrag_abrunden'] == true)
	        {
	            $amount = floor($amount);
	        }

	        $key = trim($pPreferences->config['individual_contributions']['desc'][$i]);
	        $shortDesc = trim($pPreferences->config['individual_contributions']['short_desc'][$i]);
	        $desired[$key] = array(
	            'amount' => $amount,
	            'text' => trim($bookingService->getPrefix().' '.($shortDesc !== '' ? $shortDesc.' '.$amount : $key)),
	            'rol_id' => (int) $pPreferences->config['individual_contributions']['role'][$i]
	        );
	    }

	    $ops = $bookingService->planIndividual((int) $member, $desired, $configuredKeys);
	    if (count($ops) > 0)
	    {
	        $plans[$member] = array(
	            'LAST_NAME' => $user->getValue('LAST_NAME'),
	            'FIRST_NAME' => $user->getValue('FIRST_NAME'),
	            'ops' => $ops
	        );
	    }
	}

	// save plan in session (for mode write and mode print)
	$_SESSION['pMembershipFee']['individualcontributions_user'] = $plans;

	if (sizeof($plans) > 0)
	{
		$table = new HtmlTable('table_new_individualcontributions', $page, true, true, 'table table-condensed');
        $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
		$table->setColumnAlignByArray(array('left', 'left', 'left'));
		$table->addRowHeadingByArray(array($gL10n->get('SYS_LASTNAME'), $gL10n->get('SYS_FIRSTNAME'), $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_CHANGES')));

		foreach ($plans as $member => $data)
		{
         	$user->readDataById($member);
			$profileUrl = SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid')));
			$table->addRowByArray(array(
			    '<a href="'.$profileUrl.'">'.SecurityUtils::encodeHTML($data['LAST_NAME']).'</a>',
			    '<a href="'.$profileUrl.'">'.SecurityUtils::encodeHTML($data['FIRST_NAME']).'</a>',
			    describeIndividualOps($data['ops'])
			));
		}

		// Speichern oberhalb und unterhalb der Liste (zwei Formulare mit demselben Ziel)
        $formTop = new HtmlForm('individualcontributions_preview_form_top', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/individualcontributions.php', array('mode' => 'write')), $page);
		$formTop->addSubmitButton('btn_next_page_top', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'btn btn-primary'));
		$formTop->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS_PREVIEW'));
        $page->addHtml($formTop->show(false));

		$page->addHtml($table->show(false));

        $form = new HtmlForm('individualcontributions_preview_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/individualcontributions.php', array('mode' => 'write')), $page);
		$form->addSubmitButton('btn_next_page', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg', 'class' => 'btn btn-primary'));
		$form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS_PREVIEW'));

        $page->addHtml($form->show(false));
	}
	else
	{
        $page->addHtml($gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS_NO_DATA').'<br/>');
	}
}
elseif ($getMode == 'write')
{
    $page = new HtmlPage('plg-mitgliedsbeitrag-individualcontributions-write', $headline);
    $page->setContentFullWidth();

 	$page->addPageFunctionsMenuItem('menu_item_print_view', $gL10n->get('SYS_PRINT_PREVIEW'), 'javascript:void(0);', 'bi-printer');

	$page->addJavascript('
    	$("#menu_item_print_view").click(function() {
            window.open("'. SecurityUtils::encodeUrl(ADMIDIO_URL. FOLDER_PLUGINS . PLUGIN_FOLDER .'/system/individualcontributions.php', array('mode' => 'print')). '", "_blank");
        });',
		true
	);

	$table = new HtmlTable('table_saved_individualcontributions', $page, true, true, 'table table-condensed');
    $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
	$table->setColumnAlignByArray(array('left', 'left', 'left'));
	$table->addRowHeadingByArray(array($gL10n->get('SYS_LASTNAME'), $gL10n->get('SYS_FIRSTNAME'), $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_CHANGES')));

	$gDb->startTransaction();
	foreach ($_SESSION['pMembershipFee']['individualcontributions_user'] ?? array() as $member => $data)
	{
	    $bookingService->applyOps((int) $member, $data['ops']);

    	$user->readDataById($member);
		$profileUrl = SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid')));
		$table->addRowByArray(array(
		    '<a href="'.$profileUrl.'">'.SecurityUtils::encodeHTML($data['LAST_NAME']).'</a>',
		    '<a href="'.$profileUrl.'">'.SecurityUtils::encodeHTML($data['FIRST_NAME']).'</a>',
		    describeIndividualOps($data['ops'])
		));
	}
	$gDb->endTransaction();

	$page->addHtml($table->show(false));
	$page->addHtml('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS_SAVED').'</strong><br/><br/>');
}
elseif ($getMode == 'print')
{
	$page = new HtmlPage('plg-mitgliedsbeitrag-individualcontributions-print', $gL10n->get('PLG_MEMBERSHIPFEE_INDIVIDUAL_CONTRIBUTIONS_NEW'));
	$page->setPrintMode();

	$table = new HtmlTable('table_print_individualcontributions', $page, false, false, 'table table-condensed table-striped');
	$table->setColumnAlignByArray(array('left', 'left', 'left'));
	$table->addRowHeadingByArray(array($gL10n->get('SYS_LASTNAME'), $gL10n->get('SYS_FIRSTNAME'), $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_CHANGES')));

	foreach ($_SESSION['pMembershipFee']['individualcontributions_user'] ?? array() as $data)
	{
		$table->addRowByArray(array(SecurityUtils::encodeHTML($data['LAST_NAME']), SecurityUtils::encodeHTML($data['FIRST_NAME']), describeIndividualOps($data['ops'])));
	}
	$page->addHtml($table->show(false));
}
else          // $getMode = error
{
    $page = new HtmlPage('plg-mitgliedsbeitrag-individualcontributions-error', $headline);
    $page->addHtml('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_WRONG_INDIVIDUAL_CONTRIBUTION').'</strong><br/><br/>');
}

$page->show();


