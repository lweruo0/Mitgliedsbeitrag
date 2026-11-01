<?php
/**
 ***********************************************************************************************
 * SEPA-Export fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Hinweis:   Grundgeruest erstellt von Günter Scheuermann am 28.1.2013
 *
 * Parameters:
 *
 * Wird von sepa_create.php je zu erzeugender Datei eingebunden ($mfSepaBatch = true): dann kein Download,
 * sondern Rueckgabe; Mitglieder in $mfSepaExclude (usr_id => true) werden nicht aufgenommen.
 *
 * duedatesepatype  :   Array mit Kombinationen von Faelligkeitsdatum und SepaTyp
 *                      - Zeichen 0 bis 9: Faelligkeitsdatum 
 *                      - ab Zeichen 10: Sepatyp
 *                      - Bsp.. 2017-12-12FRST oder 2017-12-30RCUR
 *
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\FileSystemUtils;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;

require_once (__DIR__ . '/common_function.php');
require_once (__DIR__ . '/sepa_control_file.php');

// only authorized user are allowed to start this module
if (! isUserAuthorized()) {
    throw new Exception('SYS_NO_RIGHTS');
}

// Konfiguration einlesen
$pPreferences = new ConfigTable();
$pPreferences->read();

$oneDueDateOnly = true; // PmtTpInf (mit SeqTp) immer im PmtInf-Block: ein Block je Faelligkeitsdatum und Sequenztyp
$user = new User($gDb, $gProfileFields);

if (! isset($_POST['duedatesepatype'])) {
    $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_SEPA_EXPORT_NO_DATA'));
}

if (sizeof($_POST['duedatesepatype']) == 1) {
    $oneDueDateOnly = true; // es gibt nur ein Fälligkeitsdatum mit einem Sequenztyp: der PmtTpInf-Block wird im PmtInf-Block plaziert (damit KSK die XML-Datei einlesen kann)
}

// Initialize and check the parameters
$postExportFileMode = admFuncVariableIsValid($_POST, 'export_file_mode', 'string', array(
    'defaultValue' => 'xml_file',
    'validValues' => array(
        'xml_file',
        'ctl_file'
    )
));

$dueDateArr = array();
$zempf = array();
$zpflgt = array();
$nbOfTxs_Msg = 0; // Anzahl der Transaktionen innerhalb der Message
$ctrlSum_Msg = 0; // Kontrollsumme der Beträge innerhalb der Message
$now = time();
$format1 = 'Y-m-d';
$format2 = 'H:i:s';
$filename_ext = '';

foreach ($_POST['duedatesepatype'] as $dummy => $data) {
    $filename_ext .= '_' . substr($data, 0, 10) . '-' . substr($data, 10); // Erweiterung fuer den Dateinamen zusammensetzen
    $dueDateArr[$data]['sequencetype'] = substr($data, 10); // je DueDate ein PmtInf-Block
    $dueDateArr[$data]['nbOfTxs_PmtInf'] = 0; // Anzahl der Transaktionen innerhalb eines PmtInf-Blocks
    $dueDateArr[$data]['ctrlSum_PmtInf'] = 0; // Kontrollsumme der Beträge innerhalb eines PmtInf-Blocks
}

$members = list_members(array(
    'FIRST_NAME',
    'LAST_NAME',
    'FEE' . $gCurrentOrgId,
    'CONTRIBUTORY_TEXT' . $gCurrentOrgId,
    'PAID' . $gCurrentOrgId,
    'STREET',
    'CITY',
    'POSTCODE',
    'DEBTOR',
    'DEBTOR_CITY',
    'DEBTOR_STREET',
    'DEBTOR_POSTCODE',
    'IBAN',
    'ORIG_IBAN',
    'BIC',
    'BANK',
    'ORIG_DEBTOR_AGENT',
    'MANDATEID' . $gCurrentOrgId,
    'ORIG_MANDATEID' . $gCurrentOrgId,
    'MANDATEDATE' . $gCurrentOrgId,
    'DUEDATE' . $gCurrentOrgId,
    'SEQUENCETYPE' . $gCurrentOrgId
), 0);

// alle Mitglieder durchlaufen und das Array $zpflgt befuellen
foreach ($members as $member => $memberdata) {
    $dueDateMember = $memberdata['DUEDATE' . $gCurrentOrgId];
    $sequenceTypeMember = (empty($memberdata['SEQUENCETYPE' . $gCurrentOrgId])) ? 'FRST' : $memberdata['SEQUENCETYPE' . $gCurrentOrgId];

    if (! empty($memberdata['FEE' . $gCurrentOrgId]) && empty($memberdata['PAID' . $gCurrentOrgId]) && ! empty($memberdata['IBAN']) && in_array($dueDateMember . $sequenceTypeMember, $_POST['duedatesepatype']) && !isset($mfSepaExclude[(int) $member])) {
        $zpflgt[$member]['duedate'] = '';
        $zpflgt[$member]['sequencetype'] = '';
        $zpflgt[$member]['name'] = '';
        $zpflgt[$member]['alt_name'] = '';
        $zpflgt[$member]['iban'] = '';
        $zpflgt[$member]['land'] = '';
        $zpflgt[$member]['street'] = '';
        $zpflgt[$member]['ort'] = '';
        $zpflgt[$member]['postcode'] = '';
        $zpflgt[$member]['bic'] = '';
        $zpflgt[$member]['mandat_id'] = '';
        $zpflgt[$member]['mandat_datum'] = '';
        $zpflgt[$member]['betrag'] = '';
        $zpflgt[$member]['text'] = '';
        $zpflgt[$member]['orig_mandat_id'] = '';
        $zpflgt[$member]['orig_iban'] = '';
        $zpflgt[$member]['orig_dbtr_agent'] = '';
        $zpflgt[$member]['end2end_id'] = '';
        $zpflgt[$member]['betrag'] = '';

        $zpflgt[$member]['duedate'] = $dueDateMember;
        $zpflgt[$member]['sequencetype'] = $sequenceTypeMember;

        if (empty($memberdata['DEBTOR'])) {
            $members[$member]['DEBTOR'] = $memberdata['FIRST_NAME'] . ' ' . $memberdata['LAST_NAME'];
            $members[$member]['DEBTOR_STREET'] = $memberdata['STREET'];
            $members[$member]['DEBTOR_CITY'] = $memberdata['CITY'];
            $members[$member]['DEBTOR_POSTCODE'] = $memberdata['POSTCODE'];
        }

        $zpflgt[$member]['name'] = substr(replace_sepadaten($members[$member]['DEBTOR']), 0, 70); // Name of account owner.
                                                                                                  // $zpflgt[$member]['alt_name'] = ''; // Zahlungspflichtiger abweichender Name
        $zpflgt[$member]['iban'] = strtoupper(str_replace(' ', '', $members[$member]['IBAN'])); // IBAN

        if (isIbanNOT_EU_EWR($zpflgt[$member]['iban'])) {
            if (empty($members[$member]['BIC'])) {
                $user->readDataById($member);
                $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_BIC_MISSING', array(
                    '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                        'user_uuid' => $user->getValue('usr_uuid')
                    )) . '">' . $zpflgt[$member]['name'] . '</a>'
                )), $gL10n->get('SYS_ERROR'));
            }
            $zpflgt[$member]['land'] = substr($zpflgt[$member]['iban'], 0, 2);
            $zpflgt[$member]['street'] = substr(replace_sepadaten($members[$member]['DEBTOR_STREET']), 0, 70);
            $zpflgt[$member]['ort'] = substr(replace_sepadaten($members[$member]['DEBTOR_CITY']), 0, 70);
            $zpflgt[$member]['postcode'] = substr(replace_sepadaten($members[$member]['DEBTOR_POSTCODE']), 0, 16);
        }

        $zpflgt[$member]['bic'] = strtoupper($members[$member]['BIC']); // BIC
        $zpflgt[$member]['mandat_id'] = $members[$member]['MANDATEID' . $gCurrentOrgId]; // Mandats-ID
        $zpflgt[$member]['mandat_datum'] = $members[$member]['MANDATEDATE' . $gCurrentOrgId]; // Mandats-Datum

        $fee = str_replace(',', '.', $members[$member]['FEE' . $gCurrentOrgId]);
        if (strpos($fee, '.') !== false) {
            $fee = substr($fee, 0, strpos($fee, '.') + 3);
        }

        $zpflgt[$member]['betrag'] = $fee; // Amount of money
        $zpflgt[$member]['text'] = substr(replace_sepadaten($members[$member]['CONTRIBUTORY_TEXT' . $gCurrentOrgId]), 0, 140); // Description of the transaction ("Verwendungszweck").
        $zpflgt[$member]['orig_mandat_id'] = $members[$member]['ORIG_MANDATEID' . $gCurrentOrgId]; // urspruengliche Mandats-ID
        $zpflgt[$member]['orig_iban'] = strtoupper(str_replace(' ', '', $members[$member]['ORIG_IBAN'])); // urspruengliche IBAN
        $zpflgt[$member]['orig_dbtr_agent'] = $members[$member]['ORIG_DEBTOR_AGENT']; // urspruengliches Kreditinstitut, nur "SMNDA" moeglich

        $dueDateArr[$dueDateMember . $sequenceTypeMember]['nbOfTxs_PmtInf'] ++;
        $dueDateArr[$dueDateMember . $sequenceTypeMember]['ctrlSum_PmtInf'] += $zpflgt[$member]['betrag'];
        $ctrlSum_Msg += $zpflgt[$member]['betrag'];

        $zpflgt[$member]['end2end_id'] = BookingService::endToEndId(replace_sepadaten($gCurrentOrganization->getValue('org_shortname')), (int) $member, $dueDateMember); // SEPA End2End-ID (max. 35)
    }
}

$nbOfTxs_Msg = count($zpflgt); // SEPA Anzahl der Lastschriften

if ($nbOfTxs_Msg == 0) {
    $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_SEPA_EXPORT_NO_DATA'));
}

$message_id = substr('Message-ID-' . replace_sepadaten($gCurrentOrganization->getValue('org_shortname')), 0, 35); // SEPA Message-ID (max. 35)

// Buchungshistorie: beim Erzeugen der XML-Datei einen Einzug anlegen und die Positionen auf "exportiert" setzen;
// die Message-ID ist damit je Datei eindeutig
if ($postExportFileMode === 'xml_file') {
    $bookingService = new BookingService($pPreferences->config);
    $gDb->startTransaction();
    $message_id = $bookingService->registerSepaExport($zpflgt, $pPreferences->config['SEPA']['dateiname'] . $filename_ext . '.xml', 'pain.008.001.08', replace_sepadaten($gCurrentOrganization->getValue('org_shortname')));
    $gDb->endTransaction();
}
$message_datum = date($format1, $now) . 'T' . date($format2, $now) . '.000Z'; // SEPA Message-Datum z.B.: 2010-11-21T09:30:47.000Z
$message_initiator_name = substr(replace_sepadaten($pPreferences->config['Kontodaten']['inhaber']), 0, 70); // SEPA Message Initiator Name

$payment_id = 'Beitragszahlungen'; // SEPA Payment_ID (max. 35)
$payment_end2end_id = 'NOTPROVIDED'; // SEPA Payment_EndToEndIdentification

$zempf['name'] = substr(replace_sepadaten($pPreferences->config['Kontodaten']['inhaber']), 0, 70); // SEPA Zahlungsempfaenger Kontoinhaber
$zempf['ci'] = $pPreferences->config['Kontodaten']['ci']; // Organisation SEPA_ID (Glaeubiger-ID Bundesdbank)

if (isIbanNOT_EU_EWR($pPreferences->config['Kontodaten']['iban']) && empty($pPreferences->config['Kontodaten']['bic'])) {
    $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_BIC_MISSING', array(
        $zempf['name']
    )), $gL10n->get('SYS_ERROR'));
}

$zempf['iban'] = strtoupper(str_replace(' ', '', $pPreferences->config['Kontodaten']['iban'])); // SEPA Zahlungsempfaenger IBAN
$zempf['bic'] = strtoupper($pPreferences->config['Kontodaten']['bic']); // SEPA Zahlungsempfaenger BIC
$zempf['orig_cdtr_name'] = $pPreferences->config['Kontodaten']['origcreditor']; // urspruenglicher Creditor
$zempf['orig_cdtr_id'] = $pPreferences->config['Kontodaten']['origci']; // urspruengliche Mandats-ID

if ($postExportFileMode === 'xml_file') {

    /**
     * ****************************************************************************
     * Schreibt Lastschriften in einen XML-String
     * ***************************************************************************
     */

    // DFÜ-Abkommen Version 26.11
    // Pain 008.001.008
    // ########## Document ###########
    $xml = new SimpleXMLElement("<?xml version='1.0' encoding='utf-8'?>
        <Document xmlns='urn:iso:std:iso:20022:tech:xsd:pain.008.001.08' 
        xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance' 
        xsi:schemaLocation='urn:iso:std:iso:20022:tech:xsd:pain.008.001.08 pain.008.001.08.xsd'/>");

    // ########## Customer Direct Debit Initiation ###########
    $cstmrDrctDbtInitn = $xml->addChild('CstmrDrctDbtInitn');

    // ########## Group-Header ###########
    $grpHdr = $cstmrDrctDbtInitn->addChild('GrpHdr');
    $grpHdr->addChild('MsgId', $message_id);
    $grpHdr->addChild('CreDtTm', $message_datum);
    $grpHdr->addChild('NbOfTxs', $nbOfTxs_Msg);
    $grpHdr->addChild('CtrlSum', $ctrlSum_Msg);
    $grpHdr->addChild('InitgPty');
    $grpHdr->InitgPty->addChild('Nm', $message_initiator_name);

    foreach ($dueDateArr as $dueDate => $data) // je DueDate ein PmtInf-Block
    {
        // Schluessel: Faelligkeitsdatum + Sequenztyp (z. B. 2026-11-02FRST); eindeutige PmtInfId je Block
        $dueDate = substr($dueDate, 0, 10);
        $paymentIdBlock = substr('BEITR-' . $data['sequencetype'] . '-' . str_replace('-', '', $dueDate) . '-' . substr(md5($message_id), 0, 8), 0, 35);
        // ########## Payment Information ###########
        $pmtInf = $cstmrDrctDbtInitn->addChild('PmtInf');
        $pmtInf->addChild('PmtInfId', $paymentIdBlock); // Payment-ID
        $pmtInf->addChild('PmtMtd', 'DD'); // Payment-Methode, Lastschrift: DD
        $pmtInf->addChild('BtchBookg', 'true'); // BatchBooking, Sammelbuchung (true) oder eine Einzelbuchung handelt (false)
        $pmtInf->addChild('NbOfTxs', $data['nbOfTxs_PmtInf']); // Number of Transactions
        $pmtInf->addChild('CtrlSum', $data['ctrlSum_PmtInf']); // Control Sum

        if ($oneDueDateOnly) // es gibt nur ein Fälligkeitsdatum mit einem Sequenztyp: der PmtTpInf-Block wird im PmtInf-Block plaziert
        {
            $pmtInf->addChild('PmtTpInf'); // PaymentTypeInformation
            $pmtInf->PmtTpInf->addChild('SvcLvl'); // ServiceLevel
            $pmtInf->PmtTpInf->SvcLvl->addChild('Cd', 'SEPA'); // Code, immer SEPA
            $pmtInf->PmtTpInf->addChild('LclInstrm'); // LocalInstrument, Lastschriftart
            $pmtInf->PmtTpInf->LclInstrm->addChild('Cd', 'CORE'); // CORE (Basislastschrift oder B2B (Firmenlastschrift)
            $pmtInf->PmtTpInf->addChild('SeqTp', $data['sequencetype']); // SequenceType
                                                                         // Der SequenceType gibt an, ob es sich um eine Erst-, Folge-,
                                                                         // Einmal- oder letztmalige Lastschrift handelt.
                                                                         // Zulaessige Werte: FRST, RCUR, OOFF, FNAL
                                                                         // Wenn <OrgnlDbtrAcct> = SMNDA und <Amdmnt-Ind> = true
                                                                         // dann muss dieses Feld mit FRST belegt sein.
        }

        $pmtInf->addChild('ReqdColltnDt', $dueDate); // RequestedCollectionDate, Faelligkeitsdatum der Lastschrift
        $pmtInf->addChild('Cdtr'); // Creditor
        $pmtInf->Cdtr->addChild('Nm', $zempf['name']); // Name, max. 70 Zeichen
        $pmtInf->addChild('CdtrAcct'); // CreditorAccount, Creditor-Konto
        $pmtInf->CdtrAcct->addChild('Id');
        $pmtInf->CdtrAcct->Id->addChild('IBAN', $zempf['iban']);
        $pmtInf->addChild('CdtrAgt'); // CreditorAgent, Creditor-Bank
        $pmtInf->CdtrAgt->addChild('FinInstnId'); // FinancialInstitutionIdentification

        if (strlen($zempf['bic']) !== 0) // ist ein BIC vorhanden?
        {
            $pmtInf->CdtrAgt->FinInstnId->addChild('BICFI', $zempf['bic']);
        } else {

            $pmtInf->CdtrAgt->FinInstnId->addChild('Othr');
            $pmtInf->CdtrAgt->FinInstnId->Othr->addChild('Id', 'NOTPROVIDED');
        }

        $pmtInf->addChild('ChrgBr', 'SLEV'); // ChargeBearer, Entgeltverrechnungsart, immer SLEV

        // ########## CREDITOR, Zahlungsempfaenger ##############
        $pmtInf->addChild('CdtrSchmeId'); // CreditorSchemeIdentification, Identifikation des Zahlungsempfaengers
        $pmtInf->CdtrSchmeId->addChild('Id'); // Eindeutiges Identifizierungmerkmal einer Organisation oder Person
        $pmtInf->CdtrSchmeId->Id->addChild('PrvtId'); // PrivateIdentification, Personenidentifikation
        $pmtInf->CdtrSchmeId->Id->PrvtId->addChild('Othr'); // OtherIdentification
        $pmtInf->CdtrSchmeId->Id->PrvtId->Othr->addChild('Id', $zempf['ci']); // Eindeutiges Identifizierungsmerkmal des Glaeubigers
        $pmtInf->CdtrSchmeId->Id->PrvtId->Othr->addChild('SchmeNm'); // SchemeName, Name des Identifikationsschemas
        $pmtInf->CdtrSchmeId->Id->PrvtId->Othr->SchmeNm->addChild('Prtry', 'SEPA'); // Proprietary, immer SEPA

        // ######### Direct Debit Transaction Information, Lastschriften ##############
        foreach ($zpflgt as $dummy => $zpflgtdata) // je Zahlungspflichtiger ein DrctDbtTxInf-Block
        {
            if ($dueDate == $zpflgtdata['duedate'] && $zpflgtdata['sequencetype'] === $data['sequencetype']) {

                $drctDbtTxInf = $pmtInf->addChild('DrctDbtTxInf'); // DirectDebitTransactionInformation

                $drctDbtTxInf->addChild('PmtId'); // PaymentIdentification, Referenzierung einer einzelnen Transaktion
                $drctDbtTxInf->PmtId->addChild('EndToEndId', $zpflgtdata['end2end_id']); // EndToEndIdentification
                                                                                         // eindeutige Referenz des Zahlers (Auftraggebers). Diese Referenz
                                                                                         // wird unveraendert durch die gesamte Kette bis zum Zahlungsempfaenger
                                                                                         // geleitet (Ende-zu-Ende-Referenz). Ist keine Referenz vorhanden
                                                                                         // muss die Konstante NOTPROVIDED benutzt werden.

                if (! $oneDueDateOnly) // PmtTpInf-Block entweder hier unter DrctDbtTxInf oder unter PmtInf
                {
                    $drctDbtTxInf->addChild('PmtTpInf'); // PaymentTypeInformation
                    $drctDbtTxInf->PmtTpInf->addChild('SvcLvl'); // ServiceLevel
                    $drctDbtTxInf->PmtTpInf->SvcLvl->addChild('Cd', 'SEPA'); // Code, immer SEPA
                    $drctDbtTxInf->PmtTpInf->addChild('LclInstrm'); // LocalInstrument, Lastschriftart
                    $drctDbtTxInf->PmtTpInf->LclInstrm->addChild('Cd', 'CORE'); // CORE (Basislastschrift oder B2B (Firmenlastschrift)
                    $drctDbtTxInf->PmtTpInf->addChild('SeqTp', $zpflgtdata['sequencetype']); // SequenceType
                                                                                             // Der SequenceType gibt an, ob es sich um eine Erst-, Folge-,
                                                                                             // Einmal- oder letztmalige Lastschrift handelt.
                                                                                             // Zulaessige Werte: FRST, RCUR, OOFF, FNAL
                                                                                             // Wenn <OrgnlDbtrAcct> = SMNDA und <Amdmnt-Ind> = true
                                                                                             // dann muss dieses Feld mit FRST belegt sein.
                }

                $drctDbtTxInf->addChild('InstdAmt', $zpflgtdata['betrag']); // InstructedAmount (Dezimalpunkt)
                $drctDbtTxInf->InstdAmt->addAttribute('Ccy', 'EUR');
                $drctDbtTxInf->addChild('DrctDbtTx'); // DirectDebitTransaction, Angaben zum Lastschriftmandat
                $drctDbtTxInf->DrctDbtTx->addChild('MndtRltdInf'); // MandateRelated-Information, mandatsbezogene Informationen
                $drctDbtTxInf->DrctDbtTx->MndtRltdInf->addChild('MndtId', $zpflgtdata['mandat_id']); // eindeutige Mandatsreferenz
                $drctDbtTxInf->DrctDbtTx->MndtRltdInf->addChild('DtOfSgntr', $zpflgtdata['mandat_datum']); // Datum, zu dem das Mandat unterschrieben wurde

                if ((strlen($zempf['orig_cdtr_name']) !== 0) || (strlen($zempf['orig_cdtr_id']) !== 0) || (strlen($zpflgtdata['orig_mandat_id']) !== 0) || (strlen($zpflgtdata['orig_iban']) !== 0) || (strlen($zpflgtdata['orig_dbtr_agent']) !== 0)) // Kennzeichnet, ob das Mandat veraendert wurde,
                {
                    $drctDbtTxInf->DrctDbtTx->MndtRltdInf->addChild('AmdmntInd', 'true'); // AmendmentIndicator "true"
                    $drctDbtTxInf->DrctDbtTx->MndtRltdInf->addChild('AmdmntInfDtls'); // AmendmentInformationDetails, Pflichtfeld, falls <AmdmntInd>=true

                    if (strlen($zpflgtdata['orig_mandat_id']) !== 0) // Kennzeichnet, ob das Mandat veraendert wurde,
                    {
                        $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->addChild('OrgnlMndtId', $zpflgtdata['orig_mandat_id']);
                    }

                    if ((strlen($zempf['orig_cdtr_name']) !== 0) || (strlen($zempf['orig_cdtr_id']) !== 0)) // Kennzeichnet, ob das Mandat veraendert wurde,
                    {
                        $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->addChild('OrgnlCdtrSchmeId'); // Identifikation des Zahlungsempfaengers

                        if (strlen($zempf['orig_cdtr_name']) !== 0) // Kennzeichnet, ob das Mandat veraendert wurde,
                        {
                            $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlCdtrSchmeId->addChild('Nm', $zempf['orig_cdtr_name']);
                        }
                        if (strlen($zempf['orig_cdtr_id']) !== 0) {

                            $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlCdtrSchmeId->addChild('Id');
                            $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlCdtrSchmeId->Id->addChild('PrvtId');
                            $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlCdtrSchmeId->Id->PrvtId->addChild('Othr');
                            $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlCdtrSchmeId->Id->PrvtId->Othr->addChild('Id', $zempf['orig_cdtr_id']);
                            $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlCdtrSchmeId->Id->PrvtId->Othr->addChild('SchmeNm');
                            $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlCdtrSchmeId->Id->PrvtId->Othr->SchmeNm->addChild('Prtry', 'SEPA');
                        }
                    }

                    if (strlen($zpflgtdata['orig_iban']) !== 0 || strlen($zpflgtdata['orig_dbtr_agent']) !== 0) // Kennzeichnet, ob das Mandat veraendert wurde,
                    {

                        $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->addChild('OrgnlDbtrAcct');
                        $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlDbtrAcct->addChild('Id');
                        $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlDbtrAcct->Id->addChild('Othr');
                        $drctDbtTxInf->DrctDbtTx->MndtRltdInf->AmdmntInfDtls->OrgnlDbtrAcct->Id->Othr->addChild('Id', 'SMNDA');
                    }

                    /*
                     * if(strlen($zpflgtdata['orig_dbtr_agent']) !== 0) //Kennzeichnet, ob das Mandat veraendert wurde,
                     * {
                     * $xmlfile .= "<OrgnlDbtrAgt>\n";
                     * $xmlfile .= "<FinInstnId>\n";
                     * $xmlfile .= "<Othr>\n";
                     * $xmlfile .= '<Id>'.$zpflgtdata['orig_dbtr_agent']."</Id>\n";
                     * $xmlfile .= "</Othr>\n";
                     * $xmlfile .= "</FinInstnId>\n";
                     * $xmlfile .= "</OrgnlDbtrAgt>\n";
                     * }
                     */
                } else {

                    $drctDbtTxInf->DrctDbtTx->MndtRltdInf->addChild('AmdmntInd', 'false'); // AmendmentIndicator "false"
                }
                ;

                // ## Kreditinstitut des Zahlers (Zahlungspflichtigen)
                $drctDbtTxInf->addChild('DbtrAgt'); // DebtorAgent, Kreditinstitut des Zahlers (Zahlungspflichtigen)
                $drctDbtTxInf->DbtrAgt->addChild('FinInstnId'); // FinancialInstitutionIdentification

                if (strlen($zpflgtdata['bic']) !== 0) // ist ein BIC vorhanden?
                {
                    $drctDbtTxInf->DbtrAgt->FinInstnId->addChild('BICFI', $zpflgtdata['bic']);
                } else {

                    $drctDbtTxInf->DbtrAgt->FinInstnId->addChild('Othr');
                    $drctDbtTxInf->DbtrAgt->FinInstnId->Othr->addChild('Id', 'NOTPROVIDED');
                }

                $drctDbtTxInf->addChild('Dbtr'); // Zahlungspflichtiger
                $drctDbtTxInf->Dbtr->addChild('Nm', $zpflgtdata['name']); // Name (70)

                if (! empty($zpflgtdata['land'])) {
                    // Zahlungspflichtigen-Adresse ist Pflicht bei Lastschriften ausserhalb EU/EWR

                    $drctDbtTxInf->Dbtr->addChild('PstlAdr');
                    $drctDbtTxInf->Dbtr->PstlAdr->addChild('PstCd', $zpflgtdata['postcode']);
                    $drctDbtTxInf->Dbtr->PstlAdr->addChild('TwnNm', $zpflgtdata['ort']);
                    $drctDbtTxInf->Dbtr->PstlAdr->addChild('Ctry', $zpflgtdata['land']);
                    $drctDbtTxInf->Dbtr->PstlAdr->addChild('AdrLine', $zpflgtdata['street']);
                }

                $drctDbtTxInf->addChild('DbtrAcct');
                $drctDbtTxInf->DbtrAcct->addChild('Id');
                $drctDbtTxInf->DbtrAcct->Id->addChild('IBAN', $zpflgtdata['iban']);

                if (strlen($zpflgtdata['alt_name']) > 0) {
                    $drctDbtTxInf->addChild('UltmtDbtr'); // UltimateDebtor
                    $drctDbtTxInf->UltmtDbtr->addChild('Nm', $zpflgtdata['alt_name']);
                }

                if (strlen($zpflgtdata['text']) > 0) {

                    $drctDbtTxInf->addChild('RmtInf'); // Remittance Information, Verwendungszweck
                    $drctDbtTxInf->RmtInf->addChild('Ustrd', $zpflgtdata['text']); // Unstructured, unstrukturierter Verwendungszweck(max. 140 Zeichen))
                }
            } // Ende if $dueDate == $zpflgtdata['duedate'] && $zpflgtdata['sequencetype'] === $data['sequencetype']
        } // Ende foreach ($zpflgt as $dummy => $zpflgtdata)
    } // Ende foreach ($dueDateArr as $dueDate => $dummy), Payment Information Block

    /**
     * ****************************************************************************
     * Schreibt XML-Datei
     * ***************************************************************************
     */

    // diese Anweisung erzeugt zwar einen wohlgeformten XML-String, er ist aber schlecht lesbar, da er in einer einzigen Zeile geschrieben ist
    // echo $xml->asXML();

    // formatierten XML-String erzeugen
    $dom = new DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    $xmlContent = $dom->saveXML();

    // Buchungshistorie: Datei beim Einzug speichern (erneuter Download und Teilen in der Einzugsuebersicht)
    try {
        $bookingService->storeExportFile($pPreferences->config['SEPA']['dateiname'] . $filename_ext . '.xml', $xmlContent);
        $controlFile = mf_build_sepa_control_file($pPreferences->config['SEPA']['kontroll_dateiname'] . $filename_ext, $message_id, $message_datum, $message_initiator_name, $nbOfTxs_Msg, $ctrlSum_Msg, $payment_id, $zempf, $zpflgt);
        $bookingService->storeExportFile($controlFile['name'], $controlFile['content'], BookingStore::FILE_CONTROL);
    } catch (\Throwable $e) {
        $gLogger->error('MembershipFee: SEPA-Datei konnte nicht gespeichert werden: ' . $e->getMessage());
    }

    // Aufruf aus sepa_create.php: Datei ist beim Einzug gespeichert, kein Download

    if (!empty($mfSepaBatch)) {

        $mfSepaCreated[] = array('file' => $pPreferences->config['SEPA']['dateiname'] . $filename_ext . '.xml', 'count' => $nbOfTxs_Msg, 'amount' => $ctrlSum_Msg);

        return;

    }


    header('content-type: text/xml');
    header('Cache-Control: private'); // noetig fuer IE, da ansonsten der Download mit SSL nicht funktioniert
    header('Content-Transfer-Encoding: binary'); // Im Grunde ueberfluessig, hat sich anscheinend bewaehrt
    header('Cache-Control: post-check=0, pre-check=0'); // Zwischenspeichern auf Proxies verhindern
    header('Content-Disposition: attachment; filename="' . $pPreferences->config['SEPA']['dateiname'] . $filename_ext . '.xml"');

    echo $xmlContent;

    die();
} elseif ($postExportFileMode === 'ctl_file') {
    // Kontrolldatei (Excel bzw. CSV), siehe sepa_control_file.php
    $controlFile = mf_build_sepa_control_file($pPreferences->config['SEPA']['kontroll_dateiname'] . $filename_ext, $message_id, $message_datum, $message_initiator_name, $nbOfTxs_Msg, $ctrlSum_Msg, $payment_id, $zempf, $zpflgt);

    header('Content-Disposition: attachment; filename="' . $controlFile['name'] . '"');
    header('Content-Type: ' . $controlFile['mime']);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, must-revalidate');
    header('Pragma: public');
    echo $controlFile['content'];
    exit();
} else {
    $gMessage->show($gL10n->get('SYS_INVALID_PAGE_VIEW'));
}
