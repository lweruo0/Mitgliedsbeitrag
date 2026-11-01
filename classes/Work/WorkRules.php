<?php
/**
 ***********************************************************************************************
 * Regeln des Arbeitsdienstes (ohne Datenbankzugriff)
 *
 * - Sollstunden je Rolle (z. B. Aktiv 10 h, Probezeit 15 h, Jugend 0 h); bei mehreren Rollen gilt der hoechste Wert.
 * - Befreit ist, wer in einer Ausnahmerolle oder einer Rolle einer Ausnahmekategorie ist (z. B. Schwerbehindert,
 *   Kategorie "Funktion"). Einzelne Rollen einer Ausnahmekategorie koennen wieder eingeschlossen werden
 *   (z. B. Fischereiaufseher); sie befreien dann nicht. Eine Befreiung geht immer vor.
 * - Befreit ist auch, wer am 31.12. des Leistungsjahres die Altersgrenze erreicht hat (z. B. 60).
 * - Massgeblich sind die Rollen am 31.12. des Leistungsjahres; volles Soll, keine Anteile, kein Uebertrag.
 * - Fehlstunden = max(0, Soll - Ist); Ersatzbeitrag = Fehlstunden x Stundensatz.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

use InvalidArgumentException;

final class WorkRules
{
    /** Stundensatz und Altersgrenze, wenn nichts eingestellt ist */
    public const DEFAULT_RATE = 20.0;
    public const DEFAULT_MAX_AGE = 60;

    /**
     * @param array<int, float> $targetHours rol_id => Sollstunden
     * @param array<int, int> $exemptRoleIds
     * @param array<int, int> $exemptCategoryIds
     * @param int|null $maxAge Altersgrenze (ab diesem Alter am 31.12. befreit); null = keine
     * @param float $rate Ersatzbeitrag je Fehlstunde
     */
    public function __construct(
        private readonly array $targetHours,
        private readonly array $exemptRoleIds = array(),
        private readonly array $exemptCategoryIds = array(),
        private readonly ?int $maxAge = self::DEFAULT_MAX_AGE,
        private readonly float $rate = self::DEFAULT_RATE,
        private readonly array $includedRoleIds = array()
    ) {
        foreach ($targetHours as $hours) {
            if ($hours < 0) {
                throw new InvalidArgumentException('Sollstunden duerfen nicht negativ sein.');
            }
        }
        if ($maxAge !== null && $maxAge < 1) {
            throw new InvalidArgumentException('Die Altersgrenze muss mindestens 1 sein.');
        }
        if ($rate < 0) {
            throw new InvalidArgumentException('Der Stundensatz darf nicht negativ sein.');
        }
    }

    /**
     * Regeln aus der Plugin-Konfiguration (Abschnitt "Arbeitsdienst", siehe configdata.php).
     *
     * @param array<string, mixed> $config $pPreferences->config
     */
    public static function fromConfig(array $config): self
    {
        $section = (array) ($config['Arbeitsdienst'] ?? array());
        $roles = (array) ($section['soll_rollen'] ?? array());
        $hours = (array) ($section['soll_stunden'] ?? array());

        $targetHours = array();
        foreach ($roles as $index => $rolId) {
            $hourValue = trim(str_replace(',', '.', (string) ($hours[$index] ?? '')));
            if ((int) $rolId > 0 && is_numeric($hourValue) && (float) $hourValue >= 0) {
                $targetHours[(int) $rolId] = (float) $hourValue;
            }
        }

        $maxAge = trim((string) ($section['altersgrenze'] ?? ''));
        $rate = trim(str_replace(',', '.', (string) ($section['stundensatz'] ?? '')));

        return new self(
            $targetHours,
            self::idList($section['ausnahme_rollen'] ?? ''),
            self::idList($section['ausnahme_kategorien'] ?? ''),
            ($maxAge !== '' && (int) $maxAge > 0) ? (int) $maxAge : null,
            (is_numeric($rate) && (float) $rate >= 0) ? (float) $rate : self::DEFAULT_RATE,
            self::idList($section['einschluss_rollen'] ?? '')
        );
    }

    /**
     * Kommagetrennte Liste oder Array von IDs.
     * @return array<int, int>
     */
    public static function idList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $ids = array();
        foreach ($items as $item) {
            if ((int) $item > 0) {
                $ids[] = (int) $item;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, float> rol_id => Sollstunden
     */
    public function targetHours(): array
    {
        return $this->targetHours;
    }

    /**
     * @return array<int, int>
     */
    public function exemptRoleIds(): array
    {
        return $this->exemptRoleIds;
    }

    /**
     * @return array<int, int>
     */
    public function exemptCategoryIds(): array
    {
        return $this->exemptCategoryIds;
    }

    /**
     * Rollen einer Ausnahmekategorie, die trotzdem nicht befreien.
     * @return array<int, int>
     */
    public function includedRoleIds(): array
    {
        return $this->includedRoleIds;
    }

    /**
     * Befreit diese Rolle? Ausnahmerolle immer; Rolle einer Ausnahmekategorie, sofern sie nicht wieder eingeschlossen ist.
     * @param array{id:int, name:string, category:int} $role
     */
    public function exempts(array $role): bool
    {
        if (in_array($role['id'], $this->exemptRoleIds, true)) {
            return true;
        }
        return in_array($role['category'], $this->exemptCategoryIds, true) && !in_array($role['id'], $this->includedRoleIds, true);
    }

    public function maxAge(): ?int
    {
        return $this->maxAge;
    }

    public function rate(): float
    {
        return $this->rate;
    }

    /**
     * Soll eines Mitglieds im Leistungsjahr.
     *
     * @param array<int, array{id:int, name:string, category:int}> $roles Rollen des Mitglieds am 31.12. des Leistungsjahres
     * @param string|null $birthday Geburtstag (Y-m-d) oder null/leer, wenn unbekannt
     */
    public function target(array $roles, ?string $birthday, int $year): WorkTarget
    {
        // hoechste Sollstunden der Rollen aus der Tabelle
        $best = null;
        foreach ($roles as $role) {
            if (!array_key_exists($role['id'], $this->targetHours)) {
                continue;
            }
            if ($best === null || $this->targetHours[$role['id']] > $this->targetHours[$best['id']]) {
                $best = $role;
            }
        }
        if ($best === null) {
            return new WorkTarget(0.0, WorkTarget::NONE, 'keine Rolle mit Sollstunden');
        }
        $hours = $this->targetHours[$best['id']];
        if ($hours <= 0.0) {
            return new WorkTarget(0.0, WorkTarget::NONE, $best['name'] . ': ' . self::formatHours(0.0));
        }

        // Befreiung geht vor
        foreach ($roles as $role) {
            if ($this->exempts($role)) {
                return new WorkTarget(0.0, WorkTarget::EXEMPT, 'befreit: ' . $role['name']);
            }
        }

        $age = self::ageAtYearEnd($birthday, $year);
        if ($this->maxAge !== null && $age !== null && $age >= $this->maxAge) {
            return new WorkTarget(0.0, WorkTarget::EXEMPT, 'befreit: Alter ' . $age . ' (ab ' . $this->maxAge . ')');
        }

        return new WorkTarget($hours, WorkTarget::REQUIRED, $best['name'] . ': ' . self::formatHours($hours));
    }

    /**
     * Alter am 31.12. des Jahres; null, wenn der Geburtstag fehlt oder ungueltig ist.
     */
    public static function ageAtYearEnd(?string $birthday, int $year): ?int
    {
        if ($birthday === null || !preg_match('/^(\d{4})-\d{2}-\d{2}$/', trim($birthday), $match)) {
            return null;
        }
        $age = $year - (int) $match[1];
        return ($age >= 0) ? $age : null;
    }

    /**
     * Fehlstunden = max(0, Soll - Ist), auf zwei Nachkommastellen.
     */
    public static function missingHours(float $target, float $done): float
    {
        return max(0.0, round($target - $done, 2));
    }

    /**
     * Ersatzbeitrag fuer die Fehlstunden.
     */
    public function replacementFee(float $target, float $done): float
    {
        return round(self::missingHours($target, $done) * $this->rate, 2);
    }

    public static function formatHours(float $hours): string
    {
        $text = number_format($hours, 2, ',', '');
        $text = rtrim(rtrim($text, '0'), ',');
        return $text . ' h';
    }
}
