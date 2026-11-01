<?php
/**
 ***********************************************************************************************
 * Abrechnung des Arbeitsdienstes (ohne Datenbankzugriff)
 *
 * Plant fuer ein Mitglied und ein Leistungsjahr die Position "Ersatzleistung Arbeitsdienst" im
 * Buchungskreis Arbeitsdienst (Quelle "ad", mfb_year = Leistungsjahr):
 *  - Ist die Position des Jahres schon exportiert oder eingezogen, wird nichts mehr geaendert
 *    (Korrekturen dann ueber eine Gutschrift).
 *  - Sonst: offene Position anlegen, anpassen oder (bei 0 Fehlstunden) loeschen.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;

final class WorkBillingPlanner
{
    /** Bezeichnung der Position (mfb_rol_name), je Leistungsjahr hoechstens eine */
    public const LABEL = 'Ersatzleistung Arbeitsdienst';

    public const STATE_NEW = 'neu';
    public const STATE_CHANGED = 'geaendert';
    public const STATE_REMOVED = 'entfernt';
    public const STATE_UNCHANGED = 'unveraendert';
    public const STATE_BILLED = 'abgerechnet';
    public const STATE_NONE = 'keine';

    /**
     * Verwendungszweck der Position (SEPA-tauglich: ohne Sonderzeichen wie EUR-Zeichen).
     */
    public static function text(int $year, float $target, float $done, float $rate): string
    {
        return self::LABEL . ' ' . $year . ': Soll ' . self::hours($target) . ' h, Ist ' . self::hours($done) . ' h, '
            . self::hours(WorkRules::missingHours($target, $done)) . ' h x ' . number_format($rate, 2, ',', '') . ' EUR';
    }

    /**
     * @param int $year Leistungsjahr
     * @param float|null $target Soll (Profilfeld); null = kein Soll eingetragen
     * @param float $done geleistete Stunden im Leistungsjahr
     * @param array<int, array<string, mixed>> $positions alle Positionen des Mitglieds (werden auf den Arbeitsdienst gefiltert)
     * @return array{state:string, amount:float, text:string, ops:array<int, array<string, mixed>>}
     */
    public static function plan(int $year, ?float $target, float $done, WorkRules $rules, array $positions): array
    {
        $amount = ($target !== null) ? $rules->replacementFee($target, $done) : 0.0;
        $text = ($target !== null) ? self::text($year, $target, $done, $rules->rate()) : '';

        $open = array();
        foreach ($positions as $position) {
            if ((string) ($position['mfb_source'] ?? '') !== CollectionScope::SOURCE_WORK
                || (int) $position['mfb_year'] !== $year
                || (string) $position['mfb_rol_name'] !== self::LABEL) {
                continue;
            }
            if (in_array($position['mfb_status'], array(BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED), true)) {
                return array('state' => self::STATE_BILLED, 'amount' => round((float) $position['mfb_amount'], 2), 'text' => (string) $position['mfb_text'], 'ops' => array());
            }
            if ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
                $open[] = $position;
            }
        }

        $ops = array();
        $state = self::STATE_NONE;
        if ($amount >= 0.005) {
            $data = array('mfb_rol_name' => self::LABEL, 'mfb_amount' => $amount, 'mfb_text' => $text);
            if (count($open) > 0) {
                $keep = array_shift($open);
                if (abs((float) $keep['mfb_amount'] - $amount) >= 0.005 || (string) $keep['mfb_text'] !== $text) {
                    $ops[] = array('op' => 'update', 'id' => (int) $keep['mfb_id'], 'data' => $data, 'before' => $keep);
                    $state = self::STATE_CHANGED;
                } else {
                    $state = self::STATE_UNCHANGED;
                }
            } else {
                $ops[] = array('op' => 'insert', 'data' => array_merge($data, array(
                    'mfb_year' => $year,
                    'mfb_source' => CollectionScope::SOURCE_WORK,
                    'mfb_status' => BookingStore::STATUS_OPEN
                )));
                $state = self::STATE_NEW;
            }
        }
        foreach ($open as $position) {
            $ops[] = array('op' => 'delete', 'id' => (int) $position['mfb_id'], 'before' => $position);
            if ($state === self::STATE_NONE) {
                $state = self::STATE_REMOVED;
            }
        }
        return array('state' => $state, 'amount' => $amount, 'text' => $text, 'ops' => $ops);
    }

    private static function hours(float $hours): string
    {
        return substr(WorkRules::formatHours($hours), 0, -2);
    }
}
