<?php
/**
 ***********************************************************************************************
 * Rechnungsnummern: JAHR-B-NNNN (Mitgliedsbeitrag) bzw. JAHR-AD-NNNN (Arbeitsdienst)
 *
 * Das Jahr ist das Beitrags- bzw. Leistungsjahr der Positionen (mfb_year), nicht das Jahr des
 * Rechnungsdatums. Die Laufnummer zaehlt je Organisation, Jahr und Buchungskreis.
 * Siehe docs/konzept-rechnungen-pdf.md, Abschnitt 4.3.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

use Plugins\MembershipFee\classes\Bookings\CollectionScope;

final class InvoiceNumber
{
    /** gueltige Rechnungsnummer */
    public const PATTERN = '/^\d{4}-(B|AD)-\d{4,}$/';

    /** gueltiger Dateiname einer abgelegten Rechnung oder Mahnung */
    public const FILE_PATTERN = '/^(rechnung|mahnung)_\d{4}-(B|AD)-\d{4,}(_storniert)?\.pdf$/';

    private const PREFIX = array(
        CollectionScope::MEMBERSHIP => 'B',
        CollectionScope::WORK => 'AD'
    );

    public static function prefix(string $kind): string
    {
        if (!isset(self::PREFIX[$kind])) {
            throw new \InvalidArgumentException('Unbekannter Buchungskreis: ' . $kind);
        }
        return self::PREFIX[$kind];
    }

    public static function format(int $year, string $kind, int $seq): string
    {
        if ($year < 1000 || $year > 9999 || $seq < 1) {
            throw new \InvalidArgumentException('Ungueltige Rechnungsnummer');
        }
        return sprintf('%04d-%s-%04d', $year, self::prefix($kind), $seq);
    }

    public static function isValid(string $number): bool
    {
        return preg_match(self::PATTERN, $number) === 1;
    }

    /**
     * @return array{year:int, kind:string, seq:int}|null
     */
    public static function parse(string $number): ?array
    {
        if (!preg_match('/^(\d{4})-(B|AD)-(\d{4,})$/', $number, $match)) {
            return null;
        }
        return array(
            'year' => (int) $match[1],
            'kind' => (string) array_search($match[2], self::PREFIX, true),
            'seq' => (int) $match[3]
        );
    }

    /**
     * Dateiname der abgelegten PDF-Datei.
     * @param string $type rechnung | mahnung
     */
    public static function fileName(string $type, string $number, bool $cancelled = false): string
    {
        if (!in_array($type, array('rechnung', 'mahnung'), true) || !self::isValid($number)) {
            throw new \InvalidArgumentException('Ungueltiger Dateiname');
        }
        return $type . '_' . $number . ($cancelled ? '_storniert' : '') . '.pdf';
    }

    public static function isValidFileName(string $fileName): bool
    {
        return preg_match(self::FILE_PATTERN, $fileName) === 1;
    }
}
