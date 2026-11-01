<?php
/**
 ***********************************************************************************************
 * Geleistete Arbeitsstunden (Arbeitsdienst) des Admidio-Plugins Mitgliedsbeitrag
 *
 * Tabelle adm_plugin_membershipfee_workhours: ein Eintrag je Mitglied und Einsatz.
 * Das Leistungsjahr ist das Jahr des Einsatzdatums.
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
    /** Spalten, die ueber insert()/update() gesetzt werden duerfen */
    private const COLUMNS = array('mfw_usr_id', 'mfw_date', 'mfw_hours', 'mfw_activity', 'mfw_comment');

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
                    mfw_usr_id_create      integer unsigned,
                    mfw_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfw_usr_id_change      integer unsigned,
                    mfw_timestamp_change   timestamp     NULL DEFAULT NULL,
                    PRIMARY KEY (mfw_id)
                ) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci');
            }
            $gDb->queryPrepared('CREATE INDEX ' . self::table() . '_idx_year ON ' . self::table() . ' (mfw_org_id, mfw_year, mfw_usr_id)');
        }
        self::$tableChecked = true;
    }

    /**
     * Prueft und normalisiert einen Eintrag (Datum Y-m-d, Stunden > 0 mit hoechstens zwei Nachkommastellen).
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
            if ($hours <= 0 || $hours > 1000) {
                throw new InvalidArgumentException('Die Stunden muessen groesser als 0 sein.');
            }
            $data['mfw_hours'] = $hours;
        }
        if (isset($data['mfw_activity'])) {
            $data['mfw_activity'] = mb_substr(trim((string) $data['mfw_activity']), 0, 255);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data mfw_usr_id, mfw_date, mfw_hours, mfw_activity, mfw_comment
     */
    public static function insert(array $data): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        if (!isset($data['mfw_usr_id'], $data['mfw_date'], $data['mfw_hours'])) {
            throw new InvalidArgumentException('Mitglied, Datum und Stunden sind Pflicht.');
        }
        $data = self::validate($data);

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
     * Summe der geleisteten Stunden je Mitglied im Leistungsjahr.
     * @return array<int, float> usr_id => Stunden
     */
    public static function sumByUser(int $year): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $sums = array();
        $statement = $gDb->queryPrepared('SELECT mfw_usr_id, SUM(mfw_hours) AS hours FROM ' . self::table() . '
            WHERE mfw_org_id = ? AND mfw_year = ? GROUP BY mfw_usr_id', array((int) $gCurrentOrgId, $year));
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
