<?php
/**
 ***********************************************************************************************
 * Darstellung der Buchungshistorie eines Mitglieds (Historienseite und Registerkarte im Profil)
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Bookings\BookingStore;

if (basename($_SERVER['SCRIPT_FILENAME']) === 'bookings_function.php') {
    exit('This page may not be called directly!');
}

require_once (__DIR__ . '/common_function.php');

/**
 * Sortiert Positionen: noch nicht eingezogene zuerst, danach nach Datum absteigend.
 *
 * @param array<int, array<string, mixed>> $positions
 * @return array<int, array<string, mixed>>
 */
function mf_sort_bookings(array $positions): array
{
    $rank = array(
        BookingStore::STATUS_OPEN => 0,
        BookingStore::STATUS_EXPORTED => 1,
        BookingStore::STATUS_COLLECTED => 2,
        BookingStore::STATUS_CANCELLED => 2
    );
    usort($positions, static function (array $a, array $b) use ($rank): int {
        $ra = $rank[$a['mfb_status']] ?? 3;
        $rb = $rank[$b['mfb_status']] ?? 3;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $da = (string) ($a['mfb_booking_date'] ?: $a['mfb_due_date'] ?: $a['mfb_storno_date'] ?: '');
        $db = (string) ($b['mfb_booking_date'] ?: $b['mfb_due_date'] ?: $b['mfb_storno_date'] ?: '');
        if ($da !== $db) {
            return strcmp($db, $da);
        }
        return $b['mfb_id'] <=> $a['mfb_id'];
    });
    return $positions;
}

/**
 * Liefert die HTML-Tabelle der Buchungshistorie eines Mitglieds.
 *
 * @param int $usrId
 * @param string $userUuid
 * @param bool $withActions Aktionen (Stornieren, Bearbeiten ...) anzeigen
 * @return string
 */
