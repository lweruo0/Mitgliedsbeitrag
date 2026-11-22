<?php
/**
 ***********************************************************************************************
 * Geleistete Arbeitsstunden (Arbeitsdienst) des Admidio-Plugins Mitgliedsbeitrag
 *
 * Tabelle adm_plugin_membershipfee_workhours: ein Eintrag je Mitglied und Einsatz.
 * Das Leistungsjahr ist das Jahr des Einsatzdatums.
 *
 * Status eines Eintrags (ab Version 6.10.0): angemeldet, teilgenommen (Standard), entschuldigt, unentschuldigt.
 * Nur "teilgenommen" hat Stunden (> 0) und zaehlt als geleistet; die anderen Status haben immer 0 Stunden.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

use InvalidArgumentException;

class WorkHoursStore
{
    public const STATUS_REGISTERED = 'angemeldet';
    public const STATUS_ATTENDED = 'teilgenommen';
    public const STATUS_EXCUSED = 'entschuldigt';
    public const STATUS_UNEXCUSED = 'unentschuldigt';

    /** Alle Status in Anzeigereihenfolge */
    public const STATUSES = array(self::STATUS_REGISTERED, self::STATUS_ATTENDED, self::STATUS_EXCUSED, self::STATUS_UNEXCUSED);

    /** Spalten, die ueber insert()/update() gesetzt werden duerfen */
    private const COLUMNS = array('mfw_usr_id', 'mfw_date', 'mfw_hours', 'mfw_activity', 'mfw_comment', 'mfw_status');

    private static bool $tableChecked = false;

    public static function table(): string
    {
        return TABLE_PREFIX . '_plugin_membershipfee_workhours';
    }

    /**
     * Legt die Tabelle an, falls sie noch nicht existiert.
     */
    public static function ensureTable(): void
    {
        global $gDb;

        if (self::$tableChecked) {
            return;
        }
        if (!self::tableExists()) {
            if (defined('DB_ENGINE') && DB_ENGINE === 'pgsql') {
                $gDb->queryPrepared('CREATE TABLE ' . self::table() . ' (
                    mfw_id                 serial        PRIMARY KEY,
                    mfw_org_id             integer       NOT NULL,
                    mfw_usr_id             integer       NOT NULL,
                    mfw_year               integer       NOT NULL,
                    mfw_date               date          NOT NULL,
                    mfw_hours              decimal(6,2)  NOT NULL DEFAULT 0,
                    mfw_activity           varchar(255)  NOT NULL DEFAULT \'\',
                    mfw_comment            text,
                    mfw_status             varchar(20)   NOT NULL DEFAULT \'teilgenommen\',
                    mfw_usr_id_create      integer,
                    mfw_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfw_usr_id_change      integer,
                    mfw_timestamp_change   timestamp
                )');
            } else {
                $gDb->queryPrepared('CREATE TABLE ' . self::table() . ' (
                    mfw_id                 integer unsigned NOT NULL AUTO_INCREMENT,
                    mfw_org_id             integer unsigned NOT NULL,
                    mfw_usr_id             integer unsigned NOT NULL,
                    mfw_year               integer       NOT NULL,
                    mfw_date               date          NOT NULL,
                    mfw_hours              decimal(6,2)  NOT NULL DEFAULT 0,
                    mfw_activity           varchar(255)  NOT NULL DEFAULT \'\',
                    mfw_comment            text,
                    mfw_status             varchar(20)   NOT NULL DEFAULT \'teilgenommen\',
                    mfw_usr_id_create      integer unsigned,
                    mfw_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfw_usr_id_change      integer unsigned,
                    mfw_timestamp_change   timestamp     NULL DEFAULT NULL,
                    PRIMARY KEY (mfw_id)
                ) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci');
            }
            $gDb->queryPrepared('CREATE INDEX ' . self::table() . '_idx_year ON ' . self::table() . ' (mfw_org_id, mfw_year, mfw_usr_id)');
        }
        // Version 6.10.0: Status nachruesten; vorhandene Eintraege gelten als teilgenommen
        if ($gDb->queryPrepared('SELECT mfw_status FROM ' . self::table() . ' WHERE 1 = 0', array(), false) === false) {
            $gDb->queryPrepared('ALTER TABLE ' . self::table() . ' ADD mfw_status varchar(20) NOT NULL DEFAULT \'' . self::STATUS_ATTENDED . '\'');
        }
        self::$tableChecked = true;
    }

    /**
     * Prueft und normalisiert die Felder eines Eintrags (Datum Y-m-d, Stunden 0 bis 1000 mit hoechstens zwei
     * Nachkommastellen, Status aus STATUSES). Ob die Stunden zum Status passen, prueft applyStatusRules().
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function validate(array $data): array
    {
        if (isset($data['mfw_date'])) {
            $date = \DateTime::createFromFormat('!Y-m-d', (string) $data['mfw_date']);
            if ($date === false || $date->format('Y-m-d') !== (string) $data['mfw_date']) {
                throw new InvalidArgumentException('Ungueltiges Datum: ' . $data['mfw_date']);
            }
        }
        if (isset($data['mfw_hours'])) {
            $hours = round((float) str_replace(',', '.', (string) $data['mfw_hours']), 2);
            if ($hours < 0 || $hours > 1000) {
                throw new InvalidArgumentException('Die Stunden muessen zwischen 0 und 1000 liegen.');
            }
            $data['mfw_hours'] = $hours;
        }
        if (isset($data['mfw_status'])) {
            $status = mb_strtolower(trim((string) $data['mfw_status']));
            if (!in_array($status, self::STATUSES, true)) {
                throw new InvalidArgumentException('Ungueltiger Status: ' . $data['mfw_status'] . ' (erlaubt: ' . implode(', ', self::STATUSES) . ')');
            }
            $data['mfw_status'] = $status;
        }
        if (isset($data['mfw_activity'])) {
            $data['mfw_activity'] = mb_substr(trim((string) $data['mfw_activity']), 0, 255);
        }
        return $data;
    }

    /**
     * Stunden und Status in Einklang bringen (nach validate()).
     *
     * Der wirksame Status ist der uebergebene, sonst der des vorhandenen Eintrags, sonst "teilgenommen".
     * - teilgenommen: Stunden > 0 sind Pflicht (uebergeben oder vorhanden).
     * - sonst: Stunden sind 0; uebergebene Stunden > 0 sind ein Fehler.
     *
     * @param array<string, mixed> $data validierte Eingabe
     * @param array<string, mixed>|null $existing vorhandener Eintrag beim Aendern
     * @return array<string, mixed> Eingabe mit mfw_status und ggf. mfw_hours = 0
     */
    public static function applyStatusRules(array $data, ?array $existing = null): array
    {
        $status = (string) ($data['mfw_status'] ?? ($existing['mfw_status'] ?? self::STATUS_ATTENDED));
        $hoursGiven = array_key_exists('mfw_hours', $data) && $data['mfw_hours'] !== null && $data['mfw_hours'] !== '';
        $hours = $hoursGiven ? (float) $data['mfw_hours'] : (isset($existing['mfw_hours']) ? (float) $existing['mfw_hours'] : null);

        if ($status === self::STATUS_ATTENDED) {
            if ($hours === null || $hours <= 0) {
                throw new InvalidArgumentException('Die Stunden muessen groesser als 0 sein.');
            }
        } else {
            if ($hoursGiven && $hours > 0) {
                throw new InvalidArgumentException('Stunden gibt es nur beim Status teilgenommen (Status: ' . $status . ').');
            }
            $data['mfw_hours'] = 0.0;
        }
        $data['mfw_status'] = $status;
        return $data;
    }

    /**
     * Zaehlen die Stunden des Eintrags als geleistet? (nur Status teilgenommen)
     *
     * @param array<string, mixed> $entry Zeile aus dem Store
     */
    public static function counts(array $entry): bool
    {
        return (string) ($entry['mfw_status'] ?? self::STATUS_ATTENDED) === self::STATUS_ATTENDED;
    }

    /**
     * Geleistete Stunden des Eintrags (0 bei allen Status ausser teilgenommen).
     *
     * @param array<string, mixed> $entry Zeile aus dem Store
     */
    public static function doneHours(array $entry): float
    {
        return self::counts($entry) ? round((float) ($entry['mfw_hours'] ?? 0), 2) : 0.0;
    }

    /**
     * @param array<string, mixed> $data mfw_usr_id, mfw_date, mfw_hours, mfw_activity, mfw_comment, mfw_status
     */
    public static function insert(array $data): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        if (!isset($data['mfw_usr_id'], $data['mfw_date'])) {
            throw new InvalidArgumentException('Mitglied und Datum sind Pflicht.');
        }
        $data = self::applyStatusRules(self::validate($data));

        $columns = array('mfw_org_id', 'mfw_year', 'mfw_usr_id_create', 'mfw_timestamp_create');
        $values = array((int) $gCurrentOrgId, (int) substr((string) $data['mfw_date'], 0, 4), self::currentUserId(), DATETIME_NOW);
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $columns[] = $column;
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $gDb->queryPrepared('INSERT INTO ' . self::table() . ' (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')', $values);
        return (int) $gDb->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $data = self::validate($data);
        if (array_key_exists('mfw_hours', $data) || array_key_exists('mfw_status', $data)) {
            $data = self::applyStatusRules($data, self::find($id));
        }
        $sets = array('mfw_usr_id_change = ?', 'mfw_timestamp_change = ?');
        $values = array(self::currentUserId(), DATETIME_NOW);
        if (isset($data['mfw_date'])) {
            $sets[] = 'mfw_year = ?';
            $values[] = (int) substr((string) $data['mfw_date'], 0, 4);
        }
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = $column . ' = ?';
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $values[] = $id;
        $values[] = (int) $gCurrentOrgId;
        $gDb->queryPrepared('UPDATE ' . self::table() . ' SET ' . implode(', ', $sets) . ' WHERE mfw_id = ? AND mfw_org_id = ?', $values);
    }

    public static function delete(int $id): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $gDb->queryPrepared('DELETE FROM ' . self::table() . ' WHERE mfw_id = ? AND mfw_org_id = ?', array($id, (int) $gCurrentOrgId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $row = $gDb->queryPrepared('SELECT * FROM ' . self::table() . ' WHERE mfw_id = ? AND mfw_org_id = ?', array($id, (int) $gCurrentOrgId))->fetch();
        return $row ? self::normalize($row) : null;
    }

    /**
     * Eintraege eines Leistungsjahres (optional nur eines Mitglieds), nach Datum.
     * @return array<int, array<string, mixed>>
     */
    public static function getByYear(int $year, ?int $usrId = null): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $sql = 'SELECT * FROM ' . self::table() . ' WHERE mfw_org_id = ? AND mfw_year = ?';
        $params = array((int) $gCurrentOrgId, $year);
        if ($usrId !== null) {
            $sql .= ' AND mfw_usr_id = ?';
            $params[] = $usrId;
        }
        $sql .= ' ORDER BY mfw_date, mfw_id';

        $rows = array();
        $statement = $gDb->queryPrepared($sql, $params);
        while ($row = $statement->fetch()) {
            $rows[] = self::normalize($row);
        }
        return $rows;
    }

    /**
     * Alle Eintraege eines Mitglieds, neueste zuerst (Profil-Registerkarte).
     * @return array<int, array<string, mixed>>
     */
    public static function getByUser(int $usrId): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $rows = array();
        $statement = $gDb->queryPrepared('SELECT * FROM ' . self::table() . ' WHERE mfw_org_id = ? AND mfw_usr_id = ? ORDER BY mfw_date DESC, mfw_id DESC', array((int) $gCurrentOrgId, $usrId));
        while ($row = $statement->fetch()) {
            $rows[] = self::normalize($row);
        }
        return $rows;
    }

    /**
     * Summe der geleisteten Stunden (Status teilgenommen) je Mitglied im Leistungsjahr.
     * @return array<int, float> usr_id => Stunden
     */
    public static function sumByUser(int $year): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $sums = array();
        $statement = $gDb->queryPrepared('SELECT mfw_usr_id, SUM(mfw_hours) AS hours FROM ' . self::table() . '
            WHERE mfw_org_id = ? AND mfw_year = ? AND mfw_status = ? GROUP BY mfw_usr_id', array((int) $gCurrentOrgId, $year, self::STATUS_ATTENDED));
        while ($row = $statement->fetch()) {
            $sums[(int) $row['mfw_usr_id']] = round((float) $row['hours'], 2);
        }
        return $sums;
    }

    /**
     * Jahre mit Eintraegen, neueste zuerst.
     * @return array<int, int>
     */
    public static function getYears(): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $years = array();
        $statement = $gDb->queryPrepared('SELECT DISTINCT mfw_year FROM ' . self::table() . ' WHERE mfw_org_id = ? ORDER BY mfw_year DESC', array((int) $gCurrentOrgId));
        while ($row = $statement->fetch()) {
            $years[] = (int) $row['mfw_year'];
        }
        return $years;
    }

    /**
     * Entfernt die Eintraege der aktuellen Organisation; die Tabelle wird geloescht, wenn sie danach leer ist.
     * @return bool true, wenn die Tabelle geloescht wurde
     */
    public static function removeDataOfCurrentOrganization(): bool
    {
        global $gDb, $gCurrentOrgId;

        if (!self::tableExists()) {
            return false;
        }
        $gDb->queryPrepared('DELETE FROM ' . self::table() . ' WHERE mfw_org_id = ?', array((int) $gCurrentOrgId));
        if ((int) $gDb->queryPrepared('SELECT COUNT(*) FROM ' . self::table())->fetchColumn() === 0) {
            $gDb->queryPrepared('DROP TABLE ' . self::table());
            self::$tableChecked = false;
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        foreach (array('mfw_id', 'mfw_org_id', 'mfw_usr_id', 'mfw_year') as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }
        $row['mfw_hours'] = round((float) ($row['mfw_hours'] ?? 0), 2);
        $row['mfw_status'] = (string) ($row['mfw_status'] ?? '') !== '' ? (string) $row['mfw_status'] : self::STATUS_ATTENDED;
        return $row;
    }

    private static function prepareValue(string $column, mixed $value): mixed
    {
        if ($column === 'mfw_hours') {
            return number_format(round((float) $value, 2), 2, '.', '');
        }
        if ($column === 'mfw_usr_id') {
            return (int) $value;
        }
        if ($column === 'mfw_comment') {
            return trim((string) $value) === '' ? null : (string) $value;
        }
        return $value;
    }

    private static function tableExists(): bool
    {
        global $gDb;
        return $gDb->queryPrepared('SELECT 1 FROM ' . self::table() . ' WHERE 1 = 0', array(), false) !== false;
    }

    private static function currentUserId(): ?int
    {
        if (isset($GLOBALS['gCurrentUser']) && is_object($GLOBALS['gCurrentUser'])) {
            $id = (int) $GLOBALS['gCurrentUser']->getValue('usr_id');
            return $id > 0 ? $id : null;
        }
        return null;
    }
}
