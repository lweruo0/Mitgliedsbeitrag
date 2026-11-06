<?php
/**
 ***********************************************************************************************
 * Abweichende Zahlart je Mitglied, Beitragsjahr und Buchungskreis
 *
 * Ohne Eintrag gilt die Zahlart aus dem Profil (IBAN und Mandats-ID = Lastschrift, sonst Ueberweisung,
 * siehe InvoicePlanner::method()). Ein Eintrag entsteht nur durch "Verschieben" in 2.0/2.1 und gilt
 * nur fuer dieses Beitragsjahr (docs/konzept-rechnungen-pdf.md, Abschnitt Zahlart-Tabellen).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

class PaymentMethodStore
{
    private static bool $tableChecked = false;

    /** @var array<string, array<int, array<int, string>>> Zwischenspeicher je Buchungskreis */
    private static array $cache = array();

    public static function table(): string
    {
        return TABLE_PREFIX . '_plugin_membershipfee_paymethods';
    }

    public static function ensureTable(): void
    {
        global $gDb;

        if (self::$tableChecked) {
            return;
        }
        if ($gDb->queryPrepared('SELECT 1 FROM ' . self::table() . ' WHERE 1 = 0', array(), false) === false) {
            if (defined('DB_ENGINE') && DB_ENGINE === 'pgsql') {
                $gDb->queryPrepared('CREATE TABLE ' . self::table() . ' (
                    mfm_id               serial       PRIMARY KEY,
                    mfm_org_id           integer      NOT NULL,
                    mfm_usr_id           integer      NOT NULL,
                    mfm_year             integer      NOT NULL,
                    mfm_kind             varchar(2)   NOT NULL,
                    mfm_method           varchar(4)   NOT NULL,
                    mfm_usr_id_change    integer,
                    mfm_timestamp_change timestamp,
                    UNIQUE (mfm_org_id, mfm_usr_id, mfm_year, mfm_kind)
                )');
            } else {
                $gDb->queryPrepared('CREATE TABLE ' . self::table() . ' (
                    mfm_id               int unsigned NOT NULL AUTO_INCREMENT,
                    mfm_org_id           int unsigned NOT NULL,
                    mfm_usr_id           int unsigned NOT NULL,
                    mfm_year             int          NOT NULL,
                    mfm_kind             varchar(2)   NOT NULL,
                    mfm_method           varchar(4)   NOT NULL,
                    mfm_usr_id_change    int unsigned,
                    mfm_timestamp_change timestamp    NULL DEFAULT NULL,
                    PRIMARY KEY (mfm_id),
                    UNIQUE KEY mfm_unique (mfm_org_id, mfm_usr_id, mfm_year, mfm_kind)
                ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci');
            }
        }
        self::$tableChecked = true;
    }

    /**
     * Alle abweichenden Zahlarten eines Buchungskreises.
     *
     * @return array<int, array<int, string>> usr_id => (Jahr => 'SEPA' | 'MAN')
     */
    public static function all(string $kind): array
    {
        global $gDb, $gCurrentOrgId;

        if (!isset(self::$cache[$kind])) {
            self::ensureTable();
            $result = array();
            $statement = $gDb->queryPrepared('SELECT mfm_usr_id, mfm_year, mfm_method FROM ' . self::table()
                . ' WHERE mfm_org_id = ? AND mfm_kind = ?', array((int) $gCurrentOrgId, $kind));
            while ($row = $statement->fetch()) {
                $result[(int) $row['mfm_usr_id']][(int) $row['mfm_year']] = (string) $row['mfm_method'];
            }
            self::$cache[$kind] = $result;
        }
        return self::$cache[$kind];
    }

    public static function get(int $usrId, int $year, string $kind): ?string
    {
        return self::all($kind)[$usrId][$year] ?? null;
    }

    /**
     * Abweichende Zahlart setzen; null entfernt den Eintrag (es gilt wieder die Zahlart aus dem Profil).
     */
    public static function set(int $usrId, int $year, string $kind, ?string $method): void
    {
        global $gDb, $gCurrentOrgId, $gCurrentUserId;

        self::ensureTable();
        $gDb->queryPrepared('DELETE FROM ' . self::table() . ' WHERE mfm_org_id = ? AND mfm_usr_id = ? AND mfm_year = ? AND mfm_kind = ?',
            array((int) $gCurrentOrgId, $usrId, $year, $kind));
        if ($method !== null) {
            $gDb->queryPrepared('INSERT INTO ' . self::table()
                . ' (mfm_org_id, mfm_usr_id, mfm_year, mfm_kind, mfm_method, mfm_usr_id_change, mfm_timestamp_change) VALUES (?, ?, ?, ?, ?, ?, ?)',
                array((int) $gCurrentOrgId, $usrId, $year, $kind, $method, (int) $gCurrentUserId, DATETIME_NOW));
        }
        unset(self::$cache[$kind]);
    }

    public static function removeDataOfCurrentOrganization(): bool
    {
        global $gDb, $gCurrentOrgId;

        self::ensureTable();
        return $gDb->queryPrepared('DELETE FROM ' . self::table() . ' WHERE mfm_org_id = ?', array((int) $gCurrentOrgId), false) !== false;
    }
}
