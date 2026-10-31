<?php
/**
 ***********************************************************************************************
 * Oeffentlicher Download einer geteilten SEPA-Datei (ohne Anmeldung)
 *
 * Der Link enthaelt ein zufaelliges Token (64 Hex-Zeichen). In der Datenbank steht nur dessen
 * SHA-256-Hash. Der Link ist bis zum Ablaufdatum gueltig und kann in der Einzugsuebersicht
 * widerrufen werden. Jeder Abruf wird gezaehlt.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * token : Token aus dem geteilten Link
 * file  : ctl = Kontrolldatei desselben Einzugs (ohne: SEPA-Datei)
 ***********************************************************************************************
 */
use Plugins\MembershipFee\classes\Bookings\BookingStore;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

$token = (string) ($_GET['token'] ?? '');
$file = null;
if (preg_match('/^[0-9a-f]{64}$/', $token) === 1) {
    $file = BookingStore::findFileByShareHash(hash('sha256', $token));
}

$valid = $file !== null
    && !empty($file['mff_share_expires'])
    && strtotime((string) $file['mff_share_expires']) !== false
    && strtotime((string) $file['mff_share_expires']) >= time();

if (!$valid) {
    // keine Auskunft, ob der Link nie gueltig war oder abgelaufen ist
    http_response_code(404);
    $gMessage->setForwardUrl(ADMIDIO_URL);
    $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_SHARE_LINK_INVALID'));
    // => EXIT
}

// file=ctl: Kontrolldatei desselben Einzugs (gleicher Link, gleiche Gueltigkeit)
if (($_GET['file'] ?? '') === BookingStore::FILE_CONTROL) {
    $controlFile = BookingStore::getRunFile((int) $file['mff_run_id'], BookingStore::FILE_CONTROL, (int) $file['mff_org_id']);
    if ($controlFile === null) {
        http_response_code(404);
        $gMessage->setForwardUrl(ADMIDIO_URL);
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_SHARE_LINK_INVALID'));
        // => EXIT
    }
    BookingStore::registerDownload((int) $file['mff_id']);
    mf_send_stored_file($controlFile);
    // => EXIT
}

BookingStore::registerDownload((int) $file['mff_id']);
mf_send_stored_file($file);
