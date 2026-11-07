<?php
/**
 ***********************************************************************************************
 * Arbeitsdienst abrechnen
 *
 * Legt je Mitglied mit Fehlstunden eine offene Position "Ersatzleistung Arbeitsdienst" im Buchungskreis
 * Arbeitsdienst an (Quelle "ad", Jahr = Leistungsjahr): Fehlstunden x Stundensatz, siehe WorkBillingPlanner.
 * Erneutes Abrechnen passt offene Positionen an; exportierte oder eingezogene Positionen bleiben unveraendert.
 * Danach geht es mit Faelligkeit, Vorabinformation und SEPA-Datei im Buchungskreis Arbeitsdienst weiter.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode : show (Vorschau, Standard) | apply (POST: Positionen anlegen)
 * year : Leistungsjahr (Standard: Vorjahr bis Ende Juni, danach das laufende Jahr)
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Work\WorkBillingPlanner;
use Plugins\MembershipFee\classes\Work\WorkHoursStore;
use Plugins\MembershipFee\classes\Work\WorkProfileFields;
use Plugins\MembershipFee\classes\Work\WorkRules;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    if (!isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $defaultYear = ((int) date('n') <= 6) ? (int) date('Y') - 1 : (int) date('Y');
    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array('defaultValue' => 'show', 'validValues' => array('show', 'apply')));
    $getYear = admFuncVariableIsValid($_GET, 'year', 'int', array('defaultValue' => $defaultYear));
    if ($getYear < 2000 || $getYear > 2100) {
        $getYear = $defaultYear;
    }

    $pPreferences = new ConfigTable();
    $pPreferences->read();
    $rules = WorkRules::fromConfig($pPreferences->config);
    $orgId = (int) $gCurrentOrgId;
    $scope = CollectionScope::work($orgId);
    $bookingService = new BookingService($pPreferences->config, $scope);
    $selfUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/work_billing.php';

    WorkProfileFields::ensure($orgId);
    WorkHoursStore::ensureTable();

    /**
     * Plan je Mitglied: Soll aus dem Profilfeld, Ist aus der Stundentabelle, vorhandene Positionen.
     * @return array<int, array<string, mixed>>
     */
    $buildPlans = static function (int $year) use ($gDb, $gProfileFields, $orgId, $rules): array {
        $usfIds = array(
            'last' => (int) $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
            'first' => (int) $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
            'target' => (int) $gProfileFields->getProperty(WorkProfileFields::targetField($orgId), 'usf_id')
        );
        $data = array();
        $statement = $gDb->queryPrepared('SELECT usd.usd_usr_id, usd.usd_usf_id, usd.usd_value
              FROM ' . TBL_USER_DATA . ' AS usd
        INNER JOIN ' . TBL_USERS . ' AS usr ON usr.usr_id = usd.usd_usr_id AND usr.usr_valid = true
             WHERE usd.usd_usf_id IN (?, ?, ?)', array_values($usfIds));
        $keyByUsf = array_flip($usfIds);
        while ($row = $statement->fetch()) {
            $data[(int) $row['usd_usr_id']][$keyByUsf[(int) $row['usd_usf_id']]] = (string) $row['usd_value'];
        }
        $done = WorkHoursStore::sumByUser($year);

        // vorhandene Positionen des Arbeitsdienstes (auch von Mitgliedern ohne Soll mehr)
        $positionsByUser = array();
        foreach (BookingStore::getByUsers(array(), array(), $year) as $usrId => $positions) {
            $workPositions = array_values(CollectionScope::work($orgId)->filterPositions($positions));
            if (count($workPositions) > 0) {
                $positionsByUser[(int) $usrId] = $workPositions;
            }
        }

        $plans = array();
        foreach (array_unique(array_merge(array_keys($data), array_keys($positionsByUser))) as $usrId) {
            $values = $data[$usrId] ?? array();
            $target = (isset($values['target']) && $values['target'] !== '') ? (float) str_replace(',', '.', $values['target']) : null;
            $ist = $done[$usrId] ?? 0.0;
            $plan = WorkBillingPlanner::plan($year, $target, $ist, $rules, $positionsByUser[$usrId] ?? array());
            if ($plan['state'] === WorkBillingPlanner::STATE_NONE) {
                continue;
            }
            $plans[$usrId] = array_merge($plan, array(
                'name' => trim(($values['last'] ?? '') . ', ' . ($values['first'] ?? ''), ', '),
                'target' => $target,
                'done' => $ist
            ));
        }
        uasort($plans, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $plans;
    };

    // ------------------------------------------------------------------
    // Positionen anlegen
    // ------------------------------------------------------------------
    if ($getMode === 'apply') {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
        $plans = $buildPlans($getYear);

        $gDb->startTransaction();
        $count = 0;
        $sum = 0.0;
        foreach ($plans as $usrId => $plan) {
            if (count($plan['ops']) === 0) {
                continue;
            }
            $bookingService->applyOps((int) $usrId, $plan['ops']);
            $count++;
        }
        foreach ($buildPlans($getYear) as $plan) {
            if ($plan['state'] !== WorkBillingPlanner::STATE_BILLED) {
                $sum += $plan['amount'];
            }
        }
        $gDb->endTransaction();

        $_SESSION['pMembershipFee']['work_message'] = $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING_DONE', array($count, mf_format_amount($sum)));
        admRedirect(SecurityUtils::encodeUrl($selfUrl, array('year' => $getYear)));
        // => EXIT
    }

    // ------------------------------------------------------------------
    // Vorschau
    // ------------------------------------------------------------------
    $headline = mf_page_headline($gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING'));
    $gNavigation->addUrl(CURRENT_URL, $headline);
    $page = new \Plugins\MembershipFee\classes\Ui\SidebarPage('plg-mitgliedsbeitrag-work-billing', $headline);
    $page->setContentFullWidth();

    $nextUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bill.php', array('collection' => CollectionScope::WORK));
    if (!empty($_SESSION['pMembershipFee']['work_message'])) {
        $page->addHtml('<div class="alert alert-success" role="alert"><i class="bi bi-check-lg"></i> ' . SecurityUtils::encodeHTML($_SESSION['pMembershipFee']['work_message'])
            . ' <a class="btn btn-primary btn-sm ms-2" href="' . $nextUrl . '"><i class="bi bi-receipt"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_NEXT_BILL') . '</a></div>');
        unset($_SESSION['pMembershipFee']['work_message']);
    }

    // Leistungsjahr
    $years = WorkHoursStore::getYears();
    foreach (array((int) date('Y'), (int) date('Y') - 1, $getYear) as $year) {
        if (!in_array($year, $years, true)) {
            $years[] = $year;
        }
    }
    rsort($years);
    $page->addHtml(mf_year_switch_html($getYear, $years, $selfUrl, array(),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING_DESC', array($getYear))));

    $plans = $buildPlans($getYear);
    $stats = array('changes' => 0, 'open' => 0.0, 'billed' => 0.0, 'members' => 0);
    foreach ($plans as $plan) {
        if (count($plan['ops']) > 0) {
            $stats['changes']++;
        }
        if ($plan['state'] === WorkBillingPlanner::STATE_BILLED) {
            $stats['billed'] += $plan['amount'];
        } else {
            $stats['open'] += $plan['amount'];
        }
        if ($plan['amount'] >= 0.005) {
            $stats['members']++;
        }
    }

    $stat = static fn (string $value, string $label): string => '<div class="text-center px-2"><div class="fs-4 fw-bold lh-1">' . $value . '</div><div class="small text-muted">' . $label . '</div></div>';
    $page->addHtml('<div class="card mb-3 border-primary"><div class="card-body d-flex flex-wrap align-items-center gap-3">'
        . '<h4 class="mb-0 me-auto"><i class="bi bi-receipt"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING_HEADING', array($getYear)) . '</h4>'
        . $stat((string) $stats['members'], $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_WITH_MISSING'))
        . $stat(mf_format_amount($stats['open']), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_OPEN_AMOUNT'))
        . $stat(mf_format_amount($stats['billed']), $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_BILLED'))
        . $stat((string) $stats['changes'], $gL10n->get('PLG_MEMBERSHIPFEE_WORK_STAT_CHANGES'))
        . '</div></div>');

    if (count($plans) === 0) {
        $page->addHtml('<div class="alert alert-info" role="alert"><i class="bi bi-info-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING_NOTHING') . '</div>');
        $page->show();
        exit();
    }

    $applyForm = '<form method="post" class="my-2" action="' . SecurityUtils::encodeUrl($selfUrl, array('mode' => 'apply', 'year' => $getYear)) . '">'
        . '<input type="hidden" name="adm_csrf_token" value="' . SecurityUtils::encodeHTML($gCurrentSession->getCsrfToken()) . '" />'
        . '<button type="submit" class="btn btn-primary"' . ($stats['changes'] === 0 ? ' disabled="disabled"' : '') . '><i class="bi bi-check-lg"></i> '
        . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING_APPLY', array($stats['changes'])) . '</button>'
        . ' <a class="btn btn-secondary" href="' . $nextUrl . '"><i class="bi bi-receipt"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_NEXT_BILL') . '</a>'
        . '</form>';
    $page->addHtml($applyForm);

    $stateBadge = array(
        WorkBillingPlanner::STATE_NEW => 'bg-primary',
        WorkBillingPlanner::STATE_CHANGED => 'bg-warning text-dark',
        WorkBillingPlanner::STATE_REMOVED => 'bg-danger',
        WorkBillingPlanner::STATE_UNCHANGED => 'bg-secondary',
        WorkBillingPlanner::STATE_BILLED => 'bg-success'
    );
    $table = new HtmlTable('table_work_billing', $page, true, true, 'table table-condensed table-hover');
    $table->setColumnAlignByArray(array('left', 'right', 'right', 'right', 'left', 'left'));
    $table->addRowHeadingByArray(array(
        $gL10n->get('SYS_NAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_TARGET'),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_DONE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_FEE'),
        $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_TEXT'),
        $gL10n->get('PLG_MEMBERSHIPFEE_STATUS')
    ));
    $table->setDatatablesRowsPerPage(50);
    foreach ($plans as $plan) {
        $table->addRowByArray(array(
            SecurityUtils::encodeHTML($plan['name']),
            array('value' => $plan['target'] === null ? '&ndash;' : WorkRules::formatHours($plan['target']), 'order' => $plan['target'] ?? -1),
            array('value' => WorkRules::formatHours($plan['done']), 'order' => $plan['done']),
            array('value' => $plan['amount'] >= 0.005 ? '<strong>' . mf_format_amount($plan['amount']) . '</strong>' : '&ndash;', 'order' => $plan['amount']),
            '<small>' . SecurityUtils::encodeHTML($plan['text']) . '</small>',
            '<span class="badge ' . $stateBadge[$plan['state']] . '">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_BILLING_STATE_' . strtoupper($plan['state'])) . '</span>'
        ));
    }
    $page->addHtml($table->show(false));
    $page->addHtml($applyForm);

    $page->show();
} catch (Exception $e) {
    if (isset($gDb)) {
        $gDb->rollback();
    }
    $gMessage->show($e->getMessage());
}
