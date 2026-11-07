<?php
/**
 ***********************************************************************************************
 * Jahressperre im Arbeitsdienst
 *
 * Ein Leistungsjahr ist gesperrt, sobald eine Arbeitsdienst-Position dieses Jahres exportiert oder
 * eingezogen ist. Danach duerfen nur noch Mitglieder der Zugriffsrolle in Admidio Stunden aendern;
 * die Schnittstelle (WorkHoursApi) aendert gesperrte Jahre nie.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;

final class WorkYearLock
{
    public static function isLocked(int $year): bool
    {
        global $gDb, $gCurrentOrgId;

        BookingStore::ensureTables();
        $scope = CollectionScope::work((int) $gCurrentOrgId);
        $sql = 'SELECT COUNT(*) FROM ' . BookingStore::bookingsTable() . '
                 WHERE mfb_org_id = ? AND mfb_year = ? AND ' . $scope->sourceSqlCondition() . '
                   AND mfb_status IN (?, ?)';
        return (int) $gDb->queryPrepared($sql, array((int) $gCurrentOrgId, $year, 'exportiert', 'eingezogen'))->fetchColumn() > 0;
    }
}