function mf_render_bookings(int $usrId, string $userUuid, bool $withActions = true): string
{
    global $gL10n;

    $positions = mf_sort_bookings(BookingStore::getByUser($usrId));
    $editUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/booking_edit.php';
    $runsUrl = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/runs.php';

    $html = '';
    if ($withActions) {
        $html .= '<p><a class="btn btn-secondary" href="' . SecurityUtils::encodeUrl($editUrl, array('mode' => 'add', 'user_uuid' => $userUuid)) . '">'
            . '<i class="bi bi-plus-circle"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ADD') . '</a></p>';
    }

    if (count($positions) === 0) {
        return $html . '<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_NONE') . '</p>';
    }

    $runs = array();
    $sums = array(BookingStore::STATUS_OPEN => 0.0, BookingStore::STATUS_EXPORTED => 0.0, BookingStore::STATUS_COLLECTED => 0.0, BookingStore::STATUS_CANCELLED => 0.0);

    $html .= '<div class="table-responsive"><table class="table table-condensed table-hover">
        <thead><tr>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_DATE') . '</th>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE') . '</th>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_METHOD') . '</th>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_SEPA_FILE') . '</th>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ROLE') . '</th>
            <th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_AMOUNT') . '</th>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_TEXT') . '</th>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REFERENCE') . '</th>
            <th>' . $gL10n->get('PLG_MEMBERSHIPFEE_STATUS') . '</th>'
            . ($withActions ? '<th></th>' : '') . '
        </tr></thead><tbody>';

    $lastSection = null;
    foreach ($positions as $position) {
        $status = $position['mfb_status'];
        $sums[$status] += (float) $position['mfb_amount'];

        $section = in_array($status, array(BookingStore::STATUS_OPEN, BookingStore::STATUS_EXPORTED), true) ? 'pending' : 'done';
        if ($section !== $lastSection) {
            $html .= '<tr class="table-light"><td colspan="' . ($withActions ? 10 : 9) . '"><strong>'
                . $gL10n->get($section === 'pending' ? 'PLG_MEMBERSHIPFEE_BOOKING_PENDING' : 'PLG_MEMBERSHIPFEE_BOOKING_DONE') . '</strong></td></tr>';
            $lastSection = $section;
        }

        $fileName = '';
        if ($position['mfb_run_id'] !== null) {
            if (!array_key_exists($position['mfb_run_id'], $runs)) {
                $runs[$position['mfb_run_id']] = BookingStore::findRun((int) $position['mfb_run_id']);
            }
            if ($runs[$position['mfb_run_id']] !== null) {
                $fileName = SecurityUtils::encodeHTML((string) $runs[$position['mfb_run_id']]['mfr_file_name']);
                if ($withActions) {
                    $fileName = '<a href="' . SecurityUtils::encodeUrl($runsUrl, array('run_id' => $position['mfb_run_id'])) . '">' . $fileName . '</a>';
                }
            }
        }

        $statusText = mf_booking_label($status);
        if ($status === BookingStore::STATUS_CANCELLED) {
            $statusText .= ' ' . mf_format_date($position['mfb_storno_date']);
            if ((string) $position['mfb_storno_reason'] !== '') {
                $statusText .= ' (' . SecurityUtils::encodeHTML((string) $position['mfb_storno_reason']) . ')';
            }
            if (!empty($position['mfb_role_revoked'])) {
                $statusText .= '<br /><small>' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_ROLE_REVOKED') . '</small>';
            }
        }
        $comment = trim((string) $position['mfb_comment']);
        $roleText = SecurityUtils::encodeHTML((string) $position['mfb_rol_name']);
        if ($position['mfb_source'] === BookingStore::SOURCE_MANUAL) {
            $roleText .= ' <small class="text-muted">(' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_MANUAL') . ')</small>';
        }
        if ($comment !== '') {
            $roleText .= ' <i class="bi bi-chat-left-text" title="' . SecurityUtils::encodeHTML($comment) . '"></i>';
        }

        $style = ($status === BookingStore::STATUS_CANCELLED) ? ' style="text-decoration: line-through;"' : '';
        $html .= '<tr>
            <td>' . mf_format_date($position['mfb_booking_date']) . '</td>
            <td>' . mf_format_date($position['mfb_due_date']) . '</td>
            <td>' . SecurityUtils::encodeHTML((string) $position['mfb_method']) . '</td>
            <td>' . $fileName . '</td>
            <td>' . $roleText . '<br /><small class="text-muted">' . (int) $position['mfb_year'] . '</small></td>
            <td style="text-align: right; white-space: nowrap;"><span' . $style . '>' . mf_format_amount($position['mfb_amount']) . '</span></td>
            <td><span' . $style . '>' . SecurityUtils::encodeHTML((string) $position['mfb_text']) . '</span></td>
            <td>' . SecurityUtils::encodeHTML((string) $position['mfb_reference']) . '</td>
            <td>' . $statusText . '</td>';

        if ($withActions) {
            $actions = array();
            if ($status === BookingStore::STATUS_OPEN && $position['mfb_source'] === BookingStore::SOURCE_MANUAL) {
                $actions[] = '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl($editUrl, array('mode' => 'edit', 'mfb_id' => $position['mfb_id'])) . '"><i class="bi bi-pencil-square" title="' . $gL10n->get('SYS_EDIT') . '"></i></a>';
                $actions[] = '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl($editUrl, array('mode' => 'delete', 'mfb_id' => $position['mfb_id'])) . '"><i class="bi bi-trash" title="' . $gL10n->get('SYS_DELETE') . '"></i></a>';
            }
            if ($status === BookingStore::STATUS_COLLECTED) {
                $actions[] = '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl($editUrl, array('mode' => 'reference', 'mfb_id' => $position['mfb_id'])) . '"><i class="bi bi-tag" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_REFERENCE_EDIT') . '"></i></a>';
            }
            if ($status !== BookingStore::STATUS_CANCELLED && !($status === BookingStore::STATUS_OPEN && $position['mfb_source'] === BookingStore::SOURCE_MANUAL)) {
                $actions[] = '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl($editUrl, array('mode' => 'cancel', 'mfb_id' => $position['mfb_id'])) . '"><i class="bi bi-x-octagon" title="' . $gL10n->get('PLG_MEMBERSHIPFEE_BOOKING_CANCEL') . '"></i></a>';
            }
            $html .= '<td style="white-space: nowrap;">' . implode(' ', $actions) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    $html .= '<p class="small">'
        . mf_booking_label(BookingStore::STATUS_OPEN) . ': <strong>' . mf_format_amount($sums[BookingStore::STATUS_OPEN]) . '</strong> &middot; '
        . mf_booking_label(BookingStore::STATUS_EXPORTED) . ': <strong>' . mf_format_amount($sums[BookingStore::STATUS_EXPORTED]) . '</strong> &middot; '
        . mf_booking_label(BookingStore::STATUS_COLLECTED) . ': <strong>' . mf_format_amount($sums[BookingStore::STATUS_COLLECTED]) . '</strong> &middot; '
        . mf_booking_label(BookingStore::STATUS_CANCELLED) . ': ' . mf_format_amount($sums[BookingStore::STATUS_CANCELLED])
        . '</p>';

    return $html;
}

