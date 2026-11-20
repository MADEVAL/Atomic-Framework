<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Definition;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Schema\Domain\Relation;

/**
 * Checks domain-declared relations against the related model's configuration.
 *
 * Cortex silently derives the kind of a has-many (inverse or many-to-many)
 * and the pivot table from the other side. Here both sides must state the
 * same thing, otherwise the model refuses to start.
 *
 * Runs on instantiation, never while collecting a definition, so two models
 * that point at each other never resolve each other recursively.
 */
final class RelationVerifier
{
    /**
     * @param array<string, array{relation: Relation, options: array<string, string|null>}> $declarations
     */
    public static function verify(string $model_class, array $declarations): void
    {
        if ($declarations === []) {
            return;
        }
        $own = $model_class::resolveConfiguration();

        foreach ($declarations as $name => $declaration) {
            $relation = $declaration['relation'];
            $options = $declaration['options'];
            $target = $relation->model::resolveConfiguration();

            if (!$relation->is_virtual()) {
                if ($relation->field !== null && !self::has_key($target, $relation->field)) {
                    throw RelationTranslator::error($model_class, (string) $name, sprintf(
                        '%s relation targets %s.%s, which is neither a configured field nor the primary key of %s',
                        $relation->kind,
                        $relation->model,
                        $relation->field,
                        $relation->model
                    ));
                }
                continue;
            }

            $other = $target['fieldConf'][$relation->field] ?? null;
            if (!is_array($other)) {
                throw RelationTranslator::error($model_class, (string) $name, sprintf(
                    '%s relation points at %s.%s, which is not a configured field of %s',
                    $relation->kind,
                    $relation->model,
                    $relation->field,
                    $relation->model
                ));
            }

            $many_to_many = $relation->kind === Relation::HAS_MANY && array_key_exists('pivot', $options);
            $expected = $many_to_many ? Relation::HAS_MANY : Relation::BELONGS_TO_ONE;
            if (!array_key_exists($expected, $other)) {
                $actual = self::kind_of($other);
                throw RelationTranslator::error($model_class, (string) $name, sprintf(
                    '%s relation%s expects %s.%s to be %s, but it is %s%s',
                    $relation->kind,
                    $many_to_many ? ' with pivot()' : '',
                    $relation->model,
                    $relation->field,
                    $expected,
                    $actual ?? 'not a relation',
                    $relation->kind === Relation::HAS_MANY && $actual === Relation::HAS_MANY
                        ? '; declare pivot() for a many-to-many'
                        : ''
                ));
            }

            $back = $other[$expected];
            $back_class = is_array($back) ? ($back[0] ?? null) : $back;
            if (!is_string($back_class) || !is_a($model_class, ltrim($back_class, '\\'), true)) {
                throw RelationTranslator::error($model_class, (string) $name, sprintf(
                    '%s relation expects %s.%s to point back at %s, but it points at %s',
                    $relation->kind,
                    $relation->model,
                    $relation->field,
                    $model_class,
                    is_string($back_class) ? $back_class : get_debug_type($back_class)
                ));
            }

            if (!$many_to_many) {
                // Inverse side: the belongs-to-one may target one of our columns.
                $local = is_array($back) ? ($back[1] ?? null) : null;
                if ($local !== null && $local !== '_id' && !self::has_key($own, (string) $local)) {
                    throw RelationTranslator::error($model_class, (string) $name, sprintf(
                        '%s relation: %s.%s targets %s.%s, which is neither a configured field nor the primary key',
                        $relation->kind,
                        $relation->model,
                        $relation->field,
                        $model_class,
                        (string) $local
                    ));
                }
                continue;
            }

            if (!is_array($back) || ($back[1] ?? null) !== $name) {
                throw RelationTranslator::error($model_class, (string) $name, sprintf(
                    'has-many relation with pivot() expects %s.%s to be has-many [%s, "%s"]',
                    $relation->model,
                    $relation->field,
                    $model_class,
                    $name
                ));
            }
            $own_pivot = $options['pivot'];
            $other_pivot = $back[2] ?? null;
            if ($own_pivot !== $other_pivot) {
                throw RelationTranslator::error($model_class, (string) $name, sprintf(
                    'pivot table %s does not match %s.%s pivot table %s; both sides must name the same table, or neither',
                    $own_pivot === null ? '(computed)' : '"' . $own_pivot . '"',
                    $relation->model,
                    $relation->field,
                    $other_pivot === null ? '(computed)' : '"' . $other_pivot . '"'
                ));
            }
        }
    }

    private static function has_key(array $configuration, string $field): bool
    {
        return $field === $configuration['primary'] || isset($configuration['fieldConf'][$field]);
    }

    private static function kind_of(array $conf): ?string
    {
        foreach ([Relation::BELONGS_TO_ONE, Relation::BELONGS_TO_MANY, Relation::HAS_ONE, Relation::HAS_MANY] as $kind) {
            if (array_key_exists($kind, $conf)) {
                return $kind;
            }
        }
        return null;
    }
}
