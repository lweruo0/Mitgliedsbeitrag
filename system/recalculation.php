<?php
/**
 ***********************************************************************************************
 * Neuberechnung der Mitgliedsbeitraege
 *
 * Die Neuberechnung legt Buchungspositionen je Mitglied, Beitragsrolle und Beitragsjahr an.
 * Rollen, fuer die im Beitragsjahr schon ein Einzug erfolgt ist, werden uebersprungen;
 * bei Beitragsgruppen wird nur die Differenz berechnet. Danach wird das Profilfeld Beitrag
 * auf die Summe der offenen Positionen gesetzt.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 ***********************************************************************************************
 */

/**
 * ****************************************************************************
 * Parameters:
 *
 * mode : preview - preview of the new fees
 * write - save the new fees
 * print - preview for printing
 *
 * ***************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Bookings\BookingService;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Config\ConfigTable;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

// only authorized user are allowed to start this module
if (! isUserAuthorized()) {
    throw new Exception('SYS_NO_RIGHTS');
}

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
    'defaultValue' => 'preview',
    'validValues' => array(
        'preview',
        'write',
        'print'
    )
));

$pPreferences = new ConfigTable();
$pPreferences->read();

$bookingService = new BookingService($pPreferences->config);
$user = new User($gDb, $gProfileFields);

// set headline of the script
$headline = $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION');

$gNavigation->addUrl(CURRENT_URL, $headline);

/**
 * Beschreibt die geplanten Aenderungen eines Mitglieds als HTML-Liste.
 *
 * @param array<string, mixed> $plan
 * @return string
 */
function describePlan(array $plan): string
{
    global $gL10n;

    $lines = array();
    foreach ($plan['ops'] as $op) {
        switch ($op['op']) {
            case 'insert':
                $lines[] = '<span class="text-success"><i class="bi bi-plus-circle"></i> ' . SecurityUtils::encodeHTML($op['data']['mfb_rol_name']) . ': ' . mf_format_amount($op['data']['mfb_amount']) . '</span>'
                    . (strpos($op['data']['mfb_text'], '(') !== false ? ' <small>' . SecurityUtils::encodeHTML($op['data']['mfb_text']) . '</small>' : '');
                break;
            case 'update':
                $lines[] = '<span class="text-primary"><i class="bi bi-arrow-repeat"></i> ' . SecurityUtils::encodeHTML($op['data']['mfb_rol_name']) . ': '
                    . mf_format_amount($op['before']['mfb_amount']) . ' &rarr; ' . mf_format_amount($op['data']['mfb_amount']) . '</span>';
                break;
            case 'delete':
                $lines[] = '<span class="text-danger"><i class="bi bi-dash-circle"></i> ' . SecurityUtils::encodeHTML($op['before']['mfb_rol_name']) . ': ' . mf_format_amount($op['before']['mfb_amount']) . '</span>';
                break;
        }
    }
    foreach ($plan['info'] as $info) {
        if ($info['type'] === 'collected') {
            $lines[] = '<span class="text-muted"><i class="bi bi-check2-circle"></i> ' . SecurityUtils::encodeHTML($info['role']) . ': ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_ALREADY_COLLECTED') . '</span>';
        } elseif ($info['type'] === 'revoked') {
            $lines[] = '<span class="text-muted"><i class="bi bi-slash-circle"></i> ' . SecurityUtils::encodeHTML($info['role']) . ': ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_ROLE_REVOKED') . '</span>';
        } elseif ($info['type'] === 'minimum') {
            $lines[] = '<span class="text-muted"><i class="bi bi-info-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_BELOW_MINIMUM') . '</span>';
        }
    }
    return implode('<br />', $lines);
}

/**
 * Berechnet den Beitrag nach Ausfuehrung des Plans (Summe der offenen Positionen).
 *
 * @param array<int, array<string, mixed>> $positions
 * @param array<string, mixed> $plan
 * @return float
 */
