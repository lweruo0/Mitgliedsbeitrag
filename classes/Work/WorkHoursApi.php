<?php
/**
 ***********************************************************************************************
 * Schnittstelle Arbeitsdienst: Stunden eines Mitglieds per UUID lesen und schreiben
 *
 * Fuer andere Plugins (z. B. WpUserSync, REST-API fuer WordPress). Die Klasse braucht nur ein
 * geladenes Admidio ($gDb, $gCurrentOrgId, $gProfileFields, $gCurrentUser) und keine Seite des
 * Plugins (common_function.php wird nicht benoetigt).
 *
 * Regeln (wie auf der Seite "Arbeitsdienst erfassen"):
 *  - Schreiben darf der angemeldete Benutzer, wenn er die Zugriffsrolle des Plugins hat oder den
 *    Menuepunkt "Arbeitsdienst erfassen" sehen darf.
 *  - Das Mitglied muss im Leistungsjahr (Jahr des Einsatzdatums) in mindestens einer Rolle sein.
 *  - Gesperrte Jahre (Arbeitsdienst schon exportiert/eingezogen) werden nie geaendert, auch nicht
 *    von der Zugriffsrolle; Korrekturen danach nur in Admidio.
 *  - Betraege werden nicht ausgegeben, nur Stunden.
 *
 * Sollstunden: writeTarget() setzt das Profilfeld "Sollstunden Arbeitsdienst" (ADTARGET<org>) wie die
 * Seite "Sollstunden abgleichen" beim manuellen Setzen (gleiche Rechte wie beim Schreiben von Stunden).
 *
 * Eintrag (Ein- und Ausgabe):
 *   id, date (Y-m-d), hours (zwei Nachkommastellen), activity (Einsatz, Pflicht), comment,
 *   status (angemeldet | teilgenommen | entschuldigt | unentschuldigt; Standard teilgenommen)
 *   Nur "teilgenommen" hat Stunden (> 0, Pflicht) und zaehlt fuer done_hours; die anderen Status haben 0 Stunden.
 *   Eingabe akzeptiert auch "name" statt "activity" (alte WpUserSync-Schnittstelle).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

use Admidio\Roles\Entity\RolesRights;
use Admidio\Users\Entity\User;
use InvalidArgumentException;

final class WorkHoursApi
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_EXISTS = 'exists';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_TARGET = 'target_updated';

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /**
     * Stunden eines Mitglieds, je Leistungsjahr zusammengefasst.
     *
     * Ohne $year: alle Jahre mit Eintraegen und das aktuelle Jahr, neueste zuerst.
     * target_hours ist das Profilfeld "Sollstunden Arbeitsdienst" (ein Wert, nicht je Jahr);
     * missing_hours rechnet wie die Jahresuebersicht mit diesem Wert.
     *
     * @return array{user_uuid:string, target_hours:float|null, years:array<int, array<string, mixed>>}
     */
    public static function read(string $userUuid, ?int $year = null): array
    {
        $usrId = self::userIdByUuid($userUuid);
        if ($year !== null) {
            self::assertYear($year);
        }

        $entries = ($year === null) ? WorkHoursStore::getByUser($usrId) : WorkHoursStore::getByYear($year, $usrId);
        $years = ($year === null) ? self::yearsOf($entries, (int) date('Y')) : array($year);
        $target = self::targetHours($usrId);

        $locked = array();
        foreach ($years as $y) {
            $locked[$y] = WorkYearLock::isLocked($y);
        }

        return array(
            'user_uuid' => $userUuid,
            'target_hours' => $target,
            'years' => self::summarize($entries, $years, $target, $locked)
        );
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * Legt einen Eintrag an oder aendert ihn (mit "id").
     *
     * Beim Aendern muessen nur die geaenderten Felder uebergeben werden (z. B. nur status und hours).
     * Mit $checkDuplicates wird kein zweiter Eintrag mit gleichem Datum, Einsatz und Stunden angelegt;
     * stattdessen kommt der vorhandene mit action "exists" zurueck (wiederholtes Senden ist damit unschaedlich).
     * Wird ein Status uebergeben, zaehlen fuer die Dublette nur Datum und Einsatz (ein Mitglied hat je Einsatz
     * einen Eintrag, dessen Status sich aendert).
     *
     * @param array<string, mixed> $data
     * @param int|null $expectedYear wenn gesetzt, muss das Einsatzdatum in diesem Jahr liegen
     * @return array{action:string, entry:array<string, mixed>, user_uuid:string, target_hours:float|null, years:array<int, array<string, mixed>>}
     */
    public static function write(string $userUuid, array $data, bool $checkDuplicates = false, ?int $expectedYear = null): array
    {
        self::assertWriteAllowed();
        $usrId = self::userIdByUuid($userUuid);

        $id = self::entryId($data);
        $statusGiven = isset($data['status']) && trim((string) (is_scalar($data['status']) ? $data['status'] : '')) !== '';
        $input = self::normalizeInput($data, $id !== null);

        if ($id !== null) {
            $existing = self::findEntryOfUser($id, $usrId);
            self::assertYearOpen((int) $existing['mfw_year']);
            $date = (string) ($input['mfw_date'] ?? $existing['mfw_date']);
        } else {
            $existing = null;
            $date = (string) $input['mfw_date'];
        }

        $year = (int) substr($date, 0, 4);
        self::assertYear($year);
        if ($expectedYear !== null && $expectedYear !== $year) {
            throw new WorkApiException('The date ' . $date . ' is not in year ' . $expectedYear . '.', 'validation_failed', 422);
        }
        self::assertYearOpen($year);
        if (!self::isMemberInYear($usrId, $year)) {
            throw new WorkApiException('The user is not a member in ' . $year . '.', 'not_member_in_year', 422, array('year' => $year));
        }

        if ($existing !== null) {
            if (count($input) > 0) {
                try {
                    WorkHoursStore::update($id, $input);
                } catch (InvalidArgumentException $e) {
                    throw new WorkApiException($e->getMessage(), 'validation_failed', 422);
                }
            }
            $action = self::ACTION_UPDATED;
        } else {
            $duplicate = $checkDuplicates ? self::findDuplicate(WorkHoursStore::getByYear($year, $usrId), $input, !$statusGiven) : null;
            if ($duplicate !== null) {
                $id = (int) $duplicate['mfw_id'];
                $action = self::ACTION_EXISTS;
            } else {
                $input['mfw_usr_id'] = $usrId;
                try {
                    $id = WorkHoursStore::insert($input);
                } catch (InvalidArgumentException $e) {
                    throw new WorkApiException($e->getMessage(), 'validation_failed', 422);
                }
                $action = self::ACTION_CREATED;
            }
        }

        $entry = WorkHoursStore::find($id);
        return array('action' => $action, 'entry' => self::publicEntry((array) $entry)) + self::read($userUuid, $year);
    }

    /**
     * Loescht einen Eintrag des Mitglieds.
     *
     * @return array{action:string, entry:array<string, mixed>, user_uuid:string, target_hours:float|null, years:array<int, array<string, mixed>>}
     */
    public static function delete(string $userUuid, int $id): array
    {
        self::assertWriteAllowed();
        $usrId = self::userIdByUuid($userUuid);
        $existing = self::findEntryOfUser($id, $usrId);
        self::assertYearOpen((int) $existing['mfw_year']);

        WorkHoursStore::delete($id);
        return array('action' => self::ACTION_DELETED, 'entry' => self::publicEntry($existing)) + self::read($userUuid, (int) $existing['mfw_year']);
    }

    /**
     * Setzt das Profilfeld "Sollstunden Arbeitsdienst" eines Mitglieds; null oder '' leert es.
     * Antwort wie read() fuer das aktuelle Jahr, dazu action "target_updated".
     *
     * @return array{action:string, user_uuid:string, target_hours:float|null, years:array<int, array<string, mixed>>}
     */
    public static function writeTarget(string $userUuid, mixed $hours): array
    {
        global $gDb, $gProfileFields, $gCurrentOrgId;

        self::assertWriteAllowed();
        $usrId = self::userIdByUuid($userUuid);
        $value = self::normalizeTarget($hours);

        $field = WorkProfileFields::targetField((int) $gCurrentOrgId);
        if ((int) $gProfileFields->getProperty($field, 'usf_id') <= 0) {
            throw new WorkApiException('Profile field ' . $field . ' does not exist.', 'target_field_missing', 500);
        }
        $user = new User($gDb, $gProfileFields, $usrId);
        // Rechte sind ueber assertWriteAllowed() geprueft; das Feld ist fuer Mitglieder gesperrt (usf_disabled)
        $user->saveChangesWithoutRights();
        $user->setValue($field, $value);
        $user->save();

        return array('action' => self::ACTION_TARGET) + self::read($userUuid, (int) date('Y'));
    }

    // ------------------------------------------------------------------
    // Rechte
    // ------------------------------------------------------------------

    /**
     * Darf der angemeldete Benutzer Stunden erfassen? Wie mf_is_work_input_authorized():
     * Menuepunkt des Plugins (index.php) oder "Arbeitsdienst erfassen" (system/work_input.php) sichtbar.
     */
    public static function currentUserMayWrite(): bool
    {
        global $gDb, $gCurrentUser;

        if (!is_object($gCurrentUser) || (int) $gCurrentUser->getValue('usr_id') <= 0) {
            return false;
        }
        $pluginFolder = '/' . basename(dirname(__DIR__, 2));
        foreach (array('index.php', 'system/work_input.php') as $script) {
            $statement = $gDb->queryPrepared('SELECT men_id FROM ' . TBL_MENU . ' WHERE men_url = ?', array(FOLDER_PLUGINS . $pluginFolder . '/' . $script));
            while ($row = $statement->fetch()) {
                $rights = new RolesRights($gDb, 'menu_view', (int) $row['men_id']);
                if (count($rights->getRolesIds()) === 0 || $rights->hasRight($gCurrentUser->getRoleMemberships())) {
                    return true;
                }
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen ohne Datenbankzugriff (getestet)
    // ------------------------------------------------------------------

    /**
     * Eingabe in Tabellenspalten umsetzen und pruefen.
     * Neu: date und activity sind Pflicht, hours beim Status teilgenommen (Standard). Aendern ($partial): nur die
     * uebergebenen Felder; ob Stunden und Status zusammenpassen, prueft der Store mit dem vorhandenen Eintrag.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> mfw_date, mfw_hours, mfw_activity, mfw_comment, mfw_status (soweit uebergeben)
     */
    public static function normalizeInput(array $data, bool $partial = false): array
    {
        if (!array_key_exists('activity', $data) && array_key_exists('name', $data)) {
            $data['activity'] = $data['name'];
        }

        $input = array();
        foreach (array('date' => 'mfw_date', 'hours' => 'mfw_hours', 'activity' => 'mfw_activity', 'comment' => 'mfw_comment', 'status' => 'mfw_status') as $key => $column) {
            if (array_key_exists($key, $data)) {
                if ($data[$key] !== null && !is_scalar($data[$key])) {
                    throw new WorkApiException('Field "' . $key . '" must be a scalar value.', 'validation_failed', 422);
                }
                $input[$column] = $data[$key];
            }
        }

        if (!$partial) {
            $required = array('date' => 'mfw_date', 'activity' => 'mfw_activity');
            $status = mb_strtolower(trim((string) ($input['mfw_status'] ?? WorkHoursStore::STATUS_ATTENDED)));
            if ($status === WorkHoursStore::STATUS_ATTENDED) {
                $required['hours'] = 'mfw_hours';
            }
            foreach ($required as $key => $column) {
                if (!isset($input[$column]) || trim((string) $input[$column]) === '') {
                    throw new WorkApiException('Field "' . $key . '" is required.', 'validation_failed', 422);
                }
            }
        }
        if (array_key_exists('mfw_date', $input)) {
            $input['mfw_date'] = trim((string) $input['mfw_date']);
        }
        if (array_key_exists('mfw_hours', $input)) {
            if (!is_numeric(str_replace(',', '.', trim((string) $input['mfw_hours'])))) {
                throw new WorkApiException('Field "hours" must be a number.', 'validation_failed', 422);
            }
        }
        if (array_key_exists('mfw_comment', $input)) {
            $input['mfw_comment'] = trim((string) $input['mfw_comment']);
        }

        if (array_key_exists('mfw_status', $input) && ($input['mfw_status'] === null || trim((string) $input['mfw_status']) === '')) {
            unset($input['mfw_status']);
        }

        try {
            $input = WorkHoursStore::validate($input);
            if (!$partial) {
                $input = WorkHoursStore::applyStatusRules($input);
            }
        } catch (InvalidArgumentException $e) {
            throw new WorkApiException($e->getMessage(), 'validation_failed', 422);
        }
        if (array_key_exists('mfw_activity', $input) && $input['mfw_activity'] === '') {
            throw new WorkApiException('Field "activity" must not be empty.', 'validation_failed', 422);
        }
        return $input;
    }

    /**
     * Sollstunden fuer das Profilfeld: '' (leer) bei null/'', sonst 0 bis 999 mit hoechstens zwei
     * Nachkommastellen ohne Nullen am Ende (wie "Sollstunden abgleichen"), z. B. "10" oder "12.5".
     */
    public static function normalizeTarget(mixed $hours): string
    {
        if ($hours === null || (is_string($hours) && trim($hours) === '')) {
            return '';
        }
        if (!is_scalar($hours) || is_bool($hours)) {
            throw new WorkApiException('Field "target_hours" must be a number or null.', 'validation_failed', 422);
        }
        $value = trim(str_replace(',', '.', (string) $hours));
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 999) {
            throw new WorkApiException('Field "target_hours" must be between 0 and 999.', 'validation_failed', 422);
        }
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * Id eines zu aendernden Eintrags aus der Eingabe ("id"), sonst null.
     * @param array<string, mixed> $data
     */
    public static function entryId(array $data): ?int
    {
        $id = $data['id'] ?? null;
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return null;
        }
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new WorkApiException('Field "id" must be an integer.', 'validation_failed', 422);
        }
        return (int) $id;
    }

    /**
     * Gleicher Eintrag (Datum, Einsatz ohne Gross-/Kleinschreibung, Stunden) unter $entries?
     * Mit Status in der Eingabe nur Datum und Einsatz (der Status eines Einsatzes aendert sich).
     *
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $input normalisierte Eingabe
     * @param bool $matchHours false: Stunden nicht vergleichen (Eingabe mit Status)
     * @return array<string, mixed>|null
     */
    public static function findDuplicate(array $entries, array $input, bool $matchHours = true): ?array
    {
        foreach ($entries as $entry) {
            if ((string) $entry['mfw_date'] === (string) $input['mfw_date']
                && mb_strtolower(trim((string) $entry['mfw_activity'])) === mb_strtolower((string) $input['mfw_activity'])
                && (!$matchHours || abs((float) $entry['mfw_hours'] - (float) $input['mfw_hours']) < 0.005)) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Jahre mit Eintraegen plus aktuelles Jahr, neueste zuerst.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, int>
     */
    public static function yearsOf(array $entries, int $currentYear): array
    {
        $years = array($currentYear);
        foreach ($entries as $entry) {
            $years[] = (int) $entry['mfw_year'];
        }
        $years = array_values(array_unique($years));
        rsort($years);
        return $years;
    }

    /**
     * Zusammenfassung je Jahr (Ist, Fehlstunden, Sperre, Eintraege nach Datum).
     *
     * @param array<int, array<string, mixed>> $entries
     * @param array<int, int> $years
     * @param array<int, bool> $locked Jahr => gesperrt
     * @return array<int, array<string, mixed>>
     */
    public static function summarize(array $entries, array $years, ?float $target, array $locked): array
    {
        $result = array();
        foreach ($years as $year) {
            $yearEntries = array_values(array_filter($entries, static fn (array $entry): bool => (int) $entry['mfw_year'] === $year));
            usort($yearEntries, static fn (array $a, array $b): int => array((string) $a['mfw_date'], (int) $a['mfw_id']) <=> array((string) $b['mfw_date'], (int) $b['mfw_id']));

            $done = round(array_sum(array_map(array(WorkHoursStore::class, 'doneHours'), $yearEntries)), 2);
            $result[] = array(
                'year' => $year,
                'done_hours' => $done,
                'missing_hours' => ($target === null) ? null : WorkRules::missingHours($target, $done),
                'locked' => (bool) ($locked[$year] ?? false),
                'entries' => array_map(array(self::class, 'publicEntry'), $yearEntries)
            );
        }
        return $result;
    }

    /**
     * Eintrag fuer die Ausgabe (ohne Organisation und Benutzer-IDs).
     *
     * @param array<string, mixed> $entry Zeile aus WorkHoursStore
     * @return array<string, mixed>
     */
    public static function publicEntry(array $entry): array
    {
        $comment = $entry['mfw_comment'] ?? null;
        return array(
            'id' => (int) ($entry['mfw_id'] ?? 0),
            'date' => (string) ($entry['mfw_date'] ?? ''),
            'year' => (int) ($entry['mfw_year'] ?? 0),
            'hours' => round((float) ($entry['mfw_hours'] ?? 0), 2),
            'activity' => (string) ($entry['mfw_activity'] ?? ''),
            'comment' => ($comment === null || $comment === '') ? null : (string) $comment,
            'status' => (string) ($entry['mfw_status'] ?? WorkHoursStore::STATUS_ATTENDED),
            'created_at' => isset($entry['mfw_timestamp_create']) ? (string) $entry['mfw_timestamp_create'] : null,
            'changed_at' => isset($entry['mfw_timestamp_change']) ? (string) $entry['mfw_timestamp_change'] : null
        );
    }

    public static function assertYear(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new WorkApiException('Invalid year ' . $year . '.', 'validation_failed', 422);
        }
    }

    // ------------------------------------------------------------------
    // Datenbank
    // ------------------------------------------------------------------

    private static function assertWriteAllowed(): void
    {
        if (!self::currentUserMayWrite()) {
            throw new WorkApiException('The current user may not record work service hours.', 'forbidden', 403);
        }
    }

    private static function assertYearOpen(int $year): void
    {
        if (WorkYearLock::isLocked($year)) {
            throw new WorkApiException('Work service ' . $year . ' is already billed; changes only in Admidio.', 'year_locked', 409, array('year' => $year));
        }
    }

    private static function userIdByUuid(string $userUuid): int
    {
        global $gDb;

        $userUuid = trim($userUuid);
        if ($userUuid === '') {
            throw new WorkApiException('User not found.', 'user_not_found', 404);
        }
        $usrId = (int) $gDb->queryPrepared('SELECT usr_id FROM ' . TBL_USERS . ' WHERE usr_uuid = ? AND usr_valid = true', array($userUuid))->fetchColumn();
        if ($usrId <= 0) {
            throw new WorkApiException('User not found.', 'user_not_found', 404);
        }
        return $usrId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function findEntryOfUser(int $id, int $usrId): array
    {
        $entry = WorkHoursStore::find($id);
        if ($entry === null || (int) $entry['mfw_usr_id'] !== $usrId) {
            throw new WorkApiException('Work service entry not found.', 'not_found', 404, array('id' => $id));
        }
        return $entry;
    }

    /**
     * Profilfeld "Sollstunden Arbeitsdienst" (ADTARGET<org>); null, wenn leer oder nicht vorhanden.
     */
    private static function targetHours(int $usrId): ?float
    {
        global $gDb, $gProfileFields, $gCurrentOrgId;

        $usfId = (int) $gProfileFields->getProperty(WorkProfileFields::targetField((int) $gCurrentOrgId), 'usf_id');
        if ($usfId <= 0) {
            return null;
        }
        $value = $gDb->queryPrepared('SELECT usd_value FROM ' . TBL_USER_DATA . ' WHERE usd_usr_id = ? AND usd_usf_id = ?', array($usrId, $usfId))->fetchColumn();
        $value = trim(str_replace(',', '.', (string) $value));
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /**
     * War das Mitglied im Jahr in mindestens einer Rolle der Organisation (wie mf_work_members_of_year())?
     */
    private static function isMemberInYear(int $usrId, int $year): bool
    {
        global $gDb, $gCurrentOrgId;

        $sql = 'SELECT COUNT(*)
                  FROM ' . TBL_MEMBERS . ' AS mem
            INNER JOIN ' . TBL_ROLES . ' AS rol ON rol.rol_id = mem.mem_rol_id AND rol.rol_valid = true
            INNER JOIN ' . TBL_CATEGORIES . ' AS cat ON cat.cat_id = rol.rol_cat_id AND (cat.cat_org_id = ? OR cat.cat_org_id IS NULL)
                 WHERE mem.mem_usr_id = ?
                   AND mem.mem_begin <= ? AND mem.mem_end >= ?
                   AND cat.cat_type = \'ROL\'';
        return (int) $gDb->queryPrepared($sql, array(
            (int) $gCurrentOrgId,
            $usrId,
            sprintf('%04d-12-31', $year),
            sprintf('%04d-01-01', $year)
        ))->fetchColumn() > 0;
    }
}