/**
 * Abschnitt "Arbeitsdienst" fuer Profil-Registerkarte und Buchungshistorie: aktuelles Soll, je Leistungsjahr
 * geleistete Stunden, Ersatzbeitrag (Position im Buchungskreis Arbeitsdienst) und die einzelnen Einsaetze.
 */
function mf_render_work(int $usrId): string
{
    global $gL10n, $gProfileFields, $gDb, $gCurrentOrgId;

    $orgId = (int) $gCurrentOrgId;
    \Plugins\MembershipFee\classes\Work\WorkHoursStore::ensureTable();
    $entries = \Plugins\MembershipFee\classes\Work\WorkHoursStore::getByUser($usrId);
    $positions = \Plugins\MembershipFee\classes\Bookings\CollectionScope::work($orgId)->filterPositions(BookingStore::getByUser($usrId));

    // aktuelles Soll aus dem Profilfeld
    $target = '';
    $targetUsfId = (int) $gProfileFields->getProperty(\Plugins\MembershipFee\classes\Work\WorkProfileFields::targetField($orgId), 'usf_id');
    if ($targetUsfId > 0) {
        $target = (string) $gDb->queryPrepared('SELECT usd_value FROM ' . TBL_USER_DATA . ' WHERE usd_usr_id = ? AND usd_usf_id = ?', array($usrId, $targetUsfId))->fetchColumn();
    }
    if (count($entries) === 0 && count($positions) === 0 && $target === '') {
        return '';
    }

    // je Jahr: Stunden, Einsaetze, Ersatzbeitrag
    $years = array();
    foreach ($entries as $entry) {
        $year = (int) $entry['mfw_year'];
        $years[$year]['hours'] = ($years[$year]['hours'] ?? 0.0) + (float) $entry['mfw_hours'];
        $years[$year]['entries'][] = $entry;
    }
    foreach ($positions as $position) {
        if ($position['mfb_status'] === BookingStore::STATUS_CANCELLED) {
            continue;
        }
        $year = (int) $position['mfb_year'];
        $years[$year]['positions'][] = $position;
    }
    $currentYear = (int) date('Y');
    if (!isset($years[$currentYear]) && $target !== '') {
        $years[$currentYear] = array();
    }
    krsort($years);

    $html = '<h5 class="mt-4"><i class="bi bi-tools"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_SETTINGS') . '</h5>';
    if ($target !== '') {
        $html .= '<p>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_PROFILE_TARGET', array(
            $currentYear,
            \Plugins\MembershipFee\classes\Work\WorkRules::formatHours((float) str_replace(',', '.', $target))
        )) . '</p>';
    }
    $html .= '<div class="table-responsive"><table class="table table-condensed"><thead><tr>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_YEAR') . '</th>'
        . '<th style="text-align: right;">' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_DONE') . '</th>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_COL_FEE') . '</th>'
        . '<th>' . $gL10n->get('PLG_MEMBERSHIPFEE_WORK_ENTRIES_SHORT') . '</th>'
        . '</tr></thead><tbody>';
    foreach ($years as $year => $data) {
        $fees = array();
        foreach ($data['positions'] ?? array() as $position) {
            $fees[] = mf_format_amount($position['mfb_amount']) . ' <small class="text-muted">(' . mf_booking_label((string) $position['mfb_status']) . ')</small>';
        }
        $list = '';
        if (count($data['entries'] ?? array()) > 0) {
            $items = array();
            foreach (array_reverse($data['entries']) as $entry) {
                $items[] = '<li>' . mf_format_date((string) $entry['mfw_date']) . ' &middot; ' . SecurityUtils::encodeHTML((string) $entry['mfw_activity'])
                    . ' &middot; ' . \Plugins\MembershipFee\classes\Work\WorkRules::formatHours((float) $entry['mfw_hours']) . '</li>';
            }
            $list = '<details><summary>' . $gL10n->get(count($data['entries']) === 1 ? 'PLG_MEMBERSHIPFEE_WORK_PROFILE_ENTRY_ONE' : 'PLG_MEMBERSHIPFEE_WORK_PROFILE_ENTRIES_MANY', array(count($data['entries']))) . '</summary><ul class="mb-0">' . implode('', $items) . '</ul></details>';
        }
        $html .= '<tr><td>' . (int) $year . '</td>'
            . '<td style="text-align: right;">' . \Plugins\MembershipFee\classes\Work\WorkRules::formatHours((float) ($data['hours'] ?? 0.0)) . '</td>'
            . '<td>' . (count($fees) > 0 ? implode('<br/>', $fees) : '&ndash;') . '</td>'
            . '<td>' . ($list !== '' ? $list : '&ndash;') . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}