function feeAfterPlan(array $positions, array $plan): float
{
    $open = array();
    foreach ($positions as $position) {
        if ($position['mfb_status'] === BookingStore::STATUS_OPEN) {
            $open[$position['mfb_id']] = (float) $position['mfb_amount'];
        }
    }
    $newIndex = 0;
    foreach ($plan['ops'] as $op) {
        if ($op['op'] === 'insert') {
            $open['new' . $newIndex++] = (float) $op['data']['mfb_amount'];
        } elseif ($op['op'] === 'update') {
            $open[$op['id']] = (float) $op['data']['mfb_amount'];
        } elseif ($op['op'] === 'delete') {
            unset($open[$op['id']]);
        }
    }
    return round(array_sum($open), 2);
}

if ($getMode == 'preview') // Default
{
    $message = '';
    $selectedRoleIds = null;

    // pruefen, ob Eintraege in der Rollenauswahl bestehen
    if (isset($_POST['recalculation_roleselection']) && is_array($_POST['recalculation_roleselection'])) {
        $_SESSION['pMembershipFee']['recalculation_rol_sel'] = $_POST['recalculation_roleselection'];
        $selectedRoleIds = array_map('intval', $_POST['recalculation_roleselection']);

        $message .= '<strong>' . $gL10n->get('SYS_NOTE') . ':</strong> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_ROLLQUERY_INFO') . '<br/><br/>';
        foreach (beitragsrollen_einlesen() as $rol => $roldata) {
            if (in_array((int) $rol, $selectedRoleIds, true)) {
                $message .= '- ' . SecurityUtils::encodeHTML($roldata['rolle']) . '<br/>';
            }
        }
    } else {
        unset($_SESSION['pMembershipFee']['recalculation_rol_sel']);
    }

    $plans = $bookingService->planRecalculation($selectedRoleIds);

    $members = array();
    if (count($plans) > 0) {
        $members = list_members(array(
            'FIRST_NAME',
            'LAST_NAME',
            'FEE' . $gCurrentOrgId
        ), 2, 'AND mem_usr_id IN (' . implode(',', array_map('intval', array_keys($plans))) . ')');
    }

    $positionsByUser = BookingStore::getByUsers(array_keys($plans));

    $changes = array();
    $waiting = array();
    foreach ($plans as $usrId => $plan) {
        $name = isset($members[$usrId]) ? $members[$usrId]['LAST_NAME'] . ', ' . $members[$usrId]['FIRST_NAME'] : '#' . $usrId;
        if ($plan['waiting']) {
            $waiting[$usrId] = $name;
            continue;
        }
        $changes[$usrId] = array(
            'name' => $name,
            'LAST_NAME' => $members[$usrId]['LAST_NAME'] ?? '',
            'FIRST_NAME' => $members[$usrId]['FIRST_NAME'] ?? '',
            'fee_before' => $members[$usrId]['FEE' . $gCurrentOrgId] ?? '',
            'fee_after' => feeAfterPlan($positionsByUser[$usrId] ?? array(), $plan),
            'plan' => $plan
        );
    }

    // save plan in session (for mode write and mode print)
    $_SESSION['pMembershipFee']['recalculation_plan'] = $changes;

    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-preview', $headline);
    $page->setContentFullWidth();

    $page->addHtml('<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_YEAR_INFO', array(getContributionYear())) . '</p>');

    if (count($changes) > 0) {
        $table = new HtmlTable('table_new_recalculation', $page, true, true, 'table table-condensed');
        $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
        $table->setColumnAlignByArray(array('left', 'left', 'left', 'right', 'right', 'center'));
        $table->addRowHeadingByArray(array(
            $gL10n->get('SYS_LASTNAME'),
            $gL10n->get('SYS_FIRSTNAME'),
            $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_CHANGES'),
            $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW'),
            $gL10n->get('PLG_MEMBERSHIPFEE_FEE_PREVIOUS'),
            $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_HISTORY')
        ));

        foreach ($changes as $usrId => $data) {
            $user->readDataById($usrId);
            $profileUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid')));
            $historyUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/bookings.php', array('user_uuid' => $user->getValue('usr_uuid')));
            $table->addRowByArray(array(
                '<a href="' . $profileUrl . '">' . SecurityUtils::encodeHTML($data['LAST_NAME']) . '</a>',
                '<a href="' . $profileUrl . '">' . SecurityUtils::encodeHTML($data['FIRST_NAME']) . '</a>',
                describePlan($data['plan']),
                mf_format_amount($data['fee_after']),
                ((string) $data['fee_before'] === '' || $data['fee_before'] === null) ? '' : mf_format_amount(str_replace(',', '.', (string) $data['fee_before'])),
                '<a class="admidio-icon-link" href="' . $historyUrl . '"><i class="bi bi-clock-history" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_HISTORY') . '"></i></a>'
            ));
        }
        $page->addHtml($table->show(false));

        $form = new HtmlForm('recalculation_preview_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation.php', array(
            'mode' => 'write'
        )), $page);
        $form->addSubmitButton('btn_next_page', $gL10n->get('SYS_SAVE'), array(
            'icon' => 'bi-check-lg',
            'class' => 'btn btn-primary'
        ));
        $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_PREVIEW'));
        $page->addHtml($form->show(false));
    } else {
        $page->addHtml($gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_NO_DATA') . '<br/>');
    }

    if (count($waiting) > 0) {
        asort($waiting);
        $page->addHtml('<div class="alert alert-info mt-3" role="alert"><i class="bi bi-hourglass-split"></i> <strong>'
            . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_WAITING') . '</strong><br/>'
            . SecurityUtils::encodeHTML(implode('; ', $waiting)) . '</div>');
    }

    if (! empty($message)) {
        $page->addHtml($message);
    }
    $page->show();
} elseif ($getMode == 'write') {
    if (!isset($_SESSION['pMembershipFee']['recalculation_plan'])) {
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_NO_DATA'));
        // => EXIT
    }

    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-write', $headline);
    $page->setContentFullWidth();

    $page->addPageFunctionsMenuItem('menu_item_print_view', $gL10n->get('SYS_PRINT_PREVIEW'), 'javascript:void(0);', 'bi-printer');

    $page->addJavascript('
    	$("#menu_item_print_view").click(function() {
            window.open("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation.php', array(
        'mode' => 'print'
    )) . '", "_blank");
        });', true);

    $table = new HtmlTable('table_saved_recalculation', $page, true, true, 'table table-condensed');
    $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
    $table->setColumnAlignByArray(array('left', 'left', 'left', 'right'));
    $table->addRowHeadingByArray(array(
        $gL10n->get('SYS_LASTNAME'),
        $gL10n->get('SYS_FIRSTNAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_CHANGES'),
        $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW')
    ));

    $gDb->startTransaction();
    foreach ($_SESSION['pMembershipFee']['recalculation_plan'] as $usrId => $data) {
        $bookingService->applyOps((int) $usrId, $data['plan']['ops']);

        $user->readDataById((int) $usrId);
        $profileUrl = SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid')));
        $table->addRowByArray(array(
            '<a href="' . $profileUrl . '">' . SecurityUtils::encodeHTML($data['LAST_NAME']) . '</a>',
            '<a href="' . $profileUrl . '">' . SecurityUtils::encodeHTML($data['FIRST_NAME']) . '</a>',
            describePlan($data['plan']),
            mf_format_amount($data['fee_after'])
        ));
    }
    $gDb->endTransaction();

    $_SESSION['pMembershipFee']['recalculation_saved'] = $_SESSION['pMembershipFee']['recalculation_plan'];
    unset($_SESSION['pMembershipFee']['recalculation_plan']);

    $page->addHtml($table->show(false));
    $page->addHtml('<br/><strong>' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_SAVED') . '</strong><br/><br/>');
    $page->show();
} elseif ($getMode == 'print') {
    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-print', $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_NEW'));
    $page->setPrintMode();

    $table = new HtmlTable('table_print_recalculation', $page, false, false, 'table table-condensed table-striped');
    $table->setColumnAlignByArray(array('left', 'left', 'left', 'right'));
    $table->addRowHeadingByArray(array(
        $gL10n->get('SYS_LASTNAME'),
        $gL10n->get('SYS_FIRSTNAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_CHANGES'),
        $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW')
    ));

    $printData = $_SESSION['pMembershipFee']['recalculation_saved'] ?? $_SESSION['pMembershipFee']['recalculation_plan'] ?? array();
    foreach ($printData as $data) {
        $table->addRowByArray(array(
            SecurityUtils::encodeHTML($data['LAST_NAME']),
            SecurityUtils::encodeHTML($data['FIRST_NAME']),
            describePlan($data['plan']),
            mf_format_amount($data['fee_after'])
        ));
    }
    $page->addHtml($table->show(false));
    $page->show();
}
