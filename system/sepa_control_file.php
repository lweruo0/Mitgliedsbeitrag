<?php
/**
 ***********************************************************************************************
 * Erzeugt die Kontrolldatei (Excel bzw. CSV) zu einer SEPA-Lastschriftdatei
 *
 * Wird von sepa_export.php (pain.008.001.02) und sepa_export_pain.008.001.08.php genutzt:
 * beim Download der Kontrolldatei und beim Speichern eines Einzugs (Teilen per Link).
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Utils\FileSystemUtils;

if (basename($_SERVER['SCRIPT_FILENAME']) === 'sepa_control_file.php') {
    exit('This page may not be called directly!');
}

/**
 * @param string $baseName Dateiname ohne Endung (Einstellung kontroll_dateiname + Endung des Exports)
 * @param array<string, mixed> $zempf Glaeubigerdaten
 * @param array<int, array<string, mixed>> $zpflgt Zahlungspflichtige
 * @return array{name: string, content: string, mime: string}
 */
function mf_build_sepa_control_file(string $baseName, string $messageId, string $messageDate, string $initiatorName, $nbOfTxs, $ctrlSum, string $paymentId, array $zempf, array $zpflgt): array
{
    global $gL10n, $gSettingsManager, $gCurrentUser, $gCurrentOrganization, $pPreferences;

    $separator = '';
    $valueQuotes = '';
    $charset = '';
    $exportMode = $pPreferences->config['SEPA']['kontroll_dateityp'];

    switch ($exportMode) {
        case 'csv-ms':
            $separator = ';'; // Microsoft Excel 2007 or new needs a semicolon
            $valueQuotes = '"'; // all values should be set with quotes
            $exportMode = 'csv';
            $charset = 'iso-8859-1';
            break;
        case 'csv-oo':
            $separator = ','; // a CSV file should have a comma
            $valueQuotes = '"'; // all values should be set with quotes
            $exportMode = 'csv';
            $charset = 'utf-8';
            break;
        default:
            include_once (__DIR__ . '/../libs/PHP_XLSXWriter/xlsxwriter.class.php');
            $exportMode = 'xlsx';
            break;
    }

    $filename = FileSystemUtils::getSanitizedPathEntry($baseName) . '.' . $exportMode;

    $rows = array();
    $rows[] = array('SEPA-' . $gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_FILE'));
    $rows[] = array('');
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_FILE_NAME'), $filename);
    $rows[] = array('');
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_MESSAGE_ID'), $messageId);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_MESSAGE_DATE'), $messageDate);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_MESSAGE_INITIATOR_NAME'), $initiatorName);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_NUMBER_TRANSACTIONS'), $nbOfTxs);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_CONTROL_SUM'), $ctrlSum);
    $rows[] = array('');
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_ID'), $paymentId);
    $rows[] = array('');
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_CREDITOR'), $zempf['name']);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_CI'), $zempf['ci']);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_IBAN'), $zempf['iban']);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_BIC'), $zempf['bic']);
    $rows[] = array('');
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_ORIG_CI'), $zempf['orig_cdtr_id']);
    $rows[] = array($gL10n->get('PLG_MEMBERSHIPFEE_ORIG_CREDITOR'), $zempf['orig_cdtr_name']);
    $rows[] = array('');
    $rows[] = array(
        $gL10n->get('PLG_MEMBERSHIPFEE_SERIAL_NUMBER'),
        $gL10n->get('PLG_MEMBERSHIPFEE_ACCOUNT_HOLDER'),
        $gL10n->get('PLG_MEMBERSHIPFEE_IBAN'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BIC'),
        $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_SEQUENCETYPE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_FEE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT'),
        $gL10n->get('PLG_MEMBERSHIPFEE_MANDATEID'),
        $gL10n->get('PLG_MEMBERSHIPFEE_MANDATEDATE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_ULTIMATE_DEBTOR'),
        $gL10n->get('PLG_MEMBERSHIPFEE_ORIG_MANDATEID'),
        $gL10n->get('PLG_MEMBERSHIPFEE_ORIG_IBAN'),
        $gL10n->get('PLG_MEMBERSHIPFEE_ORIG_DEBTOR_AGENT'),
        $gL10n->get('SYS_COUNTRY'),
        $gL10n->get('SYS_STREET'),
        $gL10n->get('SYS_POSTCODE'),
        $gL10n->get('SYS_CITY')
    );
    $headerRowCount = count($rows);

    $nr = 1;
    foreach ($zpflgt as $zpflgtdata) {
        $datumDueDate = \DateTime::createFromFormat('Y-m-d', (string) $zpflgtdata['duedate']);
        $datumMandate = \DateTime::createFromFormat('Y-m-d', (string) $zpflgtdata['mandat_datum']);
        $rows[] = array(
            $nr,
            $zpflgtdata['name'],
            $zpflgtdata['iban'],
            $zpflgtdata['bic'],
            $datumDueDate ? $datumDueDate->format($gSettingsManager->getString('system_date')) : $zpflgtdata['duedate'],
            $zpflgtdata['sequencetype'],
            $zpflgtdata['betrag'],
            $zpflgtdata['text'],
            $zpflgtdata['mandat_id'],
            $datumMandate ? $datumMandate->format($gSettingsManager->getString('system_date')) : $zpflgtdata['mandat_datum'],
            $zpflgtdata['alt_name'],
            $zpflgtdata['orig_mandat_id'],
            $zpflgtdata['orig_iban'],
            $zpflgtdata['orig_dbtr_agent'],
            $zpflgtdata['land'],
            $zpflgtdata['street'],
            $zpflgtdata['postcode'],
            $zpflgtdata['ort']
        );
        $nr += 1;
    }

    if ($exportMode === 'csv') {
        $csvStr = '';
        foreach ($rows as $index => $cols) {
            $csvStr .= $valueQuotes . implode($valueQuotes . $separator . $valueQuotes, $cols) . $valueQuotes . "\n";
            if ($index === $headerRowCount - 1) {
                $csvStr .= "\n";
            }
        }
        return array(
            'name' => $filename,
            'content' => ($charset === 'iso-8859-1') ? mb_convert_encoding($csvStr, 'ISO-8859-1', 'UTF-8') : $csvStr,
            'mime' => 'text/comma-separated-values; charset=' . $charset
        );
    }

    $writer = new XLSXWriter();
    $writer->setAuthor($gCurrentUser->getValue('FIRST_NAME') . ' ' . $gCurrentUser->getValue('LAST_NAME'));
    $writer->setTitle($filename);
    $writer->setSubject($gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE'));
    $writer->setCompany($gCurrentOrganization->getValue('org_longname'));
    $writer->setKeywords(array(
        $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_PAYMENTS'),
        $gL10n->get('PLG_MEMBERSHIPFEE_SEPA')
    ));
    $writer->setDescription($gL10n->get('PLG_MEMBERSHIPFEE_CREATED_WITH'));
    $writer->writeSheet($rows, '', array());

    return array(
        'name' => XLSXWriter::sanitize_filename($filename),
        'content' => $writer->writeToString(),
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
}
