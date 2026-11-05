<?php
namespace Plugins\MembershipFee\Tests;

use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Checks\OneTimeRoleCheck;

final class OneTimeRoleCheckTest extends TestCase
{
    private function membership(string $begin, string $end, int $usrId = 1, int $rolId = 10): array
    {
        return array('mem_id' => 100 + $usrId, 'usr_id' => $usrId, 'name' => 'Muster, Max' . $usrId, 'rol_id' => $rolId,
            'rol_name' => 'Erstbesatz Einmalig', 'begin' => $begin, 'end' => $end);
    }

    private function types(array $findings): array
    {
        return array_column($findings, 'type');
    }

    public function testCorrectMembershipYieldsNothing(): void
    {
        self::assertSame(array(), OneTimeRoleCheck::evaluate(array($this->membership('2026-01-01', '2026-12-31')), array()));
    }

    public function testEarlierEndIsFine(): void
    {
        self::assertSame(array(), OneTimeRoleCheck::evaluate(array($this->membership('2026-01-01', '2026-06-30')), array()));
    }

    public function testOpenEndIsReportedWithTargetAndLaterOpenPositions(): void
    {
        $findings = OneTimeRoleCheck::evaluate(
            array($this->membership('2025-01-01', '9999-12-31')),
            array(1 => array(10 => array(2025 => 'eingezogen', 2026 => 'offen')))
        );
        self::assertSame(array(OneTimeRoleCheck::END_LATE, OneTimeRoleCheck::YEARS), $this->types($findings));
        self::assertSame('2025-12-31', $findings[0]['target_end']);
        self::assertSame(array(2026), $findings[0]['later_open']);
        self::assertSame(array(2025, 2026), $findings[1]['years']);
    }

    public function testBeginNotFirstOfJanuaryIsHint(): void
    {
        $findings = OneTimeRoleCheck::evaluate(array($this->membership('2026-03-15', '2026-12-31')), array());
        self::assertSame(array(OneTimeRoleCheck::BEGIN), $this->types($findings));
    }

    public function testPositionsInSeveralYearsWithoutMembershipUseNameMaps(): void
    {
        $findings = OneTimeRoleCheck::evaluate(array(), array(7 => array(12 => array(2019 => 'eingezogen', 2020 => 'eingezogen'))),
            array(7 => 'Huber, Hans'), array(12 => 'Aufnahmegebühr'));
        self::assertCount(1, $findings);
        self::assertSame('Huber, Hans', $findings[0]['name']);
        self::assertSame('Aufnahmegebühr', $findings[0]['rol_name']);
        self::assertNull($findings[0]['mem_id']);
    }

    public function testSinglePositionYearIsFine(): void
    {
        self::assertSame(array(), OneTimeRoleCheck::evaluate(array(), array(1 => array(10 => array(2026 => 'offen')))));
    }

    public function testFixedEnd(): void
    {
        self::assertSame('2025-12-31', OneTimeRoleCheck::fixedEnd('2025-01-01', '9999-12-31'));
        self::assertSame('2025-12-31', OneTimeRoleCheck::fixedEnd('2025-04-01', '2026-03-31'));
        self::assertNull(OneTimeRoleCheck::fixedEnd('2025-01-01', '2025-12-31'));
        self::assertNull(OneTimeRoleCheck::fixedEnd('2025-01-01', '2025-05-31'));
    }
}
