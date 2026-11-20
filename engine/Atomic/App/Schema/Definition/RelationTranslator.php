<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Definition;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Schema\Domain\FieldRules;
use Engine\Atomic\App\Schema\Domain\Relation;
use Engine\Atomic\App\Schema\Structure\SchemaField;
use Engine\Atomic\App\Schema\Structure\SchemaStructure;

/**
 * Translates a relation declared in domain_schema() into the exact legacy
 * fieldConf keys Cortex consumes, checking everything that can be checked
 * from the declaring model's own live structure.
 *
 * Nothing is inferred: anything missing, contradictory or unsupported throws.
 * Checks that need the related model's configuration run later, when the
 * model is instantiated (RelationVerifier), so collecting a definition never
 * resolves another model.
 */
final class RelationTranslator
{
    /** Relation options each relation kind accepts. */
    private const OPTIONS = [
        Relation::BELONGS_TO_ONE => [],
        Relation::BELONGS_TO_MANY => [],
        Relation::HAS_ONE => [],
        Relation::HAS_MANY => ['pivot', 'relField', 'relPK', 'localKey'],
    ];

    /** Column kinds that can hold the JSON key list of a belongs-to-many. */
    private const JSON_KINDS = ['text', 'binary', 'json'];

    /**
     * The validated relation of a field, or null when none is declared.
     *
     * @return array{relation: Relation, options: array<string, string|null>}|null
     */
    public static function declaration(string $model_class, string $name, FieldRules $rules): ?array
    {
        $relations = $rules->relations();
        $options = [];
        foreach ($rules->relation_options() as [$option, $value]) {
            if (array_key_exists($option, $options)) {
                throw self::error($model_class, $name, sprintf('declares relation option "%s" twice', self::method_of($option)));
            }
            $options[$option] = $value;
        }

        if ($relations === []) {
            if ($options !== []) {
                throw self::error($model_class, $name, sprintf(
                    'declares relation option(s) %s without a relation',
                    implode(', ', array_map(static fn(string $o): string => self::method_of($o) . '()', array_keys($options)))
                ));
            }
            return null;
        }
        if (count($relations) > 1) {
            throw self::error($model_class, $name, sprintf(
                'declares more than one relation (%s); a field holds exactly one',
                implode(', ', array_map(static fn(Relation $r): string => $r->kind, $relations))
            ));
        }

        $relation = $relations[0];
        self::assert_target($model_class, $name, $relation);

        foreach ($options as $option => $value) {
            if (!in_array($option, self::OPTIONS[$relation->kind], true)) {
                throw self::error($model_class, $name, sprintf(
                    '%s() is not supported on a %s relation',
                    self::method_of($option),
                    $relation->kind
                ));
            }
            if ($option !== 'pivot' && ($value === null || trim($value) === '')) {
                throw self::error($model_class, $name, sprintf('%s() needs a non-empty value', self::method_of($option)));
            }
        }
        if (array_key_exists('pivot', $options) && $options['pivot'] !== null && trim($options['pivot']) === '') {
            throw self::error($model_class, $name, 'pivot() table name must be non-empty; omit it to use the name Cortex computes');
        }
        foreach (['relField', 'relPK', 'localKey'] as $option) {
            if (isset($options[$option]) && !array_key_exists('pivot', $options)) {
                throw self::error($model_class, $name, sprintf(
                    '%s() only applies to a many-to-many has-many; declare pivot() as well',
                    self::method_of($option)
                ));
            }
        }

        return ['relation' => $relation, 'options' => $options];
    }

