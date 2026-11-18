<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

/**
 * Hot counts turn red when non-zero. A total covers rows that are not on the page
 * and replaces the row count while the search is empty; a fixed count ignores the search.
 */
final class Pill
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $count,
        public readonly bool $hot = false,
        public readonly ?int $total = null,
        public readonly bool $fixed = false,
    ) {}
}
