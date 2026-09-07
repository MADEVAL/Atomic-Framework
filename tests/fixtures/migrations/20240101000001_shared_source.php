<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

return [
    'up' => static fn (): bool => true,
    'down' => static fn (): bool => true,
];
