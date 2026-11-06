<?php
/**
 ***********************************************************************************************
 * Daten einer Rechnung fuer das PDF (Schnittstelle zur Bibliothek wp-tc-lib-pdf)
 *
 * Die Templates in wp-tc-lib-pdf/adm-include/templates/ lesen keine Tabellen des Plugins, sondern rufen
 * nur InvoicePdfData::load() auf und bekommen ein neutrales Array (docs/konzept-rechnungen-pdf.md, Abschnitt 10).
 * Aenderungen an Tabellen und Profilfeldern bleiben so im Plugin.
 *
 * Empfaenger ist das Mitglied (Name und Anschrift wie im Rechnungs-Export bill_export.php).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Work\WorkHoursStore;
use Plugins\MembershipFee\classes\Work\WorkRules;

final class InvoicePdfData
{
    /** Versionsnummer der Datenstruktur (fuer die Templates) */
    public const VERSION = 1;

    /**
     * @return array<string, mixed>|null null, wenn es die Rechnung (in dieser Organisation) nicht gibt
     */
    public static function load(string $invoiceNo): ?array
    {
        global $gDb, $gProfileFields, $gCurrentOrgId;

        if (!InvoiceNumber::isValid($invoiceNo)) {
            return null;
        }
        $invoice = InvoiceStore::find($invoiceNo);
        if ($invoice === null) {
            return null;
        }
        $config = new ConfigTable();
        $config->read();
        $bank = (array) ($config->config['Kontodaten'] ?? array());

        $user = new User($gDb, $gProfileFields, (int) $invoice['mfi_usr_id']);
        $gender = (int) $user->getValue('GENDER', 'database');

        $positions = array();
        $cancelDate = '';
        $cancelReason = '';
        $admission = false;
        $oneTimeRoles = self::oneTimeRoleIds();
        foreach (BookingStore::getByInvoice($invoiceNo) as $position) {
            $cancelled = $position['mfb_status'] === BookingStore::STATUS_CANCELLED;
            if ($cancelled && (string) $position['mfb_storno_date'] > $cancelDate) {
                $cancelDate = (string) $position['mfb_storno_date'];
                $cancelReason = (string) $position['mfb_storno_reason'];
            }
            if ($position['mfb_rol_id'] !== null && isset($oneTimeRoles[(int) $position['mfb_rol_id']])) {
                $admission = true;
            }
            $entry = array(
                'label' => (string) $position['mfb_rol_name'],
                'text' => (string) $position['mfb_text'],
                'amount' => (float) $position['mfb_amount'],
                'status' => (string) $position['mfb_status'],
                'cancelled' => $cancelled,
                'cancel_date' => $cancelled ? (string) $position['mfb_storno_date'] : '',
                'manual' => in_array($position['mfb_source'], array(BookingStore::SOURCE_MANUAL, CollectionScope::SOURCE_WORK_MANUAL), true)
            );
            if ($invoice['status'] === InvoiceStatus::CANCELLED) {
                // stornierte Rechnung: alle Positionen wie ausgestellt (mit Storno-Vermerk)
                $positions[] = $entry;
                continue;
            }
            if (!$cancelled) {
                $positions[] = $entry;
                continue;
            }
            // offene Rechnung: stornierte Positionen entfallen; eine ganz erlassene erscheint mit Betrag und Nachlass
            $stornoReason = (string) $position['mfb_storno_reason'];
            if (str_starts_with($stornoReason, BookingService::WAIVE_REASON_PREFIX)) {
                $entry['cancelled'] = false;
                $entry['cancel_date'] = '';
                $positions[] = $entry;
                $positions[] = array_merge($entry, array(
                    'label' => 'Nachlass auf ' . $entry['label'] . ' (' . trim(substr($stornoReason, strlen(BookingService::WAIVE_REASON_PREFIX))) . ')',
                    'amount' => -$entry['amount'],
                    'manual' => true
                ));
            }
        }

        $data = array(
            'version' => self::VERSION,
            'invoice_no' => $invoiceNo,
            'kind' => (string) $invoice['mfi_kind'],
            'year' => (int) $invoice['mfi_year'],
            'invoice_date' => (string) $invoice['mfi_invoice_date'],
            'due_date' => (string) ($invoice['mfi_due_date'] ?? ''),
            'amount' => (float) $invoice['mfi_amount'],
            'open_amount' => (float) $invoice['open_amount'],
            'method' => (string) $invoice['mfi_method'],
            'mandate_id' => (string) ($invoice['mfi_mandate_id'] ?? ''),
            'iban_masked' => (string) ($invoice['mfi_iban_masked'] ?? ''),
            'status' => (string) $invoice['status'],
            'cancel_date' => $invoice['status'] === InvoiceStatus::CANCELLED ? $cancelDate : '',
            'cancel_reason' => $invoice['status'] === InvoiceStatus::CANCELLED ? $cancelReason : '',
            'dunning_date' => (string) ($invoice['mfi_dunning_date'] ?? ''),
            'dunning_due' => (string) ($invoice['mfi_dunning_due'] ?? ''),
            'admission' => $admission,
            'recipient' => array(
                'salutation' => $gender === 2 ? 'Frau' : ($gender === 1 ? 'Herr' : ''),
                'first_name' => (string) $user->getValue('FIRST_NAME'),
                'last_name' => (string) $user->getValue('LAST_NAME'),
                'street' => (string) $user->getValue('STREET'),
                'zip' => (string) $user->getValue('POSTCODE'),
                'city' => (string) $user->getValue('CITY'),
                'email' => (string) $user->getValue('EMAIL'),
                'member_number' => (string) $user->getValue('MEMBERNUMBER' . (int) $gCurrentOrgId)
            ),
            'positions' => $positions,
            'creditor' => array(
                'holder' => (string) ($bank['inhaber'] ?? ''),
                'iban' => (string) ($bank['iban'] ?? ''),
                'bic' => (string) ($bank['bic'] ?? ''),
                'bank' => (string) ($bank['bank'] ?? ''),
                'creditor_id' => (string) ($bank['ci'] ?? '')
            ),
            'work' => null
        );

        if ($data['kind'] === CollectionScope::WORK) {
            $rate = WorkRules::fromConfig($config->config)->rate();
            $done = WorkHoursStore::sumByUser($data['year'])[(int) $invoice['mfi_usr_id']] ?? 0.0;
            $entries = array();
            foreach (WorkHoursStore::getByYear($data['year'], (int) $invoice['mfi_usr_id']) as $entry) {
                $entries[] = array(
                    'date' => (string) $entry['mfw_date'],
                    'activity' => (string) ($entry['mfw_activity'] ?? ''),
                    'hours' => (float) $entry['mfw_hours']
                );
            }
            $missing = $rate > 0 ? round($data['amount'] / $rate, 2) : 0.0;
            $data['work'] = array(
                'rate' => $rate,
                'done' => (float) $done,
                'missing' => $missing,
                'target' => round((float) $done + $missing, 2),
                'entries' => $entries
            );
        }
        return $data;
    }

    /**
     * Rollen mit Beitragszeitraum "einmalig" (Aufnahme)
     * @return array<int, true>
     */
    private static function oneTimeRoleIds(): array
    {
        global $gDb;

        $ids = array();
        $statement = $gDb->queryPrepared('SELECT rol_id FROM ' . TBL_ROLES . ' WHERE rol_cost_period = -1');
        while ($row = $statement->fetch()) {
            $ids[(int) $row['rol_id']] = true;
        }
        return $ids;
    }
}
