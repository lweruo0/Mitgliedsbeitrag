<?php
/**
 ***********************************************************************************************
 * SEPA-Dateien erzeugen (Schritt „SEPA-Datei erzeugen“ der Registerkarte Beitragseinzug)
 *
 * Erzeugt fuer jedes Faelligkeitsdatum eine eigene SEPA-Datei (Einzug mit SEPA- und Kontrolldatei
 * in der Einzugsuebersicht). Mit combine_sequence_types = 1 stehen alle Sequenztypen eines
 * Faelligkeitsdatums in einer Datei (je Sequenztyp ein PmtInf-Block), sonst gibt es je
 * Faelligkeitsdatum und Sequenztyp eine eigene Datei.
 *
 * Mitglieder, deren Lastschrift zu diesem Faelligkeitsdatum schon in einer Datei steht (exportierte,
 * keine offenen Positionen), werden nicht erneut aufgenommen. Die Dateien werden nicht direkt
 * heruntergeladen; danach geht es zur Einzugsuebersicht.
 *
 * Die eigentliche Datei erzeugt sepa_export_pain.008.001.08.php bzw. sepa_export.php (je nach
 * eingestelltem Format), die hier je Datei eingebunden werden.
 *
 * Parameters (POST):
 *
 * combine_sequence_types : 1 = Sequenztypen eines Faelligkeitsdatums in einer Datei, 0 = je Sequenztyp eine Datei
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;

require_once (__DIR__ . '/common_function.php');

// Buchungskreis (Mitgliedsbeitrag oder Arbeitsdienst), siehe mf_collection_scope()
$mfScope = mf_collection_scope();

// only authorized user are allowed to start this module
if (! isUserAuthorized()) {
    throw new Exception('SYS_NO_RIGHTS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new Exception('SYS_INVALID_PAGE_VIEW');
}

$pPreferences = new ConfigTable();
$pPreferences->read();

// Einstellung merken
$combineSequenceTypes = isset($_POST['combine_sequence_types']) && (string) $_POST['combine_sequence_types'] === '1';
$pPreferences->config['SEPA']['combine_sequence_types'] = $combineSequenceTypes ? 1 : 0;
$pPreferences->save();

// Kandidaten wie im Formular: Faelligkeit, Beitrag, nicht bezahlt, IBAN
$members = list_members(array(
    $mfScope->field(CollectionScope::FIELD_DUEDATE),
    $mfScope->field(CollectionScope::FIELD_SEQUENCETYPE),
    $mfScope->field(CollectionScope::FIELD_FEE),
    $mfScope->field(CollectionScope::FIELD_PAID),
    'IBAN'
), 0);

$candidates = array();   // usr_id => array(duedate, sequencetype)
foreach ($members as $usrId => $memberdata) {
    if (! empty($memberdata[$mfScope->field(CollectionScope::FIELD_DUEDATE)]) && ! empty($memberdata[$mfScope->field(CollectionScope::FIELD_FEE)])
        && empty($memberdata[$mfScope->field(CollectionScope::FIELD_PAID)]) && ! empty($memberdata['IBAN'])) {
        $sequenceType = empty($memberdata[$mfScope->field(CollectionScope::FIELD_SEQUENCETYPE)]) ? 'FRST' : (string) $memberdata[$mfScope->field(CollectionScope::FIELD_SEQUENCETYPE)];
        $candidates[(int) $usrId] = array((string) $memberdata[$mfScope->field(CollectionScope::FIELD_DUEDATE)], $sequenceType);
    }
}
unset($members);

// schon in einer Datei: exportierte Positionen zu diesem Faelligkeitsdatum und keine offenen Positionen
$mfSepaExclude = array();
if (count($candidates) > 0) {
    $positionsByUser = BookingStore::getByUsers(array_keys($candidates), array(BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED));
    foreach ($candidates as $usrId => $candidate) {
        $hasOpen = false;
        $exportedForDate = false;
        foreach ($mfScope->filterPositions($positionsByUser[$usrId] ?? array()) as $position) {
            if ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
                $hasOpen = true;
            } elseif ((string) $position['mfb_due_date'] === $candidate[0]) {
                $exportedForDate = true;
            }
        }
        if ($exportedForDate && ! $hasOpen) {
            $mfSepaExclude[$usrId] = true;
        }
    }
}

// Dateien zusammenstellen: je Faelligkeitsdatum (und ggf. Sequenztyp) eine Liste "DatumTyp"
$typeOrder = array('FRST' => 1, 'RCUR' => 2, 'OOFF' => 3, 'FNAL' => 4);
$byDate = array();
foreach ($candidates as $usrId => $candidate) {
    if (isset($mfSepaExclude[$usrId])) {
        continue;
    }
    $byDate[$candidate[0]][$candidate[1]] = true;
}
ksort($byDate);
$exportGroups = array();
foreach ($byDate as $dueDate => $types) {
    $types = array_keys($types);
    usort($types, static function ($a, $b) use ($typeOrder) {
        return ($typeOrder[$a] ?? 9) <=> ($typeOrder[$b] ?? 9);
    });
    if ($combineSequenceTypes) {
        $exportGroups[] = array_map(static function ($type) use ($dueDate) {
            return $dueDate . $type;
        }, $types);
    } else {
        foreach ($types as $type) {
            $exportGroups[] = array($dueDate . $type);
        }
    }
}

if (count($exportGroups) === 0) {
    $gMessage->setForwardUrl(SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/runs.php'));
    $gMessage->show('Es gibt keine neuen Lastschriften: Alle Mitglieder mit Fälligkeitsdatum stehen bereits in einer SEPA-Datei (siehe Einzugsübersicht).');
    // => EXIT
}

$exportScript = ($pPreferences->config['SEPA']['direct_debit_format'] === 'v8') ? 'sepa_export_pain.008.001.08.php' : 'sepa_export.php';

// je Datei das Export-Skript einbinden; es legt den Einzug an, speichert SEPA- und Kontrolldatei und kehrt zurueck
$mfSepaBatch = true;
$mfSepaCreated = array();
foreach ($exportGroups as $exportGroup) {
    $_POST['duedatesepatype'] = $exportGroup;
    $_POST['export_file_mode'] = 'xml_file';
    include (__DIR__ . '/' . $exportScript);
}

$lines = array();
foreach ($mfSepaCreated as $created) {
    $lines[] = $created['file'] . ' (' . $created['count'] . ' Lastschriften, ' . number_format((float) $created['amount'], 2, ',', '.') . ' €)';
}
$_SESSION['pMembershipFee']['booking_message'] = count($mfSepaCreated) . ' SEPA-Datei(en) erzeugt: ' . implode('; ', $lines)
    . (count($mfSepaExclude) > 0 ? '. ' . count($mfSepaExclude) . ' Mitglieder standen bereits in einer Datei und wurden nicht erneut aufgenommen.' : '.');

admRedirect(SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/runs.php'));
