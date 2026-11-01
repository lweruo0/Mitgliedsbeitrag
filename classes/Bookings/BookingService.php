<?php
/**
 ***********************************************************************************************
 * Ablaeufe der Buchungshistorie des Admidio-Plugins Mitgliedsbeitrag
 *
 * Verbindet die Buchungspositionen (BookingStore) mit den Arbeitsfeldern im Profil
 * (Beitrag, Beitragstext, Faelligkeitsdatum, Bezahlt, Sequenztyp, Mandatsaenderung).
 *
 * Grundregel: Solange ein Mitglied keinen laufenden Einzug hat, ist das Profilfeld Beitrag
 * die Summe seiner offenen Positionen.
 *
 * Alle Ablaeufe beziehen sich auf einen Buchungskreis (CollectionScope): Mitgliedsbeitrag (Standard)
 * oder Arbeitsdienst. Positionen anderer Buchungskreise werden dabei nicht beruecksichtigt.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Bookings;

use Admidio\Roles\Entity\Role;
use Admidio\Users\Entity\User;

class BookingService
{
    /** Bezeichnung fuer automatisch angelegte Ausgleichspositionen */
    public const LABEL_PROFILE_FEE = 'Beitrag (Profilfeld)';
    public const LABEL_PROFILE_CORRECTION = 'Korrektur Profilfeld Beitrag';

    /** Standard-Gueltigkeit eines geteilten Download-Links in Tagen */
    public const SHARE_DEFAULT_DAYS = 14;

    /** @var array<string, mixed> Konfiguration des Plugins */
    private array $config;

    /** Einzug, der beim letzten registerSepaExport() angelegt bzw. wiederverwendet wurde */
    private ?int $lastRunId = null;

    /** Buchungskreis, auf den sich die Ablaeufe beziehen */
    private CollectionScope $scope;

    /**
     * @param array<string, mixed> $config $pPreferences->config
     * @param CollectionScope|null $scope Buchungskreis; null = Mitgliedsbeitrag
     */
    public function __construct(array $config, ?CollectionScope $scope = null)
    {
        global $gCurrentOrgId;

        $this->config = $config;
        $this->scope = $scope ?? CollectionScope::membership((int) $gCurrentOrgId);
        BookingStore::ensureTables();
    }

    public function getScope(): CollectionScope
    {
        return $this->scope;
    }

    /**
     * Dieselbe Konfiguration fuer einen anderen Buchungskreis.
     */
    public function withScope(CollectionScope $scope): self
    {
        if ($scope->key() === $this->scope->key()) {
            return $this;
        }
        $service = clone $this;
        $service->scope = $scope;
        $service->lastRunId = null;
        return $service;
    }

    /**
     * Positionen eines Mitglieds in diesem Buchungskreis.
     * @param array<int, string> $status
     * @return array<int, array<string, mixed>>
     */
    private function scopedPositions(int $usrId, array $status = array()): array
    {
        return array_values($this->scope->filterPositions(BookingStore::getByUser($usrId, $status)));
    }

    /**
     * Interner Name eines Arbeitsfelds dieses Buchungskreises (siehe CollectionScope::FIELD_*).
     */
    private function field(string $name): string
    {
        return $this->scope->field($name);
    }

    // ------------------------------------------------------------------
    // Konfiguration
    // ------------------------------------------------------------------

    public function getPrefix(): string
    {
        // der Text der Arbeitsdienst-Position ist schon vollstaendig (siehe WorkBillingPlanner::text())
        if (!$this->scope->isMembership()) {
            return '';
        }
        return trim((string) ($this->config['Beitrag']['beitrag_prefix'] ?? ''));
    }

    /**
     * @return array<int, array{name:string, roles:array<int,int>}> gueltige Beitragsgruppen
     */
    public function getGroups(): array
    {
        $names = (array) ($this->config['Beitragsgruppen']['name'] ?? array());
        $roles = (array) ($this->config['Beitragsgruppen']['roles'] ?? array());
        $groups = array();
        foreach ($names as $index => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $ids = array();
            foreach (explode(',', (string) ($roles[$index] ?? '')) as $rolId) {
                if ((int) $rolId > 0) {
                    $ids[] = (int) $rolId;
                }
            }
            $groups[(int) $index] = array('name' => trim((string) $name), 'roles' => $ids);
        }
        return $groups;
    }

    /**
     * @return array<int, array{name:string, amount:string, text:string}> Vorlagen fuer manuelle Positionen
     */
    public function getTemplates(): array
    {
        $names = (array) ($this->config['Positionsvorlagen']['name'] ?? array());
        $amounts = (array) ($this->config['Positionsvorlagen']['amount'] ?? array());
        $texts = (array) ($this->config['Positionsvorlagen']['text'] ?? array());
        $templates = array();
        foreach ($names as $index => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $templates[(int) $index] = array(
                'name' => trim((string) $name),
                'amount' => trim((string) ($amounts[$index] ?? '')),
                'text' => trim((string) ($texts[$index] ?? ''))
            );
        }
        return $templates;
    }

    /**
     * Beitragsrollen fuer den Planer inkl. Gruppenzuordnung.
     * @return array<int, array{name:string, cost:float, description:string, group:?int}>
     */
    public function getPlannerRoles(): array
    {
        $groupMap = ContributionPlanner::groupIndexByRole(
            (array) ($this->config['Beitragsgruppen']['name'] ?? array()),
            (array) ($this->config['Beitragsgruppen']['roles'] ?? array())
        );
        $roles = array();
        foreach (beitragsrollen_einlesen() as $rolId => $data) {
            $roles[(int) $rolId] = array(
                'name' => (string) $data['rolle'],
                'cost' => (float) $data['rol_cost'],
                'description' => (string) $data['rol_description'],
                'group' => $groupMap[(int) $rolId] ?? null
            );
        }
        return $roles;
    }

    // ------------------------------------------------------------------
    // Neuberechnung
    // ------------------------------------------------------------------

    /**
     * Plant die Neuberechnung fuer alle betroffenen Mitglieder.
     *
     * @param array<int, int>|null $selectedRoleIds
     * @return array<int, array{waiting:bool, ops:array, info:array}> usr_id => Plan (nur Mitglieder mit Aenderungen oder Hinweisen)
     */
    public function planRecalculation(?array $selectedRoleIds = null): array
    {
        global $gCurrentOrgId;

        $year = getContributionYear();
        $roles = $this->getPlannerRoles();
        $membership = CollectionScope::membership((int) $gCurrentOrgId);

        // Mitglieder je Rolle im Beitragsjahr
        $memberRoles = array();
        foreach ($roles as $rolId => $dummy) {
            foreach (list_members_in_year($rolId, $year) as $usrId) {
                $memberRoles[(int) $usrId][] = (int) $rolId;
            }
        }

        // auch Mitglieder mit offenen Rollenpositionen (z. B. Rolle inzwischen beendet)
        // nur Positionen des Mitgliedsbeitrags (ein laufender Einzug im Arbeitsdienst blockiert die Neuberechnung nicht)
        $allPositions = array();
        foreach (BookingStore::getByUsers(array()) as $usrId => $positions) {
            $allPositions[$usrId] = array_values($membership->filterPositions($positions));
        }
        foreach ($allPositions as $usrId => $positions) {
            foreach ($positions as $position) {
                if ($position['mfb_status'] === BookingStore::STATUS_OPEN && $position['mfb_source'] === BookingStore::SOURCE_ROLE && (int) $position['mfb_year'] === $year && !isset($memberRoles[$usrId])) {
                    $memberRoles[$usrId] = array();
                }
            }
        }

        $options = array(
            'prefix' => $this->getPrefix(),
            'floor' => !empty($this->config['Beitrag']['beitrag_abrunden']),
            'min' => (float) ($this->config['Beitrag']['beitrag_mindestbetrag'] ?? 0)
        );

        $plans = array();
        foreach ($memberRoles as $usrId => $rolIds) {
            $plan = ContributionPlanner::planMember($roles, $rolIds, $allPositions[$usrId] ?? array(), $year, $selectedRoleIds, $options);
            if ($plan['waiting'] || count($plan['ops']) > 0) {
                $plans[(int) $usrId] = $plan;
            }
        }
        return $plans;
    }

    /**
     * Fuehrt geplante Aenderungen fuer ein Mitglied aus und aktualisiert die Profilfelder.
     * @param array<int, array<string, mixed>> $ops
     */
    public function applyOps(int $usrId, array $ops): void
    {
        foreach ($ops as $op) {
            switch ($op['op']) {
                case 'insert':
                    BookingStore::insert(array_merge($op['data'], array('mfb_usr_id' => $usrId)));
                    break;
                case 'update':
                    $current = BookingStore::find((int) $op['id']);
                    if ($current !== null && $current['mfb_usr_id'] === $usrId && $current['mfb_status'] === BookingStore::STATUS_OPEN) {
                        BookingStore::update((int) $op['id'], $op['data']);
                    }
                    break;
                case 'delete':
                    $current = BookingStore::find((int) $op['id']);
                    if ($current !== null && $current['mfb_usr_id'] === $usrId && $current['mfb_status'] === BookingStore::STATUS_OPEN) {
                        BookingStore::delete((int) $op['id']);
                    }
                    break;
            }
        }
        $this->syncWorkFields($usrId);
    }

    /**
     * Ueberschreibt Beitrag und Beitragstext im Profil mit der Summe der offenen Positionen.
     * Bei laufendem Einzug (exportierte Positionen) bleibt das Profil unveraendert.
     */
    public function syncWorkFields(int $usrId): void
    {
        global $gDb, $gProfileFields;

        $positions = $this->scopedPositions($usrId);
        $open = array();
        foreach ($positions as $position) {
            if ($position['mfb_status'] === BookingStore::STATUS_EXPORTED) {
                return;
            }
            if ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
                $open[] = $position;
            }
        }

        $user = new User($gDb, $gProfileFields, $usrId);

        if (count($open) > 0) {
            $fields = ContributionPlanner::buildWorkFields($open, $this->getPrefix(), (string) ($this->config['Beitrag']['beitrag_role_separator'] ?? ''), $this->nameSuffix($user));
            $user->setValue($this->field(CollectionScope::FIELD_FEE), $fields['fee']);
            $user->setValue($this->field(CollectionScope::FIELD_TEXT), $fields['text']);
            if ((string) $user->getValue($this->field(CollectionScope::FIELD_PAID)) !== '') {
                // neue offene Positionen nach einer Zahlung: Bezahlt-Datum leeren, damit wieder ein Faelligkeitsdatum gesetzt werden kann
                $user->setValue($this->field(CollectionScope::FIELD_PAID), '');
            }
        } elseif ((string) $user->getValue($this->field(CollectionScope::FIELD_PAID)) === '') {
            // nichts mehr offen und nichts bezahlt: Arbeitsfelder leeren
            $user->setValue($this->field(CollectionScope::FIELD_FEE), '');
            $user->setValue($this->field(CollectionScope::FIELD_TEXT), '');
            $user->setValue($this->field(CollectionScope::FIELD_DUEDATE), '');
        }
        $user->save();
    }

    /**
     * Beitragstext mit dem Namen des Mitglieds, wenn ein abweichender Kontoinhaber eingetragen ist (wie bisher).
     */
    private function nameSuffix(User $user): string
    {
        if (empty($this->config['Beitrag']['beitrag_textmitnam'])) {
            return '';
        }
        $debtor = trim((string) $user->getValue('DEBTOR'));
        $first = (string) $user->getValue('FIRST_NAME');
        $last = (string) $user->getValue('LAST_NAME');
        if ($debtor === '' || $debtor === $last . ' ' . $first || $debtor === $first . ' ' . $last) {
            return '';
        }
        $token = (string) ($this->config['Beitrag']['beitrag_text_token'] ?? '#');
        $token = ($token === '#') ? ' ' : $token;
        return ' ' . $token . $last . ' ' . $first . $token;
    }

    // ------------------------------------------------------------------
    // Individualbeitraege
    // ------------------------------------------------------------------

    /**
     * @param array<string, array{amount:float, text:string, rol_id:?int}> $desired
     * @param array<int, string> $configuredKeys
     * @return array<int, array<string, mixed>>
     */
    public function planIndividual(int $usrId, array $desired, array $configuredKeys): array
    {
        global $gCurrentOrgId;

        $positions = array_values(CollectionScope::membership((int) $gCurrentOrgId)->filterPositions(BookingStore::getByUser($usrId)));
        foreach ($positions as $position) {
            if ($position['mfb_status'] === BookingStore::STATUS_EXPORTED) {
                return array();
            }
        }
        return ContributionPlanner::planIndividual($desired, $configuredKeys, $positions, getContributionYear());
    }

    // ------------------------------------------------------------------
    // SEPA-Export
    // ------------------------------------------------------------------

    /**
     * EndToEnd-ID im Mitgliedsbeitrag (bisheriges Format); fuer andere Buchungskreise CollectionScope::endToEndId().
     */
    public static function endToEndId(string $orgShortName, int $usrId, string $dueDate): string
    {
        return CollectionScope::membership(0)->endToEndId($orgShortName, $usrId, $dueDate);
    }

    /**
     * Legt beim Erzeugen einer SEPA-XML-Datei einen Einzug an und setzt die offenen Positionen
     * der enthaltenen Mitglieder auf "exportiert".
     *
     * @param array<int, array<string, mixed>> $debtors $zpflgt aus dem SEPA-Export (usr_id => Daten)
     * @return string Message-ID fuer die XML-Datei
     */
    public function registerSepaExport(array $debtors, string $fileName, string $format, string $orgShortName): string
    {
        $year = getContributionYear();
        $dueDates = array();
        $amount = 0.0;
        foreach ($debtors as $data) {
            $dueDates[$data['duedate'] . ' ' . $data['sequencetype']] = true;
            $amount += (float) $data['betrag'];
        }
        $dueDateText = array();
        foreach (array_keys($dueDates) as $entry) {
            $date = \DateTime::createFromFormat('Y-m-d', substr($entry, 0, 10));
            $dueDateText[] = ($date ? $date->format('d.m.Y') : substr($entry, 0, 10)) . substr($entry, 10);
        }

        $runId = BookingStore::insertRun(array(
            'mfr_file_name' => $fileName,
            'mfr_format' => $format,
            'mfr_due_dates' => mb_substr(implode(', ', $dueDateText), 0, 255),
            'mfr_count' => count($debtors),
            'mfr_amount' => $amount,
            'mfr_status' => BookingStore::RUN_CREATED,
            'mfr_kind' => $this->scope->key()
        ));
        $msgId = $this->scope->messageId($orgShortName, $runId, date('YmdHis'));
        BookingStore::updateRun($runId, array('mfr_msg_id' => $msgId));
        $this->lastRunId = $runId;

        $newlyExported = 0;
        $existingRunIds = array();

        foreach ($debtors as $usrId => $data) {
            $usrId = (int) $usrId;
            $positions = $this->scopedPositions($usrId);
            $open = array();
            $alreadyExported = false;
            foreach ($positions as $position) {
                if ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
                    $open[] = $position;
                } elseif ($position['mfb_status'] === BookingStore::STATUS_EXPORTED && (string) $position['mfb_due_date'] === (string) $data['duedate']) {
                    $alreadyExported = true;
                    if ($position['mfb_run_id'] !== null) {
                        $existingRunIds[$position['mfb_run_id']] = true;
                    }
                }
            }
            if (count($open) === 0 && $alreadyExported) {
                continue;   // erneuter Export derselben Lastschrift
            }

            // Abweichung zwischen Profilfeld Beitrag und offenen Positionen ausgleichen,
            // damit die Historie genau den eingezogenen Betrag zeigt
            $sum = 0.0;
            foreach ($open as $position) {
                $sum += (float) $position['mfb_amount'];
            }
            $fee = round((float) $data['betrag'], 2);
            $difference = round($fee - $sum, 2);
            if (abs($difference) >= 0.005) {
                $label = (count($open) === 0) ? self::LABEL_PROFILE_FEE : self::LABEL_PROFILE_CORRECTION;
                $newId = BookingStore::insert(array(
                    'mfb_usr_id' => $usrId,
                    'mfb_year' => $year,
                    'mfb_source' => $this->scope->manualSource(),
                    'mfb_rol_name' => $label,
                    'mfb_amount' => $difference,
                    'mfb_text' => (string) $data['text'],
                    'mfb_status' => BookingStore::STATUS_OPEN
                ));
                $open[] = BookingStore::find($newId);
            }

            foreach ($open as $position) {
                BookingStore::update((int) $position['mfb_id'], array(
                    'mfb_status' => BookingStore::STATUS_EXPORTED,
                    'mfb_method' => BookingStore::METHOD_SEPA,
                    'mfb_run_id' => $runId,
                    'mfb_due_date' => $data['duedate'],
                    'mfb_reference' => $data['end2end_id'],
                    'mfb_mandate_id' => $data['mandat_id'],
                    'mfb_sequence_type' => $data['sequencetype'],
                    'mfb_orig_mandate_id' => (string) $data['orig_mandat_id'],
                    'mfb_orig_iban' => (string) $data['orig_iban'],
                    'mfb_orig_debtor_agent' => (string) $data['orig_dbtr_agent']
                ));
                $newlyExported++;
            }
        }

        if ($newlyExported === 0) {
            // reiner erneuter Export: vorhandenen Einzug weiterverwenden
            BookingStore::deleteRun($runId);
            $this->lastRunId = null;
            if (count($existingRunIds) === 1) {
                $existing = BookingStore::findRun((int) array_key_first($existingRunIds));
                if ($existing !== null && (string) $existing['mfr_msg_id'] !== '') {
                    $this->lastRunId = (int) $existing['mfr_id'];
                    return (string) $existing['mfr_msg_id'];
                }
            }
        }
        return $msgId;
    }

    /**
     * Speichert eine erzeugte Datei (SEPA-Datei bzw. Kontrolldatei) beim zuletzt registrierten Einzug
     * und entfernt gespeicherte Dateien frueherer Beitragsjahre.
     */
    public function storeExportFile(string $fileName, string $content, string $kind = BookingStore::FILE_XML): void
    {
        $year = getContributionYear();
        if ($this->lastRunId !== null) {
            BookingStore::saveRunFile($this->lastRunId, $year, $fileName, $content, $kind);
        }
        BookingStore::purgeFilesBefore($year);
    }

    // ------------------------------------------------------------------
    // Geteilte Download-Links
    // ------------------------------------------------------------------

    /**
     * @return array{emails: array<int, string>, days: int}
     */
    public function getShareSettings(): array
    {
        $emails = array();
        foreach (preg_split('/[\s,;]+/', (string) ($this->config['SEPA-Freigabe']['email'] ?? '')) as $email) {
            $email = trim($email);
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        $days = (int) ($this->config['SEPA-Freigabe']['tage'] ?? self::SHARE_DEFAULT_DAYS);
        return array('emails' => $emails, 'days' => ($days > 0) ? $days : self::SHARE_DEFAULT_DAYS);
    }

    /**
     * Erzeugt einen neuen Download-Link fuer die gespeicherte Datei eines Einzugs.
     * Ein vorher erzeugter Link wird dadurch ungueltig. In der Datenbank steht nur der Hash des Tokens.
     *
     * @return array{url: string, url_control: string, expires: string} Links (SEPA-Datei, Kontrolldatei falls gespeichert) und Ablaufzeitpunkt (Y-m-d H:i:s)
     */
    public function createShare(int $runId, string $email, int $days): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + max(1, $days) * 86400);
        BookingStore::setShare($runId, hash('sha256', $token), $expires, $email);
        $url = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/sepa_download.php?token=' . $token;
        return array(
            'url' => $url,
            'url_control' => (BookingStore::findRunFileMeta($runId, BookingStore::FILE_CONTROL) !== null) ? $url . '&file=' . BookingStore::FILE_CONTROL : '',
            'expires' => $expires
        );
    }

    // ------------------------------------------------------------------
    // Eingezogen / bezahlt markieren
    // ------------------------------------------------------------------

    /**
     * Markiert ein Mitglied als bezahlt (Profilfelder wie bisher) und setzt seine Positionen auf "eingezogen".
     *
     * @param int|null $runId nur Positionen dieses Einzugs (Einzugsuebersicht); null = alle exportierten bzw. offenen
     * @return bool true, wenn das Mitglied markiert wurde
     */
    public function markPaid(User $user, string $date, ?int $runId = null): bool
    {
        global $gCurrentOrgId;
        $orgId = (int) $gCurrentOrgId;
        $usrId = (int) $user->getValue('usr_id');

        if ((string) $user->getValue($this->field(CollectionScope::FIELD_PAID)) !== '') {
            return false;
        }

        $positions = $this->scopedPositions($usrId);
        $exported = array();
        $open = array();
        foreach ($positions as $position) {
            if ($position['mfb_status'] === BookingStore::STATUS_EXPORTED && ($runId === null || $position['mfb_run_id'] === $runId)) {
                $exported[] = $position;
            } elseif ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
                $open[] = $position;
            }
        }

        if (count($exported) > 0) {
            foreach ($exported as $position) {
                BookingStore::update((int) $position['mfb_id'], array(
                    'mfb_status' => BookingStore::STATUS_COLLECTED,
                    'mfb_booking_date' => $date
                ));
            }
            $this->updateRunStatus(array_unique(array_filter(array_column($exported, 'mfb_run_id'))), $date);
        } elseif ($runId === null) {
            // Zahlung ohne SEPA-Datei (Ueberweisung): offene Positionen mit Einzug MAN
            $fee = round((float) str_replace(',', '.', (string) $user->getValue($this->field(CollectionScope::FIELD_FEE))), 2);
            $sum = 0.0;
            foreach ($open as $position) {
                $sum += (float) $position['mfb_amount'];
            }
            $difference = round($fee - $sum, 2);
            if (abs($difference) >= 0.005) {
                $newId = BookingStore::insert(array(
                    'mfb_usr_id' => $usrId,
                    'mfb_year' => getContributionYear(),
                    'mfb_source' => $this->scope->manualSource(),
                    'mfb_rol_name' => count($open) === 0 ? self::LABEL_PROFILE_FEE : self::LABEL_PROFILE_CORRECTION,
                    'mfb_amount' => $difference,
                    'mfb_text' => (string) $user->getValue($this->field(CollectionScope::FIELD_TEXT)),
                    'mfb_status' => BookingStore::STATUS_OPEN
                ));
                $open[] = BookingStore::find($newId);
            }
            foreach ($open as $position) {
                BookingStore::update((int) $position['mfb_id'], array(
                    'mfb_status' => BookingStore::STATUS_COLLECTED,
                    'mfb_method' => BookingStore::METHOD_MANUAL,
                    'mfb_booking_date' => $date
                ));
            }
        } else {
            return false;
        }

        // Profilfelder wie bisher (payments.php)
        $user->setValue($this->field(CollectionScope::FIELD_PAID), $date);
        // nach der Erstlastschrift (FRST, frueher als leerer Wert gespeichert) folgen Folgelastschriften
        $currentSequenceType = (string) $user->getValue($this->field(CollectionScope::FIELD_SEQUENCETYPE));
        if (($currentSequenceType === '' || $currentSequenceType === 'FRST') && strlen((string) $user->getValue($this->field(CollectionScope::FIELD_DUEDATE))) !== 0) {
            $user->setValue($this->field(CollectionScope::FIELD_SEQUENCETYPE), 'RCUR');
        }
        // Mandatsaenderung (ORIG_*) gilt fuer das Mandat und damit fuer alle Buchungskreise
        foreach (array('ORIG_MANDATEID' . $orgId, 'ORIG_IBAN', 'ORIG_DEBTOR_AGENT', $this->field(CollectionScope::FIELD_DUEDATE)) as $field) {
            if (strlen((string) $user->getValue($field)) !== 0) {
                $user->setValue($field, '');
            }
        }
        $user->save();

        // waehrend des Einzugs neu hinzugekommene offene Positionen (z. B. manuelle Position):
        // Profilfeld Beitrag jetzt auf diese setzen
        if (count($exported) > 0 && count($open) > 0) {
            $this->syncWorkFields($usrId);
        }
        return true;
    }

    /**
     * Setzt einen Einzug auf "eingezogen", sobald keine exportierten Positionen mehr enthalten sind.
     * @param array<int, int> $runIds
     */
    private function updateRunStatus(array $runIds, string $date): void
    {
        foreach ($runIds as $runId) {
            $byUser = BookingStore::getByRun((int) $runId);
            $stillExported = false;
            foreach ($byUser as $positions) {
                foreach ($positions as $position) {
                    if ($position['mfb_status'] === BookingStore::STATUS_EXPORTED) {
                        $stillExported = true;
                        break 2;
                    }
                }
            }
            if (!$stillExported) {
                $run = BookingStore::findRun((int) $runId);
                if ($run !== null && $run['mfr_status'] === BookingStore::RUN_CREATED) {
                    BookingStore::updateRun((int) $runId, array('mfr_status' => BookingStore::RUN_COLLECTED, 'mfr_paid_date' => $date));
                }
            }
        }
    }

    /**
     * Markiert ausgewaehlte Mitglieder eines Einzugs als eingezogen.
     * @param array<int, int> $usrIds
     * @return int Anzahl markierter Mitglieder
     */
    public function markRunCollected(int $runId, array $usrIds, string $date): int
    {
        global $gDb, $gProfileFields;

        $count = 0;
        $byUser = BookingStore::getByRun($runId);
        foreach ($usrIds as $usrId) {
            $usrId = (int) $usrId;
            if (!isset($byUser[$usrId])) {
                continue;
            }
            $user = new User($gDb, $gProfileFields, $usrId);
            if ($this->markPaid($user, $date, $runId)) {
                $count++;
            }
        }
        $run = BookingStore::findRun($runId);
        if ($run !== null && $run['mfr_status'] === BookingStore::RUN_CREATED) {
            BookingStore::updateRun($runId, array('mfr_status' => BookingStore::RUN_COLLECTED, 'mfr_paid_date' => $date));
        }
        return $count;
    }

    /**
     * Verwirft einen Einzug, der nie bei der Bank eingereicht wurde: Positionen zurueck auf "offen".
     */
    public function discardRun(int $runId): void
    {
        $affected = array();
        foreach (BookingStore::getByRun($runId) as $usrId => $positions) {
            foreach ($positions as $position) {
                if ($position['mfb_status'] !== BookingStore::STATUS_EXPORTED) {
                    continue;
                }
                BookingStore::update((int) $position['mfb_id'], array(
                    'mfb_status' => BookingStore::STATUS_OPEN,
                    'mfb_method' => '',
                    'mfb_run_id' => '',
                    'mfb_due_date' => '',
                    'mfb_reference' => '',
                    'mfb_mandate_id' => '',
                    'mfb_sequence_type' => '',
                    'mfb_orig_mandate_id' => '',
                    'mfb_orig_iban' => '',
                    'mfb_orig_debtor_agent' => ''
                ));
                $affected[$usrId] = true;
            }
        }
        BookingStore::updateRun($runId, array('mfr_status' => BookingStore::RUN_DISCARDED));
        BookingStore::deleteRunFile($runId);
        foreach (array_keys($affected) as $usrId) {
            $this->syncWorkFields((int) $usrId);
        }
    }

    /**
     * "Datum loeschen" in den Beitragszahlungen: eingezogene Positionen des Mitglieds mit diesem
     * Bezahlt-Datum zuruecksetzen und die Profilfelder wiederherstellen.
     *
     * @return array<int, string> Hinweise zu Positionen, die nicht zurueckgesetzt werden konnten
     */
    public function revertPaid(User $user): array
    {
        global $gCurrentOrgId;
        $orgId = (int) $gCurrentOrgId;
        $usrId = (int) $user->getValue('usr_id');
        $paidDate = (string) $user->getValue($this->field(CollectionScope::FIELD_PAID), 'Y-m-d');
        $messages = array();

        $positions = $this->scopedPositions($usrId);
        $restore = null;
        foreach ($positions as $position) {
            if ($position['mfb_status'] !== BookingStore::STATUS_COLLECTED || (string) $position['mfb_booking_date'] !== $paidDate) {
                continue;
            }
            // nicht zuruecksetzen, wenn fuer dieselbe Rolle danach schon neu exportiert/eingezogen wurde
            foreach ($positions as $later) {
                if ($later['mfb_id'] > $position['mfb_id'] && $later['mfb_year'] === $position['mfb_year']
                    && $later['mfb_rol_name'] === $position['mfb_rol_name'] && $position['mfb_source'] !== $this->scope->manualSource()
                    && in_array($later['mfb_status'], array(BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED), true)) {
                    $messages[] = $position['mfb_rol_name'];
                    continue 2;
                }
            }
            if ($position['mfb_run_id'] !== null) {
                BookingStore::update((int) $position['mfb_id'], array('mfb_status' => BookingStore::STATUS_EXPORTED, 'mfb_booking_date' => ''));
                $restore = $position;
            } else {
                BookingStore::update((int) $position['mfb_id'], array('mfb_status' => BookingStore::STATUS_OPEN, 'mfb_booking_date' => '', 'mfb_method' => ''));
            }
        }

        $user->setValue($this->field(CollectionScope::FIELD_PAID), '');
        if ($restore !== null) {
            $user->setValue($this->field(CollectionScope::FIELD_DUEDATE), (string) $restore['mfb_due_date']);
            $sequenceType = (string) $restore['mfb_sequence_type'];
            $user->setValue($this->field(CollectionScope::FIELD_SEQUENCETYPE), $sequenceType);
            $user->setValue('ORIG_MANDATEID' . $orgId, (string) $restore['mfb_orig_mandate_id']);
            $user->setValue('ORIG_IBAN', (string) $restore['mfb_orig_iban']);
            $user->setValue('ORIG_DEBTOR_AGENT', (string) $restore['mfb_orig_debtor_agent']);
        }
        $user->save();
        return $messages;
    }

    // ------------------------------------------------------------------
    // Storno und manuelle Positionen
    // ------------------------------------------------------------------

    /**
     * Storniert eine Position, optional mit Rollenentzug und zusaetzlicher Position aus einer Vorlage.
     *
     * @param bool $wholeDebit alle Positionen derselben Lastschrift (gleicher Einzug und gleiche Referenz) stornieren
     * @return string Hinweis (leer, wenn alles geklappt hat)
     */
    public function cancel(int $bookingId, string $date, string $reason, string $comment, bool $revokeRole, ?int $templateIndex, bool $wholeDebit = false): string
    {
        global $gDb, $gProfileFields, $gCurrentOrgId;

        $position = BookingStore::find($bookingId);
        if ($position === null || $position['mfb_status'] === BookingStore::STATUS_CANCELLED) {
            return '';
        }
        $usrId = (int) $position['mfb_usr_id'];
        $note = '';
        // Ablaeufe im Buchungskreis der Position (Arbeitsfelder, Profilsynchronisation)
        $service = $this->withScope(CollectionScope::forSource((string) $position['mfb_source'], (int) $gCurrentOrgId));
        $scope = $service->getScope();

        // zu stornierende Positionen bestimmen
        $toCancel = array($position);
        if ($wholeDebit && $position['mfb_run_id'] !== null) {
            foreach (BookingStore::getByUser($usrId) as $other) {
                if ($other['mfb_id'] !== $position['mfb_id'] && $other['mfb_run_id'] === $position['mfb_run_id']
                    && (string) $other['mfb_reference'] === (string) $position['mfb_reference']
                    && in_array($other['mfb_status'], array(BookingStore::STATUS_EXPORTED, BookingStore::STATUS_COLLECTED), true)) {
                    $toCancel[] = $other;
                }
            }
        }

        $revoke = $revokeRole && $position['mfb_rol_id'] !== null && $position['mfb_source'] === BookingStore::SOURCE_ROLE;
        $firstDebitReturned = false;
        $wasExported = false;
        foreach ($toCancel as $item) {
            if ($item['mfb_status'] === BookingStore::STATUS_EXPORTED) {
                $wasExported = true;
            }
            $isMain = ($item['mfb_id'] === $position['mfb_id']);
            BookingStore::update((int) $item['mfb_id'], array(
                'mfb_status' => BookingStore::STATUS_CANCELLED,
                'mfb_storno_date' => $date,
                'mfb_storno_reason' => $reason,
                'mfb_role_revoked' => $isMain && $revoke,
                'mfb_comment' => trim((string) $item['mfb_comment'] . "\n" . $comment)
            ));
            if ($item['mfb_status'] === BookingStore::STATUS_COLLECTED && $item['mfb_sequence_type'] === 'FRST') {
                $firstDebitReturned = true;
            }
        }

        if ($revoke) {
            $note = $this->endRoleMembership((int) $position['mfb_rol_id'], $usrId, $date);
        }

        // Lastschrift aus einem laufenden Einzug zurueckgegangen: Faelligkeitsdatum leeren,
        // damit fuer den naechsten Versuch ein neues gesetzt wird
        if ($wasExported && count($scope->filterPositions(BookingStore::getByUser($usrId, array(BookingStore::STATUS_EXPORTED)))) === 0) {
            $user = new User($gDb, $gProfileFields, $usrId);
            $user->setValue($scope->field(CollectionScope::FIELD_DUEDATE), '');
            $user->save();
        }

        // Erstlastschrift zurueckgegangen: Sequenztyp wieder auf FRST, wenn mit dem Mandat sonst nichts
        // per SEPA eingezogen ist (ueber alle Buchungskreise, weil FRST/RCUR am Mandat haengt)
        if ($firstDebitReturned) {
            $otherSepa = false;
            foreach (BookingStore::getByUser($usrId, array(BookingStore::STATUS_COLLECTED)) as $other) {
                if ($other['mfb_method'] === BookingStore::METHOD_SEPA) {
                    $otherSepa = true;
                    break;
                }
            }
            if (!$otherSepa) {
                $user = new User($gDb, $gProfileFields, $usrId);
                $user->setValue($scope->field(CollectionScope::FIELD_SEQUENCETYPE), 'FRST');
                $user->save();
            }
        }

        $templates = $this->getTemplates();
        if ($templateIndex !== null && isset($templates[$templateIndex])) {
            $template = $templates[$templateIndex];
            $service->addManual(array($usrId), $template['name'], (float) str_replace(',', '.', $template['amount']), $template['text'] !== '' ? $template['text'] : $template['name'], (int) $position['mfb_year'], 'Zu ' . (string) ($position['mfb_reference'] ?: $position['mfb_rol_name']));
        } else {
            $service->syncWorkFields($usrId);
        }
        return $note;
    }

    /**
     * Beendet die Mitgliedschaft in einer Rolle zum angegebenen Datum.
     */
    private function endRoleMembership(int $rolId, int $usrId, string $date): string
    {
        global $gDb;

        $statement = $gDb->queryPrepared(
            'SELECT mem_begin, mem_end, mem_leader FROM ' . TBL_MEMBERS . ' WHERE mem_rol_id = ? AND mem_usr_id = ? AND mem_end >= ? ORDER BY mem_begin',
            array($rolId, $usrId, $date)
        );
        $membership = $statement->fetch();
        if (!$membership) {
            return '';
        }
        if ($membership['mem_begin'] > $date) {
            return 'Die Mitgliedschaft beginnt erst nach dem Stornodatum und wurde nicht beendet.';
        }
        $role = new Role($gDb, $rolId);
        $role->setMembership($usrId, $membership['mem_begin'], $date, (bool) $membership['mem_leader']);
        return '';
    }

    /**
     * Legt fuer ein oder mehrere Mitglieder eine manuelle offene Position an.
     * @param array<int, int> $usrIds
     * @return int Anzahl angelegter Positionen
     */
    public function addManual(array $usrIds, string $label, float $amount, string $text, int $year, string $comment = ''): int
    {
        $count = 0;
        foreach ($usrIds as $usrId) {
            BookingStore::insert(array(
                'mfb_usr_id' => (int) $usrId,
                'mfb_year' => $year,
                'mfb_source' => $this->scope->manualSource(),
                'mfb_rol_name' => $label,
                'mfb_amount' => $amount,
                'mfb_text' => $text,
                'mfb_status' => BookingStore::STATUS_OPEN,
                'mfb_comment' => $comment
            ));
            $this->syncWorkFields((int) $usrId);
            $count++;
        }
        return $count;
    }
}