    /**
     * fieldConf for a column-backed relation (belongs-to-one / belongs-to-many).
     * The live column type is kept; a belongs-to-many column is encoded as JSON,
     * exactly as Cortex resolves it.
     *
     * @param array<string, mixed> $conf structural conf (plus policy) of the column
     * @param array{relation: Relation, options: array<string, string|null>} $declaration
     * @return array<string, mixed>
     */
    public static function column_conf(string $model_class, SchemaField $column, array $conf, array $declaration, array $policy): array
    {
        $name = $column->name;
        $relation = $declaration['relation'];
        $options = $declaration['options'];

        if ($relation->is_virtual()) {
            throw self::error($model_class, $name, sprintf(
                'declares a virtual %s relation, but the table has a column with that name; virtual relations must not shadow a column',
                $relation->kind
            ));
        }
        if (!empty($column->metadata['primary_key'])) {
            throw self::error($model_class, $name, sprintf('declares a %s relation on the primary key column, which is not supported', $relation->kind));
        }
        if ($column->type === 'DOMAIN_UNTYPED') {
            throw self::error($model_class, $name, sprintf(
                'declares a %s relation on a column with unrecognized type "%s"',
                $relation->kind,
                (string) $column->metadata['raw_type']
            ));
        }
        if (isset($policy['type'])) {
            throw self::error($model_class, $name, sprintf(
                'combines a %s relation with the %s cast; the relation decides the encoding',
                $relation->kind,
                strtolower((string) $policy['type'])
            ));
        }

        $target = $relation->field === null ? $relation->model : [$relation->model, $relation->field];
        if ($relation->kind === Relation::BELONGS_TO_MANY) {
            $kind = $column->metadata['column_kind'] ?? null;
            if (!in_array($kind, self::JSON_KINDS, true)) {
                throw self::error($model_class, $name, sprintf(
                    'declares a belongs-to-many relation on a %s (%s) column; the key list is stored as JSON and needs text, blob or native JSON storage',
                    $column->type,
                    (string) $kind
                ));
            }
            $conf['type'] = \DB\Cortex::DT_JSON;
            $conf['belongs-to-many'] = $target;
            return $conf;
        }

        $conf['belongs-to-one'] = $target;
        return $conf;
    }

    /**
     * fieldConf for a virtual relation (has-one / has-many), which has no
     * column and therefore no type, nullability or validation policy.
     *
     * @param array{relation: Relation, options: array<string, string|null>} $declaration
     * @return array<string, mixed>
     */
    public static function virtual_conf(string $model_class, string $name, FieldRules $rules, array $declaration, SchemaStructure $structure): array
    {
        $relation = $declaration['relation'];
        $options = $declaration['options'];

        if (!$relation->is_virtual()) {
            throw self::error($model_class, $name, sprintf(
                'declares a %s relation, but table "%s" has no column "%s" to store it',
                $relation->kind,
                $structure->table(),
                $name
            ));
        }
        if ($rules->has_policy()) {
            throw self::error($model_class, $name, sprintf(
                'combines a virtual %s relation with validation or cast policy; a virtual relation has no column to validate',
                $relation->kind
            ));
        }

        if ($relation->kind === Relation::HAS_ONE) {
            return ['has-one' => [$relation->model, $relation->field]];
        }

        $conf = [$relation->model, $relation->field];
        if (isset($options['pivot'])) {
            $conf[2] = $options['pivot'];
        }
        if (isset($options['relPK'])) {
            $column = $structure->field($options['relPK']);
            if ($column === null || !empty($column->metadata['primary_key'])) {
                throw self::error($model_class, $name, sprintf(
                    'rel_pk("%s") must name a non-primary-key column of table "%s" (Cortex reads its type from the field configuration)',
                    $options['relPK'],
                    $structure->table()
                ));
            }
        }
        if (isset($options['localKey']) && !$structure->has($options['localKey'])) {
            throw self::error($model_class, $name, sprintf(
                'local_key("%s") has no matching column in table "%s"',
                $options['localKey'],
                $structure->table()
            ));
        }
        foreach (['relField', 'relPK', 'localKey'] as $option) {
            if (isset($options[$option])) {
                $conf[$option] = $options[$option];
            }
        }

        return ['has-many' => $conf];
    }

    private static function assert_target(string $model_class, string $name, Relation $relation): void
    {
        if ($relation->model === '' || !class_exists($relation->model)) {
            throw self::error($model_class, $name, sprintf('declares a %s relation to unknown class "%s"', $relation->kind, $relation->model));
        }
        if (!is_subclass_of($relation->model, \DB\Cortex::class)) {
            throw self::error($model_class, $name, sprintf('declares a %s relation to %s, which is not a Cortex model', $relation->kind, $relation->model));
        }
        if ($relation->field === null) {
            if ($relation->is_virtual()) {
                throw self::error($model_class, $name, sprintf('declares a %s relation without the related field', $relation->kind));
            }
            return;
        }
        if (trim($relation->field) === '') {
            throw self::error($model_class, $name, sprintf('declares a %s relation with an empty related field', $relation->kind));
        }
        if ($relation->field === '_id') {
            throw self::error($model_class, $name, sprintf(
                'names "_id" as the related field of a %s relation; omit the field to target the primary key',
                $relation->kind
            ));
        }
    }

    private static function method_of(string $option): string
    {
        return match ($option) {
            'relField' => 'rel_field',
            'relPK' => 'rel_pk',
            'localKey' => 'local_key',
            default => $option,
        };
    }

    public static function error(string $model_class, string $name, string $problem): \LogicException
    {
        return new \LogicException(sprintf('Model %s field "%s" %s.', $model_class, $name, $problem));
    }
}
