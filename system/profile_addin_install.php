<?php
/**
 ***********************************************************************************************
 * Registerkarte "Beitraege" im Mitgliedsprofil ein- oder ausbauen
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode : install | uninstall (POST)
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Bookings\ProfileAddin;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    // Admidio-Dateien veraendern duerfen nur Administratoren
    if (! $gCurrentUser->isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('requireValue' => true, 'validValues' => array('install', 'uninstall')));
    SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));

    $error = ($getMode === 'install') ? ProfileAddin::install(PLUGIN_FOLDER) : ProfileAddin::uninstall();

    $gMessage->setForwardUrl(SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php', array('choice' => 'profileaddin')));
    if ($error !== '') {
        $gMessage->show($error, $gL10n->get('SYS_ERROR'));
    }
    $gMessage->show($gL10n->get($getMode === 'install' ? 'PLG_MEMBERSHIPFEE_PROFILE_ADDIN_INSTALLED' : 'PLG_MEMBERSHIPFEE_PROFILE_ADDIN_REMOVED'));
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}
