<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Config;
if (!defined('ATOMIC_START')) exit;

final class FloatValue extends ConfigValue
{
    public function cast(mixed $raw): mixed { return (float)$raw; }
    public function typeName(): string { return 'float'; }
}