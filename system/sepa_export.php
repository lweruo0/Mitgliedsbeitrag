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

use Admidio\Infrastructure\Utils\FileSystemUtils;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;

require_once(__DIR__ . '/common_function.php');

// Buchungskreis (Mitgliedsbeitrag oder Arbeitsdienst), siehe mf_collection_scope()
$mfScope = mf_collection_scope();
require_once(__DIR__ . '/sepa_control_file.php');

// only authorized user are allowed to start this module
if (!isUserAuthorized())
{
    throw new Exception('SYS_NO_RIGHTS');   
}

// Konfiguration einlesen
$pPreferences = new ConfigTable();
$pPreferences->read();

$oneDueDateOnly = true; // PmtTpInf (mit SeqTp) immer im PmtInf-Block: ein Block je Faelligkeitsdatum und Sequenztyp
$user = new User($gDb, $gProfileFields);

if (!isset($_POST['duedatesepatype']))
{
	$gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_SEPA_EXPORT_NO_DATA'));
}

if (sizeof($_POST['duedatesepatype']) == 1)
{
	$oneDueDateOnly = true;				// es gibt nur ein Fälligkeitsdatum mit einem Sequenztyp: der PmtTpInf-Block wird im PmtInf-Block plaziert (damit KSK die XML-Datei einlesen kann)
}

// Initialize and check the parameters
$postExportFileMode = admFuncVariableIsValid($_POST, 'export_file_mode', 'string', array('defaultValue' => 'xml_file', 'validValues' => array('xml_file', 'ctl_file')));

$dueDateArr   = array();
$zempf        = array();
$zpflgt       = array();
$nbOfTxs_Msg  = 0; 																	// Anzahl der Transaktionen innerhalb der Message
$ctrlSum_Msg  = 0;																	// Kontrollsumme der Beträge innerhalb der Message
$now          = time();
$format1      = 'Y-m-d';
$format2      = 'H:i:s';
$filename_ext = $mfScope->isMembership() ? '' : '_arbeitsdienst';   // eigene Dateinamen fuer den Arbeitsdienst

foreach ($_POST['duedatesepatype'] as $dummy => $data)
{
	$filename_ext .= '_'.substr($data, 0, 10).'-'.substr($data, 10);				// Erweiterung fuer den Dateinamen zusammensetzen
	$dueDateArr[$data]['sequencetype'] = substr($data, 10);			// je DueDate ein PmtInf-Block
	$dueDateArr[$data]['nbOfTxs_PmtInf'] = 0;						// Anzahl der Transaktionen innerhalb eines PmtInf-Blocks
	$dueDateArr[$data]['ctrlSum_PmtInf'] = 0;						// Kontrollsumme der Beträge innerhalb eines PmtInf-Blocks
}
	
$members = list_members(array('FIRST_NAME', 'LAST_NAME', $mfScope->field(CollectionScope::FIELD_FEE), $mfScope->field(CollectionScope::FIELD_TEXT), $mfScope->field(CollectionScope::FIELD_PAID), 'STREET', 'CITY', 'DEBTOR', 'DEBTOR_CITY', 'DEBTOR_STREET', 'IBAN', 'ORIG_IBAN', 'BIC', 'BANK', 'ORIG_DEBTOR_AGENT', 'MANDATEID'.$gCurrentOrgId, 'ORIG_MANDATEID'.$gCurrentOrgId, 'MANDATEDATE'.$gCurrentOrgId, $mfScope->field(CollectionScope::FIELD_DUEDATE), $mfScope->field(CollectionScope::FIELD_SEQUENCETYPE)), 0);
		
// per "Verschieben" auf Ueberweisung gestellte Mitglieder gehoeren nicht in die SEPA-Datei (2.0/2.1)
$mfTransferExclude = mf_paymethod_override_users($mfScope);

