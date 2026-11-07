<?php
/**
 ***********************************************************************************************
 * Fachlicher Fehler der Arbeitsdienst-Schnittstelle (WorkHoursApi)
 *
 * Traegt einen maschinenlesbaren Code und den passenden HTTP-Status, damit aufrufende Plugins
 * (z. B. WpUserSync) den Fehler ohne eigene Zuordnung weiterreichen koennen.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\classes\Work;

use RuntimeException;

final class WorkApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus = 422,
        private readonly array $details = array()
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
