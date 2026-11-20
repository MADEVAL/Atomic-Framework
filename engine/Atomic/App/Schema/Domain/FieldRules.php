<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Domain;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Enums\Rule;

/**
 * Domain policy for a single field — validation, value casts and pre-checks.
 *
 * Column structure lives in the database; encoding is model policy.
 * Rule arguments are attached here so a rule can never be declared without
 * the parameter it needs.
 */
final class FieldRules
{
    private bool $required = false;
    private bool $unique = false;
    /** @var string[]|null */
    private ?array $unique_by = null;
    private ?string $cast = null;

    private ?int $str_min = null;
    private ?int $str_max = null;
    private ?int $mb_min = null;
    private ?int $mb_max = null;
    private int|float|null $num_min = null;
    private int|float|null $num_max = null;
    private bool $email = false;
    private bool $url = false;
    private bool $uuid_v4 = false;
    private bool $password_entropy = false;
    private ?float $min_entropy = null;
    private ?array $enum_values = null;
    private ?string $regex = null;
    private mixed $callback = null;

    public function required(bool $required = true): self
    {
        $this->required = $required;
        return $this;
    }

    /**
     * Domain-level uniqueness pre-check. The database index declared in the
     * migration remains the concurrency guarantee; this only adds an early,
     * friendly rejection.
     */
    public function unique(bool $unique = true): self
    {
        $this->unique = $unique;
        if (!$unique) {
            $this->unique_by = null;
        }
        return $this;
    }

    /**
     * Scoped uniqueness: the value must be unique only among rows sharing the
     * same values in $scope (e.g. ['store'] for per-tenant emails). Same
     * pre-check semantics as legacy fieldConf 'unique_by'; a composite UNIQUE
     * index in the migration remains the concurrency guarantee.
     *
     * @param string[] $scope
     */
    public function unique_by(array $scope): self
    {
        if ($scope === []) {
            throw new \InvalidArgumentException('unique_by() scope fields must not be empty.');
        }
        foreach ($scope as $field) {
            if (!is_string($field) || $field === '') {
                throw new \InvalidArgumentException(sprintf('unique_by() scope fields must be non-empty strings, %s given.', get_debug_type($field)));
            }
        }
        $this->unique = true;
        $this->unique_by = array_values(array_unique($scope));
        return $this;
    }

    public function json(): self
    {
        return $this->set_cast(\DB\Cortex::DT_JSON);
    }

    /** Cortex's deprecated serialized compatibility cast (new arrays use JSON). */
    public function serialized(): self
    {
        return $this->set_cast(\DB\Cortex::DT_SERIALIZED);
    }

    private function set_cast(string $cast): self
    {
        if ($this->cast !== null && $this->cast !== $cast) {
            throw new \LogicException('A field cannot declare both json() and serialized() casts.');
        }
        $this->cast = $cast;
        return $this;
    }

    public function str_min(int $min): self
    {
        $this->str_min = $min;
        return $this;
    }

    public function str_max(int $max): self
    {
        $this->str_max = $max;
        return $this;
    }

    public function mb_min(int $min): self
    {
        $this->mb_min = $min;
        return $this;
    }

    public function mb_max(int $max): self
    {
        $this->mb_max = $max;
        return $this;
    }

    public function num_min(int|float $min): self
    {
        $this->num_min = $min;
        return $this;
    }

    public function num_max(int|float $max): self
    {
        $this->num_max = $max;
        return $this;
    }

    public function email(bool $email = true): self
    {
        $this->email = $email;
        return $this;
    }

    public function url(bool $url = true): self
    {
        $this->url = $url;
        return $this;
    }

    public function uuid_v4(bool $uuid = true): self
    {
        $this->uuid_v4 = $uuid;
        return $this;
    }

    public function password_entropy(?float $min_entropy = null): self
    {
        $this->password_entropy = true;
        $this->min_entropy = $min_entropy;
        return $this;
    }

