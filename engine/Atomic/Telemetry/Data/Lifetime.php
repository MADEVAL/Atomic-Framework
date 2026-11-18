<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Lifetime
{
    /** @param array<string, int> $counters */
    public function __construct(public readonly array $counters) {}

    /** @param array<string, mixed> $counters */
    public static function from_array(array $counters): self
    {
        return new self(array_map('intval', $counters));
    }

    /** @return array<string, int> label => count, "failed logins" => 12 */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->counters as $key => $count) {
            $labels[str_replace('_', ' ', (string)$key)] = $count;
        }

        return $labels;
    }
}
