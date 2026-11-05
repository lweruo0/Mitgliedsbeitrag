<?php
/**
 ***********************************************************************************************
 * Abgelegte Rechnung (PDF) anzeigen
 *
 * Fuer die Zugriffsrolle des Plugins (docs/konzept-rechnungen-pdf.md, 7.4). Ausgeliefert wird die beim
 * Erstellen abgelegte Datei; eine ausgestellte Rechnung aendert sich nicht mehr. Fehlt die Datei, wird sie
 * erzeugt. Eine stornierte Rechnung wird mit Storno-Vermerk ausgeliefert (eigene Datei, die ausgestellte bleibt).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * nr       : Rechnungsnummer, z. B. 2026-B-0012
 * mode     : show (Standard) | regenerate (nur Administratoren: PDF neu erzeugen, z. B. nach einem Layoutfehler)
 * download : 1 = als Datei herunterladen statt im Browser anzeigen
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Invoices\InvoiceNumber;
use Plugins\MembershipFee\classes\Invoices\InvoiceService;
use Plugins\MembershipFee\classes\Invoices\InvoiceStatus;
use Plugins\MembershipFee\classes\Invoices\InvoiceStore;
use Plugins\MembershipFee\classes\Invoices\PdfBridge;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    if (!isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getNr = admFuncVariableIsValid($_GET, 'nr', 'string');
    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'show', 'validValues' => array('show', 'regenerate')));
    $getDownload = admFuncVariableIsValid($_GET, 'download', 'bool');

    if (!InvoiceNumber::isValid($getNr)) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }
    $invoice = InvoiceStore::find($getNr);
    if ($invoice === null) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }
    $kind = (string) $invoice['mfi_kind'];

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    $service = new InvoiceService($pPreferences->config, CollectionScope::fromKey($kind, (int) $gCurrentOrgId));

    $cancelled = $invoice['status'] === InvoiceStatus::CANCELLED;
    $fileName = (string) ($cancelled ? ($invoice['mfi_cancel_file'] ?? '') : ($invoice['mfi_pdf_file'] ?? ''));

    if ($getMode === 'regenerate') {
        if (!$gCurrentUser->isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
        SecurityUtils::validateCsrfToken((string) ($_GET['adm_csrf_token'] ?? ''));
        $fileName = '';
    }

    if ($fileName === '' || PdfBridge::filePath($kind, $fileName) === '') {
        $error = $service->createPdf($getNr);
        if ($error !== '') {
            throw new Exception($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_ERROR', array($getNr, $error)));
        }
        $invoice = InvoiceStore::find($getNr);
        $fileName = (string) ($cancelled ? ($invoice['mfi_cancel_file'] ?? '') : ($invoice['mfi_pdf_file'] ?? ''));
    }
    $path = PdfBridge::filePath($kind, $fileName);
    if ($path === '') {
        throw new Exception($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_PDF_ERROR', array($getNr, 'Datei fehlt')));
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($getDownload ? 'attachment' : 'inline') . '; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    readfile($path);
    exit();
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}