    public function enum_values(array $values): self
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Enum values for a field must not be empty.');
        }
        $this->enum_values = array_values($values);
        return $this;
    }

    public function regex(string $pattern): self
    {
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException(sprintf('Invalid regex pattern: %s', $pattern));
        }
        $this->regex = $pattern;
        return $this;
    }

    public function callback(callable $callback): self
    {
        $this->callback = $callback;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────
    // Relations (Cortex). Recorded as written; RelationTranslator validates
    // them against the live structure and the related model.
    // ─────────────────────────────────────────────────────────────

    /** @var Relation[] */
    private array $relations = [];
    /** @var array<int, array{0: string, 1: string|null}> [option, value] pairs in call order */
    private array $relation_options = [];

    /**
     * Column-backed many-to-one. Without $field the relation targets the
     * related model's primary key (Cortex string form); with $field it
     * targets that column ([class, field] form).
     */
    public function belongs_to_one(string $model, ?string $field = null): self
    {
        $this->relations[] = new Relation(Relation::BELONGS_TO_ONE, $model, $field);
        return $this;
    }

    /** Column-backed one-way many-to-many, stored as a JSON list of keys. */
    public function belongs_to_many(string $model, ?string $field = null): self
    {
        $this->relations[] = new Relation(Relation::BELONGS_TO_MANY, $model, $field);
        return $this;
    }

    /** Virtual inverse of $model's belongs-to-one field $field. */
    public function has_one(string $model, string $field): self
    {
        $this->relations[] = new Relation(Relation::HAS_ONE, $model, $field);
        return $this;
    }

    /**
     * Virtual inverse of $model's belongs-to-one field $field, or — with
     * pivot() — one side of a many-to-many with $model's has-many $field.
     */
    public function has_many(string $model, string $field): self
    {
        $this->relations[] = new Relation(Relation::HAS_MANY, $model, $field);
        return $this;
    }

    /**
     * Declare a has-many as many-to-many through a pivot table. Without a
     * table name Cortex computes it; both sides must then agree on that.
     */
    public function pivot(?string $table = null): self
    {
        return $this->relation_option('pivot', $table);
    }

    /** m:m: pivot column holding this side's key (Cortex 'relField'). */
    public function rel_field(string $column): self
    {
        return $this->relation_option('relField', $column);
    }

    /** m:m: this model's key stored in the pivot (Cortex 'relPK'). */
    public function rel_pk(string $column): self
    {
        return $this->relation_option('relPK', $column);
    }

    /** m:m: this model's column used to look up pivot rows (Cortex 'localKey'). */
    public function local_key(string $column): self
    {
        return $this->relation_option('localKey', $column);
    }

    private function relation_option(string $name, ?string $value): self
    {
        $this->relation_options[] = [$name, $value];
        return $this;
    }

    /** @return Relation[] */
    public function relations(): array
    {
        return $this->relations;
    }

    /** @return array<int, array{0: string, 1: string|null}> */
    public function relation_options(): array
    {
        return $this->relation_options;
    }

    /** Whether any non-relation policy (validation, cast, uniqueness) is declared. */
    public function has_policy(?string $field = null): bool
    {
        return $this->to_policy($field) !== [];
    }

    /**
     * Export validation policy and an optional Cortex encoding type.
     * The structural merger combines this with the live column type.
     *
     * @return array<string, mixed>
     */
    public function to_policy(?string $field = null): array
    {
        foreach (['min', 'max'] as $bound) {
            $present = [];
            foreach (['num', 'str', 'mb'] as $variant) {
                $rule = $variant . '_' . $bound;
                if ($this->$rule !== null) {
                    $present[] = $rule . '(' . $this->$rule . ')';
                }
            }
            if (count($present) > 1) {
                throw new \LogicException(sprintf(
                    '%s has conflicting %s rules: %s. Keep only one variant.',
                    $field === null ? 'Field' : 'Field "' . $field . '"',
                    $bound,
                    implode(', ', $present)
                ));
            }
        }
        $policy = [];

        if ($this->required) {
            $policy['required'] = true;
        }
        if ($this->cast !== null) {
            $policy['type'] = $this->cast;
        }
        if ($this->unique) {
            $policy['unique'] = true;
            if ($this->unique_by !== null) {
                $policy['unique_by'] = $this->unique_by;
            }
        }

        $rules = [];
        if ($this->uuid_v4) {
            $rules[] = Rule::UUID_V4;
        }
        if ($this->email) {
            $rules[] = Rule::EMAIL;
        }
        if ($this->url) {
            $rules[] = Rule::URL;
        }
        if ($this->enum_values !== null) {
            $rules[] = Rule::ENUM;
        }
        if ($this->regex !== null) {
            $rules[] = Rule::REGEX;
        }
        if ($this->callback !== null) {
            $rules[] = Rule::CALLBACK;
        }
        if ($this->num_min !== null) {
            $rules[] = Rule::NUM_MIN;
        }
        if ($this->num_max !== null) {
            $rules[] = Rule::NUM_MAX;
        }
        if ($this->str_min !== null) {
            $rules[] = Rule::STR_MIN;
        }
        if ($this->str_max !== null) {
            $rules[] = Rule::STR_MAX;
        }
        if ($this->mb_min !== null) {
            $rules[] = Rule::MB_MIN;
        }
        if ($this->mb_max !== null) {
            $rules[] = Rule::MB_MAX;
        }
        if ($this->password_entropy) {
            $rules[] = Rule::PASSWORD_ENTROPY;
        }

        if ($rules !== []) {
            $policy['rules'] = $rules;
        }

        $min = $this->num_min ?? $this->str_min ?? $this->mb_min;
        $max = $this->num_max ?? $this->str_max ?? $this->mb_max;
        if ($min !== null) {
            $policy['min'] = $min;
        }
        if ($max !== null) {
            $policy['max'] = $max;
        }
        if ($this->enum_values !== null) {
            $policy['enum'] = $this->enum_values;
        }
        if ($this->regex !== null) {
            $policy['pattern'] = $this->regex;
        }
        if ($this->callback !== null) {
            $policy['callback'] = $this->callback;
        }
        if ($this->min_entropy !== null) {
            $policy['min_entropy'] = $this->min_entropy;
        }

        return $policy;
    }
}

