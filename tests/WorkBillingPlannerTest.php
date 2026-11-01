<?php
namespace Plugins\MembershipFee\Tests;

use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Work\WorkBillingPlanner;
use Plugins\MembershipFee\classes\Work\WorkRules;

final class WorkBillingPlannerTest extends TestCase
{
    private function rules(): WorkRules
    {
        return new WorkRules(array(5 => 10.0), array(), array(), 60, 20.0);
    }

    /** @return array<string, mixed> */
    private function position(int $id, string $status, float $amount, int $year = 2026, string $source = 'ad', string $label = WorkBillingPlanner::LABEL): array
    {
        return array('mfb_id' => $id, 'mfb_source' => $source, 'mfb_year' => $year, 'mfb_rol_name' => $label,
            'mfb_status' => $status, 'mfb_amount' => $amount, 'mfb_text' => 'alt');
    }

    public function testNewPositionForMissingHours(): void
    {
        $plan = WorkBillingPlanner::plan(2026, 10.0, 6.0, $this->rules(), array());

        self::assertSame(WorkBillingPlanner::STATE_NEW, $plan['state']);
        self::assertSame(80.0, $plan['amount']);
        self::assertSame('Ersatzleistung Arbeitsdienst 2026: Soll 10 h, Ist 6 h, 4 h x 20,00 EUR', $plan['text']);
        self::assertCount(1, $plan['ops']);
        self::assertSame('insert', $plan['ops'][0]['op']);
        self::assertSame('ad', $plan['ops'][0]['data']['mfb_source']);
        self::assertSame(2026, $plan['ops'][0]['data']['mfb_year']);
        self::assertSame('offen', $plan['ops'][0]['data']['mfb_status']);
    }

    public function testFulfilledOrExemptGivesNothing(): void
    {
        self::assertSame(array(), WorkBillingPlanner::plan(2026, 10.0, 12.0, $this->rules(), array())['ops']);
        self::assertSame(array(), WorkBillingPlanner::plan(2026, 0.0, 0.0, $this->rules(), array())['ops']);
        self::assertSame(WorkBillingPlanner::STATE_NONE, WorkBillingPlanner::plan(2026, null, 5.0, $this->rules(), array())['state']);
    }

    public function testOpenPositionIsUpdatedOrKept(): void
    {
        $changed = WorkBillingPlanner::plan(2026, 10.0, 7.5, $this->rules(), array($this->position(9, 'offen', 80.0)));
        self::assertSame(WorkBillingPlanner::STATE_CHANGED, $changed['state']);
        self::assertSame('update', $changed['ops'][0]['op']);
        self::assertSame(50.0, $changed['ops'][0]['data']['mfb_amount']);

        $text = WorkBillingPlanner::text(2026, 10.0, 7.5, 20.0);
        $same = $this->position(9, 'offen', 50.0);
        $same['mfb_text'] = $text;
        $unchanged = WorkBillingPlanner::plan(2026, 10.0, 7.5, $this->rules(), array($same));
        self::assertSame(WorkBillingPlanner::STATE_UNCHANGED, $unchanged['state']);
        self::assertSame(array(), $unchanged['ops']);
    }

    public function testOpenPositionIsRemovedWhenHoursAreDone(): void
    {
        $plan = WorkBillingPlanner::plan(2026, 10.0, 10.0, $this->rules(), array($this->position(9, 'offen', 80.0)));

        self::assertSame(WorkBillingPlanner::STATE_REMOVED, $plan['state']);
        self::assertSame(array(array('op' => 'delete', 'id' => 9, 'before' => $this->position(9, 'offen', 80.0))), $plan['ops']);
    }

    public function testBilledYearIsNotChanged(): void
    {
        foreach (array('exportiert', 'eingezogen') as $status) {
            $plan = WorkBillingPlanner::plan(2026, 10.0, 10.0, $this->rules(), array($this->position(9, $status, 80.0)));
            self::assertSame(WorkBillingPlanner::STATE_BILLED, $plan['state']);
            self::assertSame(80.0, $plan['amount']);
            self::assertSame(array(), $plan['ops']);
        }
    }

    public function testOtherYearsSourcesAndLabelsAreIgnored(): void
    {
        $positions = array(
            $this->position(1, 'eingezogen', 80.0, 2025),
            $this->position(2, 'offen', 310.0, 2026, 'rolle', 'Aktivbeitrag'),
            $this->position(3, 'offen', -20.0, 2026, 'ad_manuell', 'Gutschrift Arbeitsdienst'),
        );
        $plan = WorkBillingPlanner::plan(2026, 10.0, 6.0, $this->rules(), $positions);

        self::assertSame(WorkBillingPlanner::STATE_NEW, $plan['state']);
        self::assertCount(1, $plan['ops']);
    }

    public function testDuplicateOpenPositionsAreReduced(): void
    {
        $plan = WorkBillingPlanner::plan(2026, 10.0, 6.0, $this->rules(), array($this->position(7, 'offen', 80.0), $this->position(8, 'offen', 80.0)));

        self::assertSame(array('update', 'delete'), array_column($plan['ops'], 'op'));
        self::assertSame(8, $plan['ops'][1]['id']);
    }
}
