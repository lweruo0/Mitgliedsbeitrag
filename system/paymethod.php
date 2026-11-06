<?php
/**
 ***********************************************************************************************
 * Mitglied zwischen den Tabellen "Ueberweisung" und "SEPA-Einzug" verschieben (2.0 und 2.1)
 *
 * Gilt nur fuer das Beitragsjahr und den Buchungskreis (PaymentMethodStore). Eine offene Rechnung dieses
 * Jahres behaelt ihre Nummer; Zahlart, Faelligkeit und Mandatsangaben werden umgestellt und das PDF neu
 * erzeugt. Rechnungen im Einzug (in einer SEPA-Datei) koennen nicht verschoben werden.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters (POST, JSON-Antwort):
 *
 * adm_csrf_token : CSRF-Token
 * usr_id         : Mitglied
 * year           : Beitragsjahr
 * target         : MAN (nach Ueberweisung) | SEPA (nach SEPA-Einzug)
 * debit_date     : Einzugsdatum (Y-m-d), nur noetig nach SEPA mit offener Rechnung und ohne Faelligkeit
 * sequencetype   : Sequenztyp dazu (AUTO, FRST, RCUR, FNAL, OOFF)
 * collection     : Buchungskreis (GET, siehe mf_collection_scope())
 ***********************************************************************************************
 */
use Admidio\Users\Entity\User;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Invoices\InvoicePlanner;
use Plugins\MembershipFee\classes\Invoices\InvoiceService;
use Plugins\MembershipFee\classes\Invoices\InvoiceStatus;
use Plugins\MembershipFee\classes\Invoices\InvoiceStore;
use Plugins\MembershipFee\classes\Invoices\PaymentMethodStore;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

header('Content-Type: application/json; charset=utf-8');

