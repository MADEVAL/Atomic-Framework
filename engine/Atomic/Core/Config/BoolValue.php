<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Config;
if (!defined('ATOMIC_START')) exit;

final class BoolValue extends ConfigValue
{
    public function cast(mixed $raw): mixed
    {
        if (is_bool($raw)) return $raw;
        if (is_string($raw)) return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        return (bool)$raw;
    }
    public function typeName(): string { return 'bool'; }
}