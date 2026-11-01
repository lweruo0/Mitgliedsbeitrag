<?php
/**
 ***********************************************************************************************
 * Registerkarte "Beitraege" im Mitgliedsprofil
 *
 * Wird von modules/profile/profile.php eingebunden (siehe classes/Bookings/ProfileAddin.php).
 * Verfuegbare Variablen aus profile.php: $page (PagePresenter), $user (angezeigtes Mitglied).
 * Die Registerkarte "Beitraege" sehen nur Mitglieder der Zugriffsrolle des Plugins.
 * Die Registerkarte "Arbeitsdienst" sehen zusaetzlich Berechtigte der Erfassung und das Mitglied selbst.
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

        // Registerkarte "Arbeitsdienst": fuer die Zugriffsrolle, fuer Berechtigte der Erfassung und fuer das Mitglied selbst
        $pmbWorkInput = $pmbUserId > 0 && mf_is_work_input_authorized();
        if ($pmbUserId > 0 && ($pmbWorkInput || $pmbUserId === (int) $gCurrentUserId)) {
            $pmbWorkHtml = mf_render_work_tab($pmbUserId, $pmbWorkInput);
            if ($pmbWorkHtml !== '') {
                $page->assignSmartyVariable('pmbWorkTitle', $gL10n->get('PLG_MEMBERSHIPFEE_WORK_TAB'));
                $page->assignSmartyVariable('pmbWorkHtml', $pmbWorkHtml);
            }
        }
    } catch (Throwable $pmbException) {
        // die Profilseite soll auch bei einem Fehler im Plugin angezeigt werden
    }
}
