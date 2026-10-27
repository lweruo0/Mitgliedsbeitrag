<?php
/**
 ***********************************************************************************************
 * Neuberechnung der Mitgliedsbeitraege
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 ***********************************************************************************************
 */

/**
 * ****************************************************************************
 * Parameters:
 *
 * mode : preview - preview of the new fees
 * write - save the new fees
 * print - preview for printing
 *
 * ***************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
use Admidio\Roles\Entity\Role;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

// only authorized user are allowed to start this module
if (! isUserAuthorized()) {
    throw new Exception('SYS_NO_RIGHTS');
}

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
    'defaultValue' => 'preview',
    'validValues' => array(
        'preview',
        'write',
        'print'
    )
));

$pPreferences = new ConfigTable();
$pPreferences->read();

$user = new User($gDb, $gProfileFields);

// set headline of the script
$headline = $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION');

$gNavigation->addUrl(CURRENT_URL, $headline);

if ($getMode == 'preview') // Default
{
    $members = array();
    $message = '';

    // anstelle eines Leerzeichens ist ein # in der $pPreferences->config gespeichert; # wird hier wieder ersetzt
    $text_token = ($pPreferences->config['Beitrag']['beitrag_text_token'] == '#') ? ' ' : $pPreferences->config['Beitrag']['beitrag_text_token'];

    $role_separator = ($pPreferences->config['Beitrag']['beitrag_role_separator'] != '') ? $pPreferences->config['Beitrag']['beitrag_role_separator'] : ' ';

    // alle Beitragsrollen einlesen (die Mitglieder werden anhand des Beitragsjahres bestimmt)
    $contributingRolls = beitragsrollen_einlesen();

    // Beitragsjahr: ohne Angabe das aktuelle Jahr
    $contributionYear = getContributionYear();

    // pruefen, ob Eintraege in der Rollenauswahl bestehen
    if (isset($_POST['recalculation_roleselection'])) {
        $_SESSION['pMembershipFee']['recalculation_rol_sel'] = $_POST['recalculation_roleselection'];

        // nicht gewaehlte Beitragsrollen im Array $contributingRolls loeschen
        $message .= '<strong>' . $gL10n->get('SYS_NOTE') . ':</strong> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_ROLLQUERY_INFO') . '</strong><br/><br/>';
        foreach ($contributingRolls as $rol => $roldata) {
            if (! in_array($rol, $_POST['recalculation_roleselection'])) {
                unset($contributingRolls[$rol]);
            } else {
                $message .= '- ' . $roldata['rolle'] . '<br/>';
            }
        }
    } else {
        unset($_SESSION['pMembershipFee']['recalculation_rol_sel']);
    }

    // wenn $_SESSION['pMembershipFee']['recalculation_user'] vorhanden ist, dann wurde bereits eine Berechnung durchgeführt
    if (! isset($_SESSION['pMembershipFee']['recalculation_user'])) {

        // Mitglieder der Beitragsrollen: Mitgliedschaft im Beitragsjahr
        $memberIds = array();
        foreach ($contributingRolls as $rol => $roldata) {
            $contributingRolls[$rol]['members'] = list_members_in_year($rol, $contributionYear);
            $memberIds += $contributingRolls[$rol]['members'];
        }

        // alle betroffenen Mitglieder einlesen (auch inzwischen ausgetretene)
        $members = array();
        if (count($memberIds) > 0) {
            $members = list_members(array(
                'FIRST_NAME',
                'LAST_NAME',
                'FEE' . $gCurrentOrgId,
                'CONTRIBUTORY_TEXT' . $gCurrentOrgId,
                'PAID' . $gCurrentOrgId,
                'DEBTOR'
            ), 2, 'AND mem_usr_id IN (' . implode(',', array_map('intval', array_keys($memberIds))) . ')');
        }

        // alle Mitglieder durchlaufen und aufgrund von Rollenzugehoerigkeiten die Beitraege bestimmen
        foreach ($members as $member => $memberdata) {
            $members[$member]['FEE_NEW'] = 0;
            $members[$member]['CONTRIBUTORY_TEXT_NEW'] = '';

            foreach ($contributingRolls as $rol => $roldata) {
                $rolDescription = ' ';
                if ($roldata['rol_description'] != '') {
                    $rolDescription = $role_separator . $roldata['rol_description'] . ' ';
                }

                if (array_key_exists($member, $roldata['members'])) {
                    $members[$member]['FEE_NEW'] += $roldata['rol_cost'];
                    $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= $rolDescription;
                }
            }

            // wenn definiert: Beitragstext mit dem Namen des Benutzers
            if (($pPreferences->config['Beitrag']['beitrag_textmitnam'] == true) && ($members[$member]['FEE_NEW'] != 0) && ! (($members[$member]['LAST_NAME'] . ' ' . $members[$member]['FIRST_NAME'] == $members[$member]['DEBTOR']) || ($members[$member]['FIRST_NAME'] . ' ' . $members[$member]['LAST_NAME'] == $members[$member]['DEBTOR']) || (empty($members[$member]['DEBTOR'])))) {
                $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= $text_token . $members[$member]['LAST_NAME'] . ' ' . $members[$member]['FIRST_NAME'] . $text_token;
            }
        }

        foreach ($members as $member => $memberdata) {
            // letzte Datenaufbereitung (aufsummieren, ueberschreiben, runden...)
            if ((empty($members[$member]['FEE' . $gCurrentOrgId]) || (! (empty($members[$member]['FEE' . $gCurrentOrgId])) && (isset($_POST['recalculation_modus']) && (($_POST['recalculation_modus'] == 'overwrite') || ($_POST['recalculation_modus'] == 'summation'))))) && (! isset($_POST['recalculation_notpaid']) || (isset($_POST['recalculation_notpaid']) && $members[$member]['PAID' . $gCurrentOrgId] == '')) && ($members[$member]['FEE_NEW'] > $pPreferences->config['Beitrag']['beitrag_mindestbetrag'])) {
                // wenn vorhanden, dann das erste $role_separator entfernen
                // Bsp: SV Musterverein +Jahresbeitrag+Spartenbeitrag soll sein SV Musterverein Jahresbeitrag+Spartenbeitrag
                if (substr($members[$member]['CONTRIBUTORY_TEXT_NEW'], 0, strlen($role_separator)) == $role_separator) {
                    $members[$member]['CONTRIBUTORY_TEXT_NEW'] = substr($members[$member]['CONTRIBUTORY_TEXT_NEW'], strlen($role_separator));
                }

                $members[$member]['CONTRIBUTORY_TEXT_NEW'] = $pPreferences->config['Beitrag']['beitrag_prefix'] . ' ' . $members[$member]['CONTRIBUTORY_TEXT_NEW'] . ' ';

                // alle Beitraege auf 2 Nachkommastellen runden
                $members[$member]['FEE_NEW'] = round($members[$member]['FEE_NEW'], 2);

                // ggf. abrunden
                if ($pPreferences->config['Beitrag']['beitrag_abrunden'] == true) {
                    $members[$member]['FEE_NEW'] = floor($members[$member]['FEE_NEW']);
                }

                if (isset($_POST['recalculation_modus']) && $_POST['recalculation_modus'] == 'summation') {
                    $members[$member]['FEE_NEW'] += (float) $members[$member]['FEE' . $gCurrentOrgId];
                    $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= ' ' . $members[$member]['CONTRIBUTORY_TEXT' . $gCurrentOrgId] . ' ';
                }

                // fuehrende und nachfolgene Leerstellen im Beitragstext loeschen
                $members[$member]['CONTRIBUTORY_TEXT_NEW'] = trim($members[$member]['CONTRIBUTORY_TEXT_NEW']);
                // zwei aufeinanderfolgende Leerzeichen durch ein Leerzeichen ersetzen
                $members[$member]['CONTRIBUTORY_TEXT_NEW'] = str_replace('  ', ' ', $members[$member]['CONTRIBUTORY_TEXT_NEW']);
            } else {
                unset($members[$member]); // wenn kein neuer Beitrag errechnet wurde, dann dieses Mitglied in der Liste loeschen
            }
        }
        // save members in session (for mode write and mode print)
        $_SESSION['pMembershipFee']['recalculation_user'] = $members;
    }

    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-preview', $headline);
    $page->setContentFullWidth();

    if (sizeof($_SESSION['pMembershipFee']['recalculation_user']) > 0) {
        $datatable = true;
        $hoverRows = true;
        $classTable = 'table table-condensed';
        $table = new HtmlTable('table_new_recalculation', $page, $hoverRows, $datatable, $classTable);
        $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
        $table->setColumnAlignByArray(array(
            'left',
            'left',
            'center',
            'center',
            'center',
            'center'
        ));
        $columnValues = array(
            $gL10n->get('SYS_LASTNAME'),
            $gL10n->get('SYS_FIRSTNAME'),
            $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW'),
            $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_NEW'),
            $gL10n->get('PLG_MEMBERSHIPFEE_FEE_PREVIOUS'),
            $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_PREVIOUS')
        );
        $table->addRowHeadingByArray($columnValues);

        foreach ($_SESSION['pMembershipFee']['recalculation_user'] as $member => $data) {
            $user->readDataById($member);

            $columnValues = array();
            $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $data['LAST_NAME'] . '</a>';
            $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $data['FIRST_NAME'] . '</a>';
            $columnValues[] = '
                <a class="admidio-icon-link openPopup" href="javascript:void(0);" data-href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation_edit.php', array(
                'user_id' => $member
            )) . '">' . '
                    ' . $data['FEE_NEW'] . '
                </a>';
            $columnValues[] = '
                <a class="admidio-icon-link openPopup" href="javascript:void(0);" data-href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation_edit.php', array(
                'user_id' => $member
            )) . '">' . '
                    ' . $data['CONTRIBUTORY_TEXT_NEW'] . '
                </a>';
            $columnValues[] = $data['FEE' . $gCurrentOrgId];
            $columnValues[] = $data['CONTRIBUTORY_TEXT' . $gCurrentOrgId];
            $table->addRowByArray($columnValues);
        }

        $page->addHtml($table->show(false));

        $form = new HtmlForm('recalculation_preview_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation.php', array(
            'mode' => 'write'
        )), $page);

        $form->addSubmitButton('btn_next_page', $gL10n->get('SYS_SAVE'), array(
            'icon' => 'bi-check-lg',
            'class' => 'btn btn-primary'
        ));
        $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_PREVIEW'));

        $page->addHtml($form->show(false));
    } else {
        $page->addHtml($gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_NO_DATA') . '<br/>');
    }

    if (! empty($message)) {
        $page->addHtml($message);
    }
    $page->show();
} elseif ($getMode == 'write') {
    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-write', $headline);
    $page->setContentFullWidth();

    $page->addPageFunctionsMenuItem('menu_item_print_view', $gL10n->get('SYS_PRINT_PREVIEW'), 'javascript:void(0);', 'bi-printer');

    $page->addJavascript('
    	$("#menu_item_print_view").click(function() {
            window.open("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation.php', array(
        'mode' => 'print'
    )) . '", "_blank");
        });', true);

    $datatable = true;
    $hoverRows = true;
    $classTable = 'table table-condensed';

    $table = new HtmlTable('table_saved_recalculation', $page, $hoverRows, $datatable, $classTable);
    $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
    $table->setColumnAlignByArray(array(
        'left',
        'left',
        'center',
        'center'
    ));
    $columnValues = array(
        $gL10n->get('SYS_LASTNAME'),
        $gL10n->get('SYS_FIRSTNAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW'),
        $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_NEW')
    );
    $table->addRowHeadingByArray($columnValues);

    foreach ($_SESSION['pMembershipFee']['recalculation_user'] as $member => $data) {
        $user->readDataById($member);

        $columnValues = array();
        $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
            'user_uuid' => $user->getValue('usr_uuid')
        )) . '">' . $data['LAST_NAME'] . '</a>';
        $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
            'user_uuid' => $user->getValue('usr_uuid')
        )) . '">' . $data['FIRST_NAME'] . '</a>';
        $columnValues[] = $data['FEE_NEW'];
        $columnValues[] = $data['CONTRIBUTORY_TEXT_NEW'];
        $table->addRowByArray($columnValues);

        $user->setValue('FEE' . $gCurrentOrgId, $data['FEE_NEW']);
        $user->setValue('CONTRIBUTORY_TEXT' . $gCurrentOrgId, $data['CONTRIBUTORY_TEXT_NEW']);
        $user->save();
    }

    $page->addHtml('<div style="width:100%; height: 500px; overflow:auto; border:20px;">');
    $page->addHtml($table->show(false));
    $page->addHtml('</div><br/>');
    $page->addHtml('<strong>' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_SAVED') . '</strong><br/><br/>');
    $page->show();
} elseif ($getMode == 'print') {
    // create html page object without the custom theme files
    $hoverRows = false;
    $datatable = false;
    $classTable = 'table table-condensed table-striped';

    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-print', $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_NEW'));
    $page->setPrintMode();

    $table = new HtmlTable('table_print_recalculation', $page, $hoverRows, $datatable, $classTable);
    $table->setColumnAlignByArray(array(
        'left',
        'left',
        'center',
        'center'
    ));
    $columnValues = array(
        $gL10n->get('SYS_LASTNAME'),
        $gL10n->get('SYS_FIRSTNAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW'),
        $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_NEW')
    );
    $table->addRowHeadingByArray($columnValues);

    foreach ($_SESSION['pMembershipFee']['recalculation_user'] as $data) {
        $columnValues = array();
        $columnValues[] = $data['LAST_NAME'];
        $columnValues[] = $data['FIRST_NAME'];
        $columnValues[] = $data['FEE_NEW'];
        $columnValues[] = $data['CONTRIBUTORY_TEXT_NEW'];
        $table->addRowByArray($columnValues);
    }
    $page->addHtml($table->show(false));
    $page->show();
}
