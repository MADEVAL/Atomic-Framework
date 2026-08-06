<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Config;

final class IntValue extends ConfigValue
{
    public function cast(mixed $raw): mixed { return (int)$raw; }
    public function typeName(): string { return 'int'; }
}
