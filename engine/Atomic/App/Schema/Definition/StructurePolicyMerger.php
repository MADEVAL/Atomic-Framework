<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Definition;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\Relation;
use Engine\Atomic\App\Schema\Structure\SchemaStructure;

/**
 * Compiles the database structure plus the model-declared domain
 * policy into the exact fieldConf structure Cortex and the Validator expect.
 *
 * This is the only place the two halves meet.
 */
final class StructurePolicyMerger
{
    /** Process-wide warning deduplication is independent of manager resets. */
    private static array $warned = [];
    private static array $skipped = [];
    /** @var array<string, array<string, array{relation: Relation, options: array<string, string|null>}>> */
    private static array $relations = [];

    public static function skipped(string $model_class): array
    {
        return self::$skipped[$model_class] ?? [];
    }

    /**
     * Relations declared by the definition merged last for $model_class.
     *
     * @return array<string, array{relation: Relation, options: array<string, string|null>}>
     */
    public static function relations(string $model_class): array
    {
        return self::$relations[$model_class] ?? [];
    }

    public static function warn_skipped(string $model_class, array $problems): void
    {
        foreach ($problems as $name => $message) {
            $key = $model_class . '::' . $name;
            if (!isset(self::$warned[$key])) {
                self::$warned[$key] = true;
                \Engine\Atomic\Core\Log::warning($message);
            }
        }
    }

