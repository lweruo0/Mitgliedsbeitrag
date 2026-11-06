<?php
/**
 ***********************************************************************************************
 * Welche Positionen eines Mitglieds kommen auf welche neue Rechnung? (ohne Datenbank, testbar)
 *
 * Regeln (docs/konzept-rechnungen-pdf.md, Abschnitt 5):
 *  - nur Positionen des Buchungskreises mit Status "offen" und ohne Rechnungsnummer
 *    (Rechnungen entstehen vor der Vorabinformation und der SEPA-Datei)
 *  - je Beitragsjahr eine Rechnung
 *  - Summe <= 0: keine Rechnung, Fehler "amount" (Gutschriften gibt es nicht)
 *  - Zahlart SEPA, wenn IBAN und Mandats-ID vorhanden sind, sonst MAN (Ueberweisung)
 *  - Lastschrift: kein Zahlungsziel; gespeichert wird der Einzugstag (Faelligkeit aus dem Profil, kann fehlen)
 *  - Ueberweisung: Faelligkeit aus dem Profil, sonst Rechnungsdatum + Zahlungsziel in Tagen
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

use Plugins\MembershipFee\classes\Bookings\BookingStore;

final class InvoicePlanner
{
    public const ERROR_AMOUNT = 'amount';

    public const METHOD_SEPA = BookingStore::METHOD_SEPA;
    public const METHOD_MANUAL = BookingStore::METHOD_MANUAL;

    /**
     * @param array<int, array<string, mixed>> $positions bereits auf den Buchungskreis gefilterte Positionen eines Mitglieds
     * @return array<int, array{year:int, position_ids:array<int,int>, amount:float, error:string}> je Jahr (aufsteigend)
     */
    public static function plan(array $positions): array
    {
        $byYear = array();
        foreach ($positions as $position) {
            if ($position['mfb_status'] !== BookingStore::STATUS_OPEN || (string) ($position['mfb_invoice_no'] ?? '') !== '') {
                continue;
            }
            $year = (int) $position['mfb_year'];
            $byYear[$year]['ids'][] = (int) $position['mfb_id'];
            $byYear[$year]['amount'] = ($byYear[$year]['amount'] ?? 0.0) + (float) $position['mfb_amount'];
        }
        ksort($byYear);

        $result = array();
        foreach ($byYear as $year => $data) {
            $amount = round($data['amount'], 2);
            $result[] = array(
                'year' => (int) $year,
                'position_ids' => $data['ids'],
                'amount' => $amount,
                'error' => $amount <= 0.0 ? self::ERROR_AMOUNT : ''
            );
        }
        return $result;
    }

    public static function method(string $iban, string $mandateId): string
    {
        return (trim($iban) !== '' && trim($mandateId) !== '') ? self::METHOD_SEPA : self::METHOD_MANUAL;
    }

    /**
     * Zahlart unter Beruecksichtigung einer abweichenden Zahlart des Beitragsjahres (PaymentMethodStore):
     * Ueberweisung geht immer; Lastschrift nur mit IBAN und Mandats-ID ($autoMethod aus method()), deshalb
     * wird nur 'MAN' als Abweichung gespeichert.
     */
    public static function effectiveMethod(string $autoMethod, ?string $override): string
    {
        return $override === self::METHOD_MANUAL ? self::METHOD_MANUAL : $autoMethod;
    }

    /**
     * Faelligkeit der Rechnung (Y-m-d) oder null (Lastschrift ohne bekannten Einzugstag).
     *
     * @param string $profileDueDate Faelligkeit aus dem Profilfeld des Buchungskreises (Y-m-d oder leer)
     * @param string $invoiceDate    Rechnungsdatum (Y-m-d)
     * @param int    $termDays       Zahlungsziel in Tagen (Einstellung)
     */
    public static function dueDate(string $method, string $profileDueDate, string $invoiceDate, int $termDays): ?string
    {
        if ($profileDueDate !== '') {
            return $profileDueDate;
        }
        if ($method === self::METHOD_SEPA) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $invoiceDate);
        if ($date === false) {
            throw new \InvalidArgumentException('Ungueltiges Rechnungsdatum: ' . $invoiceDate);
        }
        return $date->modify('+' . max(0, $termDays) . ' days')->format('Y-m-d');
    }

    /**
     * IBAN fuer Rechnung und Anschreiben kuerzen: Laenderkennung, Pruefziffer und die letzten 4 Stellen.
     */
    public static function maskIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (strlen($iban) < 8) {
            return $iban;
        }
        return substr($iban, 0, 4) . str_repeat('x', strlen($iban) - 8) . substr($iban, -4);
    }
}
