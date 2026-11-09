<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Definition;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Enums\Rule;

/**
 * Configuration linting shared by both definition strategies.
 *
 * Catches the legacy failure class on hand-written arrays: rules whose
 * argument keys are missing or misspelled, and unsupported types.
 */
final class FieldConfLinter
{
    private const KNOWN_TYPES = [
        'BOOLEAN',
        'INT1', 'INT2', 'INT4', 'INT8',
        'FLOAT', 'DOUBLE', 'DECIMAL',
        'VARCHAR128', 'VARCHAR256', 'VARCHAR512',
        'TEXT', 'LONGTEXT',
        'DATE', 'DATETIME', 'TIMESTAMP', 'BLOB', 'JSON', 'SERIALIZED',
    ];

    private const NUMERIC_TYPES = ['INT1', 'INT2', 'INT4', 'INT8', 'FLOAT', 'DOUBLE', 'DECIMAL'];
    private const STRING_TYPES = ['VARCHAR128', 'VARCHAR256', 'VARCHAR512', 'TEXT', 'LONGTEXT'];

    /** rule value => [min-or-max argument key, required type category] */
    private const RULE_TABLE = [
        'num_min' => ['min', 'numeric'],
        'num_max' => ['max', 'numeric'],
        'str_min' => ['min', 'string'],
        'str_max' => ['max', 'string'],
        'mb_min' => ['min', 'string'],
        'mb_max' => ['max', 'string'],
        'enum' => ['enum', 'any'],
        'regex' => ['pattern', 'any'],
        'callback' => ['callback', 'any'],
    ];

    /** @return string[] */
    public static function lint(array $field_conf): array
    {
        $errors = [];
        foreach ($field_conf as $field => $conf) {
            if (!is_array($conf)) {
                $errors[] = sprintf(
                    'Field "%s" must be declared as an array, %s given.',
                    (string) $field,
                    get_debug_type($conf)
                );
                continue;
            }
            foreach (self::lint_field((string) $field, $conf) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @return string[] */
    private static function lint_field(string $field, array $conf): array
    {
        $errors = [];
        foreach (['has-many', 'has-one', 'belongs-to-one', 'belongs-to-many'] as $relation) {
            if (!array_key_exists($relation, $conf)) continue;
            $target = $conf[$relation];
            if (is_string($target) && $target !== '') return [];
            if (is_array($target) && isset($target[0]) && is_string($target[0]) && $target[0] !== '') return [];
            return [sprintf('Field "%s" has invalid "%s" relation target.', $field, $relation)];
        }
        $type = strtoupper((string) ($conf['type'] ?? ''));

        $domain_cast = isset($conf['column_kind']) && in_array($type, ['JSON', 'SERIALIZED'], true);
        $domain_untyped = $type === 'DOMAIN_UNTYPED' && ($conf['column_kind'] ?? null) === 'unknown';
        if (!$domain_untyped && !$domain_cast && ($type === '' || !in_array($type, self::KNOWN_TYPES, true))) {
            $errors[] = sprintf('Field "%s" has unsupported type "%s".', $field, $type === '' ? 'missing' : $type);
            return $errors;
        }

        $names = array_map(
            static fn(Rule $rule): string => $rule->value,
            array_filter($conf['rules'] ?? [], static fn($rule): bool => $rule instanceof Rule)
        );

        foreach ($names as $name) {
            $requirement = self::RULE_TABLE[$name] ?? null;
            if ($requirement === null) {
                continue;
            }
            [$argument, $category] = $requirement;

            if ($category === 'numeric' && !in_array($type, self::NUMERIC_TYPES, true)) {
                $errors[] = sprintf('Field "%s" (%s) cannot use rule "%s" — it requires a numeric field.', $field, $type, $name);
            }
            if ($category === 'string' && !in_array($type, self::STRING_TYPES, true)) {
                $errors[] = sprintf('Field "%s" (%s) cannot use rule "%s" — it requires a string field.', $field, $type, $name);
            }
            if (($conf[$argument] ?? null) === null) {
                $errors[] = sprintf('Field "%s" declares rule "%s" without a "%s" argument.', $field, $name, $argument);
            }
        }

        foreach ([['num_min', 'str_min', 'mb_min'], ['num_max', 'str_max', 'mb_max']] as $variants) {
            $present = array_values(array_intersect($variants, $names));
            if (count($present) > 1) {
                $errors[] = sprintf('Field "%s" has conflicting rules (%s) — use only one variant.', $field, implode(', ', $present));
            }
        }

        return $errors;
    }
}
