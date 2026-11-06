<?php
namespace Plugins\MembershipFee\Tests;

use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Invoices\InvoiceNumber;
use Plugins\MembershipFee\classes\Invoices\InvoicePlanner;
use Plugins\MembershipFee\classes\Invoices\InvoiceStatus;

final class InvoiceTest extends TestCase
{
    private function position(int $id, int $year, float $amount, string $status = 'offen', ?string $nr = null): array
    {
        return array('mfb_id' => $id, 'mfb_year' => $year, 'mfb_amount' => $amount, 'mfb_status' => $status, 'mfb_invoice_no' => $nr);
    }

    public function testNumberFormatAndParse(): void
    {
        self::assertSame('2026-B-0012', InvoiceNumber::format(2026, 'mf', 12));
        self::assertSame('2025-AD-0003', InvoiceNumber::format(2025, 'ad', 3));
        self::assertSame('2026-B-12345', InvoiceNumber::format(2026, 'mf', 12345));
        self::assertSame(array('year' => 2025, 'kind' => 'ad', 'seq' => 3), InvoiceNumber::parse('2025-AD-0003'));
        self::assertNull(InvoiceNumber::parse('2025-X-0003'));
        self::assertTrue(InvoiceNumber::isValid('2026-AD-0001'));
        self::assertFalse(InvoiceNumber::isValid('2026-AD-001'));
        self::assertFalse(InvoiceNumber::isValid('../2026-B-0001'));
    }

    public function testFileNames(): void
    {
        self::assertSame('rechnung_2026-AD-0001.pdf', InvoiceNumber::fileName('rechnung', '2026-AD-0001'));
        self::assertSame('rechnung_2026-B-0001_storniert.pdf', InvoiceNumber::fileName('rechnung', '2026-B-0001', true));
        self::assertTrue(InvoiceNumber::isValidFileName('mahnung_2026-AD-0001.pdf'));
        self::assertFalse(InvoiceNumber::isValidFileName('mahnung_2026-AD-0001.pdf.php'));
        $this->expectException(\InvalidArgumentException::class);
        InvoiceNumber::fileName('../x', '2026-B-0001');
    }

    public function testUnknownKindIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvoiceNumber::format(2026, 'xx', 1);
    }

    public function testStatusFromCounts(): void
    {
        self::assertSame(InvoiceStatus::OPEN, InvoiceStatus::fromCounts(1, 0, 0, 0));
        self::assertSame(InvoiceStatus::OPEN, InvoiceStatus::fromCounts(1, 1, 1, 1));
        self::assertSame(InvoiceStatus::IN_COLLECTION, InvoiceStatus::fromCounts(0, 2, 1, 0));
        self::assertSame(InvoiceStatus::PAID, InvoiceStatus::fromCounts(0, 0, 2, 1));
        self::assertSame(InvoiceStatus::CANCELLED, InvoiceStatus::fromCounts(0, 0, 0, 3));
    }

    public function testStatusFromPositionsAndOpenAmount(): void
    {
        $positions = array($this->position(1, 2026, 310.0, 'storniert'), $this->position(2, 2026, 310.0), $this->position(3, 2026, 5.5));
        self::assertSame(InvoiceStatus::OPEN, InvoiceStatus::fromPositions($positions));
        self::assertSame(315.5, InvoiceStatus::openAmount($positions));
    }

    public function testOverdue(): void
    {
        self::assertTrue(InvoiceStatus::isOverdue('offen', '2026-11-01', null, '2026-11-05'));
        self::assertFalse(InvoiceStatus::isOverdue('offen', '2026-11-05', null, '2026-11-05'));
        self::assertFalse(InvoiceStatus::isOverdue('offen', '2026-11-01', '2026-11-03', '2026-11-05'));
        self::assertFalse(InvoiceStatus::isOverdue('im_einzug', '2026-11-01', null, '2026-11-05'));
        self::assertFalse(InvoiceStatus::isOverdue('offen', null, null, '2026-11-05'));
    }

    public function testPlanGroupsOpenPositionsWithoutNumberByYear(): void
    {
        $plan = InvoicePlanner::plan(array(
            $this->position(1, 2026, 310.0),
            $this->position(2, 2026, 20.0),
            $this->position(3, 2025, 15.0),
            $this->position(4, 2026, 99.0, 'exportiert'),
            $this->position(5, 2026, 50.0, 'offen', '2026-B-0001'),
            $this->position(6, 2026, 10.0, 'storniert'),
        ));
        self::assertSame(array(
            array('year' => 2025, 'position_ids' => array(3), 'amount' => 15.0, 'error' => ''),
            array('year' => 2026, 'position_ids' => array(1, 2), 'amount' => 330.0, 'error' => ''),
        ), $plan);
    }

    public function testPlanReportsNonPositiveSum(): void
    {
        $plan = InvoicePlanner::plan(array($this->position(1, 2026, 10.0), $this->position(2, 2026, -10.0)));
        self::assertSame(InvoicePlanner::ERROR_AMOUNT, $plan[0]['error']);
        self::assertSame(array(), InvoicePlanner::plan(array()));
    }

    public function testMethodAndDueDate(): void
    {
        self::assertSame('SEPA', InvoicePlanner::method('DE02 1203 0000 0000 2020 51', 'BFV-0012'));
        self::assertSame('MAN', InvoicePlanner::method('DE02120300000000202051', ''));
        self::assertSame('MAN', InvoicePlanner::method('', 'BFV-0012'));

        self::assertSame('2026-12-01', InvoicePlanner::dueDate('SEPA', '2026-12-01', '2026-11-05', 14));
        self::assertNull(InvoicePlanner::dueDate('SEPA', '', '2026-11-05', 14));
        self::assertSame('2026-12-01', InvoicePlanner::dueDate('MAN', '2026-12-01', '2026-11-05', 14));
        self::assertSame('2026-11-19', InvoicePlanner::dueDate('MAN', '', '2026-11-05', 14));
        self::assertSame('2027-01-08', InvoicePlanner::dueDate('MAN', '', '2026-12-25', 14));
    }

    public function testEffectiveMethod(): void
    {
        // Verschieben nach Ueberweisung gilt immer; zurueck nach Lastschrift nur mit Mandat (sonst bleibt MAN)
        self::assertSame('SEPA', InvoicePlanner::effectiveMethod('SEPA', null));
        self::assertSame('MAN', InvoicePlanner::effectiveMethod('SEPA', 'MAN'));
        self::assertSame('MAN', InvoicePlanner::effectiveMethod('MAN', null));
        self::assertSame('MAN', InvoicePlanner::effectiveMethod('MAN', 'SEPA'));
        self::assertSame('MAN', InvoicePlanner::effectiveMethod('MAN', 'MAN'));
    }

    public function testMaskIban(): void
    {
        self::assertSame('DE02xxxxxxxxxxxxxx2051', InvoicePlanner::maskIban('DE02 1203 0000 0000 2020 51'));
        self::assertSame('DE0', InvoicePlanner::maskIban('de0'));
    }
}
