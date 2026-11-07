<?php
/**
 * Signaturen der verwendeten Admidio-Klassen fuer PHPStan (Admidio ist zur Analyse nicht installiert).
 */

namespace Admidio\Infrastructure\Entity;

class Entity
{
    public function __construct(mixed $database, string $tableName, string $columnPrefix, string|int $id = '')
    {
    }

    /** @param array<string, mixed> $columnArray */
    public function readDataByColumns(array $columnArray): bool
    {
        return true;
    }

    public function getValue(string $columnName, string $format = ''): mixed
    {
        return null;
    }

    public function setValue(string $columnName, mixed $newValue, bool $checkValue = true): bool
    {
        return true;
    }

    public function save(bool $updateFingerPrint = true): bool
    {
        return true;
    }
}

namespace Admidio\Roles\Entity;

class RolesRights
{
    public function __construct(mixed $database, string $rolesRightName = '', int $referenceId = 0)
    {
    }

    /** @return array<int, int> */
    public function getRolesIds(): array
    {
        return array();
    }

    /** @param array<int, int> $assignedRoles */
    public function hasRight(array $assignedRoles): bool
    {
        return true;
    }
}

namespace Admidio\Users\Entity;

class User
{
    public function __construct(mixed $database, mixed $userFields = null, int $userId = 0)
    {
    }

    public function saveChangesWithoutRights(): void
    {
    }

    public function setValue(string $columnName, mixed $newValue, bool $checkValue = true): bool
    {
        return true;
    }

    public function save(bool $updateFingerPrint = true): bool
    {
        return true;
    }
}
