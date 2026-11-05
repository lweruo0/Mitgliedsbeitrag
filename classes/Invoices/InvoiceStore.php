<?php
/**
 ***********************************************************************************************
 * Rechnungskopf: Tabelle <prefix>_plugin_membershipfee_invoices
 *
 * Eine Zeile je Rechnung (Mitglied, Jahr, Buchungskreis). Die Positionen verweisen ueber
 * mfb_invoice_no auf den Kopf. Der Status wird nicht gespeichert, sondern beim Lesen aus den
 * Positionen berechnet (InvoiceStatus). Siehe docs/konzept-rechnungen-pdf.md, Abschnitt 4.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

use Plugins\MembershipFee\classes\Bookings\BookingStore;

class InvoiceStore
{
    /** Spalten, die ueber insert()/update() gesetzt werden duerfen */
    private const COLUMNS = array(
        'mfi_usr_id', 'mfi_year', 'mfi_kind', 'mfi_invoice_no', 'mfi_seq', 'mfi_invoice_date', 'mfi_due_date',
        'mfi_amount', 'mfi_method', 'mfi_mandate_id', 'mfi_iban_masked', 'mfi_dunning_date', 'mfi_dunning_due',
        'mfi_sent_mail', 'mfi_sent_link', 'mfi_sent_print', 'mfi_pdf_file', 'mfi_dunning_file', 'mfi_cancel_file'
    );

    /** Spalten, bei denen '' als NULL gespeichert wird */
    private const NULLABLE = array(
        'mfi_due_date', 'mfi_mandate_id', 'mfi_iban_masked', 'mfi_dunning_date', 'mfi_dunning_due', 'mfi_sent_mail',
        'mfi_sent_link', 'mfi_sent_print', 'mfi_pdf_file', 'mfi_dunning_file', 'mfi_cancel_file'
    );

    private static bool $tableChecked = false;

    public static function table(): string
    {
        return TABLE_PREFIX . '_plugin_membershipfee_invoices';
    }

    public static function ensureTable(): void
    {
        global $gDb;

        if (self::$tableChecked) {
            return;
        }
        BookingStore::ensureTables();

        if ($gDb->queryPrepared('SELECT 1 FROM ' . self::table() . ' WHERE 1 = 0', array(), false) === false) {
            if (defined('DB_ENGINE') && DB_ENGINE === 'pgsql') {
                $gDb->queryPrepared('CREATE TABLE ' . self::table() . ' (
                    mfi_id                 serial        PRIMARY KEY,
                    mfi_org_id             integer       NOT NULL,
                    mfi_usr_id             integer       NOT NULL,
                    mfi_year               integer       NOT NULL,
                    mfi_kind               varchar(2)    NOT NULL,
                    mfi_invoice_no         varchar(20)   NOT NULL,
                    mfi_seq                integer       NOT NULL,
                    mfi_invoice_date       date          NOT NULL,
                    mfi_due_date           date,
                    mfi_amount             decimal(10,2) NOT NULL DEFAULT 0,
                    mfi_method             varchar(4)    NOT NULL DEFAULT \'MAN\',
                    mfi_mandate_id         varchar(35),
                    mfi_iban_masked        varchar(34),
                    mfi_dunning_date       date,
                    mfi_dunning_due        date,
                    mfi_sent_mail          timestamp,
                    mfi_sent_link          timestamp,
                    mfi_sent_print         timestamp,
                    mfi_pdf_file           varchar(100),
                    mfi_dunning_file       varchar(100),
                    mfi_cancel_file        varchar(100),
                    mfi_usr_id_create      integer,
                    mfi_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfi_usr_id_change      integer,
                    mfi_timestamp_change   timestamp
                )');
                $gDb->queryPrepared('CREATE UNIQUE INDEX ' . self::table() . '_uq_no ON ' . self::table() . ' (mfi_org_id, mfi_invoice_no)');
                $gDb->queryPrepared('CREATE UNIQUE INDEX ' . self::table() . '_uq_seq ON ' . self::table() . ' (mfi_org_id, mfi_year, mfi_kind, mfi_seq)');
                $gDb->queryPrepared('CREATE INDEX ' . self::table() . '_idx_usr ON ' . self::table() . ' (mfi_org_id, mfi_usr_id)');
            } else {
                $gDb->queryPrepared('CREATE TABLE ' . self::table() . ' (
                    mfi_id                 integer unsigned NOT NULL AUTO_INCREMENT,
                    mfi_org_id             integer unsigned NOT NULL,
                    mfi_usr_id             integer unsigned NOT NULL,
                    mfi_year               integer       NOT NULL,
                    mfi_kind               varchar(2)    NOT NULL,
                    mfi_invoice_no         varchar(20)   NOT NULL,
                    mfi_seq                integer       NOT NULL,
                    mfi_invoice_date       date          NOT NULL,
                    mfi_due_date           date          NULL DEFAULT NULL,
                    mfi_amount             decimal(10,2) NOT NULL DEFAULT 0,
                    mfi_method             varchar(4)    NOT NULL DEFAULT \'MAN\',
                    mfi_mandate_id         varchar(35),
                    mfi_iban_masked        varchar(34),
                    mfi_dunning_date       date          NULL DEFAULT NULL,
                    mfi_dunning_due        date          NULL DEFAULT NULL,
                    mfi_sent_mail          timestamp     NULL DEFAULT NULL,
                    mfi_sent_link          timestamp     NULL DEFAULT NULL,
                    mfi_sent_print         timestamp     NULL DEFAULT NULL,
                    mfi_pdf_file           varchar(100),
                    mfi_dunning_file       varchar(100),
                    mfi_cancel_file        varchar(100),
                    mfi_usr_id_create      integer unsigned,
                    mfi_timestamp_create   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    mfi_usr_id_change      integer unsigned,
                    mfi_timestamp_change   timestamp     NULL DEFAULT NULL,
                    PRIMARY KEY (mfi_id),
                    UNIQUE INDEX uq_mfi_no (mfi_org_id, mfi_invoice_no),
                    UNIQUE INDEX uq_mfi_seq (mfi_org_id, mfi_year, mfi_kind, mfi_seq),
                    INDEX idx_mfi_usr (mfi_org_id, mfi_usr_id)
                ) ENGINE = InnoDB DEFAULT CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci');
            }
        }
        self::$tableChecked = true;
    }

    /**
     * Naechste freie Laufnummer fuer Jahr und Buchungskreis.
     */
    public static function nextSeq(int $year, string $kind): int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $max = $gDb->queryPrepared('SELECT MAX(mfi_seq) FROM ' . self::table() . ' WHERE mfi_org_id = ? AND mfi_year = ? AND mfi_kind = ?',
            array((int) $gCurrentOrgId, $year, $kind))->fetchColumn();
        return (int) $max + 1;
    }

    /**
     * Legt einen Rechnungskopf an.
     * @param array<string, mixed> $data
     * @return int|null mfi_id, oder null, wenn die Nummer bereits vergeben ist (Unique-Index)
     */
    public static function insert(array $data): ?int
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $columns = array('mfi_org_id', 'mfi_usr_id_create', 'mfi_timestamp_create');
        $values = array((int) $gCurrentOrgId, self::currentUserId(), DATETIME_NOW);
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $data)) {
                $columns[] = $column;
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $result = $gDb->queryPrepared('INSERT INTO ' . self::table() . ' (' . implode(', ', $columns) . ')
            VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')', $values, false);
        if ($result === false) {
            return null;
        }
        return (int) $gDb->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(string $invoiceNo, array $data): void
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $sets = array('mfi_usr_id_change = ?', 'mfi_timestamp_change = ?');
        $values = array(self::currentUserId(), DATETIME_NOW);
        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $data) && !in_array($column, array('mfi_invoice_no', 'mfi_seq', 'mfi_kind', 'mfi_year', 'mfi_usr_id'), true)) {
                $sets[] = $column . ' = ?';
                $values[] = self::prepareValue($column, $data[$column]);
            }
        }
        $values[] = (int) $gCurrentOrgId;
        $values[] = $invoiceNo;
        $gDb->queryPrepared('UPDATE ' . self::table() . ' SET ' . implode(', ', $sets) . ' WHERE mfi_org_id = ? AND mfi_invoice_no = ?', $values);
    }

    /**
     * Rechnung mit Status und Zaehlern.
     * @return array<string, mixed>|null
     */
    public static function find(string $invoiceNo): ?array
    {
        $rows = self::search(array('invoice_no' => $invoiceNo));
        return $rows[0] ?? null;
    }

    /**
     * Rechnungen mit berechnetem Status.
     *
     * @param array<string, mixed> $filter kind, year, usr_ids, invoice_no
     * @return array<int, array<string, mixed>> je Rechnung die Kopfspalten und zusaetzlich
     *         cnt_open, cnt_exported, cnt_collected, cnt_cancelled, open_amount, status
     */
    public static function search(array $filter): array
    {
        global $gDb, $gCurrentOrgId;
        self::ensureTable();

        $bookings = BookingStore::bookingsTable();
        $sql = 'SELECT i.*,
                       SUM(CASE WHEN b.mfb_status = ? THEN 1 ELSE 0 END) AS cnt_open,
                       SUM(CASE WHEN b.mfb_status = ? THEN 1 ELSE 0 END) AS cnt_exported,
                       SUM(CASE WHEN b.mfb_status = ? THEN 1 ELSE 0 END) AS cnt_collected,
                       SUM(CASE WHEN b.mfb_status = ? THEN 1 ELSE 0 END) AS cnt_cancelled,
                       SUM(CASE WHEN b.mfb_status = ? THEN b.mfb_amount ELSE 0 END) AS open_amount
                  FROM ' . self::table() . ' i
             LEFT JOIN ' . $bookings . ' b ON b.mfb_org_id = i.mfi_org_id AND b.mfb_invoice_no = i.mfi_invoice_no
                 WHERE i.mfi_org_id = ?';
        $params = array(BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED,
            BookingStore::STATUS_CANCELLED, BookingStore::STATUS_OPEN, (int) $gCurrentOrgId);

        if (!empty($filter['kind'])) {
            $sql .= ' AND i.mfi_kind = ?';
            $params[] = (string) $filter['kind'];
        }
        if (!empty($filter['year'])) {
            $sql .= ' AND i.mfi_year = ?';
            $params[] = (int) $filter['year'];
        }
        if (!empty($filter['invoice_no'])) {
            $sql .= ' AND i.mfi_invoice_no = ?';
            $params[] = (string) $filter['invoice_no'];
        }
        if (isset($filter['usr_ids']) && is_array($filter['usr_ids'])) {
            if (count($filter['usr_ids']) === 0) {
                return array();
            }
            $ids = array_values(array_map('intval', $filter['usr_ids']));
            $sql .= ' AND i.mfi_usr_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $sql .= ' GROUP BY i.mfi_id ORDER BY i.mfi_year DESC, i.mfi_kind, i.mfi_seq';

        $result = array();
        $statement = $gDb->queryPrepared($sql, $params);
        while ($row = $statement->fetch()) {
            foreach (array('mfi_id', 'mfi_org_id', 'mfi_usr_id', 'mfi_year', 'mfi_seq', 'cnt_open', 'cnt_exported', 'cnt_collected', 'cnt_cancelled') as $column) {
                $row[$column] = (int) $row[$column];
            }
            $row['mfi_amount'] = round((float) $row['mfi_amount'], 2);
            $row['open_amount'] = round((float) $row['open_amount'], 2);
            $row['status'] = InvoiceStatus::fromCounts($row['cnt_open'], $row['cnt_exported'], $row['cnt_collected'], $row['cnt_cancelled']);
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Rechnungsdaten der aktuellen Organisation loeschen (nur beim Deinstallieren mit ausdruecklicher Bestaetigung).
     * @return bool true, wenn die Tabelle geloescht wurde
     */
    public static function removeDataOfCurrentOrganization(): bool
    {
        global $gDb, $gCurrentOrgId;

        if ($gDb->queryPrepared('SELECT 1 FROM ' . self::table() . ' WHERE 1 = 0', array(), false) === false) {
            return false;
        }
        $gDb->queryPrepared('DELETE FROM ' . self::table() . ' WHERE mfi_org_id = ?', array((int) $gCurrentOrgId));
        if ((int) $gDb->queryPrepared('SELECT COUNT(*) FROM ' . self::table())->fetchColumn() === 0) {
            $gDb->queryPrepared('DROP TABLE ' . self::table());
            return true;
        }
        return false;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function prepareValue(string $column, $value)
    {
        if (($value === '' || $value === null) && in_array($column, self::NULLABLE, true)) {
            return null;
        }
        if ($column === 'mfi_amount') {
            return number_format(round((float) $value, 2), 2, '.', '');
        }
        return $value;
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
