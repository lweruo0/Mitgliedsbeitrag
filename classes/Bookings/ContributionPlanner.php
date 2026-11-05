<?php
/**
 ***********************************************************************************************
 * Rechenlogik der Neuberechnung (ohne Datenbankzugriff)
 *
 * Ermittelt fuer ein Mitglied, welche offenen Buchungspositionen angelegt, geaendert oder geloescht
 * werden muessen. Regeln (siehe docs/konzept-buchungshistorie.md):
 *  - Mitglieder mit einem laufenden Einzug (Position im Status "exportiert") werden uebersprungen.
 *  - Rollen ohne Beitragsgruppe: exportiert/eingezogen -> ueberspringen; storniert mit Rollenentzug ->
 *    ueberspringen; sonst offene Position mit dem aktuellen Rollenbeitrag.
 *  - Beitragsgruppen: Soll = hoechster Rollenbeitrag der Gruppe, Gebucht = exportierte und eingezogene
 *    Positionen der Gruppe im Jahr; nur eine positive Differenz wird zur offenen Position.
 *  - Manuelle Positionen und Individualbeitraege werden hier nie veraendert.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Bookings;

class ContributionPlanner
{
    /**
     * Plant die Aenderungen fuer ein Mitglied.
     *
     * @param array<int, array{name:string, cost:float, description:string, group:?int}> $roles alle Beitragsrollen
     * @param array<int, int> $memberRoleIds Beitragsrollen, in denen das Mitglied im Beitragsjahr mindestens einen Tag war
     * @param array<int, array<string, mixed>> $positions alle Positionen des Mitglieds
     * @param int $year Beitragsjahr
     * @param array<int, int>|null $selectedRoleIds nur diese Rollen berechnen (null = alle)
     * @param array{prefix?:string, floor?:bool, min?:float, group_names?:array<int,string>} $options
     * @return array{waiting:bool, ops:array<int, array<string, mixed>>, info:array<int, array<string, string>>}
     */
    public static function planMember(array $roles, array $memberRoleIds, array $positions, int $year, ?array $selectedRoleIds = null, array $options = array()): array
    {
        $result = array('waiting' => false, 'ops' => array(), 'info' => array());

        foreach ($positions as $position) {
            if ($position['mfb_status'] === BookingStore::STATUS_EXPORTED) {
                $result['waiting'] = true;
                return $result;
            }
        }

        $prefix = trim((string) ($options['prefix'] ?? ''));
        $floor = (bool) ($options['floor'] ?? false);
        $min = (float) ($options['min'] ?? 0);

        $memberRoleIds = array_map('intval', $memberRoleIds);

        // Positionen des Beitragsjahres aus der Neuberechnung
        $yearRolePositions = array();
        foreach ($positions as $position) {
            if ((int) $position['mfb_year'] === $year && $position['mfb_source'] === BookingStore::SOURCE_ROLE) {
                $yearRolePositions[] = $position;
            }
        }

        $revoked = array();
        foreach ($yearRolePositions as $position) {
            if ($position['mfb_status'] === BookingStore::STATUS_CANCELLED && !empty($position['mfb_role_revoked']) && $position['mfb_rol_id'] !== null) {
                $revoked[(int) $position['mfb_rol_id']] = true;
            }
        }

        // betrachtete Rollen und Gruppen bestimmen
        $considered = array();
        foreach ($roles as $rolId => $role) {
            if ($selectedRoleIds === null || in_array((int) $rolId, array_map('intval', $selectedRoleIds), true)) {
                $considered[(int) $rolId] = true;
            }
        }
        $groups = array();          // groupIndex => array of rolIds
        foreach ($roles as $rolId => $role) {
            if ($role['group'] !== null) {
                $groups[(int) $role['group']][] = (int) $rolId;
            }
        }
        $consideredGroups = array();
        foreach ($groups as $groupIndex => $groupRoleIds) {
            foreach ($groupRoleIds as $rolId) {
                if (isset($considered[$rolId])) {
                    $consideredGroups[$groupIndex] = true;
                    break;
                }
            }
        }

        // Sollpositionen ermitteln: key => array(rol_id, amount, text)
        $desired = array();

        foreach ($considered as $rolId => $dummy) {
            $role = $roles[$rolId];
            if ($role['group'] !== null) {
                continue;
            }
            if (!in_array($rolId, $memberRoleIds, true)) {
                continue;
            }
            if (isset($revoked[$rolId])) {
                $result['info'][] = array('type' => 'revoked', 'role' => $role['name']);
                continue;
            }
            $booked = false;
            foreach ($yearRolePositions as $position) {
                if ($position['mfb_rol_id'] === $rolId && in_array($position['mfb_status'], array(BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED), true)) {
                    $booked = true;
                    break;
                }
            }
            if ($booked) {
                $result['info'][] = array('type' => 'collected', 'role' => $role['name']);
                continue;
            }
            $desired['role:' . $rolId] = array(
                'rol_id' => $rolId,
                'amount' => self::amount((float) $role['cost'], $floor),
                'text' => self::buildText($prefix, $role)
            );
        }

        foreach ($consideredGroups as $groupIndex => $dummy) {
            $target = null;
            foreach ($groups[$groupIndex] as $rolId) {
                if (!in_array($rolId, $memberRoleIds, true) || isset($revoked[$rolId])) {
                    continue;
                }
                if ($target === null || (float) $roles[$rolId]['cost'] > (float) $roles[$target]['cost']) {
                    $target = $rolId;
                }
            }
            $booked = 0.0;
            $bookedNames = array();
            foreach ($yearRolePositions as $position) {
                if ($position['mfb_rol_id'] !== null && in_array((int) $position['mfb_rol_id'], $groups[$groupIndex], true)
                    && in_array($position['mfb_status'], array(BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED), true)) {
                    $booked += (float) $position['mfb_amount'];
                    $bookedNames[$position['mfb_rol_name']] = true;
                }
            }
            if ($target === null) {
                continue;
            }
            $should = self::amount((float) $roles[$target]['cost'], $floor);
            $diff = round($should - $booked, 2);
            if ($diff <= 0) {
                if ($booked > 0) {
                    $result['info'][] = array('type' => 'collected', 'role' => $roles[$target]['name']);
                }
                continue;
            }
            $text = self::buildText($prefix, $roles[$target]);
            if (count($bookedNames) > 0) {
                $text .= ' (Differenz zu ' . implode(', ', array_keys($bookedNames)) . ')';
            }
            $desired['group:' . $groupIndex] = array(
                'rol_id' => $target,
                'amount' => $diff,
                'text' => $text
            );
        }

        // Positionen mit Betrag 0 entfallen
        foreach ($desired as $key => $data) {
            if (abs($data['amount']) < 0.005) {
                unset($desired[$key]);
            }
        }

        // Mindestbetrag: gilt fuer die Summe der neu zu berechnenden Positionen
        $sum = 0.0;
        foreach ($desired as $data) {
            $sum += $data['amount'];
        }
        if (count($desired) > 0 && round($sum, 2) <= $min) {
            $result['info'][] = array('type' => 'minimum', 'role' => '');
            $desired = array();
        }

        // offene Positionen den Sollpositionen zuordnen
        $openByKey = array();
        $openUnassigned = array();
        foreach ($yearRolePositions as $position) {
            if ($position['mfb_status'] !== BookingStore::STATUS_OPEN) {
                continue;
            }
            $rolId = $position['mfb_rol_id'];
            if ($rolId !== null && isset($roles[$rolId])) {
                if ($roles[$rolId]['group'] !== null) {
                    if (!isset($consideredGroups[(int) $roles[$rolId]['group']])) {
                        continue;           // Gruppe wird in diesem Lauf nicht berechnet
                    }
                    $openByKey['group:' . (int) $roles[$rolId]['group']][] = $position;
                } else {
                    if (!isset($considered[$rolId])) {
                        continue;           // Rolle wird in diesem Lauf nicht berechnet
                    }
                    $openByKey['role:' . $rolId][] = $position;
                }
            } elseif ($selectedRoleIds === null) {
                // Rolle ist keine Beitragsrolle mehr (oder wurde geloescht)
                $openUnassigned[] = $position;
            }
        }

        foreach ($desired as $key => $data) {
            $role = $roles[$data['rol_id']];
            $newData = array(
                'mfb_rol_id' => $data['rol_id'],
                'mfb_rol_name' => $role['name'],
                'mfb_amount' => $data['amount'],
                'mfb_text' => $data['text']
            );
            if (isset($openByKey[$key])) {
                $keep = array_shift($openByKey[$key]);
                if ((int) $keep['mfb_rol_id'] !== $data['rol_id'] || abs((float) $keep['mfb_amount'] - $data['amount']) >= 0.005
                    || (string) $keep['mfb_text'] !== $data['text'] || (string) $keep['mfb_rol_name'] !== $role['name']) {
                    $result['ops'][] = array('op' => 'update', 'id' => (int) $keep['mfb_id'], 'data' => $newData, 'before' => $keep);
                }
            } else {
                $result['ops'][] = array('op' => 'insert', 'data' => array_merge($newData, array(
                    'mfb_year' => $year,
                    'mfb_source' => BookingStore::SOURCE_ROLE,
                    'mfb_status' => BookingStore::STATUS_OPEN
                )));
            }
        }
        foreach ($openByKey as $key => $rest) {
            foreach ($rest as $position) {
                $result['ops'][] = array('op' => 'delete', 'id' => (int) $position['mfb_id'], 'before' => $position);
            }
        }
        foreach ($openUnassigned as $position) {
            $result['ops'][] = array('op' => 'delete', 'id' => (int) $position['mfb_id'], 'before' => $position);
        }

        $result['ops'] = self::lockInvoiced($result['ops']);
        return $result;
    }

    /**
     * Plant die Individualbeitraege eines Mitglieds.
     *
     * @param array<string, array{amount:float, text:string, rol_id:?int}> $desired Bezeichnung => Sollbetrag (nur Eintraege mit Betrag != 0)
     * @param array<int, string> $configuredKeys alle konfigurierten Bezeichnungen
     * @param array<int, array<string, mixed>> $positions alle Positionen des Mitglieds
     * @return array<int, array<string, mixed>> ops
     */
    public static function planIndividual(array $desired, array $configuredKeys, array $positions, int $year): array
    {
        $ops = array();
        $booked = array();
        $open = array();
        foreach ($positions as $position) {
            if ((int) $position['mfb_year'] !== $year || $position['mfb_source'] !== BookingStore::SOURCE_INDIVIDUAL) {
                continue;
            }
            $key = (string) $position['mfb_rol_name'];
            if (in_array($position['mfb_status'], array(BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED), true)) {
                $booked[$key] = true;
            } elseif ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
                $open[$key][] = $position;
            }
        }

        foreach ($configuredKeys as $key) {
            $key = (string) $key;
            $want = $desired[$key] ?? null;
            if (isset($booked[$key])) {
                $want = null;
            }
            $existing = $open[$key] ?? array();
            if ($want !== null && abs($want['amount']) >= 0.005) {
                $data = array(
                    'mfb_rol_id' => $want['rol_id'],
                    'mfb_rol_name' => $key,
                    'mfb_amount' => round($want['amount'], 2),
                    'mfb_text' => $want['text']
                );
                if (count($existing) > 0) {
                    $keep = array_shift($existing);
                    if (abs((float) $keep['mfb_amount'] - $data['mfb_amount']) >= 0.005 || (string) $keep['mfb_text'] !== $data['mfb_text']) {
                        $ops[] = array('op' => 'update', 'id' => (int) $keep['mfb_id'], 'data' => $data, 'before' => $keep);
                    }
                } else {
                    $ops[] = array('op' => 'insert', 'data' => array_merge($data, array(
                        'mfb_year' => $year,
                        'mfb_source' => BookingStore::SOURCE_INDIVIDUAL,
                        'mfb_status' => BookingStore::STATUS_OPEN
                    )));
                }
            }
            foreach ($existing as $position) {
                $ops[] = array('op' => 'delete', 'id' => (int) $position['mfb_id'], 'before' => $position);
            }
        }
        return self::lockInvoiced($ops);
    }

    /**
     * Positionen, die schon auf einer Rechnung stehen (mfb_invoice_no), aendert und entfernt die Neuberechnung
     * nicht, damit der Einzugsbetrag zum ausgestellten Beleg passt (docs/konzept-rechnungen-pdf.md, 5.4).
     * Solche Aenderungen werden als op "locked" gemeldet und von applyOps() nicht ausgefuehrt.
     *
     * @param array<int, array<string, mixed>> $ops
     * @return array<int, array<string, mixed>>
     */
    public static function lockInvoiced(array $ops): array
    {
        foreach ($ops as $index => $op) {
            if (in_array($op['op'], array('update', 'delete'), true) && (string) ($op['before']['mfb_invoice_no'] ?? '') !== '') {
                $ops[$index] = array('op' => 'locked', 'id' => (int) $op['id'], 'before' => $op['before'], 'data' => $op['data'] ?? null, 'change' => $op['op']);
            }
        }
        return $ops;
    }

    /**
     * Ermittelt Beitrag und Beitragstext fuer die Profilfelder aus den offenen Positionen.
     *
     * @param array<int, array<string, mixed>> $openPositions
     * @return array{fee:float, text:string}
     */
    public static function buildWorkFields(array $openPositions, string $prefix, string $separator, string $nameSuffix = ''): array
    {
        $prefix = trim($prefix);
        $fee = 0.0;
        $labels = array();
        foreach ($openPositions as $position) {
            $fee += (float) $position['mfb_amount'];
            $label = trim((string) $position['mfb_text']);
            if ($prefix !== '' && strpos($label, $prefix) === 0) {
                $label = trim(substr($label, strlen($prefix)));
            }
            if ($label === '') {
                $label = trim((string) $position['mfb_rol_name']);
            }
            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }
        $separator = ($separator === '') ? ' ' : $separator;
        $text = trim($prefix . ' ' . implode($separator, $labels) . $nameSuffix);
        $text = preg_replace('/\s{2,}/', ' ', $text);

        return array('fee' => round($fee, 2), 'text' => (string) $text);
    }

    /**
     * Ermittelt die Beitragsgruppe je Rolle aus der Konfiguration.
     *
     * @param array<int, string> $names Gruppennamen
     * @param array<int, string> $roleLists je Gruppe die rol_ids, durch Komma getrennt
     * @return array<int, int> rol_id => Gruppenindex
     */
    public static function groupIndexByRole(array $names, array $roleLists): array
    {
        $map = array();
        foreach ($names as $index => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            foreach (explode(',', (string) ($roleLists[$index] ?? '')) as $rolId) {
                $rolId = (int) trim($rolId);
                if ($rolId > 0 && !isset($map[$rolId])) {
                    $map[$rolId] = (int) $index;
                }
            }
        }
        return $map;
    }

    /**
     * @param array{name:string, description:string} $role
     */
    private static function buildText(string $prefix, array $role): string
    {
        $label = trim((string) $role['description']) !== '' ? trim((string) $role['description']) : trim((string) $role['name']);
        return trim($prefix . ' ' . $label);
    }

    private static function amount(float $amount, bool $floor): float
    {
        $amount = round($amount, 2);
        return $floor ? (float) floor($amount) : $amount;
    }
}
