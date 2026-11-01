<?php
namespace Plugins\MembershipFee\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Bookings\BookingStore;
use Plugins\MembershipFee\classes\Bookings\CollectionScope;

final class CollectionScopeTest extends TestCase
{
    public function testMembershipUsesOrganizationFields(): void
    {
        $scope = CollectionScope::membership(1);

        self::assertSame('mf', $scope->key());
        self::assertTrue($scope->isMembership());
        self::assertSame('FEE1', $scope->field(CollectionScope::FIELD_FEE));
        self::assertSame('CONTRIBUTORY_TEXT1', $scope->field(CollectionScope::FIELD_TEXT));
        self::assertSame('DUEDATE1', $scope->field(CollectionScope::FIELD_DUEDATE));
        self::assertSame('SEQUENCETYPE1', $scope->field(CollectionScope::FIELD_SEQUENCETYPE));
        self::assertSame('PAID1', $scope->field(CollectionScope::FIELD_PAID));
        self::assertSame(BookingStore::SOURCE_MANUAL, $scope->manualSource());
    }

    public function testWorkUsesOwnFieldsNotTheOldPluginFields(): void
    {
        $scope = CollectionScope::work(1);

        self::assertSame('ad', $scope->key());
        self::assertFalse($scope->isMembership());
        self::assertSame('ADFEE1', $scope->field(CollectionScope::FIELD_FEE));
        self::assertSame('ADTEXT1', $scope->field(CollectionScope::FIELD_TEXT));
        self::assertSame('ADDUEDATE1', $scope->field(CollectionScope::FIELD_DUEDATE));
        self::assertSame('ADSEQUENCETYPE1', $scope->field(CollectionScope::FIELD_SEQUENCETYPE));
        self::assertSame('ADPAID1', $scope->field(CollectionScope::FIELD_PAID));
        self::assertSame(CollectionScope::SOURCE_WORK_MANUAL, $scope->manualSource());
    }

    public function testUnknownFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CollectionScope::work(1)->field('IBAN');
    }

    public function testFromKeyDefaultsToMembership(): void
    {
        self::assertSame('ad', CollectionScope::fromKey('ad', 1)->key());
        self::assertSame('mf', CollectionScope::fromKey('mf', 1)->key());
        self::assertSame('mf', CollectionScope::fromKey('', 1)->key());
        self::assertSame('mf', CollectionScope::fromKey(null, 1)->key());
        self::assertSame('mf', CollectionScope::fromKey('xyz', 1)->key());
    }

    public function testSourcesAreSeparated(): void
    {
        $membership = CollectionScope::membership(1);
        $work = CollectionScope::work(1);

        foreach (array(BookingStore::SOURCE_ROLE, BookingStore::SOURCE_INDIVIDUAL, BookingStore::SOURCE_MANUAL) as $source) {
            self::assertTrue($membership->includesSource($source));
            self::assertFalse($work->includesSource($source));
            self::assertSame('mf', CollectionScope::forSource($source, 1)->key());
        }
        foreach (array(CollectionScope::SOURCE_WORK, CollectionScope::SOURCE_WORK_MANUAL) as $source) {
            self::assertTrue($work->includesSource($source));
            self::assertFalse($membership->includesSource($source));
            self::assertSame('ad', CollectionScope::forSource($source, 1)->key());
            self::assertLessThanOrEqual(10, strlen($source), 'Spalte mfb_source ist varchar(10)');
        }
    }

    public function testFilterPositionsKeepsKeys(): void
    {
        $positions = array(
            5 => array('mfb_id' => 5, 'mfb_source' => 'rolle'),
            6 => array('mfb_id' => 6, 'mfb_source' => 'ad'),
            7 => array('mfb_id' => 7, 'mfb_source' => 'manuell'),
            8 => array('mfb_id' => 8, 'mfb_source' => 'ad_manuell'),
        );

        self::assertSame(array(5, 7), array_keys(CollectionScope::membership(1)->filterPositions($positions)));
        self::assertSame(array(6, 8), array_keys(CollectionScope::work(1)->filterPositions($positions)));
    }

    public function testMessageIdAndEndToEndId(): void
    {
        $membership = CollectionScope::membership(1);
        $work = CollectionScope::work(1);

        self::assertSame('MF-BFV-112-20261101121930', $membership->messageId('BFV', 112, '20261101121930'));
        self::assertSame('AD-BFV-113-20261101121930', $work->messageId('BFV', 113, '20261101121930'));
        // unveraendert gegenueber dem bisherigen Format
        self::assertSame('BFV-42-20261102', $membership->endToEndId('BFV', 42, '2026-11-02'));
        self::assertSame('BFV-AD-42-20261102', $work->endToEndId('BFV', 42, '2026-11-02'));
        self::assertSame(35, strlen($membership->messageId(str_repeat('X', 40), 1, '20261101121930')));
    }

    public function testSourceSqlCondition(): void
    {
        self::assertSame("mfb_source IN ('ad', 'ad_manuell')", CollectionScope::work(1)->sourceSqlCondition());
        self::assertSame("b.mfb_source IN ('rolle', 'individual', 'manuell')", CollectionScope::membership(1)->sourceSqlCondition('b.mfb_source'));
    }
}
