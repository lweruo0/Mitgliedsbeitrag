<?php
/**
 ***********************************************************************************************
 * Rechnungen erstellen (ohne PDF): Nummern vergeben, Kopf speichern, Nummer an die Positionen schreiben
 *
 * Ablauf und Regeln: docs/konzept-rechnungen-pdf.md, Abschnitt 5. Die Auswahl der Positionen und die
 * Faelligkeit berechnet InvoicePlanner (ohne Datenbank, getestet).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Invoices;

use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;

class InvoiceService
{
    /** Standard fuer die Einstellung "Zahlungsziel (Tage)" */
    public const DEFAULT_TERM_DAYS = 14;

    /** Sequenztypen, die beim Erstellen mit dem Einzugsdatum gesetzt werden koennen (AUTO = FRST/RCUR je Mitglied) */
    public const SEQUENCE_TYPES = array('AUTO', 'FRST', 'RCUR', 'FNAL', 'OOFF');

    /** Versuche bei gleichzeitiger Vergabe derselben Nummer */
    private const MAX_ATTEMPTS = 3;

    private CollectionScope $scope;
    private int $termDays;

    /**
     * @param array<string, mixed> $config Plugin-Konfiguration ($pPreferences->config)
     */
    public function __construct(array $config, CollectionScope $scope)
    {
        $this->scope = $scope;
        $days = (int) ($config['Rechnung']['zahlungsziel'] ?? self::DEFAULT_TERM_DAYS);
        $this->termDays = ($days >= 0 && $days <= 365) ? $days : self::DEFAULT_TERM_DAYS;
    }

    public function getScope(): CollectionScope
    {
        return $this->scope;
    }

    public function getTermDays(): int
    {
        return $this->termDays;
    }

    /**
     * Vorschau: welche Rechnungen wuerden fuer diese Mitglieder entstehen?
     *
     * @param array<int, int> $usrIds
     * @return array<int, array{usr_id:int, year:int, amount:float, method:string, due_date:?string, error:string, warnings:array<int,string>}>
     */
    public function preview(array $usrIds, string $today): array
    {
        $result = array();
        if (count($usrIds) === 0) {
            return $result;     // getByUsers() liefert bei leerer Liste alle Mitglieder
        }
        $positionsByUser = BookingStore::getByUsers($usrIds, array(BookingStore::STATUS_OPEN));
        foreach ($usrIds as $usrId) {
            $usrId = (int) $usrId;
            $plan = InvoicePlanner::plan($this->scope->filterPositions($positionsByUser[$usrId] ?? array()));
            if (count($plan) === 0) {
                continue;
            }
            $data = $this->userData($usrId);
            foreach ($plan as $entry) {
                $entryData = $this->entryData($data, $usrId, $entry['year']);
                $result[] = array(
                    'usr_id' => $usrId,
                    'year' => $entry['year'],
                    'amount' => $entry['amount'],
                    'method' => $entryData['method'],
                    'due_date' => InvoicePlanner::dueDate($entryData['method'], $entryData['due_date'], $today, $this->termDays),
                    'error' => $entry['error'],
                    'warnings' => $entryData['warnings']
                );
            }
        }
        return $result;
    }

    /**
     * Rechnungen fuer die angegebenen Mitglieder anlegen (idempotent: Positionen mit Nummer werden uebersprungen).
     *
     * Lastschrift-Zahler ohne Faelligkeit erhalten dabei $debitDate als Faelligkeit (mit Sequenztyp); ohne
     * $debitDate werden sie uebersprungen (Grund "duedate"). Vorhandene Faelligkeiten bleiben unveraendert.
     *
     * @param array<int, int> $usrIds
     * @param string $today Rechnungsdatum (Y-m-d, Vereinszeitzone)
     * @param string $debitDate Einzugsdatum (Y-m-d, vom Aufrufer geprueft) fuer Lastschrift-Zahler ohne Faelligkeit, '' = keins
     * @param string $sequenceType Sequenztyp dazu (siehe SEQUENCE_TYPES)
     * @param string $transferDue Zahlungsziel (Y-m-d) fuer Ueberweiser, '' = Rechnungsdatum + Zahlungsziel aus den Einstellungen
     * @param bool $overwriteDue vorhandene Faelligkeiten der Lastschrift-Zahler durch $debitDate ersetzen
     * @return array{created: array<int, array{usr_id:int, invoice_no:string, amount:float, warnings:array<int,string>, pdf_error:string}>,
     *               skipped: array<int, array{usr_id:int, year:int, reason:string}>}
     */
    public function create(array $usrIds, string $today, string $debitDate = '', string $sequenceType = 'AUTO', string $transferDue = '', bool $overwriteDue = false): array
    {
        global $gDb;

        InvoiceStore::ensureTable();
        $created = array();
        $skipped = array();
        if (count($usrIds) === 0) {
            return array('created' => $created, 'skipped' => $skipped);
        }
        $positionsByUser = BookingStore::getByUsers($usrIds, array(BookingStore::STATUS_OPEN));
        $pdfAvailable = PdfBridge::isAvailable();
        $autoTypes = ($debitDate !== '' && $sequenceType === 'AUTO') ? mf_auto_sequence_types($usrIds) : array();

        foreach ($usrIds as $usrId) {
            $usrId = (int) $usrId;
            $plan = InvoicePlanner::plan($this->scope->filterPositions($positionsByUser[$usrId] ?? array()));
            if (count($plan) === 0) {
                continue;
            }
            $data = $this->userData($usrId);

            // Lastschrift: Einzugsdatum von der Seite setzen (ohne Faelligkeit immer, vorhandene nur mit $overwriteDue)
            if ($this->hasValidSepaEntry($plan, $data, $usrId)) {
                if ($data['due_date'] === '' && $debitDate === '') {
                    foreach ($plan as $entry) {
                        $skipped[] = array('usr_id' => $usrId, 'year' => $entry['year'], 'reason' => 'duedate');
                    }
                    continue;
                }
                if ($debitDate !== '' && ($data['due_date'] === '' || $overwriteDue)) {
                    $this->setDueDate($usrId, $debitDate, $sequenceType === 'AUTO' ? ($autoTypes[$usrId] ?? 'FRST') : $sequenceType);
                    $data['due_date'] = $debitDate;
                    $data['warnings'] = array_values(array_diff($data['warnings'], array('duedate')));
                }
            }

            foreach ($plan as $entry) {
                if ($entry['error'] !== '') {
                    $skipped[] = array('usr_id' => $usrId, 'year' => $entry['year'], 'reason' => $entry['error']);
                    continue;
                }
                $entryData = $this->entryData($data, $usrId, $entry['year']);
                if ($entryData['method'] === InvoicePlanner::METHOD_MANUAL && $transferDue !== '') {
                    $entryData['due_date'] = $transferDue;     // Zahlungsziel von der Seite
                }
                $invoiceNo = $this->createOne($usrId, $entry, $entryData, $today);
                if ($invoiceNo === null) {
                    $skipped[] = array('usr_id' => $usrId, 'year' => $entry['year'], 'reason' => 'changed');
                    continue;
                }
                // PDF erzeugen, wenn die Bibliothek verfuegbar ist; die Rechnung bleibt auch ohne PDF gueltig
                $pdfError = $pdfAvailable ? $this->createPdf($invoiceNo) : '';
                $created[] = array('usr_id' => $usrId, 'invoice_no' => $invoiceNo, 'amount' => $entry['amount'], 'warnings' => $this->entryData($data, $usrId, $entry['year'])['warnings'], 'pdf_error' => $pdfError);
            }
        }
        return array('created' => $created, 'skipped' => $skipped);
    }

    /**
     * PDF einer Rechnung erzeugen (bzw. neu erzeugen) und den Dateinamen im Kopf speichern.
     * Eine stornierte Rechnung erhaelt die Fassung mit Storno-Vermerk (die ausgestellte Datei bleibt).
     *
     * @return string Fehlermeldung, leer bei Erfolg
     */
    public function createPdf(string $invoiceNo): string
    {
        try {
            $invoice = InvoiceStore::find($invoiceNo);
            if ($invoice === null) {
                return 'Rechnung nicht gefunden';
            }
            $file = PdfBridge::create($invoiceNo, (string) $invoice['mfi_kind']);
            if ($invoice['status'] === InvoiceStatus::CANCELLED) {
                InvoiceStore::update($invoiceNo, array('mfi_cancel_file' => $file));
            } else {
                // Rechnungsbetrag wie im PDF: Summe der nicht stornierten Positionen (aendert sich nach
                // Storno mit erneutem Einzug, Gebuehr oder "Erlassen / Reduzieren")
                $amount = 0.0;
                foreach (BookingStore::getByInvoice($invoiceNo) as $position) {
                    if ($position['mfb_status'] !== BookingStore::STATUS_CANCELLED) {
                        $amount += (float) $position['mfb_amount'];
                    }
                }
                InvoiceStore::update($invoiceNo, array('mfi_pdf_file' => $file, 'mfi_amount' => round($amount, 2)));
            }
            return '';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Eine Rechnung in einer Transaktion anlegen.
     *
     * @param array{year:int, position_ids:array<int,int>, amount:float, error:string} $entry
     * @param array{method:string, due_date:string, mandate_id:string, iban_masked:string, warnings:array<int,string>} $data
     * @return string|null Rechnungsnummer, oder null, wenn sich die Positionen inzwischen geaendert haben
     */
    private function createOne(int $usrId, array $entry, array $data, string $today): ?string
    {
        global $gDb;

        $kind = $this->scope->key();
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $gDb->startTransaction();
            $seq = InvoiceStore::nextSeq($entry['year'], $kind);
            $invoiceNo = InvoiceNumber::format($entry['year'], $kind, $seq);
            $id = InvoiceStore::insert(array(
                'mfi_usr_id' => $usrId,
                'mfi_year' => $entry['year'],
                'mfi_kind' => $kind,
                'mfi_invoice_no' => $invoiceNo,
                'mfi_seq' => $seq,
                'mfi_invoice_date' => $today,
                'mfi_due_date' => InvoicePlanner::dueDate($data['method'], $data['due_date'], $today, $this->termDays),
                'mfi_amount' => $entry['amount'],
                'mfi_method' => $data['method'],
                'mfi_mandate_id' => $data['method'] === InvoicePlanner::METHOD_SEPA ? $data['mandate_id'] : '',
                'mfi_iban_masked' => $data['method'] === InvoicePlanner::METHOD_SEPA ? $data['iban_masked'] : ''
            ));
            if ($id === null) {
                // Nummer gleichzeitig vergeben: neu lesen und erneut versuchen
                $gDb->rollback();
                continue;
            }
            if (BookingStore::assignInvoiceNo($entry['position_ids'], $invoiceNo) !== count($entry['position_ids'])) {
                // Positionen wurden zwischenzeitlich geaendert (z. B. exportiert oder schon abgerechnet)
                $gDb->rollback();
                return null;
            }
            $gDb->endTransaction();
            return $invoiceNo;
        }
        throw new \RuntimeException('Rechnungsnummer konnte nicht vergeben werden (' . $entry['year'] . ', ' . $kind . ').');
    }

    /**
     * Gibt es im Plan ein abrechenbares Beitragsjahr, das per Lastschrift bezahlt wird?
     *
     * @param array<int, array{year:int, error:string}> $plan
     * @param array{method:string} $data
     */
    private function hasValidSepaEntry(array $plan, array $data, int $usrId): bool
    {
        foreach ($plan as $entry) {
            if ($entry['error'] === '' && $this->entryData($data, $usrId, $entry['year'])['method'] === InvoicePlanner::METHOD_SEPA) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mitgliedsdaten fuer ein Beitragsjahr: eine abweichende Zahlart (PaymentMethodStore, "Verschieben" in 2.0/2.1)
     * macht aus der Lastschrift eine Ueberweisung; dann ohne Mandat, IBAN und Faelligkeit.
     *
     * @param array{method:string, due_date:string, mandate_id:string, iban_masked:string, warnings:array<int,string>} $data
     * @return array{method:string, due_date:string, mandate_id:string, iban_masked:string, warnings:array<int,string>}
     */
    private function entryData(array $data, int $usrId, int $year): array
    {
        $method = InvoicePlanner::effectiveMethod($data['method'], PaymentMethodStore::get($usrId, $year, $this->scope->key()));
        if ($method === $data['method']) {
            return $data;
        }
        $data['method'] = $method;
        $data['due_date'] = '';
        $data['mandate_id'] = '';
        $data['iban_masked'] = '';
        $data['warnings'] = array_values(array_diff($data['warnings'], array('duedate')));
        return $data;
    }

    /**
     * Faelligkeit und Sequenztyp im Profil des Mitglieds setzen (Felder des Buchungskreises).
     */
    private function setDueDate(int $usrId, string $dueDate, string $sequenceType): void
    {
        global $gDb, $gProfileFields;

        $user = new User($gDb, $gProfileFields, $usrId);
        $user->setValue($this->scope->field(CollectionScope::FIELD_DUEDATE), $dueDate);
        $user->setValue($this->scope->field(CollectionScope::FIELD_SEQUENCETYPE), $sequenceType);
        $user->save();
    }

    /**
     * Zahlart, Faelligkeit und Hinweise eines Mitglieds.
     *
     * @return array{method:string, due_date:string, mandate_id:string, iban_masked:string, warnings:array<int,string>}
     */
    private function userData(int $usrId): array
    {
        global $gDb, $gProfileFields, $gCurrentOrgId;

        $user = new User($gDb, $gProfileFields, $usrId);
        $iban = (string) $user->getValue('IBAN');
        $mandateId = (string) $user->getValue('MANDATEID' . (int) $gCurrentOrgId);
        $method = InvoicePlanner::method($iban, $mandateId);
        $dueDate = (string) $user->getValue($this->scope->field(CollectionScope::FIELD_DUEDATE), 'Y-m-d');

        $warnings = array();
        // Anschrift wie im Rechnungs-Export (bill_export.php): Strasse, PLZ und Ort des Mitglieds
        if (trim((string) $user->getValue('STREET')) === '' || trim((string) $user->getValue('CITY')) === '') {
            $warnings[] = 'address';
        }
        if ($method === InvoicePlanner::METHOD_SEPA && $dueDate === '') {
            $warnings[] = 'duedate';
        }

        return array(
            'method' => $method,
            'due_date' => $dueDate,
            'mandate_id' => $mandateId,
            'iban_masked' => InvoicePlanner::maskIban($iban),
            'warnings' => $warnings
        );
    }
}
