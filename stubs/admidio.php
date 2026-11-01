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
