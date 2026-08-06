<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Config;

final class StringValue extends ConfigValue
{
    public function cast(mixed $raw): mixed { return (string)$raw; }
    public function typeName(): string { return 'string'; }
}
