<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Config;

final class CsvValue extends ConfigValue
{
    public function cast(mixed $raw): mixed
    {
        if (is_array($raw)) return $raw;
        if ($raw === '' || $raw === null) return [];
        return array_map('trim', explode(',', (string)$raw));
    }
    public function typeName(): string { return 'csv'; }
}
