<?php
namespace Plugins\MembershipFee\Tests;

use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Work\WorkApiException;
use Plugins\MembershipFee\classes\Work\WorkHoursApi;

final class WorkHoursApiTest extends TestCase
{
    public function testNewEntryIsNormalized(): void
    {
        $input = WorkHoursApi::normalizeInput(array('date' => '2026-04-18', 'hours' => '2,5', 'activity' => ' Gewässerputzete ', 'comment' => ' Ufer '));

        self::assertSame(array('mfw_date' => '2026-04-18', 'mfw_hours' => 2.5, 'mfw_activity' => 'Gewässerputzete', 'mfw_comment' => 'Ufer'), $input);
    }

    public function testLegacyNameIsAcceptedAsActivity(): void
    {
        $input = WorkHoursApi::normalizeInput(array('date' => '2026-04-18', 'hours' => 3, 'name' => 'Hüttendienst'));

        self::assertSame('Hüttendienst', $input['mfw_activity']);
        self::assertSame(3.0, $input['mfw_hours']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidNewEntries(): array
    {
        return array(
            'ohne Datum' => array(array('hours' => 2, 'activity' => 'X')),
            'ohne Stunden' => array(array('date' => '2026-04-18', 'activity' => 'X')),
            'ohne Einsatz' => array(array('date' => '2026-04-18', 'hours' => 2)),
            'leerer Einsatz' => array(array('date' => '2026-04-18', 'hours' => 2, 'activity' => '   ')),
            'ungueltiges Datum' => array(array('date' => '18.04.2026', 'hours' => 2, 'activity' => 'X')),
            'null Stunden' => array(array('date' => '2026-04-18', 'hours' => 0, 'activity' => 'X')),
            'Stunden kein Wert' => array(array('date' => '2026-04-18', 'hours' => 'viel', 'activity' => 'X')),
            'Array als Wert' => array(array('date' => '2026-04-18', 'hours' => array(2), 'activity' => 'X')),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @dataProvider invalidNewEntries
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNewEntries')]
    public function testInvalidNewEntryIsRejected(array $data): void
    {
        try {
            WorkHoursApi::normalizeInput($data);
            self::fail('WorkApiException erwartet');
        } catch (WorkApiException $e) {
            self::assertSame('validation_failed', $e->getErrorCode());
            self::assertSame(422, $e->getHttpStatus());
        }
    }

    public function testPartialUpdateOnlyContainsGivenFields(): void
    {
        self::assertSame(array('mfw_hours' => 4.0), WorkHoursApi::normalizeInput(array('id' => 5, 'hours' => '4'), true));
        self::assertSame(array(), WorkHoursApi::normalizeInput(array('id' => 5), true));
    }

    public function testPartialUpdateRejectsEmptyActivity(): void
    {
        $this->expectException(WorkApiException::class);
        WorkHoursApi::normalizeInput(array('activity' => ''), true);
    }

    public function testEntryId(): void
    {
        self::assertNull(WorkHoursApi::entryId(array()));
        self::assertNull(WorkHoursApi::entryId(array('id' => 0)));
        self::assertNull(WorkHoursApi::entryId(array('id' => '')));
        self::assertSame(12, WorkHoursApi::entryId(array('id' => '12')));
        self::assertSame(12, WorkHoursApi::entryId(array('id' => 12)));

        $this->expectException(WorkApiException::class);
        WorkHoursApi::entryId(array('id' => 'abc'));
    }

    public function testDuplicateMatchesDateActivityAndHours(): void
    {
        $entries = array(
            array('mfw_id' => 1, 'mfw_date' => '2026-04-18', 'mfw_activity' => 'Gewässerputzete', 'mfw_hours' => 2.5),
            array('mfw_id' => 2, 'mfw_date' => '2026-05-02', 'mfw_activity' => 'Hüttendienst', 'mfw_hours' => 3.0),
        );

        $found = WorkHoursApi::findDuplicate($entries, array('mfw_date' => '2026-04-18', 'mfw_activity' => 'gewässerputzete', 'mfw_hours' => 2.5));
        self::assertNotNull($found);
        self::assertSame(1, $found['mfw_id']);
        self::assertNull(WorkHoursApi::findDuplicate($entries, array('mfw_date' => '2026-04-18', 'mfw_activity' => 'Gewässerputzete', 'mfw_hours' => 3.0)));
        self::assertNull(WorkHoursApi::findDuplicate($entries, array('mfw_date' => '2026-04-19', 'mfw_activity' => 'Gewässerputzete', 'mfw_hours' => 2.5)));
    }

    public function testYearsIncludeCurrentYearNewestFirst(): void
    {
        $entries = array(array('mfw_year' => 2024), array('mfw_year' => 2025), array('mfw_year' => 2024));

        self::assertSame(array(2026, 2025, 2024), WorkHoursApi::yearsOf($entries, 2026));
        self::assertSame(array(2026), WorkHoursApi::yearsOf(array(), 2026));
    }

    public function testSummarizePerYear(): void
    {
        $entries = array(
            array('mfw_id' => 3, 'mfw_year' => 2026, 'mfw_date' => '2026-06-01', 'mfw_hours' => 4.0, 'mfw_activity' => 'B', 'mfw_comment' => null),
            array('mfw_id' => 1, 'mfw_year' => 2026, 'mfw_date' => '2026-04-18', 'mfw_hours' => 2.5, 'mfw_activity' => 'A', 'mfw_comment' => ''),
            array('mfw_id' => 2, 'mfw_year' => 2025, 'mfw_date' => '2025-04-18', 'mfw_hours' => 12.0, 'mfw_activity' => 'C', 'mfw_comment' => 'x'),
        );

        $years = WorkHoursApi::summarize($entries, array(2026, 2025, 2024), 10.0, array(2025 => true));

        self::assertCount(3, $years);
        self::assertSame(2026, $years[0]['year']);
        self::assertSame(6.5, $years[0]['done_hours']);
        self::assertSame(3.5, $years[0]['missing_hours']);
        self::assertFalse($years[0]['locked']);
        self::assertSame(array(1, 3), array_column($years[0]['entries'], 'id'));
        self::assertNull($years[0]['entries'][0]['comment']);

        self::assertSame(0.0, $years[1]['missing_hours']);
        self::assertTrue($years[1]['locked']);
        self::assertSame('x', $years[1]['entries'][0]['comment']);

        self::assertSame(0.0, $years[2]['done_hours']);
        self::assertSame(array(), $years[2]['entries']);
    }

    public function testSummarizeWithoutTarget(): void
    {
        $years = WorkHoursApi::summarize(array(), array(2026), null, array());

        self::assertNull($years[0]['missing_hours']);
    }

    public function testPublicEntryHidesInternalColumns(): void
    {
        $entry = WorkHoursApi::publicEntry(array(
            'mfw_id' => 7, 'mfw_org_id' => 1, 'mfw_usr_id' => 42, 'mfw_year' => 2026, 'mfw_date' => '2026-04-18',
            'mfw_hours' => 2.5, 'mfw_activity' => 'A', 'mfw_comment' => 'c', 'mfw_usr_id_create' => 1,
            'mfw_timestamp_create' => '2026-04-18 10:00:00', 'mfw_timestamp_change' => null
        ));

        self::assertSame(array('id', 'date', 'year', 'hours', 'activity', 'comment', 'created_at', 'changed_at'), array_keys($entry));
        self::assertSame('2026-04-18 10:00:00', $entry['created_at']);
        self::assertNull($entry['changed_at']);
    }

    public function testYearBounds(): void
    {
        WorkHoursApi::assertYear(2026);
        $this->expectException(WorkApiException::class);
        WorkHoursApi::assertYear(1999);
    }
}
