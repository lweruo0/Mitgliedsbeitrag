<?php
namespace Plugins\MembershipFee\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Work\WorkHoursStore;

final class WorkHoursStoreTest extends TestCase
{
    public function testValidateNormalizesHoursAndActivity(): void
    {
        $data = WorkHoursStore::validate(array('mfw_date' => '2026-04-18', 'mfw_hours' => '2,5', 'mfw_activity' => '  Gewässerputzete  '));

        self::assertSame(2.5, $data['mfw_hours']);
        self::assertSame('Gewässerputzete', $data['mfw_activity']);
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::validate(array('mfw_date' => '2026-02-30'));
    }

    public function testZeroHoursAreRejectedForAttended(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::applyStatusRules(WorkHoursStore::validate(array('mfw_hours' => '0')));
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::validate(array('mfw_status' => 'krank'));
    }

    public function testStatusChangeToExcusedSetsZeroHours(): void
    {
        $existing = array('mfw_status' => 'teilgenommen', 'mfw_hours' => 4.0);

        self::assertSame(array('mfw_status' => 'entschuldigt', 'mfw_hours' => 0.0), WorkHoursStore::applyStatusRules(array('mfw_status' => 'entschuldigt'), $existing));
    }

    public function testStatusChangeToAttendedNeedsHours(): void
    {
        $existing = array('mfw_status' => 'angemeldet', 'mfw_hours' => 0.0);

        self::assertSame(array('mfw_status' => 'teilgenommen', 'mfw_hours' => 4.0), WorkHoursStore::applyStatusRules(array('mfw_status' => 'teilgenommen', 'mfw_hours' => 4.0), $existing));
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::applyStatusRules(array('mfw_status' => 'teilgenommen'), $existing);
    }

    public function testHoursOnRegisteredEntryAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::applyStatusRules(array('mfw_hours' => 3.0), array('mfw_status' => 'angemeldet', 'mfw_hours' => 0.0));
    }

    public function testOnlyAttendedHoursCount(): void
    {
        self::assertSame(2.5, WorkHoursStore::doneHours(array('mfw_hours' => 2.5)));
        self::assertSame(2.5, WorkHoursStore::doneHours(array('mfw_hours' => 2.5, 'mfw_status' => 'teilgenommen')));
        self::assertSame(0.0, WorkHoursStore::doneHours(array('mfw_hours' => 2.5, 'mfw_status' => 'entschuldigt')));
    }

    public function testNegativeHoursAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::validate(array('mfw_hours' => '-3'));
    }
}
