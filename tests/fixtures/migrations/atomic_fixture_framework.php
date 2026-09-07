<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

return [
    'up' => static function (): void {
        throw new RuntimeException('An adopted framework migration must not run again.');
    },
    'down' => static fn (): bool => true,
];
