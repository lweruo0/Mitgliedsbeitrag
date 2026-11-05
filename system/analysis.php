<?php
/**
 ***********************************************************************************************
 * Beitragsanalyse fuer das Admidio-Plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:  none
 *
 ***********************************************************************************************
 */

use Plugins\MembershipFee\classes\Config\ConfigTable;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;

require_once(__DIR__ . '/../../../system/common.php');
require_once(__DIR__ . '/common_function.php');

// only authorized user are allowed to start this module
if (!isUserAuthorized())
{
    throw new Exception('SYS_NO_RIGHTS');   
}

$pPreferences = new ConfigTable();
$pPreferences->read();

$headline = mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTION_ANALYSIS'));
$gNavigation->addUrl(CURRENT_URL, $headline);

$page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-analysis', $headline);

// Buchungskreis (Umschaltung Mitgliedsbeitrag/Arbeitsdienst ueber der Seite)
$mfScope = mf_collection_scope();
$beitrag = analyse_mem($mfScope);
$sum = 0;

$page->addHtml(openGroupBox('members_contribution', $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERS_CONTRIBUTION')));

$datatable = false;
$hoverRows = true;
$classTable  = 'table table-condensed';

$table = new HtmlTable('table_members_contribution', $page, $hoverRows, $datatable, $classTable);

$columnAttributes['style'] = 'text-align: left';
$table->addColumn('', $columnAttributes, 'th');

$columnAttributes['colspan'] = 2;
$columnAttributes['style'] = 'text-align: right';
$table->addColumn($gL10n->get('PLG_MEMBERSHIPFEE_WITH_ACCOUNT_DATA'), $columnAttributes, 'th');
$table->addColumn($gL10n->get('PLG_MEMBERSHIPFEE_WITHOUT_ACCOUNT_DATA'), $columnAttributes, 'th');
$table->addColumn($gL10n->get('PLG_MEMBERSHIPFEE_SUM'), $columnAttributes, 'th');

$columnAlign  = array('left', 'right', 'right', 'right', 'right', 'right', 'right');
$table->setColumnAlignByArray($columnAlign);

$columnValues = array();
$columnValues[] = '';
$columnValues[] = $gL10n->get('SYS_CONTRIBUTION');
$columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_NUMBER');
$columnValues[] = $gL10n->get('SYS_CONTRIBUTION');
$columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_NUMBER');
$columnValues[] = $gL10n->get('SYS_CONTRIBUTION');
$columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_NUMBER');
$table->addRowByArray($columnValues);

$columnValues = array();
$columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_DUES');
$columnValues[] = mf_format_amount($beitrag['BEITRAG_kto']);
$columnValues[] = $beitrag['BEITRAG_kto_anzahl'];
$columnValues[] = mf_format_amount($beitrag['BEITRAG_rech']);
$columnValues[] = $beitrag['BEITRAG_rech_anzahl'];
$columnValues[] = mf_format_amount($beitrag['BEITRAG_kto']+$beitrag['BEITRAG_rech']);
$columnValues[] = ($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl']);
$table->addRowByArray($columnValues);

$columnValues = array();
$columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_ALREADY_PAID');
$columnValues[] = mf_format_amount($beitrag['BEZAHLT_kto']);
$columnValues[] = $beitrag['BEZAHLT_kto_anzahl'];
$columnValues[] = mf_format_amount($beitrag['BEZAHLT_rech']);
$columnValues[] = $beitrag['BEZAHLT_rech_anzahl'];
$columnValues[] = mf_format_amount($beitrag['BEZAHLT_kto']+$beitrag['BEZAHLT_rech']);
$columnValues[] = ($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl']);
$table->addRowByArray($columnValues);

$columnValues = array();
$columnValues[] = $gL10n->get('PLG_MEMBERSHIPFEE_PENDING');
$columnValues[] = mf_format_amount($beitrag['BEITRAG_kto']-$beitrag['BEZAHLT_kto']);
$columnValues[] = ($beitrag['BEITRAG_kto_anzahl']-$beitrag['BEZAHLT_kto_anzahl']);
$columnValues[] = mf_format_amount($beitrag['BEITRAG_rech']-$beitrag['BEZAHLT_rech']);
$columnValues[] = ($beitrag['BEITRAG_rech_anzahl']-$beitrag['BEZAHLT_rech_anzahl']);
$columnValues[] = mf_format_amount(($beitrag['BEITRAG_kto']+$beitrag['BEITRAG_rech'])-($beitrag['BEZAHLT_kto']+$beitrag['BEZAHLT_rech']));
$columnValues[] = (($beitrag['BEITRAG_kto_anzahl']+$beitrag['BEITRAG_rech_anzahl'])-($beitrag['BEZAHLT_kto_anzahl']+$beitrag['BEZAHLT_rech_anzahl']));
$table->addRowByArray($columnValues);

$page->addHtml($table->show(false));
$page->addHtml('<strong>'.$gL10n->get('SYS_NOTE').':</strong> '.$gL10n->get('PLG_MEMBERSHIPFEE_MEMBERS_CONTRIBUTION_DESC'));

$page->addHtml(closeGroupBox());

if ($mfScope->isMembership()) {
    $page->addHtml(openGroupBox('roles_contribution', $gL10n->get('PLG_MEMBERSHIPFEE_ROLES_CONTRIBUTION')));

    // Beitragsrollen (Prognose aus Rollenbeitrag x Mitglieder im Beitragsjahr), alphabetisch
    $rollen = analyse_rol();
    uasort($rollen, static fn (array $a, array $b): int => strnatcasecmp((string) $a['rolle'], (string) $b['rolle']));

    // Positionen ohne Beitragsrolle (manuell, Individualbeitraege) des Beitragsjahres, nicht storniert:
    // sie sind im Beitrag der Mitglieder enthalten, aber nicht in der Prognose der Rollen
    $otherPositions = array();
    $membershipScope = \Plugins\MembershipFee\classes\Bookings\CollectionScope::membership((int) $gCurrentOrgId);
    foreach ($membershipScope->filterPositions(\Plugins\MembershipFee\classes\Bookings\BookingStore::search(array('year' => getContributionYear()))) as $position) {
        if ($position['mfb_source'] === 'rolle' || $position['mfb_status'] === \Plugins\MembershipFee\classes\Bookings\BookingStore::STATUS_CANCELLED) {
            continue;
        }
        $label = (string) $position['mfb_rol_name'];
        $otherPositions[$label] ??= array('count' => 0, 'amount' => 0.0);
        $otherPositions[$label]['count']++;
        $otherPositions[$label]['amount'] += (float) $position['mfb_amount'];
    }
    ksort($otherPositions, SORT_NATURAL | SORT_FLAG_CASE);

    $rowsHtml = '';
    foreach ($rollen as $roldata) {
        $amount = (float) $roldata['rol_cost'] * count($roldata['members']);
        $sum += $amount;
        $rowsHtml .= '<tr' . (count($roldata['members']) === 0 ? ' class="text-muted"' : '') . '><td>' . SecurityUtils::encodeHTML((string) $roldata['rolle']) . '</td>'
            . '<td class="text-end text-nowrap">' . mf_format_amount($roldata['rol_cost']) . '</td>'
            . '<td class="text-end">' . count($roldata['members']) . '</td>'
            . '<td class="text-end text-nowrap">' . mf_format_amount($amount) . '</td></tr>';
    }
    $otherSum = 0.0;
    foreach ($otherPositions as $label => $data) {
        $otherSum += $data['amount'];
        $rowsHtml .= '<tr><td>' . SecurityUtils::encodeHTML($label) . ' <small class="text-muted">(' . $gL10n->get('PLG_MEMBERSHIPFEE_ANALYSIS_WITHOUT_ROLE') . ')</small></td>'
            . '<td class="text-end"></td>'
            . '<td class="text-end">' . $data['count'] . '</td>'
            . '<td class="text-end text-nowrap">' . mf_format_amount($data['amount']) . '</td></tr>';
    }

    $page->addHtml('<div class="table-responsive"><table class="table table-sm table-striped mb-2"><thead><tr>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_ROLE') . '</th>'
        . '<th class="text-end">' . $gL10n->get('SYS_CONTRIBUTION') . '</th>'
        . '<th class="text-end">' . $gL10n->get('PLG_MEMBERSHIPFEE_NUMBER') . '</th>'
        . '<th class="text-end">' . $gL10n->get('PLG_MEMBERSHIPFEE_SUM') . '</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody><tfoot>'
        . (count($otherPositions) > 0
            ? '<tr><td colspan="3">' . $gL10n->get('PLG_MEMBERSHIPFEE_ANALYSIS_ROLES_SUM') . '</td><td class="text-end text-nowrap">' . mf_format_amount($sum) . '</td></tr>'
            : '')
        . '<tr class="fw-bold"><td colspan="3">' . $gL10n->get('PLG_MEMBERSHIPFEE_TOTAL') . '</td><td class="text-end text-nowrap">' . mf_format_amount($sum + $otherSum) . '</td></tr>'
        . '</tfoot></table></div>');

    $page->addHtml('<strong>'.$gL10n->get('SYS_NOTE').':</strong> '.$gL10n->get('PLG_MEMBERSHIPFEE_ROLES_CONTRIBUTION_DESC'));

    $page->addHtml(closeGroupBox());
} else {
    // Arbeitsdienst: Ersatzbeitraege aus der Buchungshistorie je Leistungsjahr (nicht storniert)
    $page->addHtml(openGroupBox('work_contribution', $gL10n->get('PLG_MEMBERSHIPFEE_ANALYSIS_WORK_TITLE')));

    $years = array();
    foreach ($mfScope->filterPositions(\Plugins\MembershipFee\classes\Bookings\BookingStore::search(array())) as $position) {
        if ($position['mfb_status'] === \Plugins\MembershipFee\classes\Bookings\BookingStore::STATUS_CANCELLED) {
            continue;
        }
        $year = (int) $position['mfb_year'];
        $label = (string) $position['mfb_rol_name'];
        $years[$year][$label] ??= array('count' => 0, 'amount' => 0.0, 'collected' => 0.0, 'open' => 0.0);
        $row = &$years[$year][$label];
        $row['count']++;
        $row['amount'] += (float) $position['mfb_amount'];
        if ($position['mfb_status'] === \Plugins\MembershipFee\classes\Bookings\BookingStore::STATUS_COLLECTED) {
            $row['collected'] += (float) $position['mfb_amount'];
        } else {
            $row['open'] += (float) $position['mfb_amount'];
        }
        unset($row);
    }
    krsort($years);

    $total = array('count' => 0, 'amount' => 0.0, 'collected' => 0.0, 'open' => 0.0);
    $rowsHtml = '';
    foreach ($years as $year => $labels) {
        ksort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($labels as $label => $data) {
            $rowsHtml .= '<tr><td>' . $year . '</td><td>' . SecurityUtils::encodeHTML($label) . '</td>'
                . '<td class="text-end">' . $data['count'] . '</td>'
                . '<td class="text-end text-nowrap">' . mf_format_amount($data['amount']) . '</td>'
                . '<td class="text-end text-nowrap">' . mf_format_amount($data['collected']) . '</td>'
                . '<td class="text-end text-nowrap' . ($data['open'] >= 0.005 ? ' text-danger' : '') . '">' . mf_format_amount($data['open']) . '</td></tr>';
            foreach (array_keys($total) as $key) {
                $total[$key] += $data[$key];
            }
        }
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="6" class="text-muted">' . $gL10n->get('PLG_MEMBERSHIPFEE_ANALYSIS_WORK_NONE') . '</td></tr>';
    }

    $page->addHtml('<div class="table-responsive"><table class="table table-sm table-striped mb-2"><thead><tr>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_YEAR') . '</th>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_ANALYSIS_LABEL') . '</th>'
        . '<th class="text-end">' . $gL10n->get('PLG_MEMBERSHIPFEE_NUMBER') . '</th>'
        . '<th class="text-end">' . $gL10n->get('PLG_MEMBERSHIPFEE_SUM') . '</th>'
        . '<th class="text-end">' . $gL10n->get('PLG_MEMBERSHIPFEE_ANALYSIS_COLLECTED') . '</th>'
        . '<th class="text-end">' . $gL10n->get('PLG_MEMBERSHIPFEE_PENDING') . '</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody><tfoot>'
        . '<tr class="fw-bold"><td colspan="2">' . $gL10n->get('PLG_MEMBERSHIPFEE_TOTAL') . '</td>'
        . '<td class="text-end">' . $total['count'] . '</td>'
        . '<td class="text-end text-nowrap">' . mf_format_amount($total['amount']) . '</td>'
        . '<td class="text-end text-nowrap">' . mf_format_amount($total['collected']) . '</td>'
        . '<td class="text-end text-nowrap">' . mf_format_amount($total['open']) . '</td></tr>'
        . '</tfoot></table></div>');
    $page->addHtml('<strong>' . $gL10n->get('SYS_NOTE') . ':</strong> ' . $gL10n->get('PLG_MEMBERSHIPFEE_ANALYSIS_WORK_DESC'));

    $page->addHtml(closeGroupBox());
}

$page->show();
