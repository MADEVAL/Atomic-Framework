<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

/** Keys are sorted, so the same tag set always maps to the same hash. */
final class TagHash
{
    private const MAX_VALUE_LENGTH = 120;

    public static function canonical_json(array $tags): string
    {
        if ($tags === []) {
            return '';
        }
        // Values are capped so attacker-controlled tags (login identifiers,
        // rate-limit keys) always fit the tags column.
        $tags = array_map(
            static fn ($value): string => mb_substr(
                is_scalar($value) ? (string)$value : (string)json_encode($value),
                0,
                self::MAX_VALUE_LENGTH,
            ),
            $tags,
        );
        ksort($tags);

        return (string)json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function of(array $tags): string
    {
        $json = self::canonical_json($tags);
        if ($json === '') {
            return '';
        }

        return substr(md5($json), 0, 16);
    }
}
