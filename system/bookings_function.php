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
