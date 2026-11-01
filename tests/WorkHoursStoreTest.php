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

    public function testZeroHoursAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::validate(array('mfw_hours' => '0'));
    }

    public function testNegativeHoursAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkHoursStore::validate(array('mfw_hours' => '-3'));
    }
}
