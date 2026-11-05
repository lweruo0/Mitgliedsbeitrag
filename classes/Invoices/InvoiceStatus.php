<?php
/**
 ***********************************************************************************************
 * Status einer Rechnung, berechnet aus den Positionen mit ihrer Rechnungsnummer
 *
 * Der Status wird nicht gespeichert (siehe docs/konzept-rechnungen-pdf.md, Abschnitt 4.4), damit die
 * Stellen, die Positionen aendern (Einzug, Zahlung, Storno, Neuberechnung, Import), den Rechnungskopf
 * nicht mitpflegen muessen.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

use Plugins\MembershipFee\classes\Bookings\BookingStore;

final class InvoiceStatus
{
    public const OPEN = 'offen';
    public const IN_COLLECTION = 'im_einzug';
    public const PAID = 'bezahlt';
    public const CANCELLED = 'storniert';

    /**
     * Status aus der Anzahl der Positionen je Status.
     */
    public static function fromCounts(int $open, int $exported, int $collected, int $cancelled): string
    {
        $active = $open + $exported + $collected;
        if ($active === 0) {
            return $cancelled > 0 ? self::CANCELLED : self::OPEN;
        }
        if ($open === 0 && $exported === 0) {
            return self::PAID;
        }
        if ($open === 0) {
            return self::IN_COLLECTION;
        }
        return self::OPEN;
    }

    /**
     * @param array<int, array<string, mixed>> $positions Positionen einer Rechnung
     */
    public static function fromPositions(array $positions): string
    {
        $counts = array(
            BookingStore::STATUS_OPEN => 0,
            BookingStore::STATUS_EXPORTED => 0,
            BookingStore::STATUS_COLLECTED => 0,
            BookingStore::STATUS_CANCELLED => 0
        );
        foreach ($positions as $position) {
            $status = (string) $position['mfb_status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        return self::fromCounts($counts[BookingStore::STATUS_OPEN], $counts[BookingStore::STATUS_EXPORTED],
            $counts[BookingStore::STATUS_COLLECTED], $counts[BookingStore::STATUS_CANCELLED]);
    }

    /**
     * Offener Betrag: Positionen mit Status "offen" (exportierte stecken in einem laufenden Einzug).
     * @param array<int, array<string, mixed>> $positions
     */
    public static function openAmount(array $positions): float
    {
        $sum = 0.0;
        foreach ($positions as $position) {
            if ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
                $sum += (float) $position['mfb_amount'];
            }
        }
        return round($sum, 2);
    }

    /**
     * Ueberfaellig: offen, Faelligkeit vor heute, noch nicht gemahnt.
     */
    public static function isOverdue(string $status, ?string $dueDate, ?string $dunningDate, string $today): bool
    {
        return $status === self::OPEN && $dueDate !== null && $dueDate !== '' && $dueDate < $today
            && ($dunningDate === null || $dunningDate === '');
    }
}
