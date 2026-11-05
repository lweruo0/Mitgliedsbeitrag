<?php
/**
 ***********************************************************************************************
 * Pruefung "Einmalige Beitragsrollen"
 *
 * Rollen mit Beitragszeitraum "einmalig" (rol_cost_period = -1, z. B. Aufnahmegebuehr, Erstbesatz) werden
 * nicht von der Neuberechnung begrenzt: Sie berechnet jede Beitragsrolle in jedem Beitragsjahr, in dem das
 * Mitglied ihr angehoert. Deshalb gilt (siehe docs/konzept-rechnungen-pdf.md, Abschnitt 3):
 * Mitgliedschaften in einmaligen Rollen beginnen am 01.01. und enden am 31.12. desselben Jahres.
 *
 * Gemeldet wird:
 *  - END_LATE:     Ende nach dem 31.12. des Beginnjahres (auch offenes Ende) -> Behebung: Ende auf den 31.12. setzen
 *  - BEGIN:        Beginn nicht am 01.01. (nur Hinweis, Beginn und Ende von Hand korrigieren)
 *  - YEARS:        nicht stornierte Positionen dieser Rolle in mehr als einem Beitragsjahr (doppelt berechnet)
 *
 * Die Klasse arbeitet nur mit uebergebenen Daten (ohne Datenbank) und ist dadurch testbar.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Checks;

class OneTimeRoleCheck
{
    public const END_LATE = 'end_late';
    public const BEGIN = 'begin';
    public const YEARS = 'years';

    /**
     * @param array<int, array{mem_id:int, usr_id:int, name:string, rol_id:int, rol_name:string, begin:string, end:string}> $memberships
     *        Mitgliedschaften in einmaligen Rollen (Datum als Y-m-d)
     * @param array<int, array<int, array<int, string>>> $positionYears
     *        usr_id => rol_id => Beitragsjahr => Status (nicht stornierte Positionen; je Jahr der "staerkste" Status)
     * @param array<int, string> $names     usr_id => Name (fuer Funde ohne Mitgliedschaft)
     * @param array<int, string> $roleNames rol_id => Rollenname
     * @return array<int, array{type:string, usr_id:int, name:string, rol_id:int, rol_name:string, mem_id:int|null,
     *         begin:string, end:string, target_end:string, years:array<int,int>, later_open:array<int,int>}>
     */
    public static function evaluate(array $memberships, array $positionYears, array $names = array(), array $roleNames = array()): array
    {
        $findings = array();
        $seenRole = array();

        foreach ($memberships as $membership) {
            $usrId = (int) $membership['usr_id'];
            $rolId = (int) $membership['rol_id'];
            $begin = substr((string) $membership['begin'], 0, 10);
            $end = substr((string) $membership['end'], 0, 10);
            $beginYear = (int) substr($begin, 0, 4);
            $targetEnd = sprintf('%04d-12-31', $beginYear);
            $base = array(
                'usr_id' => $usrId,
                'name' => (string) $membership['name'],
                'rol_id' => $rolId,
                'rol_name' => (string) $membership['rol_name'],
                'mem_id' => (int) $membership['mem_id'],
                'begin' => $begin,
                'end' => $end,
                'target_end' => $targetEnd,
                'years' => array(),
                'later_open' => array()
            );

            if ($end > $targetEnd) {
                // offene Positionen dieser Rolle in spaeteren Jahren (entfernt die naechste Neuberechnung)
                $laterOpen = array();
                foreach ($positionYears[$usrId][$rolId] ?? array() as $year => $status) {
                    if ((int) $year > $beginYear && $status === 'offen') {
                        $laterOpen[] = (int) $year;
                    }
                }
                sort($laterOpen);
                $findings[] = array_merge($base, array('type' => self::END_LATE, 'later_open' => $laterOpen));
            }
            if (substr($begin, 5) !== '01-01') {
                $findings[] = array_merge($base, array('type' => self::BEGIN));
            }
            $seenRole[$usrId][$rolId] = $base;
        }

        // Positionen in mehreren Jahren (auch ohne aktuelle Mitgliedschaft, z. B. Altdaten)
        foreach ($positionYears as $usrId => $roles) {
            foreach ($roles as $rolId => $years) {
                if (count($years) < 2) {
                    continue;
                }
                $yearList = array_map('intval', array_keys($years));
                sort($yearList);
                $base = $seenRole[$usrId][$rolId] ?? null;
                $findings[] = array(
                    'type' => self::YEARS,
                    'usr_id' => (int) $usrId,
                    'name' => $base['name'] ?? ($names[$usrId] ?? ''),
                    'rol_id' => (int) $rolId,
                    'rol_name' => $base['rol_name'] ?? ($roleNames[$rolId] ?? ''),
                    'mem_id' => null,
                    'begin' => $base['begin'] ?? '',
                    'end' => $base['end'] ?? '',
                    'target_end' => '',
                    'years' => $yearList,
                    'later_open' => array()
                );
            }
        }

        usort($findings, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name'])
                ?: strcasecmp($a['rol_name'], $b['rol_name'])
                ?: strcmp($a['type'], $b['type']);
        });
        return $findings;
    }

    /**
     * Neues Ende einer Mitgliedschaft fuer die Behebung, oder null, wenn nichts zu tun ist.
     */
    public static function fixedEnd(string $begin, string $end): ?string
    {
        $targetEnd = sprintf('%04d-12-31', (int) substr($begin, 0, 4));
        return substr($end, 0, 10) > $targetEnd ? $targetEnd : null;
    }
}
