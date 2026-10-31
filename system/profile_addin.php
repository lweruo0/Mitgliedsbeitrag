<?php
/**
 ***********************************************************************************************
 * Registerkarte "Beitraege" im Mitgliedsprofil
 *
 * Wird von modules/profile/profile.php eingebunden (siehe classes/Bookings/ProfileAddin.php).
 * Verfuegbare Variablen aus profile.php: $page (PagePresenter), $user (angezeigtes Mitglied).
 * Die Registerkarte sehen nur Mitglieder der Zugriffsrolle des Plugins.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;

if (basename($_SERVER['SCRIPT_FILENAME']) === 'profile_addin.php') {
    exit('This page may not be called directly!');
}

if (isset($page, $user) && is_object($page) && is_object($user) && method_exists($page, 'assignSmartyVariable')) {
    try {
        require_once (__DIR__ . '/bookings_function.php');

        $pmbUserId = (int) $user->getValue('usr_id');
        if ($pmbUserId > 0 && isUserAuthorized()) {
            $pmbHistoryUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bookings.php', array('user_uuid' => $user->getValue('usr_uuid')));
            $page->assignSmartyVariable('pmbBookingsTitle', $gL10n->get('PLG_MEMBERSHIPFEE_BOOKINGS_TAB'));
            $page->assignSmartyVariable('pmbBookingsHtml',
                '<p><a class="btn btn-secondary" href="' . $pmbHistoryUrl . '"><i class="bi bi-clock-history"></i> '
                . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_HISTORY_EDIT') . '</a></p>'
                . mf_render_bookings($pmbUserId, (string) $user->getValue('usr_uuid'), false));
        }
    } catch (Throwable $pmbException) {
        // die Profilseite soll auch bei einem Fehler im Plugin angezeigt werden
    }
}
