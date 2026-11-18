<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Redactor;
use Engine\Atomic\Telemetry\Data\HiveNode;

class HiveReporter
{
    public function __construct(private readonly \Base $atomic) {}

    /** @return array<mixed> the redacted hive; GET /telemetry/hive returns it as JSON */
    public function hive(): array
    {
        try {
            return (array)Redactor::redact_secrets($this->atomic->hive());
        } catch (\Throwable $e) {
            return ['error' => Redactor::redact_secrets($e->getMessage())];
        }
    }

    /** @return list<HiveNode> */
    public function nodes(): array
    {
        return self::tree($this->hive());
    }

    /**
     * @param array<mixed> $hive
     * @return list<HiveNode>
     */
    public static function tree(array $hive): array
    {
        $json = json_encode($hive, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $nodes = [];
        self::walk(json_decode($json === false ? '{}' : $json), 0, '', $nodes);

        return $nodes;
    }

    /** @param list<HiveNode> $nodes */
    private static function walk(mixed $branch, int $depth, string $path, array &$nodes): void
    {
        foreach (self::entries($branch) as $key => $value) {
            $key = (string)$key;
            $full = $path === '' ? $key : $path . '.' . $key;
            if (is_array($value) || $value instanceof \stdClass) {
                $nodes[] = HiveNode::branch($key, $full, $depth, is_array($value), count(self::entries($value)));
                self::walk($value, $depth + 1, $full, $nodes);
            } else {
                $nodes[] = HiveNode::leaf($key, $full, $depth, $value);
            }
        }
    }

    /** @return array<string|int, mixed> */
    private static function entries(mixed $branch): array
    {
        if (is_array($branch)) {
            return $branch;
        }
        $entries = get_object_vars($branch);
        $numeric = array_filter($entries, static fn (string|int $key): bool => is_int($key) || preg_match('/^(0|[1-9]\d{0,8})$/', $key) === 1, ARRAY_FILTER_USE_KEY);
        ksort($numeric, SORT_NUMERIC);

        return $numeric + array_diff_key($entries, $numeric);
    }
}
