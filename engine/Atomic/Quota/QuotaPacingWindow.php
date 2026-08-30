<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

final class QuotaPacingWindow
{
    public function __construct(
        public readonly string $key,
        public readonly int $limit,
        public readonly int $window
    ) {}
}