//alle Mitglieder durchlaufen und das Array $zpflgt befuellen
foreach ($members as $member => $memberdata)
{
	$dueDateMember = $memberdata[$mfScope->field(CollectionScope::FIELD_DUEDATE)];
	$sequenceTypeMember = (empty($memberdata[$mfScope->field(CollectionScope::FIELD_SEQUENCETYPE)])) ? 'FRST' : $memberdata[$mfScope->field(CollectionScope::FIELD_SEQUENCETYPE)];
	
    if  (!empty($memberdata[$mfScope->field(CollectionScope::FIELD_FEE)])
        && empty($memberdata[$mfScope->field(CollectionScope::FIELD_PAID)])
        && !empty($memberdata['IBAN'])
        && in_array($dueDateMember . $sequenceTypeMember, $_POST['duedatesepatype']) && !isset($mfSepaExclude[(int) $member]) && !isset($mfTransferExclude[(int) $member]) )
    {
        $zpflgt[$member]['duedate'] = '';
        $zpflgt[$member]['sequencetype'] = '';
        $zpflgt[$member]['name'] = '';
        $zpflgt[$member]['alt_name'] = '';
        $zpflgt[$member]['iban'] = '';
        $zpflgt[$member]['land'] = '';
        $zpflgt[$member]['street']  = '';
        $zpflgt[$member]['ort']  = '';
        $zpflgt[$member]['bic']  = '';                        
        $zpflgt[$member]['mandat_id'] = '';
        $zpflgt[$member]['mandat_datum'] = '';
        $zpflgt[$member]['betrag']  = '';                                          										
        $zpflgt[$member]['text']  = '';
        $zpflgt[$member]['orig_mandat_id']  = '';
        $zpflgt[$member]['orig_iban']  = '';
        $zpflgt[$member]['orig_dbtr_agent'] = '';
        $zpflgt[$member]['end2end_id']  = '';
        $zpflgt[$member]['betrag'] = '';
        
    	$zpflgt[$member]['duedate'] = $dueDateMember;
    	$zpflgt[$member]['sequencetype'] = $sequenceTypeMember;
    	
        if (empty($memberdata['DEBTOR']))
        {
            $members[$member]['DEBTOR'] = $memberdata['FIRST_NAME'].' '.$memberdata['LAST_NAME'];
            $members[$member]['DEBTOR_STREET'] = $memberdata['STREET'];
            $members[$member]['DEBTOR_CITY'] = $memberdata['CITY'];
        }

        $zpflgt[$member]['name'] = substr(replace_sepadaten($members[$member]['DEBTOR']), 0, 70);                                                     // Name of account owner.
       // $zpflgt[$member]['alt_name'] = '';                                                                                                            // Zahlungspflichtiger abweichender Name
        $zpflgt[$member]['iban'] = strtoupper(str_replace(' ', '', $members[$member]['IBAN']));														  // IBAN
        
        if (isIbanNOT_EU_EWR($zpflgt[$member]['iban']))
        {
        	if (empty($members[$member]['BIC']))
        	{
                $user->readDataById($member);
        		$gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_BIC_MISSING', array('<a href="'. SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid'))). '">'.$zpflgt[$member]['name']. '</a>')), $gL10n->get('SYS_ERROR'));
        	}
        	$zpflgt[$member]['land'] = substr($zpflgt[$member]['iban'], 0, 2);
        	$zpflgt[$member]['street'] = substr(replace_sepadaten($members[$member]['DEBTOR_STREET']), 0, 70);    
        	$zpflgt[$member]['ort'] = substr(replace_sepadaten($members[$member]['DEBTOR_CITY']), 0, 70);    
        }
                              
        $zpflgt[$member]['bic'] = strtoupper($members[$member]['BIC']);                                                                                           // BIC
        $zpflgt[$member]['mandat_id'] = $members[$member]['MANDATEID'.$gCurrentOrgId];                                     // Mandats-ID
        $zpflgt[$member]['mandat_datum'] = $members[$member]['MANDATEDATE'.$gCurrentOrgId];                                // Mandats-Datum

       	$fee = str_replace(',', '.', $members[$member][$mfScope->field(CollectionScope::FIELD_FEE)]);
       	if (strpos($fee, '.') !== false)
       	{
       		$fee = substr($fee, 0, strpos($fee, '.') +3);
       	}
       	
        $zpflgt[$member]['betrag'] = $fee;                                               															  // Amount of money
        $zpflgt[$member]['text'] = substr(replace_sepadaten($members[$member][$mfScope->field(CollectionScope::FIELD_TEXT)]), 0, 140);   // Description of the transaction ("Verwendungszweck").
        $zpflgt[$member]['orig_mandat_id'] = $members[$member]['ORIG_MANDATEID'.$gCurrentOrgId];                           // urspruengliche Mandats-ID
        $zpflgt[$member]['orig_iban'] = strtoupper(str_replace(' ', '', $members[$member]['ORIG_IBAN']));                                             // urspruengliche IBAN
        $zpflgt[$member]['orig_dbtr_agent'] = $members[$member]['ORIG_DEBTOR_AGENT'];                                                                 // urspruengliches Kreditinstitut, nur "SMNDA" moeglich

        $dueDateArr[$dueDateMember . $sequenceTypeMember]['nbOfTxs_PmtInf']++;
        $dueDateArr[$dueDateMember . $sequenceTypeMember]['ctrlSum_PmtInf'] += $zpflgt[$member]['betrag'];
        $ctrlSum_Msg += $zpflgt[$member]['betrag'];

        $zpflgt[$member]['end2end_id'] = $mfScope->endToEndId(replace_sepadaten($gCurrentOrganization->getValue('org_shortname')), (int) $member, $dueDateMember);     //SEPA End2End-ID   (max. 35)
    }
}

