<?php
/**
 ***********************************************************************************************
 * Buchungshistorie eines Mitglieds
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * user_uuid : UUID des Mitglieds
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingStore;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/bookings_function.php');

    if (! isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getUserUuid = admFuncVariableIsValid($_GET, 'user_uuid', 'uuid', array('requireValue' => true));

    $user = new User($gDb, $gProfileFields);
    $user->readDataByUuid($getUserUuid);
    if ((int) $user->getValue('usr_id') === 0) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    BookingStore::ensureTables();

    $headline = $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_HISTORY') . ' - ' . $user->getValue('FIRST_NAME') . ' ' . $user->getValue('LAST_NAME');
    $gNavigation->addUrl(CURRENT_URL, $headline);

    $page = new HtmlPage('plg-mitgliedsbeitrag-bookings', $headline);
    $page->setContentFullWidth();

    $page->addPageFunctionsMenuItem('menu_item_profile', $gL10n->get('SYS_PROFILE'), SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $getUserUuid)), 'bi-person');

    if (isset($_SESSION['pMembershipFee']['booking_message'])) {
        $page->addHtml('<div class="alert alert-success" role="alert"><i class="bi bi-check-lg"></i> ' . SecurityUtils::encodeHTML($_SESSION['pMembershipFee']['booking_message']) . '</div>');
        unset($_SESSION['pMembershipFee']['booking_message']);
    }

    $page->addHtml(mf_render_bookings((int) $user->getValue('usr_id'), $getUserUuid, true));
    $page->addHtml(mf_render_work((int) $user->getValue('usr_id')));
    $page->show();
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}
