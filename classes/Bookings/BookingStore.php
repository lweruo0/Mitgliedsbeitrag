<?php
/**
 ***********************************************************************************************
 * Datenzugriff fuer die Buchungshistorie des Admidio-Plugins Mitgliedsbeitrag
 *
 * Zwei Tabellen:
 *  - <prefix>_plugin_membershipfee_bookings : eine Zeile je Buchungsposition
 *                                             (Mitglied + Beitragsrolle/Bezeichnung + Beitragsjahr)
 *  - <prefix>_plugin_membershipfee_runs     : eine Zeile je erzeugter SEPA-XML-Datei (Einzug)
 *  - <prefix>_plugin_membershipfee_files    : Inhalt der SEPA-XML-Datei eines Einzugs (nur aktuelles
 *                                             Beitragsjahr) und ein evtl. geteilter Download-Link
 *
 * Die Tabellen werden bei Bedarf automatisch angelegt (MySQL/MariaDB und PostgreSQL).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Bookings;

class BookingStore
{
    public const STATUS_OPEN = 'offen';
    public const STATUS_EXPORTED = 'exportiert';
    public const STATUS_COLLECTED = 'eingezogen';
    public const STATUS_CANCELLED = 'storniert';

    public const SOURCE_ROLE = 'rolle';
    public const SOURCE_INDIVIDUAL = 'individual';
    public const SOURCE_MANUAL = 'manuell';

    public const METHOD_SEPA = 'SEPA';
    public const METHOD_MANUAL = 'MAN';

    public const RUN_CREATED = 'erstellt';
    public const RUN_COLLECTED = 'eingezogen';
    public const RUN_DISCARDED = 'verworfen';

    /** Format historischer Einzuege aus einem Datenimport (z. B. Winner) */
    public const RUN_FORMAT_IMPORT = 'import';

    /** Spalten, die ueber insert()/update() gesetzt werden duerfen */
    private const BOOKING_COLUMNS = array(
        'mfb_usr_id', 'mfb_year', 'mfb_source', 'mfb_rol_id', 'mfb_rol_name', 'mfb_amount', 'mfb_text',
        'mfb_status', 'mfb_method', 'mfb_run_id', 'mfb_due_date', 'mfb_booking_date', 'mfb_reference',
        'mfb_mandate_id', 'mfb_sequence_type', 'mfb_orig_mandate_id', 'mfb_orig_iban', 'mfb_orig_debtor_agent',
        'mfb_storno_date', 'mfb_storno_reason', 'mfb_role_revoked', 'mfb_comment', 'mfb_import_ref'
    );

    private const RUN_COLUMNS = array(
        'mfr_file_name', 'mfr_msg_id', 'mfr_format', 'mfr_due_dates', 'mfr_count', 'mfr_amount',
        'mfr_status', 'mfr_paid_date', 'mfr_kind'
    );

    private static bool $tablesChecked = false;

    public static function bookingsTable(): string
    {
        return TABLE_PREFIX . '_plugin_membershipfee_bookings';
    }

    public static function runsTable(): string
    {
        return TABLE_PREFIX . '_plugin_membershipfee_runs';
    }

    public static function filesTable(): string
    {
        return TABLE_PREFIX . '_plugin_membershipfee_files';
    }

    /**
     * Legt die beiden Tabellen an, falls sie noch nicht existieren.
     */
    public static function ensureTables(): void
    {
        global $gDb;

        if (self::$tablesChecked) {
            return;
        }

        $isPgsql = (defined('DB_ENGINE') && DB_ENGINE === 'pgsql');

        if (!self::tableExists(self::bookingsTable())) {
            if ($isPgsql) {
                $sql = 'CREATE TABLE ' . self::bookingsTable() . ' (
                    mfb_id                 serial        PRIMARY KEY,
                    mfb_org_id             integer       NOT NULL,
                    mfb_usr_id             integer       NOT NULL,
                    mfb_year               integer       NOT NULL,
                    mfb_source             varchar(10)   NOT NULL,
                    mfb_rol_id             integer,
                    mfb_rol_name           varchar(100)  NOT NULL DEFAULT \'\',
                    mfb_amount             decimal(10,2) NOT NULL DEFAULT 0,
                    mfb_text               varchar(140)  NOT NULL DEFAULT \'\',
                    mfb_status             varchar(20)   NOT NULL,
                    mfb_method             varchar(10),
                    mfb_run_id             integer,
                    mfb_due_date           date,
                    mfb_booking_date       date,
                    mfb_reference          varchar(35),
                    mfb_mandate_id         varchar(35),
                    mfb_sequence_type      varchar(4),
                    mfb_orig_mandate_id    varchar(35),
                    mfb_orig_iban          varchar(34),
                    mfb_orig_debtor_agent  varchar(35),
                    mfb_storno_date        date,
                    mfb_storno_reason      varchar(20),
                    mfb_role_revoked       smallint      NOT NULL DEFAULT 0,
                    mfb_comment            text,
                    mfb_import_ref         varchar(50),
                    mfb_usr_id_create      integer,
                    mfb_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfb_usr_id_change      integer,
                    mfb_timestamp_change   timestamp
                )';
                $gDb->queryPrepared($sql);
                $gDb->queryPrepared('CREATE INDEX ' . self::bookingsTable() . '_idx_usr ON ' . self::bookingsTable() . ' (mfb_org_id, mfb_usr_id, mfb_year, mfb_rol_id)');
                $gDb->queryPrepared('CREATE INDEX ' . self::bookingsTable() . '_idx_run ON ' . self::bookingsTable() . ' (mfb_run_id)');
                $gDb->queryPrepared('CREATE INDEX ' . self::bookingsTable() . '_idx_import ON ' . self::bookingsTable() . ' (mfb_import_ref)');
            } else {
                $sql = 'CREATE TABLE ' . self::bookingsTable() . ' (
                    mfb_id                 integer unsigned NOT NULL AUTO_INCREMENT,
                    mfb_org_id             integer unsigned NOT NULL,
                    mfb_usr_id             integer unsigned NOT NULL,
                    mfb_year               integer       NOT NULL,
                    mfb_source             varchar(10)   NOT NULL,
                    mfb_rol_id             integer unsigned,
                    mfb_rol_name           varchar(100)  NOT NULL DEFAULT \'\',
                    mfb_amount             decimal(10,2) NOT NULL DEFAULT 0,
                    mfb_text               varchar(140)  NOT NULL DEFAULT \'\',
                    mfb_status             varchar(20)   NOT NULL,
                    mfb_method             varchar(10),
                    mfb_run_id             integer unsigned,
                    mfb_due_date           date,
                    mfb_booking_date       date,
                    mfb_reference          varchar(35),
                    mfb_mandate_id         varchar(35),
                    mfb_sequence_type      varchar(4),
                    mfb_orig_mandate_id    varchar(35),
                    mfb_orig_iban          varchar(34),
                    mfb_orig_debtor_agent  varchar(35),
                    mfb_storno_date        date,
                    mfb_storno_reason      varchar(20),
                    mfb_role_revoked       smallint      NOT NULL DEFAULT 0,
                    mfb_comment            text,
                    mfb_import_ref         varchar(50),
                    mfb_usr_id_create      integer unsigned,
                    mfb_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfb_usr_id_change      integer unsigned,
                    mfb_timestamp_change   timestamp     NULL DEFAULT NULL,
                    PRIMARY KEY (mfb_id),
                    INDEX idx_mfb_usr (mfb_org_id, mfb_usr_id, mfb_year, mfb_rol_id),
                    INDEX idx_mfb_run (mfb_run_id),
                    INDEX idx_mfb_import (mfb_import_ref)
                ) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci';
                $gDb->queryPrepared($sql);
            }
        }

        if (!self::tableExists(self::runsTable())) {
            if ($isPgsql) {
                $sql = 'CREATE TABLE ' . self::runsTable() . ' (
                    mfr_id                 serial        PRIMARY KEY,
                    mfr_org_id             integer       NOT NULL,
                    mfr_file_name          varchar(255)  NOT NULL DEFAULT \'\',
                    mfr_msg_id             varchar(35)   NOT NULL DEFAULT \'\',
                    mfr_format             varchar(20)   NOT NULL DEFAULT \'\',
                    mfr_due_dates          varchar(255)  NOT NULL DEFAULT \'\',
                    mfr_count              integer       NOT NULL DEFAULT 0,
                    mfr_amount             decimal(10,2) NOT NULL DEFAULT 0,
                    mfr_status             varchar(20)   NOT NULL,
                    mfr_paid_date          date,
                    mfr_usr_id_create      integer,
                    mfr_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfr_usr_id_change      integer,
                    mfr_timestamp_change   timestamp
                )';
            } else {
                $sql = 'CREATE TABLE ' . self::runsTable() . ' (
                    mfr_id                 integer unsigned NOT NULL AUTO_INCREMENT,
                    mfr_org_id             integer unsigned NOT NULL,
                    mfr_file_name          varchar(255)  NOT NULL DEFAULT \'\',
                    mfr_msg_id             varchar(35)   NOT NULL DEFAULT \'\',
                    mfr_format             varchar(20)   NOT NULL DEFAULT \'\',
                    mfr_due_dates          varchar(255)  NOT NULL DEFAULT \'\',
                    mfr_count              integer       NOT NULL DEFAULT 0,
                    mfr_amount             decimal(10,2) NOT NULL DEFAULT 0,
                    mfr_status             varchar(20)   NOT NULL,
                    mfr_paid_date          date,
                    mfr_usr_id_create      integer unsigned,
                    mfr_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfr_usr_id_change      integer unsigned,
                    mfr_timestamp_change   timestamp     NULL DEFAULT NULL,
                    PRIMARY KEY (mfr_id)
                ) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci';
            }
            $gDb->queryPrepared($sql);
        }

        if (!self::tableExists(self::filesTable())) {
            if ($isPgsql) {
                $sql = 'CREATE TABLE ' . self::filesTable() . ' (
                    mff_id                 serial        PRIMARY KEY,
                    mff_org_id             integer       NOT NULL,
                    mff_run_id             integer       NOT NULL,
                    mff_kind               varchar(10)   NOT NULL DEFAULT \'xml\',
                    mff_year               integer       NOT NULL,
                    mff_file_name          varchar(255)  NOT NULL DEFAULT \'\',
                    mff_content            text          NOT NULL,
                    mff_size               integer       NOT NULL DEFAULT 0,
                    mff_share_hash         varchar(64),
                    mff_share_expires      timestamp,
                    mff_share_email        varchar(255),
                    mff_share_usr_id       integer,
                    mff_share_timestamp    timestamp,
                    mff_downloads          integer       NOT NULL DEFAULT 0,
                    mff_last_download      timestamp,
                    mff_usr_id_create      integer,
                    mff_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP
                )';
                $gDb->queryPrepared($sql);
                $gDb->queryPrepared('CREATE INDEX ' . self::filesTable() . '_idx_run ON ' . self::filesTable() . ' (mff_org_id, mff_run_id)');
                $gDb->queryPrepared('CREATE INDEX ' . self::filesTable() . '_idx_hash ON ' . self::filesTable() . ' (mff_share_hash)');
            } else {
                $sql = 'CREATE TABLE ' . self::filesTable() . ' (
                    mff_id                 integer unsigned NOT NULL AUTO_INCREMENT,
                    mff_org_id             integer unsigned NOT NULL,
                    mff_run_id             integer unsigned NOT NULL,
                    mff_kind               varchar(10)   NOT NULL DEFAULT \'xml\',
                    mff_year               integer       NOT NULL,
                    mff_file_name          varchar(255)  NOT NULL DEFAULT \'\',
                    mff_content            mediumtext    NOT NULL,
                    mff_size               integer       NOT NULL DEFAULT 0,
                    mff_share_hash         varchar(64),
                    mff_share_expires      timestamp     NULL DEFAULT NULL,
                    mff_share_email        varchar(255),
                    mff_share_usr_id       integer unsigned,
                    mff_share_timestamp    timestamp     NULL DEFAULT NULL,
                    mff_downloads          integer       NOT NULL DEFAULT 0,
                    mff_last_download      timestamp     NULL DEFAULT NULL,
                    mff_usr_id_create      integer unsigned,
                    mff_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (mff_id),
                    INDEX idx_mff_run (mff_org_id, mff_run_id),
                    INDEX idx_mff_hash (mff_share_hash)
                ) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci';
                $gDb->queryPrepared($sql);
            }
        }

        // Version 6.3.0: Herkunft importierter Positionen (z. B. WINNER-1140) nachruesten
        if ($gDb->queryPrepared('SELECT mfb_import_ref FROM ' . self::bookingsTable() . ' WHERE 1 = 0', array(), false) === false) {
            $gDb->queryPrepared('ALTER TABLE ' . self::bookingsTable() . ' ADD mfb_import_ref varchar(50)');
        }

        // Version 6.4.0: Buchungskreis des Einzugs (mf = Mitgliedsbeitrag, ad = Arbeitsdienst) nachruesten
        if ($gDb->queryPrepared('SELECT mfr_kind FROM ' . self::runsTable() . ' WHERE 1 = 0', array(), false) === false) {
            $gDb->queryPrepared('ALTER TABLE ' . self::runsTable() . ' ADD mfr_kind varchar(10) NOT NULL DEFAULT \'mf\'');
        }

        // Version 6.2.0 (erste Fassung): Spalte fuer die Art der Datei (xml = SEPA-Datei, ctl = Kontrolldatei) nachruesten
        if ($gDb->queryPrepared('SELECT mff_kind FROM ' . self::filesTable() . ' WHERE 1 = 0', array(), false) === false) {
            $gDb->queryPrepared('ALTER TABLE ' . self::filesTable() . ' ADD mff_kind varchar(10) NOT NULL DEFAULT \'xml\'');
        }

        self::$tablesChecked = true;
    }

    /**
     * Entfernt die Tabellen (nur, wenn sie keine Daten mehr enthalten) bzw. die Daten der aktuellen Organisation.
     * @return bool true, wenn die Tabellen geloescht wurden
     */
    public static function removeDataOfCurrentOrganization(): bool
    {
        global $gDb, $gCurrentOrgId;

        $dropped = false;
        foreach (array(self::bookingsTable() => 'mfb', self::runsTable() => 'mfr', self::filesTable() => 'mff') as $table => $prefix) {
            if (!self::tableExists($table)) {
                continue;
            }
            $gDb->queryPrepared('DELETE FROM ' . $table . ' WHERE ' . $prefix . '_org_id = ?', array((int) $gCurrentOrgId));
            $count = (int) $gDb->queryPrepared('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
            if ($count === 0) {
                $gDb->queryPrepared('DROP TABLE ' . $table);
                $dropped = true;
            }
        }
        return $dropped;
    }

    // ------------------------------------------------------------------
    // Buchungspositionen
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $bookingId): ?array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $row = $gDb->queryPrepared(
            'SELECT * FROM ' . self::bookingsTable() . ' WHERE mfb_id = ? AND mfb_org_id = ?',
            array($bookingId, (int) $gCurrentOrgId)
        )->fetch();

        return $row ? self::normalizeBooking($row) : null;
    }

    /**
     * Liefert alle Positionen eines Mitglieds.
     * @param array<int, string> $status optional nur diese Status
     * @return array<int, array<string, mixed>>
     */
    public static function getByUser(int $userId, array $status = array(), ?int $year = null): array
    {
        $result = self::getByUsers(array($userId), $status, $year);
        return $result[$userId] ?? array();
    }

    /**
     * Liefert Positionen mehrerer Mitglieder, gruppiert nach usr_id.
     * @param array<int, int> $userIds leer = alle Mitglieder
     * @param array<int, string> $status
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function getByUsers(array $userIds, array $status = array(), ?int $year = null): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $sql = 'SELECT * FROM ' . self::bookingsTable() . ' WHERE mfb_org_id = ?';
        $params = array((int) $gCurrentOrgId);

        if (count($userIds) > 0) {
            $userIds = array_values(array_unique(array_map('intval', $userIds)));
            $sql .= ' AND mfb_usr_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
            $params = array_merge($params, $userIds);
        }
        if (count($status) > 0) {
            $sql .= ' AND mfb_status IN (' . implode(',', array_fill(0, count($status), '?')) . ')';
            $params = array_merge($params, array_values($status));
        }
        if ($year !== null) {
            $sql .= ' AND mfb_year = ?';
            $params[] = $year;
        }
        $sql .= ' ORDER BY mfb_usr_id, mfb_id';

        $result = array();
        $statement = $gDb->queryPrepared($sql, $params);
        while ($row = $statement->fetch()) {
            $row = self::normalizeBooking($row);
            $result[$row['mfb_usr_id']][] = $row;
        }
        return $result;
    }

    /**
     * Gefilterte Liste fuer die Gesamtuebersicht.
     * @param array<string, mixed> $filter year, status, method, rol_name, usr_ids
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $filter): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $sql = 'SELECT b.*, r.mfr_file_name
                  FROM ' . self::bookingsTable() . ' b
             LEFT JOIN ' . self::runsTable() . ' r ON r.mfr_id = b.mfb_run_id
                 WHERE b.mfb_org_id = ?';
        $params = array((int) $gCurrentOrgId);

        if (!empty($filter['year'])) {
            $sql .= ' AND b.mfb_year = ?';
            $params[] = (int) $filter['year'];
        }
        if (!empty($filter['status'])) {
            $sql .= ' AND b.mfb_status = ?';
            $params[] = (string) $filter['status'];
        }
        if (!empty($filter['method'])) {
            $sql .= ' AND b.mfb_method = ?';
            $params[] = (string) $filter['method'];
        }
        if (!empty($filter['rol_name'])) {
            $sql .= ' AND b.mfb_rol_name = ?';
            $params[] = (string) $filter['rol_name'];
        }
        if (!empty($filter['run_id'])) {
            $sql .= ' AND b.mfb_run_id = ?';
            $params[] = (int) $filter['run_id'];
        }
        if (isset($filter['usr_ids']) && is_array($filter['usr_ids'])) {
            if (count($filter['usr_ids']) === 0) {
                return array();
            }
            $ids = array_values(array_map('intval', $filter['usr_ids']));
            $sql .= ' AND b.mfb_usr_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $sql .= ' ORDER BY b.mfb_year DESC, b.mfb_usr_id, b.mfb_id';

        $result = array();
        $statement = $gDb->queryPrepared($sql, $params);
        while ($row = $statement->fetch()) {
            $result[] = self::normalizeBooking($row);
        }
        return $result;
    }

    /**
     * Liefert die vorhandenen Beitragsjahre und Bezeichnungen (fuer Filter).
     * @return array{years: array<int,int>, names: array<int,string>}
     */
    public static function getFilterValues(): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $years = array();
        $statement = $gDb->queryPrepared('SELECT DISTINCT mfb_year FROM ' . self::bookingsTable() . ' WHERE mfb_org_id = ? ORDER BY mfb_year DESC', array((int) $gCurrentOrgId));
        while ($row = $statement->fetch()) {
            $years[] = (int) $row['mfb_year'];
        }
        $names = array();
        $statement = $gDb->queryPrepared('SELECT DISTINCT mfb_rol_name FROM ' . self::bookingsTable() . ' WHERE mfb_org_id = ? ORDER BY mfb_rol_name', array((int) $gCurrentOrgId));
        while ($row = $statement->fetch()) {
            $names[] = (string) $row['mfb_rol_name'];
        }
        return array('years' => $years, 'names' => $names);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function insert(array $data): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $columns = array('mfb_org_id', 'mfb_usr_id_create', 'mfb_timestamp_create');
        $values = array((int) $gCurrentOrgId, self::currentUserId(), DATETIME_NOW);

        foreach (self::BOOKING_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $columns[] = $column;
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }

        $sql = 'INSERT INTO ' . self::bookingsTable() . ' (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $gDb->queryPrepared($sql, $values);

        return $gDb->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $bookingId, array $data): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $sets = array('mfb_usr_id_change = ?', 'mfb_timestamp_change = ?');
        $values = array(self::currentUserId(), DATETIME_NOW);

        foreach (self::BOOKING_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = $column . ' = ?';
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $values[] = $bookingId;
        $values[] = (int) $gCurrentOrgId;

        $gDb->queryPrepared('UPDATE ' . self::bookingsTable() . ' SET ' . implode(', ', $sets) . ' WHERE mfb_id = ? AND mfb_org_id = ?', $values);
    }

    public static function delete(int $bookingId): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $gDb->queryPrepared('DELETE FROM ' . self::bookingsTable() . ' WHERE mfb_id = ? AND mfb_org_id = ?', array($bookingId, (int) $gCurrentOrgId));
    }

    // ------------------------------------------------------------------
    // Einzuege (SEPA-Dateien)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public static function insertRun(array $data): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $columns = array('mfr_org_id', 'mfr_usr_id_create', 'mfr_timestamp_create');
        $values = array((int) $gCurrentOrgId, self::currentUserId(), DATETIME_NOW);
        foreach (self::RUN_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $columns[] = $column;
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $gDb->queryPrepared('INSERT INTO ' . self::runsTable() . ' (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')', $values);

        return $gDb->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateRun(int $runId, array $data): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $sets = array('mfr_usr_id_change = ?', 'mfr_timestamp_change = ?');
        $values = array(self::currentUserId(), DATETIME_NOW);
        foreach (self::RUN_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = $column . ' = ?';
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $values[] = $runId;
        $values[] = (int) $gCurrentOrgId;
        $gDb->queryPrepared('UPDATE ' . self::runsTable() . ' SET ' . implode(', ', $sets) . ' WHERE mfr_id = ? AND mfr_org_id = ?', $values);
    }

    public static function deleteRun(int $runId): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $gDb->queryPrepared('DELETE FROM ' . self::runsTable() . ' WHERE mfr_id = ? AND mfr_org_id = ?', array($runId, (int) $gCurrentOrgId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findRun(int $runId): ?array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $row = $gDb->queryPrepared('SELECT * FROM ' . self::runsTable() . ' WHERE mfr_id = ? AND mfr_org_id = ?', array($runId, (int) $gCurrentOrgId))->fetch();
        return $row ?: null;
    }

    /**
     * Alle Einzuege mit Kennzahlen der zugehoerigen Positionen, neueste zuerst.
     * @return array<int, array<string, mixed>>
     */
    public static function getRuns(): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $runs = array();
        $statement = $gDb->queryPrepared('SELECT * FROM ' . self::runsTable() . ' WHERE mfr_org_id = ? ORDER BY mfr_id DESC', array((int) $gCurrentOrgId));
        while ($row = $statement->fetch()) {
            $row['count_exported'] = 0;
            $row['count_collected'] = 0;
            $row['count_cancelled'] = 0;
            $row['amount_cancelled'] = 0.0;
            $runs[(int) $row['mfr_id']] = $row;
        }

        if (count($runs) > 0) {
            $sql = 'SELECT mfb_run_id, mfb_status, COUNT(DISTINCT mfb_usr_id) AS members, SUM(mfb_amount) AS amount
                      FROM ' . self::bookingsTable() . '
                     WHERE mfb_org_id = ? AND mfb_run_id IS NOT NULL
                  GROUP BY mfb_run_id, mfb_status';
            $statement = $gDb->queryPrepared($sql, array((int) $gCurrentOrgId));
            while ($row = $statement->fetch()) {
                $runId = (int) $row['mfb_run_id'];
                if (!isset($runs[$runId])) {
                    continue;
                }
                switch ($row['mfb_status']) {
                    case self::STATUS_EXPORTED:
                        $runs[$runId]['count_exported'] = (int) $row['members'];
                        break;
                    case self::STATUS_COLLECTED:
                        $runs[$runId]['count_collected'] = (int) $row['members'];
                        break;
                    case self::STATUS_CANCELLED:
                        $runs[$runId]['count_cancelled'] = (int) $row['members'];
                        $runs[$runId]['amount_cancelled'] = (float) $row['amount'];
                        break;
                }
            }
        }
        return array_values($runs);
    }

    /**
     * Positionen eines Einzugs, gruppiert nach usr_id.
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function getByRun(int $runId): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $result = array();
        $statement = $gDb->queryPrepared('SELECT * FROM ' . self::bookingsTable() . ' WHERE mfb_org_id = ? AND mfb_run_id = ? ORDER BY mfb_usr_id, mfb_id', array((int) $gCurrentOrgId, $runId));
        while ($row = $statement->fetch()) {
            $row = self::normalizeBooking($row);
            $result[$row['mfb_usr_id']][] = $row;
        }
        return $result;
    }


    // ------------------------------------------------------------------
    // SEPA-Dateien und geteilte Download-Links
    // ------------------------------------------------------------------

    /** Spalten ohne den Dateiinhalt (fuer Listen) */
    public const FILE_XML = 'xml';
    public const FILE_CONTROL = 'ctl';

    private const FILE_META_COLUMNS = 'mff_id, mff_org_id, mff_run_id, mff_kind, mff_year, mff_file_name, mff_size, mff_share_hash, mff_share_expires,
        mff_share_email, mff_share_usr_id, mff_share_timestamp, mff_downloads, mff_last_download, mff_timestamp_create';

    /**
     * Speichert eine Datei eines Einzugs (SEPA-Datei oder Kontrolldatei). Eine bereits gespeicherte Datei bleibt
     * unveraendert, damit immer die Datei erhalten bleibt, die zuerst erzeugt (und an die Bank gegeben) wurde.
     * Die Kontrolldatei kann binaer sein (Excel) und wird deshalb Base64-kodiert abgelegt.
     */
    public static function saveRunFile(int $runId, int $year, string $fileName, string $content, string $kind = self::FILE_XML): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        if (self::findRunFileMeta($runId, $kind) !== null) {
            return;
        }
        $stored = ($kind === self::FILE_XML) ? $content : base64_encode($content);
        $gDb->queryPrepared('INSERT INTO ' . self::filesTable() . '
                (mff_org_id, mff_run_id, mff_kind, mff_year, mff_file_name, mff_content, mff_size, mff_usr_id_create, mff_timestamp_create)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array((int) $gCurrentOrgId, $runId, $kind, $year, mb_substr($fileName, 0, 255), $stored, strlen($content), self::currentUserId(), DATETIME_NOW));
    }

    /**
     * @return array<string, mixed>|null Metadaten ohne Inhalt
     */
    public static function findRunFileMeta(int $runId, string $kind = self::FILE_XML): ?array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $row = $gDb->queryPrepared('SELECT ' . self::FILE_META_COLUMNS . ' FROM ' . self::filesTable() . ' WHERE mff_org_id = ? AND mff_run_id = ? AND mff_kind = ?',
            array((int) $gCurrentOrgId, $runId, $kind))->fetch();
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, array<string, mixed>>> Metadaten je run_id und Art (xml/ctl)
     */
    public static function getRunFilesMeta(): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $result = array();
        $statement = $gDb->queryPrepared('SELECT ' . self::FILE_META_COLUMNS . ' FROM ' . self::filesTable() . ' WHERE mff_org_id = ?', array((int) $gCurrentOrgId));
        while ($row = $statement->fetch()) {
            $result[(int) $row['mff_run_id']][(string) $row['mff_kind']] = $row;
        }
        return $result;
    }

    /**
     * @param int|null $orgId Organisation (null = aktuelle), fuer den oeffentlichen Download die des Links
     * @return array<string, mixed>|null Datei inkl. (dekodiertem) Inhalt
     */
    public static function getRunFile(int $runId, string $kind = self::FILE_XML, ?int $orgId = null): ?array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $row = $gDb->queryPrepared('SELECT * FROM ' . self::filesTable() . ' WHERE mff_org_id = ? AND mff_run_id = ? AND mff_kind = ?',
            array($orgId ?? (int) $gCurrentOrgId, $runId, $kind))->fetch();
        if (!$row) {
            return null;
        }
        if ($kind !== self::FILE_XML) {
            $row['mff_content'] = (string) base64_decode((string) $row['mff_content']);
        }
        return $row;
    }

    /**
     * Sucht eine geteilte Datei ueber den Hash des Tokens (ohne Organisationsbezug, fuer den oeffentlichen Download).
     * @return array<string, mixed>|null Datei inkl. Inhalt
     */
    public static function findFileByShareHash(string $hash): ?array
    {
        global $gDb;
        self::ensureTables();

        if (strlen($hash) !== 64) {
            return null;
        }
        $row = $gDb->queryPrepared('SELECT * FROM ' . self::filesTable() . ' WHERE mff_share_hash = ? AND mff_kind = ?', array($hash, self::FILE_XML))->fetch();
        return $row ?: null;
    }

    public static function setShare(int $runId, string $hash, string $expires, string $email): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $gDb->queryPrepared('UPDATE ' . self::filesTable() . '
                SET mff_share_hash = ?, mff_share_expires = ?, mff_share_email = ?, mff_share_usr_id = ?, mff_share_timestamp = ?
              WHERE mff_org_id = ? AND mff_run_id = ? AND mff_kind = ?',
            array($hash, $expires, mb_substr($email, 0, 255), self::currentUserId(), DATETIME_NOW, (int) $gCurrentOrgId, $runId, self::FILE_XML));
    }

    public static function revokeShare(int $runId): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $gDb->queryPrepared('UPDATE ' . self::filesTable() . '
                SET mff_share_hash = NULL, mff_share_expires = NULL
              WHERE mff_org_id = ? AND mff_run_id = ?', array((int) $gCurrentOrgId, $runId));
    }

    public static function registerDownload(int $fileId): void
    {
        global $gDb;
        self::ensureTables();

        // Abrufe werden bei der SEPA-Datei gezaehlt (auch Abrufe der Kontrolldatei ueber denselben Link)
        $gDb->queryPrepared('UPDATE ' . self::filesTable() . ' SET mff_downloads = mff_downloads + 1, mff_last_download = ? WHERE mff_id = ?',
            array(DATETIME_NOW, $fileId));
    }

    public static function deleteRunFile(int $runId): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $gDb->queryPrepared('DELETE FROM ' . self::filesTable() . ' WHERE mff_org_id = ? AND mff_run_id = ?', array((int) $gCurrentOrgId, $runId));
    }

    /**
     * Entfernt die gespeicherten Dateien frueherer Beitragsjahre (die Einzuege selbst bleiben erhalten).
     * @return int Anzahl entfernter Dateien
     */
    public static function purgeFilesBefore(int $year): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $statement = $gDb->queryPrepared('DELETE FROM ' . self::filesTable() . ' WHERE mff_org_id = ? AND mff_year < ?', array((int) $gCurrentOrgId, $year));
        return $statement->rowCount();
    }


    // ------------------------------------------------------------------
    // Datenuebernahme aus einem Altsystem (z. B. Plugin winner-import)
    // ------------------------------------------------------------------

    /**
     * Legt eine importierte Position an. Anders als insert() wird der Zeitpunkt der Anlage
     * aus dem Altsystem uebernommen; mfb_import_ref kennzeichnet die Herkunft.
     *
     * @param array<string, mixed> $data Spalten wie bei insert(), mfb_import_ref ist Pflicht
     */
    public static function insertImported(array $data, string $timestampCreate): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        if (empty($data['mfb_import_ref'])) {
            throw new \InvalidArgumentException('mfb_import_ref fehlt');
        }
        $columns = array('mfb_org_id', 'mfb_usr_id_create', 'mfb_timestamp_create');
        $values = array((int) $gCurrentOrgId, self::currentUserId(), $timestampCreate !== '' ? $timestampCreate : DATETIME_NOW);
        foreach (self::BOOKING_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $columns[] = $column;
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $gDb->queryPrepared('INSERT INTO ' . self::bookingsTable() . ' (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')', $values);

        return $gDb->lastInsertId();
    }

    /**
     * Legt einen historischen Einzug (ohne SEPA-Datei) an.
     * @param array<string, mixed> $data Spalten wie bei insertRun()
     */
    public static function insertImportedRun(array $data, string $timestampCreate): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $data['mfr_format'] = self::RUN_FORMAT_IMPORT;
        $columns = array('mfr_org_id', 'mfr_usr_id_create', 'mfr_timestamp_create');
        $values = array((int) $gCurrentOrgId, self::currentUserId(), $timestampCreate !== '' ? $timestampCreate : DATETIME_NOW);
        foreach (self::RUN_COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $columns[] = $column;
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $gDb->queryPrepared('INSERT INTO ' . self::runsTable() . ' (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')', $values);

        return $gDb->lastInsertId();
    }

    /**
     * Bereits importierte Herkunftskennungen mit gegebenem Praefix.
     * @return array<string, bool>
     */
    public static function getImportRefs(string $prefix): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $refs = array();
        $statement = $gDb->queryPrepared('SELECT mfb_import_ref FROM ' . self::bookingsTable() . ' WHERE mfb_org_id = ? AND mfb_import_ref LIKE ?',
            array((int) $gCurrentOrgId, $prefix . '%'));
        while ($row = $statement->fetch()) {
            $refs[(string) $row['mfb_import_ref']] = true;
        }
        return $refs;
    }

    /**
     * Historische Einzuege eines Imports, Schluessel = Nachrichten-ID (z. B. WINNER-LS-99).
     * @return array<string, int> msg_id => mfr_id
     */
    public static function getImportedRuns(string $msgIdPrefix): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $runs = array();
        $statement = $gDb->queryPrepared('SELECT mfr_id, mfr_msg_id FROM ' . self::runsTable() . ' WHERE mfr_org_id = ? AND mfr_format = ? AND mfr_msg_id LIKE ?',
            array((int) $gCurrentOrgId, self::RUN_FORMAT_IMPORT, $msgIdPrefix . '%'));
        while ($row = $statement->fetch()) {
            $runs[(string) $row['mfr_msg_id']] = (int) $row['mfr_id'];
        }
        return $runs;
    }

    /**
     * Entfernt alle importierten Positionen und historischen Einzuege eines Imports.
     * @return array{bookings: int, runs: int}
     */
    public static function deleteImported(string $prefix, string $msgIdPrefix): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTables();

        $bookings = $gDb->queryPrepared('DELETE FROM ' . self::bookingsTable() . ' WHERE mfb_org_id = ? AND mfb_import_ref LIKE ?',
            array((int) $gCurrentOrgId, $prefix . '%'))->rowCount();
        $runs = $gDb->queryPrepared('DELETE FROM ' . self::runsTable() . ' WHERE mfr_org_id = ? AND mfr_format = ? AND mfr_msg_id LIKE ?',
            array((int) $gCurrentOrgId, self::RUN_FORMAT_IMPORT, $msgIdPrefix . '%'))->rowCount();
        return array('bookings' => (int) $bookings, 'runs' => (int) $runs);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeBooking(array $row): array
    {
        foreach (array('mfb_id', 'mfb_org_id', 'mfb_usr_id', 'mfb_year', 'mfb_role_revoked') as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }
        foreach (array('mfb_rol_id', 'mfb_run_id') as $column) {
            $row[$column] = (isset($row[$column]) && $row[$column] !== '') ? (int) $row[$column] : null;
        }
        $row['mfb_amount'] = round((float) $row['mfb_amount'], 2);
        return $row;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function prepareValue(string $column, $value)
    {
        if ($value === '' && in_array($column, array('mfb_rol_id', 'mfb_run_id', 'mfb_due_date', 'mfb_booking_date', 'mfb_storno_date', 'mfr_paid_date', 'mfb_method', 'mfb_reference'), true)) {
            return null;
        }
        if ($column === 'mfb_amount' || $column === 'mfr_amount') {
            return number_format(round((float) $value, 2), 2, '.', '');
        }
        if ($column === 'mfb_role_revoked') {
            return $value ? 1 : 0;
        }
        if ($column === 'mfb_text') {
            return mb_substr((string) $value, 0, 140);
        }
        if ($column === 'mfb_rol_name') {
            return mb_substr((string) $value, 0, 100);
        }
        if ($column === 'mfb_import_ref' && $value !== null) {
            return mb_substr((string) $value, 0, 50);
        }
        if (in_array($column, array('mfb_reference', 'mfb_mandate_id', 'mfb_orig_mandate_id', 'mfb_orig_debtor_agent', 'mfr_msg_id'), true) && $value !== null) {
            return mb_substr((string) $value, 0, 35);
        }
        return $value;
    }

    /**
     * Prueft ueber eine leere Abfrage, ob eine Tabelle existiert (funktioniert mit MySQL und PostgreSQL).
     */
    private static function tableExists(string $table): bool
    {
        global $gDb;
        return $gDb->queryPrepared('SELECT 1 FROM ' . $table . ' WHERE 1 = 0', array(), false) !== false;
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