$nbOfTxs_Msg = count($zpflgt);                                                                                        //SEPA Anzahl der Lastschriften

if ($nbOfTxs_Msg == 0)
{
    $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_SEPA_EXPORT_NO_DATA'));
}

$message_id = substr('Message-ID-'.replace_sepadaten($gCurrentOrganization->getValue('org_shortname')), 0, 35);   //SEPA Message-ID    (max. 35)

// Buchungshistorie: beim Erzeugen der XML-Datei einen Einzug anlegen und die Positionen auf "exportiert" setzen;
// die Message-ID ist damit je Datei eindeutig
if ($postExportFileMode === 'xml_file') {
    $bookingService = new BookingService($pPreferences->config, $mfScope);
    $gDb->startTransaction();
    $message_id = $bookingService->registerSepaExport($zpflgt, $pPreferences->config['SEPA']['dateiname'] . $filename_ext . '.xml', 'pain.008.001.02', replace_sepadaten($gCurrentOrganization->getValue('org_shortname')));
    $gDb->endTransaction();
}
$message_datum = date($format1, $now).'T'.date($format2, $now).'.000Z';                                           //SEPA Message-Datum z.B.: 2010-11-21T09:30:47.000Z
$message_initiator_name = substr(replace_sepadaten($pPreferences->config['Kontodaten']['inhaber']), 0, 70);       //SEPA Message Initiator Name

$payment_id = 'Beitragszahlungen';                                                                                //SEPA Payment_ID (max. 35)
$payment_end2end_id = 'NOTPROVIDED';                                                                              //SEPA Payment_EndToEndIdentification

$zempf['name'] = substr(replace_sepadaten($pPreferences->config['Kontodaten']['inhaber']), 0, 70);                //SEPA  Zahlungsempfaenger Kontoinhaber
$zempf['ci'] = $pPreferences->config['Kontodaten']['ci'];                                                         //Organisation SEPA_ID (Glaeubiger-ID Bundesdbank)

if (isIbanNOT_EU_EWR($pPreferences->config['Kontodaten']['iban']) && empty($pPreferences->config['Kontodaten']['bic']))
{
	$gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_BIC_MISSING', array($zempf['name'])), $gL10n->get('SYS_ERROR'));
}

$zempf['iban'] = strtoupper(str_replace(' ', '', $pPreferences->config['Kontodaten']['iban']));                   //SEPA  Zahlungsempfaenger IBAN
$zempf['bic'] = strtoupper($pPreferences->config['Kontodaten']['bic']);                                           //SEPA  Zahlungsempfaenger BIC
$zempf['orig_cdtr_name'] = $pPreferences->config['Kontodaten']['origcreditor'];                                   //urspruenglicher Creditor
$zempf['orig_cdtr_id'] = $pPreferences->config['Kontodaten']['origci'];                                           //urspruengliche Mandats-ID

