<?php
namespace Plugins\MembershipFee\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Work\WorkRules;
use Plugins\MembershipFee\classes\Work\WorkTarget;

final class WorkRulesTest extends TestCase
{
    // Rollen-IDs wie auf der Testinstanz
    private const AKTIV = array('id' => 5, 'name' => 'Aktiv', 'category' => 1);
    private const JUGEND = array('id' => 7, 'name' => 'Jugend', 'category' => 1);
    private const PASSIV = array('id' => 6, 'name' => 'Passiv', 'category' => 1);
    private const SCHWERBEHINDERT = array('id' => 10, 'name' => 'Schwerbehindert', 'category' => 4);
    private const KASSIER = array('id' => 27, 'name' => 'Kassier', 'category' => 3);
    private const PROBEZEIT = array('id' => 55, 'name' => 'Probezeit', 'category' => 2);

    private function rules(?int $maxAge = 60): WorkRules
    {
        // Aktiv 10 h, Probezeit 15 h, Jugend 0 h; Ausnahmerolle Schwerbehindert, Ausnahmekategorie Funktion (3)
        return new WorkRules(array(5 => 10.0, 55 => 15.0, 7 => 0.0), array(10), array(3), $maxAge, 20.0);
    }

    public function testActiveMemberHasTenHours(): void
    {
        $target = $this->rules()->target(array(self::AKTIV), '1980-05-01', 2026);

        self::assertTrue($target->isRequired());
        self::assertSame(10.0, $target->hours);
        self::assertSame('Aktiv: 10 h', $target->reason);
    }

    public function testProbationTakesTheHigherValue(): void
    {
        $target = $this->rules()->target(array(self::AKTIV, self::PROBEZEIT), '1990-01-01', 2026);

        self::assertSame(15.0, $target->hours);
        self::assertSame('Probezeit: 15 h', $target->reason);
    }

    public function testYouthWithZeroHoursIsNotRequired(): void
    {
        $target = $this->rules()->target(array(self::JUGEND), '2012-03-03', 2026);

        self::assertSame(WorkTarget::NONE, $target->status);
        self::assertSame(0.0, $target->hours);
        self::assertSame('Jugend: 0 h', $target->reason);
    }

    public function testYouthAndActiveGivesTenHours(): void
    {
        $target = $this->rules()->target(array(self::JUGEND, self::AKTIV), '2008-03-03', 2026);

        self::assertSame(10.0, $target->hours);
    }

    public function testRoleWithoutTargetIsNotRequired(): void
    {
        $target = $this->rules()->target(array(self::PASSIV), '1970-01-01', 2026);

        self::assertSame(WorkTarget::NONE, $target->status);
        self::assertSame('keine Rolle mit Sollstunden', $target->reason);
    }

    public function testExemptRoleWins(): void
    {
        $target = $this->rules()->target(array(self::AKTIV, self::PROBEZEIT, self::SCHWERBEHINDERT), '1990-01-01', 2026);

        self::assertSame(WorkTarget::EXEMPT, $target->status);
        self::assertSame(0.0, $target->hours);
        self::assertSame('befreit: Schwerbehindert', $target->reason);
    }

    public function testExemptCategoryWins(): void
    {
        $target = $this->rules()->target(array(self::AKTIV, self::KASSIER), '1990-01-01', 2026);

        self::assertSame(WorkTarget::EXEMPT, $target->status);
        self::assertSame('befreit: Kassier', $target->reason);
    }

