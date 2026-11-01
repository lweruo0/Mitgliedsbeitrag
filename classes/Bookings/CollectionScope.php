<?php
/**
 ***********************************************************************************************
 * Buchungskreis des Admidio-Plugins Mitgliedsbeitrag
 *
 * Ein Buchungskreis fasst die Positionen zusammen, die gemeinsam abgerechnet und eingezogen werden:
 *  - "mf" Mitgliedsbeitrag (Beitragsrollen, Individualbeitraege, manuelle Positionen)
 *  - "ad" Arbeitsdienst (Ersatzbeitrag fuer fehlende Arbeitsstunden)
 *
 * Jeder Buchungskreis hat eigene Arbeitsfelder im Profil (Beitrag, Text, Faelligkeit, Sequenztyp,
 * Bezahlt), eigene Positionsquellen und eigene SEPA-Dateien (Praefix der Nachrichten-ID).
 * Die Klasse greift nicht auf die Datenbank zu.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Bookings;

use InvalidArgumentException;

final class CollectionScope
{
    public const MEMBERSHIP = 'mf';
    public const WORK = 'ad';

    /** Positionsquellen des Buchungskreises Arbeitsdienst (Spalte mfb_source, max. 10 Zeichen) */
    public const SOURCE_WORK = 'ad';
    public const SOURCE_WORK_MANUAL = 'ad_manuell';

    /** logische Namen der Arbeitsfelder */
    public const FIELD_FEE = 'FEE';
    public const FIELD_TEXT = 'TEXT';
    public const FIELD_DUEDATE = 'DUEDATE';
    public const FIELD_SEQUENCETYPE = 'SEQUENCETYPE';
    public const FIELD_PAID = 'PAID';

    /**
     * @param array<string, string> $fields logischer Name => usf_name_intern
     * @param array<int, string> $sources Positionsquellen (mfb_source)
     */
    private function __construct(
        private readonly string $key,
        private readonly array $fields,
        private readonly array $sources,
        private readonly string $manualSource,
        private readonly string $messageIdPrefix
    ) {
    }

    /**
     * Mitgliedsbeitrag: Arbeitsfelder mit der Organisations-ID als Suffix (z. B. FEE1).
     */
    public static function membership(int $orgId): self
    {
        return new self(
            self::MEMBERSHIP,
            array(
                self::FIELD_FEE => 'FEE' . $orgId,
                self::FIELD_TEXT => 'CONTRIBUTORY_TEXT' . $orgId,
                self::FIELD_DUEDATE => 'DUEDATE' . $orgId,
                self::FIELD_SEQUENCETYPE => 'SEQUENCETYPE' . $orgId,
                self::FIELD_PAID => 'PAID' . $orgId
            ),
            array(BookingStore::SOURCE_ROLE, BookingStore::SOURCE_INDIVIDUAL, BookingStore::SOURCE_MANUAL),
            BookingStore::SOURCE_MANUAL,
            'MF'
        );
    }

    /**
     * Arbeitsdienst: eigene Arbeitsfelder des Plugins (Praefix AD, Organisations-ID als Suffix, z. B. ADFEE1).
     * Die Felder des frueheren Plugins Arbeitsdienst (WORK*) werden bewusst nicht verwendet, damit dessen
     * Deinstallation nichts entfernt, was hier gebraucht wird.
     */
    public static function work(int $orgId): self
    {
        return new self(
            self::WORK,
            array(
                self::FIELD_FEE => 'ADFEE' . $orgId,
                self::FIELD_TEXT => 'ADTEXT' . $orgId,
                self::FIELD_DUEDATE => 'ADDUEDATE' . $orgId,
                self::FIELD_SEQUENCETYPE => 'ADSEQUENCETYPE' . $orgId,
                self::FIELD_PAID => 'ADPAID' . $orgId
            ),
            array(self::SOURCE_WORK, self::SOURCE_WORK_MANUAL),
            self::SOURCE_WORK_MANUAL,
            'AD'
        );
    }

    /**
     * Buchungskreis zu einem Schluessel ("mf", "ad"); unbekannte oder leere Schluessel ergeben den Mitgliedsbeitrag.
     */
    public static function fromKey(?string $key, int $orgId): self
    {
        return ($key === self::WORK) ? self::work($orgId) : self::membership($orgId);
    }

    /**
     * Buchungskreis, zu dem eine Positionsquelle gehoert.
     */
    public static function forSource(string $source, int $orgId): self
    {
        return in_array($source, array(self::SOURCE_WORK, self::SOURCE_WORK_MANUAL), true) ? self::work($orgId) : self::membership($orgId);
    }

    /**
     * @return array<int, string> alle bekannten Schluessel
     */
    public static function keys(): array
    {
        return array(self::MEMBERSHIP, self::WORK);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function isMembership(): bool
    {
        return $this->key === self::MEMBERSHIP;
    }

    /**
     * Interner Name (usf_name_intern) eines Arbeitsfelds.
     */
    public function field(string $name): string
    {
        if (!isset($this->fields[$name])) {
            throw new InvalidArgumentException('Unbekanntes Arbeitsfeld: ' . $name);
        }
        return $this->fields[$name];
    }

    /**
     * @return array<string, string> logischer Name => usf_name_intern
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<int, string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Quelle fuer manuelle Positionen und Ausgleichspositionen in diesem Buchungskreis.
     */
    public function manualSource(): string
    {
        return $this->manualSource;
    }

    public function includesSource(string $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    /**
     * Nur die Positionen dieses Buchungskreises (Schluessel bleiben erhalten).
     *
     * @param array<int|string, array<string, mixed>> $positions
     * @return array<int|string, array<string, mixed>>
     */
    public function filterPositions(array $positions): array
    {
        return array_filter($positions, fn (array $position): bool => $this->includesSource((string) ($position['mfb_source'] ?? '')));
    }

    /**
     * Nachrichten-ID einer SEPA-Datei (max. 35 Zeichen), z. B. MF-BFV-112-20261101121930.
     */
    public function messageId(string $orgShortName, int $runId, string $timestamp): string
    {
        return substr($this->messageIdPrefix . '-' . $orgShortName . '-' . $runId . '-' . $timestamp, 0, 35);
    }

    /**
     * EndToEnd-ID einer Lastschrift (max. 35 Zeichen). Im Arbeitsdienst mit Kennung, damit sie sich
     * von der Lastschrift des Mitgliedsbeitrags am selben Faelligkeitsdatum unterscheidet.
     */
    public function endToEndId(string $orgShortName, int $usrId, string $dueDate): string
    {
        $prefix = $this->isMembership() ? $orgShortName : $orgShortName . '-' . $this->messageIdPrefix;
        return substr($prefix . '-' . $usrId . '-' . str_replace('-', '', $dueDate), 0, 35);
    }

    /**
     * SQL-Bedingung auf mfb_source fuer diesen Buchungskreis (Quellen sind Konstanten, daher ohne Platzhalter).
     */
    public function sourceSqlCondition(string $column = 'mfb_source'): string
    {
        return $column . ' IN (\'' . implode('\', \'', $this->sources) . '\')';
    }
}
