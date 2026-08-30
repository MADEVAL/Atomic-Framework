<?php
declare(strict_types=1);
namespace Engine\Atomic\Quota;

if (!defined('ATOMIC_START')) exit;

/** One configured pacing window: at most $limit credits inside $window seconds. */
final class QuotaPacingSpec
{
    public function __construct(
        public readonly int $limit,
        public readonly int $window
    ) {}
}
