<?php
/**
 ***********************************************************************************************
 * Anzeige von Prüfungen fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * openiban : 1 = IBANs zusaetzlich mit OpenIBAN abgleichen (nur auf Anforderung per Button)
 * mode     : (leer) Anzeige | openiban_fix (POST, per fetch): BIC und Bankname laut OpenIBAN ins Profil uebernehmen
 * usr_id[] : (POST, mode=openiban_fix) zu korrigierende Benutzer
 *
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
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

// OpenIBAN-Korrektur: BIC und Bankname der gewaehlten Mitglieder auf die Werte von OpenIBAN setzen
if (($_GET['mode'] ?? '') === 'openiban_fix') {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $userIds = array_map('intval', (array) ($_POST['usr_id'] ?? array()));
        $user = new User($gDb, $gProfileFields);
        $ibans = array();
        foreach ($userIds as $userId) {
            $user->readDataById($userId);
            $ibans[$userId] = strtoupper(str_replace(' ', '', (string) $user->getValue('IBAN')));
        }
        $lookup = mf_openiban_lookup(array_values(array_filter($ibans)));
        $count = 0;
        foreach ($ibans as $userId => $iban) {
            $info = $lookup[$iban] ?? null;
            if ($info === null || $info['error'] !== '' || !$info['valid']) {
                continue;
            }
            $user->readDataById($userId);
            if ($info['bic'] !== '') {
                $user->setValue('BIC', $info['bic']);
            }
            if ($info['bank'] !== '') {
                $user->setValue('BANK', $info['bank']);
            }
            $user->save();
            $count++;
        }
        echo 'success:' . $count;
    } catch (\Throwable $e) {
        echo $e->getMessage();
    }
    exit();
}

$headline = $gL10n->get('PLG_MEMBERSHIPFEE_TESTS');
$gNavigation->addUrl(CURRENT_URL, $headline);

$page = new HtmlPage('plg-mitgliedsbeitrag-tests', $headline);

//Prüfungen nur anzeigen, wenn mindestens ein Einzeltest aktiviert ist
if (in_array(1, $pPreferences->config['tests_enable']))
{
    $form = new HtmlForm('tests_form', '', $page);
    
    if ($pPreferences->config['tests_enable']['role_membership_duty_and_exclusion'])
    {
        $form->openGroupBox('role_membership_duty', $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_DUTY'));
        $form->addDescription('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_DUTY_DESC').'</strong>');
        $form->addDescription(showTestResultWithScrollbar(check_rollenmitgliedschaft_pflicht()));
        $form->closeGroupBox();
        
        $form->openGroupBox('role_membership_exclusion', $gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_EXCLUSION'));
        $form->addDescription('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_ROLE_MEMBERSHIP_EXCLUSION_DESC').'</strong>');
        $form->addDescription(showTestResultWithScrollbar(check_rollenmitgliedschaft_ausschluss()));
        $form->closeGroupBox();
    }
    
    if ($pPreferences->config['tests_enable']['account_details'])
    {
        $form->openGroupBox('account_details', $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA'));
        $form->addDescription('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_DATA_TEST_DESC').'</strong>');
        $form->addDescription(showTestResultWithScrollbar(check_account_details()));
        $form->closeGroupBox();
    }
    
    if ($pPreferences->config['tests_enable']['mandate_management'])
    {
        $form->openGroupBox('mandate_management', $gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT'));
        $form->addDescription('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_MANDATE_MANAGEMENT_DESC2').'</strong>');
        $form->addDescription(showTestResultWithScrollbar(check_mandate_management()));
        $form->closeGroupBox();
    }
    
    if ($pPreferences->config['tests_enable']['iban_check'])
    {
        $form->openGroupBox('iban_check', $gL10n->get('PLG_MEMBERSHIPFEE_IBANCHECK'));
        $form->addDescription('<strong>'.$gL10n->get('PLG_MEMBERSHIPFEE_IBANCHECK_DESC').'</strong>');
        $form->addDescription(showTestResultWithScrollbar(check_iban()));

        // Abgleich mit OpenIBAN (externer Dienst openiban.com; Ergebnisse 30 Tage zwischengespeichert) – nur auf Anforderung
        $openIbanUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/tests.php', array('openiban' => 1)) . '#iban_check';
        if (($_GET['openiban'] ?? '') !== '1') {
            $html = '<hr/><strong>Abgleich mit OpenIBAN</strong><br/>'
                . '<a class="btn btn-primary btn-sm my-2" href="' . $openIbanUrl . '"><i class="bi bi-bank"></i> Mit OpenIBAN abgleichen</a>'
                . '<div class="form-text">Prüft Bankleitzahl, BIC und Bankname aller IBANs über den externen Dienst openiban.com. '
                . 'Die IBANs werden dafür an openiban.com übermittelt; Ergebnisse werden 30 Tage zwischengespeichert (beim ersten Mal dauert es etwa 20 Sekunden).</div>';
            $openIban = null;
        } else {
            $openIban = mf_openiban_check();
            $html = '<hr/><strong>Abgleich mit OpenIBAN</strong> <small class="text-muted">(' . $openIban['checked'] . ' IBANs geprüft: Bankleitzahl, Bankname, BIC; Daten von openiban.com, 30 Tage zwischengespeichert)</small> '
                . '<a class="btn btn-secondary btn-sm ms-2" href="' . $openIbanUrl . '"><i class="bi bi-arrow-clockwise"></i> Neu laden</a><br/>';
        }
        if ($openIban === null) {
            // kein Abgleich angefordert
        } elseif (count($openIban['rows']) === 0) {
            $html .= 'Alle IBANs sind laut OpenIBAN gültig, BIC und Bankname stimmen überein.';
        } else {
            // wichtig fuer den Einzug: ungueltige IBAN, fehlende oder abweichende BIC, Abfragefehler;
            // nur abweichender Bankname (meist andere Schreibweise oder Fusion) separat und eingeklappt
            $important = array();
            $nameOnly = array();
            foreach ($openIban['rows'] as $row) {
                $onlyName = count(array_filter($row['problems'], static function ($problem) {
                    return strpos($problem, 'Bankname') !== 0;
                })) === 0;
                if ($onlyName) {
                    $nameOnly[] = $row;
                } else {
                    $important[] = $row;
                }
            }
            $renderTable = static function (array $rows, string $label) use ($gDb, $gProfileFields): string {
                $fixable = array_values(array_filter($rows, static function ($row) {
                    return $row['fixable'];
                }));
                $out = '';
                if (count($fixable) > 1) {
                    $out .= '<button type="button" class="btn btn-primary btn-sm my-2 mf-openiban-fix" data-users="' . implode(',', array_column($fixable, 'usr_id')) . '">'
                        . '<i class="bi bi-wrench"></i> ' . $label . ' (' . count($fixable) . ')</button>';
                }
                $out .= '<div class="table-responsive" style="max-height: 400px; overflow: auto;"><table class="table table-sm table-striped"><thead><tr>'
                    . '<th>Name</th><th>IBAN</th><th>Hinweis</th><th>BIC (Profil → OpenIBAN)</th><th>Bank (Profil → OpenIBAN)</th><th></th></tr></thead><tbody>';
                foreach ($rows as $row) {
                    $profileUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => (new User($gDb, $gProfileFields, $row['usr_id']))->getValue('usr_uuid')));
                    $out .= '<tr><td><a href="' . $profileUrl . '">' . SecurityUtils::encodeHTML($row['name']) . '</a></td>'
                        . '<td class="text-nowrap">' . SecurityUtils::encodeHTML($row['iban']) . '</td>'
                        . '<td>' . SecurityUtils::encodeHTML(implode(', ', $row['problems'])) . '</td>'
                        . '<td>' . SecurityUtils::encodeHTML($row['stored_bic'] !== '' ? $row['stored_bic'] : '–') . ' → ' . SecurityUtils::encodeHTML($row['bic'] !== '' ? $row['bic'] : '–') . '</td>'
                        . '<td>' . SecurityUtils::encodeHTML($row['stored_bank'] !== '' ? $row['stored_bank'] : '–') . ' → ' . SecurityUtils::encodeHTML($row['bank'] !== '' ? $row['bank'] : '–') . '</td>'
                        . '<td>' . ($row['fixable'] ? '<button type="button" class="btn btn-secondary btn-sm mf-openiban-fix" data-users="' . $row['usr_id'] . '"><i class="bi bi-wrench"></i> Korrektur</button>' : '') . '</td></tr>';
                }
                return $out . '</tbody></table></div>';
            };
            if (count($important) > 0) {
                $html .= '<div class="mt-2"><strong class="text-danger">IBAN ungültig oder BIC fehlt/weicht ab (' . count($important) . ')</strong></div>'
                    . $renderTable($important, 'Alle korrigieren');
            } else {
                $html .= '<div class="mt-2">Keine ungültigen IBANs, alle BICs stimmen.</div>';
            }
            if (count($nameOnly) > 0) {
                $html .= '<details class="mt-3"><summary><strong>Nur Bankname abweichend (' . count($nameOnly) . ')</strong> '
                    . '<small class="text-muted">– meist andere Schreibweise oder Fusion; für den Einzug ohne Bedeutung. Achtung: Die Banknamen von OpenIBAN sind nicht immer aktuell.</small></summary>'
                    . $renderTable($nameOnly, 'Alle Banknamen übernehmen') . '</details>';
            }
            $html .= '<div id="mf_openiban_message" class="alert alert-danger d-none" role="alert"></div>';
            $page->addJavascript('
                $(".mf-openiban-fix").click(function() {
                    var users = String($(this).data("users")).split(",");
                    if (users.length > 1 && !confirm("BIC und Bankname bei " + users.length + " Mitgliedern auf die Werte von OpenIBAN setzen?")) {
                        return false;
                    }
                    var body = new URLSearchParams();
                    body.append("adm_csrf_token", "' . $gCurrentSession->getCsrfToken() . '");
                    users.forEach(function(id) { body.append("usr_id[]", id); });
                    $(".mf-openiban-fix").prop("disabled", true);
                    fetch("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/tests.php', array('mode' => 'openiban_fix')) . '", {method: "POST", body: body, credentials: "same-origin"})
                        .then(function(response) { return response.text(); })
                        .then(function(text) {
                            if (text.indexOf("success") === 0) {
                                window.location.reload();
                            } else {
                                $(".mf-openiban-fix").prop("disabled", false);
                                $("#mf_openiban_message").text(text).removeClass("d-none");
                            }
                        });
                });', true);
        }
        $form->addDescription($html);
        $form->closeGroupBox();
    }
    
    if ($pPreferences->config['tests_enable']['bic_check'])
    {
        $form->openGroupBox('bic_check', $gL10n->get('PLG_MEMBERSHIPFEE_BICCHECK'));
        $form->addDescription(showTestResultWithScrollbar(check_bic()));
        $form->closeGroupBox();
    }
    
    $page->addHtml($form->show(false));
}

$page->show();
