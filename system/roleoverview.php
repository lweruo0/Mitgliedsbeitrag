<?php
/**
 ***********************************************************************************************
 * Rollenübersicht fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:  none
 *
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
use Admidio\Roles\Entity\Role;
use Plugins\MembershipFee\classes\Bookings\BookingService;
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

$role = new Role($gDb);

$headline = $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_OVERVIEW');
$gNavigation->addUrl(CURRENT_URL, $headline);

$page = new HtmlPage('plg-mitgliedsbeitrag-eoleoverview', $headline);
  
$datatable = true;
$hoverRows = true;
$classTable  = 'table table-condensed';

$table = new HtmlTable('table_role_overview', $page, $hoverRows, $datatable, $classTable);

$columnAlign  = array('left', 'right', 'right', 'left', 'right');
$table->setColumnAlignByArray($columnAlign);

$columnValues = array($gL10n->get('PLG_MEMBERSHIPFEE_ROLE_NAME'), 'dummy', $gL10n->get('PLG_MEMBERSHIPFEE_MEMBER_ACCOUNT'), $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_GROUP'), $gL10n->get('SYS_CONTRIBUTION'));
$table->addRowHeadingByArray($columnValues);

$bookingService = new BookingService($pPreferences->config);
$groupNames = array();
foreach ($bookingService->getGroups() as $group)
{
    foreach ($group['roles'] as $groupRoleId)
    {
        $groupNames[$groupRoleId] = $group['name'];
    }
}

$rollen = beitragsrollen_einlesen();
foreach ($rollen as $rol_id => $data)
{
    $data['members'] = list_members_in_year($rol_id, getContributionYear());
    $role->readDataById($rol_id);
    
    $columnValues = array();
    $columnValues[] = '<a href="'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/groups-roles/groups_roles.php', array('mode' => 'edit', 'role_uuid' => $role->getValue('rol_uuid'))). '">'.$data['rolle']. '</a>';
    $columnValues[] = expand_rollentyp($data['rollentyp']);
    $columnValues[] = count($data['members']);
    $columnValues[] = SecurityUtils::encodeHTML($groupNames[(int) $rol_id] ?? '');
    $columnValues[] = mf_format_amount($data['rol_cost']);
    $table->addRowByArray($columnValues);
}
$table->setDatatablesGroupColumn(2);
$table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));

$page->addHtml($table->show(false));

$page->show();