    /** rule value => required type category */
    private const RULE_CATEGORIES = [
        'num_min' => 'numeric',
        'num_max' => 'numeric',
        'str_min' => 'string',
        'str_max' => 'string',
        'mb_min' => 'string',
        'mb_max' => 'string',
        'email' => 'string',
        'url' => 'string',
        'uuid_v4' => 'string',
        'regex' => 'string',
        'password_entropy' => 'string',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function merge(SchemaStructure $structure, DomainDefinition $domain, string $model_class): array
    {
        $conf = [];
        self::$skipped[$model_class] = [];
        self::$relations[$model_class] = [];
        $declarations = [];
        foreach ($domain->fields() as $name => $rules) {
            $declaration = RelationTranslator::declaration($model_class, (string) $name, $rules);
            if ($declaration !== null) {
                $declarations[(string) $name] = $declaration;
            }
        }

        foreach ($structure->fields() as $name => $field) {
            $rules = $domain->field($name);
            if (!empty($field->metadata['primary_key'])) {
                if ($rules === null) continue;
                if (!empty($field->metadata['auto_increment'])) {
                    throw new \LogicException(sprintf('Cannot declare domain policy on auto-increment primary key "%s" in %s.', $name, $model_class));
                }
            }

            if ($field->type === 'DOMAIN_UNTYPED') {
                $raw = $field->metadata['raw_type'];
                if ($rules === null) {
                    $message = sprintf('Model %s table "%s" column "%s" has unrecognized type "%s"; pre-save validation is skipped.', $model_class, $structure->table(), $name, $raw);
                    self::$skipped[$model_class][$name] = $message;
                    continue;
                }
                $policy = $rules->to_policy($name);
                $dependent = isset($policy['type']) ? strtolower($policy['type']) : null;
                foreach ($policy['rules'] ?? [] as $rule) {
                    if (isset(self::RULE_CATEGORIES[$rule->value]) && $rule->value !== 'regex') {
                        $dependent ??= $rule->value;
                    }
                }
                if ($dependent !== null) {
                    throw new \LogicException(sprintf('Cannot apply rule "%s" in %s to column "%s" with unrecognized raw type "%s" (table "%s").', $dependent, $model_class, $name, $raw, $structure->table()));
                }
            }

            if ($rules === null) {
                $conf[$name] = self::structural_conf($field);
                continue;
            }

            $policy = $rules->to_policy($name);

            if ($policy !== [] && ($declarations[$name]['relation'] ?? null)?->kind === Relation::BELONGS_TO_MANY) {
                // Atomic's pre-save validation skips belongs-to-many fields.
                throw RelationTranslator::error($model_class, $name, 'combines a belongs-to-many relation with validation or cast policy, which is never applied to belongs-to-many fields');
            }

            if (!empty($policy['required']) && $field->nullable) {
                throw new \LogicException(sprintf(
                    'Cannot mark field "%s" as required in %s: the database declares it nullable.',
                    $name,
                    $model_class
                ));
            }

            foreach ($policy['unique_by'] ?? [] as $scope) {
                if ($scope === $name || !$structure->has($scope)) {
                    throw new \LogicException(sprintf(
                        'unique_by() scope field "%s" for field "%s" in %s %s.',
                        $scope,
                        $name,
                        $model_class,
                        $scope === $name ? 'cannot be the field itself' : sprintf('has no matching column in table "%s"', $structure->table())
                    ));
                }
            }

            $structural = self::structural_conf($field);
            $declaration = $declarations[$name] ?? null;
            if ($declaration !== null) {
                $conf[$name] = RelationTranslator::column_conf($model_class, $field, $structural + self::policy_remainder($policy), $declaration, $policy);
                self::assert_compatible($name, $structural, $policy, $model_class);
                continue;
            }
            self::assert_compatible($name, $structural, $policy, $model_class);
            if (isset($policy['type'])) $structural['type'] = $policy['type'];
            $conf[$name] = $structural + self::policy_remainder($policy);
        }

        foreach ($domain->fields() as $name => $rules) {
            $name = (string) $name;
            if (isset($declarations[$name]) && !$structure->has($name)) {
                $conf[$name] = RelationTranslator::virtual_conf($model_class, $name, $rules, $declarations[$name], $structure);
                continue;
            }
            if (!$structure->has((string) $name)) {
                throw new \LogicException(sprintf(
                    'Domain policy for field "%s" in %s has no matching column in the database schema for table "%s".',
                    (string) $name,
                    $model_class,
                    $structure->table()
                ));
            }
        }

        self::$relations[$model_class] = $declarations;
        return $conf;
    }

    /** @return array<string, mixed> */
    private static function structural_conf(\Engine\Atomic\App\Schema\Structure\SchemaField $field): array
    {
        return ['type' => $field->type, 'nullable' => $field->nullable] + $field->metadata;
    }

    /**
     * Policy keys not already provided by the structure, keeping the
     * canonical legacy ordering (type, nullable, default, unique, index,
     * required, rules, min, max, ...).
     *
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private static function policy_remainder(array $policy): array
    {
        unset($policy['type'], $policy['nullable']);
        $remainder = [];

        if (isset($policy['default'])) {
            $remainder['default'] = $policy['default'];
            unset($policy['default']);
        }
        if (isset($policy['unique'])) {
            $remainder['unique'] = $policy['unique'];
            unset($policy['unique']);
        }
        if (isset($policy['unique_by'])) {
            $remainder['unique_by'] = $policy['unique_by'];
            unset($policy['unique_by']);
        }
        if (isset($policy['index'])) {
            $remainder['index'] = $policy['index'];
            unset($policy['index']);
        }

        return $remainder + $policy;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private static function assert_compatible(string $name, array $column, array $policy, string $model_class): void
    {
        if (($column['column_kind'] ?? null) === 'unknown') return;
        // Semantic kinds prevent TIME/YEAR token buckets from accepting unrelated rules.
        $type = $column['type'];
        $kind = $column['column_kind'];
        $cast = $policy['type'] ?? ($kind === 'json' ? \DB\Cortex::DT_JSON : null);
        if ($cast !== null && !in_array($kind, ['text', 'binary'], true)
            && !($kind === 'json' && $cast === \DB\Cortex::DT_JSON)) {
            throw new \LogicException(sprintf('Cannot apply cast "%s" to field "%s" (%s, %s) in %s: use text/blob storage or native JSON for json().', $cast, $name, $type, $kind, $model_class));
        }
        if (isset($column['enum_values'], $policy['enum'])) {
            foreach ($policy['enum'] as $value) {
                if (!in_array($value, $column['enum_values'], true)) {
                    throw new \LogicException(sprintf('Domain enum for field "%s" in %s must be a subset of the native enum_values.', $name, $model_class));
                }
            }
        }
        foreach ($policy['rules'] ?? [] as $rule) {
            $category = self::RULE_CATEGORIES[$rule->value] ?? null;
            if ($category === null) {
                continue;
            }
            $matches = $category === 'numeric'
                ? $cast === null && in_array($kind, ['integer', 'decimal', 'floating'], true)
                : $cast === null && in_array($kind, ['string', 'text', 'enum'], true);
            if ($category === 'string' && isset($column['max_length']) && in_array($rule->value, ['str_min', 'str_max', 'mb_min', 'mb_max'], true)) {
                $argument = str_ends_with($rule->value, 'min') ? 'min' : 'max';
                if ($policy[$argument] > $column['max_length']) {
                    throw new \LogicException(sprintf('Rule "%s" on field "%s" in %s exceeds column max_length %d.', $rule->value, $name, $model_class, $column['max_length']));
                }
            }
            if (!$matches) {
                throw new \LogicException(sprintf(
                    'Cannot apply rule "%s" to a %s field "%s" (%s) in %s.',
                    $rule->value,
                    $category,
                    $name,
                    $type,
                    $model_class
                ));
            }
        }
    }
}