    public function testRoleOfExemptCategoryCanBeIncludedAgain(): void
    {
        $fischereiaufseher = array('id' => 18, 'name' => 'Fischereiaufseher', 'category' => 3);
        $rules = new WorkRules(array(5 => 10.0), array(10), array(3), 60, 20.0, array(18));

        // Fischereiaufseher ist trotz Kategorie Funktion pflichtig
        self::assertTrue($rules->target(array(self::AKTIV, $fischereiaufseher), '1980-01-01', 2026)->isRequired());
        // andere Funktionsrollen befreien weiterhin
        self::assertSame('befreit: Kassier', $rules->target(array(self::AKTIV, $fischereiaufseher, self::KASSIER), '1980-01-01', 2026)->reason);
        // eine ausdruecklich eingetragene Ausnahmerolle befreit immer
        $both = new WorkRules(array(5 => 10.0), array(18), array(3), 60, 20.0, array(18));
        self::assertSame(WorkTarget::EXEMPT, $both->target(array(self::AKTIV, $fischereiaufseher), '1980-01-01', 2026)->status);
    }

    public function testAgeLimitAtYearEnd(): void
    {
        // wird im Dezember 2026 60: am 31.12.2026 60 Jahre alt, also befreit
        $turning60 = $this->rules()->target(array(self::AKTIV), '1966-12-30', 2026);
        self::assertSame(WorkTarget::EXEMPT, $turning60->status);
        self::assertSame('befreit: Alter 60 (ab 60)', $turning60->reason);

        // wird erst 2027 60
        $still59 = $this->rules()->target(array(self::AKTIV), '1967-01-01', 2026);
        self::assertTrue($still59->isRequired());
    }

    public function testNoAgeLimitAndUnknownBirthday(): void
    {
        self::assertTrue($this->rules(null)->target(array(self::AKTIV), '1940-01-01', 2026)->isRequired());
        self::assertTrue($this->rules()->target(array(self::AKTIV), null, 2026)->isRequired());
        self::assertTrue($this->rules()->target(array(self::AKTIV), '', 2026)->isRequired());
    }

    public function testMissingHoursAndReplacementFee(): void
    {
        $rules = $this->rules();

        self::assertSame(4.0, WorkRules::missingHours(10.0, 6.0));
        self::assertSame(0.0, WorkRules::missingHours(10.0, 12.5), 'kein Uebertrag, keine Gutschrift');
        self::assertSame(2.5, WorkRules::missingHours(10.0, 7.5));
        self::assertSame(80.0, $rules->replacementFee(10.0, 6.0));
        self::assertSame(50.0, $rules->replacementFee(10.0, 7.5));
        self::assertSame(0.0, $rules->replacementFee(0.0, 0.0));
    }

    public function testFromConfig(): void
    {
        $rules = WorkRules::fromConfig(array('Arbeitsdienst' => array(
            'soll_rollen' => array('5', '55', '7', '', '0'),
            'soll_stunden' => array('10', '15', '0', '8', '4'),
            'ausnahme_rollen' => '10,13',
            'ausnahme_kategorien' => '3',
            'einschluss_rollen' => '18',
            'altersgrenze' => '60',
            'stundensatz' => '20,00'
        )));

        self::assertSame(array(5 => 10.0, 55 => 15.0, 7 => 0.0), $rules->targetHours());
        self::assertSame(array(10, 13), $rules->exemptRoleIds());
        self::assertSame(array(3), $rules->exemptCategoryIds());
        self::assertSame(array(18), $rules->includedRoleIds());
        self::assertSame(60, $rules->maxAge());
        self::assertSame(20.0, $rules->rate());
    }

    public function testFromEmptyConfigUsesDefaults(): void
    {
        $rules = WorkRules::fromConfig(array());

        self::assertSame(array(), $rules->targetHours());
        self::assertNull($rules->maxAge());
        self::assertSame(20.0, $rules->rate());
    }

    public function testFormatHours(): void
    {
        self::assertSame('10 h', WorkRules::formatHours(10.0));
        self::assertSame('7,5 h', WorkRules::formatHours(7.5));
        self::assertSame('0 h', WorkRules::formatHours(0.0));
        self::assertSame('2,25 h', WorkRules::formatHours(2.25));
    }

    public function testNegativeValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkRules(array(5 => -1.0));
    }
}