if ($postExportFileMode === 'xml_file')
{

    /******************************************************************************
    * Schreibt Lastschriften in einen XML-String
    *****************************************************************************/
    $xmlfile = '';
    $xmlfile .= "<?xml version='1.0' encoding='UTF-8'?>\n";

    // DFÜ-Abkommen Version 3.1
    // Pain 008.001.002
    // ########## Document ###########
    $xmlfile .=  "<Document xmlns='urn:iso:std:iso:20022:tech:xsd:pain.008.001.02' 
    		xmlns:xsi='http://www.w3.org/2001/XMLSchema-instance' 
    		xsi:schemaLocation='urn:iso:std:iso:20022:tech:xsd:pain.008.001.02 pain.008.001.02.xsd'>\n";
    
    // ########## Customer Direct Debit Initiation ###########
    $xmlfile .= "<CstmrDrctDbtInitn>\n";

        // ########## Group-Header ###########
        $xmlfile .= "<GrpHdr>\n";
            $xmlfile .= "<MsgId>$message_id</MsgId>\n";                       		//MessageIdentification
            $xmlfile .= "<CreDtTm>$message_datum</CreDtTm>\n";                		//Datum & Zeit
            $xmlfile .= "<NbOfTxs>$nbOfTxs_Msg</NbOfTxs>\n";                      	//NumberOfTransactions
            $xmlfile .= "<CtrlSum>$ctrlSum_Msg</CtrlSum>\n";                 		//ControlSum
            $xmlfile .= "<InitgPty>\n";
                $xmlfile .= "<Nm>$message_initiator_name</Nm>\n";
            $xmlfile .= "</InitgPty>\n";
        $xmlfile .= "</GrpHdr>\n";

        foreach ($dueDateArr as $dueDate => $data)      							// je DueDate ein PmtInf-Block
        {
            // Schluessel: Faelligkeitsdatum + Sequenztyp (z. B. 2026-11-02FRST); eindeutige PmtInfId je Block
            $dueDate = substr($dueDate, 0, 10);
            $paymentIdBlock = substr('BEITR-' . $data['sequencetype'] . '-' . str_replace('-', '', $dueDate) . '-' . substr(md5($message_id), 0, 8), 0, 35);
        	// ########## Payment Information ###########
        	$xmlfile .= "<PmtInf>\n";
            	$xmlfile .= "<PmtInfId>$paymentIdBlock</PmtInfId>\n";                 	//Payment-ID
            	$xmlfile .= "<PmtMtd>DD</PmtMtd>\n";                              	//Payment-Methode, Lastschrift: DD
            	$xmlfile .= "<BtchBookg>true</BtchBookg>\n";                      	//BatchBooking, Sammelbuchung (true) oder eine Einzelbuchung handelt (false)
            	$xmlfile .= '<NbOfTxs>'.$data['nbOfTxs_PmtInf']."</NbOfTxs>\n";     //Number of Transactions
            	$xmlfile .= '<CtrlSum>'.$data['ctrlSum_PmtInf']."</CtrlSum>\n";     //Control Sum
     
            	if ($oneDueDateOnly)												//es gibt nur ein Fälligkeitsdatum mit einem Sequenztyp: der PmtTpInf-Block wird im PmtInf-Block plaziert
            	{
            		$xmlfile .= "<PmtTpInf>\n";                                     //PaymentTypeInformation
            			$xmlfile .= "<SvcLvl>\n";                                   //ServiceLevel
            				$xmlfile .= "<Cd>SEPA</Cd>\n";                          //Code, immer SEPA
            			$xmlfile .= "</SvcLvl>\n";
            			$xmlfile .= "<LclInstrm>\n";                                //LocalInstrument, Lastschriftart
            				$xmlfile .= "<Cd>CORE</Cd>\n";                          //CORE (Basislastschrift oder B2B (Firmenlastschrift)
            			$xmlfile .= "</LclInstrm>\n";
            			$xmlfile .= '<SeqTp>'.$data['sequencetype']."</SeqTp>\n";           //SequenceType
            			//Der SequenceType gibt an, ob es sich um eine Erst-, Folge-,
            			//Einmal- oder letztmalige Lastschrift handelt.
            			//Zulaessige Werte: FRST, RCUR, OOFF, FNAL
            			//Wenn <OrgnlDbtrAcct> = SMNDA und <Amdmnt-Ind> = true
            			//dann muss dieses Feld mit FRST belegt sein.
            		$xmlfile .= "</PmtTpInf>\n";
            	}
            	
            	$xmlfile .= "<ReqdColltnDt>$dueDate</ReqdColltnDt>\n";      		//RequestedCollectionDate, Faelligkeitsdatum der Lastschrift
            	$xmlfile .= "<Cdtr>\n";                                           	//Creditor
                	$xmlfile .= '<Nm>'.$zempf['name']."</Nm>\n";                  	//Name, max. 70 Zeichen
            	$xmlfile .= "</Cdtr>\n";
            	$xmlfile .= "<CdtrAcct>\n";                                       	//CreditorAccount, Creditor-Konto
                	$xmlfile .= "<Id>\n";
                    	$xmlfile .= '<IBAN>'.$zempf['iban']."</IBAN>\n";
                	$xmlfile .= "</Id>\n";
            	$xmlfile .= "</CdtrAcct>\n";
            	$xmlfile .= "<CdtrAgt>\n";                                        	//CreditorAgent, Creditor-Bank
                	$xmlfile .= "<FinInstnId>\n";                                 	//FinancialInstitutionIdentification
                	if(strlen($zempf['bic']) !== 0)       						  	//ist ein BIC vorhanden?
                	{
                		$xmlfile .= '<BIC>'.$zempf['bic']."</BIC>\n";
                	}
                	else
                	{
                		$xmlfile .= "<Othr>\n";
                			$xmlfile .= "<Id>NOTPROVIDED</Id>\n";
                		$xmlfile .= "</Othr>\n";
                	}
                	$xmlfile .= "</FinInstnId>\n";
            	$xmlfile .= "</CdtrAgt>\n";
            	$xmlfile .= "<ChrgBr>SLEV</ChrgBr>\n";                            //ChargeBearer, Entgeltverrechnungsart, immer SLEV

            	// ########## CREDITOR, Zahlungsempfaenger ##############
            	$xmlfile .= "<CdtrSchmeId>\n";                                    //CreditorSchemeIdentification, Identifikation des Zahlungsempfaengers
                	$xmlfile .= "<Id>\n";                                         //Eindeutiges Identifizierungmerkmal einer Organisation oder Person
                    	$xmlfile .= "<PrvtId>\n";                                 //PrivateIdentification, Personenidentifikation
                        	$xmlfile .= "<Othr>\n";                               //OtherIdentification
                            	$xmlfile .= '<Id>'.$zempf['ci']."</Id>\n";        //Eindeutiges Identifizierungsmerkmal des Glaeubigers
                            	$xmlfile .= "<SchmeNm>\n";                        //SchemeName, Name des Identifikationsschemas
                                	$xmlfile .= "<Prtry>SEPA</Prtry>\n";          //Proprietary, immer SEPA
                            	$xmlfile .= "</SchmeNm>\n";
                        	$xmlfile .= "</Othr>\n";
                    	$xmlfile .= "</PrvtId>\n";
                	$xmlfile .= "</Id>\n";
            	$xmlfile .= "</CdtrSchmeId>\n";

            	// ######### Direct Debit Transaction Information, Lastschriften ##############
            	foreach ($zpflgt as $dummy => $zpflgtdata)                        // je Zahlungspflichtiger ein DrctDbtTxInf-Block
            	{
            		if ($dueDate == $zpflgtdata['duedate'] && $zpflgtdata['sequencetype'] === $data['sequencetype'])
            		{
                		$xmlfile .= "<DrctDbtTxInf>\n";                               //DirectDebitTransactionInformation
                    		$xmlfile .= "<PmtId>\n";                                  //PaymentIdentification, Referenzierung einer einzelnen Transaktion
                        		$xmlfile .= '<EndToEndId>'.$zpflgtdata['end2end_id']."</EndToEndId>\n";   //EndToEndIdentification
                                        //eindeutige Referenz des Zahlers (Auftraggebers). Diese Referenz
                                        //wird unveraendert durch die gesamte Kette bis zum Zahlungsempfaenger
                                        //geleitet (Ende-zu-Ende-Referenz). Ist keine Referenz vorhanden
                                        //muss die Konstante NOTPROVIDED benutzt werden.
                    		$xmlfile .= "</PmtId>\n";
                    		
                    		if (!$oneDueDateOnly)												//PmtTpInf-Block entweder hier unter DrctDbtTxInf oder unter PmtInf
                    		{
                     			$xmlfile .= "<PmtTpInf>\n";                                     //PaymentTypeInformation
                     				$xmlfile .= "<SvcLvl>\n";                                   //ServiceLevel
                     					$xmlfile .= "<Cd>SEPA</Cd>\n";                          //Code, immer SEPA
                     				$xmlfile .= "</SvcLvl>\n";
                     				$xmlfile .= "<LclInstrm>\n";                                //LocalInstrument, Lastschriftart
                     					$xmlfile .= "<Cd>CORE</Cd>\n";                          //CORE (Basislastschrift oder B2B (Firmenlastschrift)
                     				$xmlfile .= "</LclInstrm>\n";
                     				$xmlfile .= '<SeqTp>'.$zpflgtdata['sequencetype']."</SeqTp>\n";                //SequenceType
                     																//Der SequenceType gibt an, ob es sich um eine Erst-, Folge-,
                     																//Einmal- oder letztmalige Lastschrift handelt.
                     																//Zulaessige Werte: FRST, RCUR, OOFF, FNAL
                     																//Wenn <OrgnlDbtrAcct> = SMNDA und <Amdmnt-Ind> = true
                     																//dann muss dieses Feld mit FRST belegt sein.
                     			$xmlfile .= "</PmtTpInf>\n";
                    		}
                    		
                    		$xmlfile .= '<InstdAmt Ccy="EUR">'.$zpflgtdata['betrag']."</InstdAmt>\n";   //InstructedAmount (Dezimalpunkt)
                    		$xmlfile .= "<DrctDbtTx>\n";                              //DirectDebitTransaction, Angaben zum Lastschriftmandat
                        		$xmlfile .= "<MndtRltdInf>\n";                        //MandateRelated-Information, mandatsbezogene Informationen
                            		$xmlfile .= '<MndtId>'.$zpflgtdata['mandat_id']."</MndtId>\n";            //eindeutige Mandatsreferenz
                            		$xmlfile .= '<DtOfSgntr>'.$zpflgtdata['mandat_datum']."</DtOfSgntr>\n";   //Datum, zu dem das Mandat unterschrieben wurde

                            		if((strlen($zempf['orig_cdtr_name']) !== 0)
                                		|| (strlen($zempf['orig_cdtr_id']) !== 0)
                                		|| (strlen($zpflgtdata['orig_mandat_id']) !== 0)
                                		|| (strlen($zpflgtdata['orig_iban']) !== 0)
                                		|| (strlen($zpflgtdata['orig_dbtr_agent']) !== 0)) //Kennzeichnet, ob das Mandat veraendert wurde,
                            		{
                                		$xmlfile .= "<AmdmntInd>true</AmdmntInd>\n";  //AmendmentIndicator "true"
                                		$xmlfile .= "<AmdmntInfDtls>\n";              //AmendmentInformationDetails, Pflichtfeld, falls <AmdmntInd>=true

                                		if(strlen($zpflgtdata['orig_mandat_id']) !== 0)        //Kennzeichnet, ob das Mandat veraendert wurde,
                                		{
                                    		$xmlfile .= '<OrgnlMndtId>'.$zpflgtdata['orig_mandat_id']."</OrgnlMndtId>\n";
                                		}

                                		if((strlen($zempf['orig_cdtr_name']) !== 0) || (strlen($zempf['orig_cdtr_id']) !== 0))                //Kennzeichnet, ob das Mandat veraendert wurde,
                                		{
                                    		$xmlfile .= "<OrgnlCdtrSchmeId>\n";       //Identifikation des Zahlungsempfaengers
                                    		if(strlen($zempf['orig_cdtr_name']) !== 0) //Kennzeichnet, ob das Mandat veraendert wurde,
                                    		{
                                        		$xmlfile .= '<Nm>'.$zempf['orig_cdtr_name']."</Nm>\n";
                                    		}
                                    		if(strlen($zempf['orig_cdtr_id']) !== 0)
                                    		{
                                        		$xmlfile .= "<Id>\n";
                                            		$xmlfile .= "<PrvtId>\n";
                                                		$xmlfile .= "<Othr>\n";
                                                    		$xmlfile .= '<Id>'.$zempf['orig_cdtr_id']."</Id>\n";
                                                    		$xmlfile .= "<SchmeNm>\n";
                                                        		$xmlfile .= "<Prtry>SEPA</Prtry>\n";
                                                    		$xmlfile .= "</SchmeNm>\n";
                                                		$xmlfile .= "</Othr>\n";
                                            		$xmlfile .= "</PrvtId>\n";
                                        		$xmlfile .= "</Id>\n";
                                    		}
                                    		$xmlfile .= "</OrgnlCdtrSchmeId>\n";
                                		}

                                		if(strlen($zpflgtdata['orig_iban']) !== 0 || strlen($zpflgtdata['orig_dbtr_agent']) !== 0)             //Kennzeichnet, ob das Mandat veraendert wurde,
                                		{
                                    		$xmlfile .= "<OrgnlDbtrAcct>\n";
                                        		$xmlfile .= "<Id>\n";
                                        			$xmlfile .= "<Othr>\n";
                                        				$xmlfile .= "<Id>SMNDA</Id>\n";
                                        			$xmlfile .= "</Othr>\n";
                                        		$xmlfile .= "</Id>\n";
                                    		$xmlfile .= "</OrgnlDbtrAcct>\n";
                                		}

                                	/*	if(strlen($zpflgtdata['orig_dbtr_agent']) !== 0)       //Kennzeichnet, ob das Mandat veraendert wurde,
                                		{
                                    		$xmlfile .= "<OrgnlDbtrAgt>\n";
                                        		$xmlfile .= "<FinInstnId>\n";
                                            		$xmlfile .= "<Othr>\n";
                                                		$xmlfile .= '<Id>'.$zpflgtdata['orig_dbtr_agent']."</Id>\n";
                                            		$xmlfile .= "</Othr>\n";
                                       	 		$xmlfile .= "</FinInstnId>\n";
                                    		$xmlfile .= "</OrgnlDbtrAgt>\n";
                                		}*/

                                		$xmlfile .= "</AmdmntInfDtls>\n";
                            		}
                            		else
                            		{
                                		$xmlfile .= "<AmdmntInd>false</AmdmntInd>\n";     //AmendmentIndicator "false"
                            		}
                        		$xmlfile .= "</MndtRltdInf>\n";
                    		$xmlfile .= "</DrctDbtTx>\n";

                    		//## Kreditinstitut des Zahlers (Zahlungspflichtigen)
                        	$xmlfile .= "<DbtrAgt>\n";                                //DebtorAgent, Kreditinstitut des Zahlers (Zahlungspflichtigen)
                            	$xmlfile .= "<FinInstnId>\n";                         //FinancialInstitutionIdentification
                            	if(strlen($zpflgtdata['bic']) !== 0)       			  //ist ein BIC vorhanden?
                            	{
                                	$xmlfile .= '<BIC>'.$zpflgtdata['bic']."</BIC>\n";
                            	}
                            	else
                            	{
                                	$xmlfile .= "<Othr>\n";
                                    	$xmlfile .= "<Id>NOTPROVIDED</Id>\n";
                                	$xmlfile .= "</Othr>\n";
                            	}
                            	$xmlfile .= "</FinInstnId>\n";
                        	$xmlfile .= "</DbtrAgt>\n";

                    		$xmlfile .= "<Dbtr>\n";                                       //Zahlungspflichtiger
                        		$xmlfile .= '<Nm>'.$zpflgtdata['name']."</Nm>\n";         //Name (70)
                        		if (!empty($zpflgtdata['land']))
                        		{
                        			$xmlfile .= "<PstlAdr>\n";
                        				$xmlfile .= '<Ctry>'.$zpflgtdata['land']."</Ctry>\n";              //Zahlungspflichtigen-Adresse ist Pflicht
                        				$xmlfile .= '<AdrLine>'.$zpflgtdata['street']."</AdrLine>\n";     // bei Lastschriften ausserhalb EU/EWR
                        				$xmlfile .= '<AdrLine>'.$zpflgtdata['ort']."</AdrLine>\n";          
                        			$xmlfile .= "</PstlAdr>\n";
                        		}
                    		$xmlfile .= "</Dbtr>\n";
                    		
                    		$xmlfile .= "<DbtrAcct>\n";
                        		$xmlfile .= "<Id>\n";
                            		$xmlfile .= '<IBAN>'.$zpflgtdata['iban']."</IBAN>\n";
                        		$xmlfile .= "</Id>\n";
                    		$xmlfile .= "</DbtrAcct>\n";
                    		
                    		if(strlen($zpflgtdata['alt_name']) > 0)
                    		{
                        		$xmlfile .= "<UltmtDbtr>\n";                              //UltimateDebtor
                            		$xmlfile .= '<Nm>'.$zpflgtdata['alt_name']."</Nm>\n";
                        		$xmlfile .= "</UltmtDbtr>\n";
                    		}
                    		
                    		if(strlen($zpflgtdata['text']) > 0)
                    		{
                                $xmlfile .= "<RmtInf>\n";                                     // Remittance Information, Verwendungszweck
                                    $xmlfile .= '<Ustrd>'.$zpflgtdata['text']."</Ustrd>\n";   //Unstructured, unstrukturierter Verwendungszweck(max. 140 Zeichen))
                    		    $xmlfile .= "</RmtInf>\n";
                    		}
                    		
                		$xmlfile .= "</DrctDbtTxInf>\n";
            		}                 							// Ende if $dueDate == $zpflgtdata['duedate'] && $zpflgtdata['sequencetype'] === $data['sequencetype']
            	}												// Ende foreach ($zpflgt as $dummy => $zpflgtdata)
        	$xmlfile .= "</PmtInf>\n";
    	}														// Ende foreach ($dueDateArr as $dueDate => $dummy), Payment Information Block
    $xmlfile .= "</CstmrDrctDbtInitn>\n";						// Ende Customer Direct Debit Transfer Initiation

    $xmlfile .= "</Document>\n";								//Ende Document

    /******************************************************************************
    * Schreibt XML-Datei
    *****************************************************************************/

    // Buchungshistorie: Datei beim Einzug speichern (erneuter Download und Teilen in der Einzugsuebersicht)
    try {
        $bookingService->storeExportFile($pPreferences->config['SEPA']['dateiname'] . $filename_ext . '.xml', $xmlfile);
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
    header('Content-Disposition: attachment; filename="'.$pPreferences->config['SEPA']['dateiname'].$filename_ext.'.xml"');

    echo $xmlfile;

    die();
}
elseif ($postExportFileMode === 'ctl_file')
{
    // Kontrolldatei (Excel bzw. CSV), siehe sepa_control_file.php
    $controlFile = mf_build_sepa_control_file($pPreferences->config['SEPA']['kontroll_dateiname'] . $filename_ext, $message_id, $message_datum, $message_initiator_name, $nbOfTxs_Msg, $ctrlSum_Msg, $payment_id, $zempf, $zpflgt);

    header('Content-Disposition: attachment; filename="' . $controlFile['name'] . '"');
    header('Content-Type: ' . $controlFile['mime']);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, must-revalidate');
    header('Pragma: public');
    echo $controlFile['content'];
    exit();
}
else
{
    $gMessage->show($gL10n->get('SYS_INVALID_PAGE_VIEW'));
}
