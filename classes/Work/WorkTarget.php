<?php
/**
 ***********************************************************************************************
 * Ergebnis der Soll-Berechnung im Arbeitsdienst (siehe WorkRules)
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

final class WorkTarget
{
    /** pflichtig mit Sollstunden > 0 */
    public const REQUIRED = 'pflichtig';
    /** in einer Pflichtrolle, aber befreit (Ausnahmerolle, Ausnahmekategorie oder Altersgrenze) */
    public const EXEMPT = 'befreit';
    /** in keiner Rolle mit Sollstunden > 0 */
    public const NONE = 'keine';

    public function __construct(
        public readonly float $hours,
        public readonly string $status,
        public readonly string $reason
    ) {
    }

    public function isRequired(): bool
    {
        return $this->status === self::REQUIRED;
    }
}
