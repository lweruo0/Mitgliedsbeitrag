<?php
/**
 ***********************************************************************************************
 * Verarbeiten der Einstellungen des Admidio-Plugins Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * form         : The name of the form preferences that were submitted.
 *
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Entity\Text;
use Admidio\Roles\Entity\Role;
use Plugins\MembershipFee\classes\Config\ConfigTable;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

$pPreferences = new ConfigTable();
$pPreferences->read();

// Initialize and check the parameters
$getForm = admFuncVariableIsValid($_GET, 'form', 'string');

// in ajax mode return simple text on error
$gMessage->showHtmlTextOnly(true);

// nur Berechtigte duerfen die Einstellungen aendern
if (!isUserAuthorizedForPreferences()) {
    $gMessage->show($gL10n->get('SYS_NO_RIGHTS'));
    // => EXIT
}

// Rueckgabecode
$ret = 'success';

try {
    switch ($getForm) {
        case 'contributionsettings':
            unset($pPreferences->config['Beitrag']);

            $pPreferences->config['Beitrag']['beitrag_prefix'] = $_POST['beitrag_prefix'];
            $pPreferences->config['Beitrag']['beitrag_abrunden'] = isset($_POST['beitrag_abrunden']) ? 1 : 0;
            $pPreferences->config['Beitrag']['beitrag_mindestbetrag'] = $_POST['beitrag_mindestbetrag'];
            $pPreferences->config['Beitrag']['beitrag_jahr'] = (int) $_POST['beitrag_jahr'];
            $pPreferences->config['Beitrag']['beitrag_textmitnam'] = isset($_POST['beitrag_textmitnam']) ? 1 : 0;
            $pPreferences->config['Beitrag']['beitrag_text_token'] = $_POST['beitrag_text_token'];
            $pPreferences->config['Beitrag']['beitrag_role_separator'] = replace_sepadaten($_REQUEST['beitrag_role_separator']); // $_POST übergibt keine führenden und nachfolgenden Leerzeichen, $_REQUEST schon
            break;

        case 'advancedroleediting':
            $alt_fix_rollen = beitragsrollen_einlesen('fix');

            $role = new Role($gDb);

            foreach ($alt_fix_rollen as $rol_id => $dummy) {
                $role->readDataById((int) $rol_id);
                $role->setvalue('rol_cost', str_replace(',', '.', $_POST['rol_cost' . $rol_id]));
                $role->setvalue('rol_cost_period', $_POST['rol_cost_period' . $rol_id]);
                $role->setvalue('rol_description', $_POST['rol_description' . $rol_id]);
                $role->save();
            }
            $ret = 'success_replace';
            break;

        case 'accountdata':
            unset($pPreferences->config['Kontodaten']);

            $pPreferences->config['Kontodaten']['iban'] = $_POST['iban'];
            $pPreferences->config['Kontodaten']['bic'] = $_POST['bic'];
            $pPreferences->config['Kontodaten']['bank'] = $_POST['bank'];
            $pPreferences->config['Kontodaten']['inhaber'] = $_POST['creditor'];
            $pPreferences->config['Kontodaten']['origcreditor'] = isset($_POST['origcreditor']) ? $_POST['origcreditor'] : '';
            $pPreferences->config['Kontodaten']['ci'] = $_POST['ci'];
            $pPreferences->config['Kontodaten']['origci'] = isset($_POST['origci']) ? $_POST['origci'] : '';
            break;

        case 'export':
            unset($pPreferences->config['SEPA'], $pPreferences->config['Rechnungs-Export']);

            $pPreferences->config['SEPA']['dateiname'] = $_POST['dateiname'];
            $pPreferences->config['SEPA']['direct_debit_format'] = $_POST['direct_debit_format'];
            $pPreferences->config['SEPA']['kontroll_dateiname'] = $_POST['kontroll_dateiname'];
            $pPreferences->config['SEPA']['kontroll_dateityp'] = $_POST['kontroll_dateityp'];
            $pPreferences->config['SEPA']['vorabinformation_dateiname'] = $_POST['vorabinformation_dateiname'];
            $pPreferences->config['SEPA']['vorabinformation_dateityp'] = $_POST['vorabinformation_dateityp'];
            $pPreferences->config['SEPA']['duedate_for_all'] = isset($_POST['duedate_for_all']) ? 1 : 0;
            $pPreferences->config['Rechnungs-Export']['rechnung_dateiname'] = $_POST['rechnung_dateiname'];
            $pPreferences->config['Rechnungs-Export']['rechnung_dateityp'] = $_POST['rechnung_dateityp'];
            $ret = 'success_export';
            break;

        case 'mandatemanagement':
            unset($pPreferences->config['Mandatsreferenz']);

            $pPreferences->config['Mandatsreferenz']['prefix_mem'] = $_POST['prefix_mem'];
            $pPreferences->config['Mandatsreferenz']['prefix_pay'] = $_POST['prefix_pay'];
            $pPreferences->config['Mandatsreferenz']['min_length'] = $_POST['min_length'];
            $pPreferences->config['Mandatsreferenz']['data_field'] = $_POST['data_field'];
            break;

        case 'testssetup':
            unset($pPreferences->config['Rollenpruefung'], $pPreferences->config['tests_enable']);
            $pPreferences->config['tests_enable']['role_membership_duty_and_exclusion'] = isset($_POST['role_membership_duty_and_exclusion']) ? 1 : 0;
            $pPreferences->config['tests_enable']['account_details'] = isset($_POST['account_details']) ? 1 : 0;
            $pPreferences->config['tests_enable']['mandate_management'] = isset($_POST['mandate_management']) ? 1 : 0;
            $pPreferences->config['tests_enable']['iban_check'] = isset($_POST['iban_check']) ? 1 : 0;
            $pPreferences->config['tests_enable']['bic_check'] = isset($_POST['bic_check']) ? 1 : 0;

            $fixrollen = beitragsrollen_einlesen('fix');
            foreach ($fixrollen as $key => $data) {
                if (isset($_POST['fixrollenpflicht' . $key])) {
                    $pPreferences->config['Rollenpruefung']['fixrollenpflicht'][] = $key;
                }
            }

            if (count($fixrollen) > 1) {
                $fixrollenL = $fixrollen;
                array_pop($fixrollenL); // das letzte Element entfernen
                $fixrollenR = $fixrollen;

                foreach ($fixrollenL as $keyL => $dataL) {
                    unset($fixrollenR[$keyL]); // dasselbe Element entfernen
                    foreach ($fixrollenR as $keyR => $dataR) {
                        if (isset($_POST['fixrollenfixrollen' . $keyL . '_' . $keyR])) {
                            $pPreferences->config['Rollenpruefung']['fixrollenfixrollen'][] = $keyL . '_' . $keyR;
                        }
                    }
                }
                unset($fixrollenL);
                unset($fixrollenR);
            }

            if (isset($_POST['bezugskategorie'])) {
                $pPreferences->config['Rollenpruefung']['bezugskategorie'] = $_POST['bezugskategorie'];
            }

            foreach ($pPreferences->config_default['Rollenpruefung'] as $roleTest => $dummy) {
                if (! isset($pPreferences->config['Rollenpruefung'][$roleTest])) {
                    $pPreferences->config['Rollenpruefung'][$roleTest] = $pPreferences->config_default['Rollenpruefung'][$roleTest];
                }
            }
            break;

        case 'columnset':
            foreach ($pPreferences->config['columnconfig'] as $conf => $confFields) {
                $pPreferences->config['columnconfig'][$conf] = array();

                for ($number = 1; isset($_POST['column' . $conf . '_' . $number]); $number ++) {
                    if (strlen($_POST['column' . $conf . '_' . $number]) > 0) {
                        $pPreferences->config['columnconfig'][$conf][] = $_POST['column' . $conf . '_' . $number];
                    }
                }
                // doppelte Einträge führen zu einem SQL-FEHLER in der Funktion list_members
                $pPreferences->config['columnconfig'][$conf] = array_unique($pPreferences->config['columnconfig'][$conf]);
            }
            break;

        case 'individualcontributions':
            $pPreferences->config['individual_contributions']['access_to_module'] = $_POST['enable_individual_contributions'];

            unset($pPreferences->config['individual_contributions']['desc']);
            unset($pPreferences->config['individual_contributions']['short_desc']);
            unset($pPreferences->config['individual_contributions']['role']);
            unset($pPreferences->config['individual_contributions']['amount']);
            unset($pPreferences->config['individual_contributions']['profilefield']);

            for ($conf = 0; isset($_POST['individual_contributions_desc' . $conf]); $conf ++) {
                if (empty($_POST['individual_contributions_desc' . $conf])) {
                    continue;
                }

                $pPreferences->config['individual_contributions']['desc'][] = $_POST['individual_contributions_desc' . $conf];
                $pPreferences->config['individual_contributions']['short_desc'][] = $_POST['individual_contributions_short_desc' . $conf];
                $pPreferences->config['individual_contributions']['role'][] = $_POST['individual_contributions_role' . $conf];
                $pPreferences->config['individual_contributions']['amount'][] = $_POST['individual_contributions_amount' . $conf];
                $pPreferences->config['individual_contributions']['profilefield'][] = $_POST['individual_contributions_profilefield' . $conf];
            }
            break;

        case 'access_preferences':
            if (isset($_POST['access_preferences'])) {
                $pPreferences->config['access']['preferences'] = array_filter($_POST['access_preferences']);
            } else {
                $pPreferences->config['access']['preferences'] = array();
            }
            break;


        case 'emailnotifications':

            $text = new Text($gDb);

            $text->readDataByColumns(array(
                'txt_name' => 'PMBMAIL_CONTRIBUTION_PAYMENTS',
                'txt_org_id' => $gCurrentOrgId
            ));
            $text->setValue('txt_text', $_POST['mail_text']);
            $text->save();

            $text->readDataByColumns(array(
                'txt_name' => 'PMBMAIL_PRE_NOTIFICATION',
                'txt_org_id' => $gCurrentOrgId
            ));
            $text->setValue('txt_text', $_POST['pre_notification_text']);
            $text->save();

            $text->readDataByColumns(array(
                'txt_name' => 'PMBMAIL_BILL',
                'txt_org_id' => $gCurrentOrgId
            ));
            $text->setValue('txt_text', $_POST['bill_text']);
            $text->save();
            break;

        default:
            $gMessage->show($gL10n->get('SYS_INVALID_PAGE_VIEW'));
    }
} catch (AdmException $e) {
    $e->showText();
}

$pPreferences->save();

echo $ret;