try {
    $mfScope = mf_collection_scope();
    if (!isUserAuthorized()) {
        throw new \RuntimeException($gL10n->get('SYS_NO_RIGHTS'));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new \RuntimeException($gL10n->get('SYS_INVALID_PAGE_VIEW'));
    }
    SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));

    $usrId = (int) ($_POST['usr_id'] ?? 0);
    $year = (int) ($_POST['year'] ?? 0);
    $target = (string) ($_POST['target'] ?? '');
    if ($usrId <= 0 || $year < 2000 || !in_array($target, array(InvoicePlanner::METHOD_SEPA, InvoicePlanner::METHOD_MANUAL), true)) {
        throw new \RuntimeException($gL10n->get('SYS_INVALID_PAGE_VIEW'));
    }

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    $invoiceService = new InvoiceService($pPreferences->config, $mfScope);
    $kind = $mfScope->key();

    $user = new User($gDb, $gProfileFields, $usrId);
    if ((int) $user->getValue('usr_id') !== $usrId) {
        throw new \RuntimeException($gL10n->get('SYS_INVALID_PAGE_VIEW'));
    }
    $mandateId = (string) $user->getValue('MANDATEID' . (int) $gCurrentOrgId);
    $iban = (string) $user->getValue('IBAN');
    $autoMethod = InvoicePlanner::method($iban, $mandateId);
    if ($target === InvoicePlanner::METHOD_SEPA && $autoMethod !== InvoicePlanner::METHOD_SEPA) {
        throw new \RuntimeException($gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_NO_MANDATE'));
    }
    $dueField = $mfScope->field(CollectionScope::FIELD_DUEDATE);
    $profileDueDate = (string) $user->getValue($dueField, 'Y-m-d');

    // offene Rechnungen dieses Jahres (Nummer bleibt); im Einzug: nicht verschiebbar
    $invoices = array();
    foreach (InvoiceStore::search(array('kind' => $kind, 'usr_ids' => array($usrId))) as $invoice) {
        if ((int) $invoice['mfi_year'] !== $year) {
            continue;
        }
        if ($invoice['status'] === InvoiceStatus::IN_COLLECTION) {
            throw new \RuntimeException($gL10n->get('PLG_MEMBERSHIPFEE_PAYMETHOD_IN_COLLECTION', array($invoice['mfi_invoice_no'])));
        }
        if ($invoice['status'] === InvoiceStatus::OPEN && $invoice['mfi_method'] !== $target) {
            $invoices[] = $invoice;
        }
    }

    // nach SEPA mit offener Rechnung braucht die Rechnung einen Einzugstag
    $debitDate = '';
    if ($target === InvoicePlanner::METHOD_SEPA && count($invoices) > 0 && $profileDueDate === '') {
        $debitDate = (string) ($_POST['debit_date'] ?? '');
        if ($debitDate === '') {
            echo json_encode(array('ok' => false, 'need_date' => true,
                'debit_default' => mf_next_valid_duedate(date('Y-m-d', (int) strtotime(DATE_NOW . ' +14 days')))));
            exit();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $debitDate) !== 1 || $debitDate < DATE_NOW) {
            throw new \RuntimeException($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_DEBIT_DATE_INVALID'));
        }
        $problem = mf_duedate_problem($debitDate);
        if ($problem !== null) {
            throw new \RuntimeException($gL10n->get('PLG_MEMBERSHIPFEE_INVOICE_DEBIT_DATE_PROBLEM', array(
                date('d.m.Y', (int) strtotime($debitDate)), $problem, date('d.m.Y', (int) strtotime(mf_next_valid_duedate($debitDate))))));
        }
    }

    $gDb->startTransaction();
    // nur die Abweichung (Ueberweisung) wird gespeichert; zurueck nach SEPA = wieder Zahlart aus dem Profil
    PaymentMethodStore::set($usrId, $year, $kind, $target === InvoicePlanner::METHOD_MANUAL ? InvoicePlanner::METHOD_MANUAL : null);

    if ($target === InvoicePlanner::METHOD_MANUAL) {
        // ohne Faelligkeit kommt das Mitglied weder in die Vorabinformation noch in die SEPA-Datei
        if ($profileDueDate !== '') {
            $user->setValue($dueField, '');
            $user->save();
        }
    } elseif ($debitDate !== '') {
        $sequenceType = (string) ($_POST['sequencetype'] ?? 'AUTO');
        if (!in_array($sequenceType, InvoiceService::SEQUENCE_TYPES, true)) {
            $sequenceType = 'AUTO';
        }
        if ($sequenceType === 'AUTO') {
            $sequenceType = mf_auto_sequence_types(array($usrId))[$usrId] ?? 'FRST';
        }
        $user->setValue($dueField, $debitDate);
        $user->setValue($mfScope->field(CollectionScope::FIELD_SEQUENCETYPE), $sequenceType);
        $user->save();
        $profileDueDate = $debitDate;
    }

    foreach ($invoices as $invoice) {
        InvoiceStore::update((string) $invoice['mfi_invoice_no'], $target === InvoicePlanner::METHOD_SEPA
            ? array('mfi_method' => InvoicePlanner::METHOD_SEPA, 'mfi_mandate_id' => $mandateId,
                'mfi_iban_masked' => InvoicePlanner::maskIban($iban), 'mfi_due_date' => $profileDueDate)
            : array('mfi_method' => InvoicePlanner::METHOD_MANUAL, 'mfi_mandate_id' => '', 'mfi_iban_masked' => '',
                'mfi_due_date' => InvoicePlanner::dueDate(InvoicePlanner::METHOD_MANUAL, '', DATE_NOW, $invoiceService->getTermDays())));
    }
    $gDb->endTransaction();

    // PDF mit der neuen Zahlart (Einzugsankuendigung bzw. Zahlungsziel und QR-Code)
    $pdfErrors = array();
    foreach ($invoices as $invoice) {
        $error = $invoiceService->createPdf((string) $invoice['mfi_invoice_no']);
        if ($error !== '') {
            $pdfErrors[] = $invoice['mfi_invoice_no'] . ': ' . $error;
        }
    }

    echo json_encode(array('ok' => true, 'invoices' => array_column($invoices, 'mfi_invoice_no'), 'pdf_errors' => $pdfErrors));
} catch (\Throwable $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    echo json_encode(array('ok' => false, 'message' => $e->getMessage()));
}
