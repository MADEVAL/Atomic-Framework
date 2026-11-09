<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Domain;

if (!defined('ATOMIC_START')) exit;

/**
 * Domain-only field definition surface for models (new style).
 *
 * Models declare validation policy and value encoding here; column types live
 * in the database and are merged in by ModelDefinitionManager. No column type, no
 * length, no nullability — those are database concerns.
 *
 * Usage:
 *   protected static function domain_schema(DomainSchema $schema): DomainDefinition {
 *       return $schema->define([
 *           'name'  => $schema->field()->required()->str_min(2),
 *           'email' => $schema->field()->required()->email(),
 *       ]);
 *   }
 */
final class DomainSchema
{
    /** @var array<string, FieldRules> */
    private array $fields = [];

    private const E_DEFINE_VALUE = 'Field "%s" must be a FieldRules builder (use $schema->field()), %s given.';

    public static function create(): self
    {
        return new self();
    }

    /**
     * Register domain policy builders under their column names.
     *
     * @param array<string, FieldRules> $fields
     */
    public function define(array $fields): DomainDefinition
    {
        foreach ($fields as $name => $field) {
            if (!$field instanceof FieldRules) {
                throw new \InvalidArgumentException(sprintf(
                    self::E_DEFINE_VALUE,
                    (string) $name,
                    get_debug_type($field)
                ));
            }
            $this->fields[(string) $name] = $field;
        }

        return DomainDefinition::from($this->fields);
    }

    /**
     * Start the policy for a single field.
     */
    public function field(): FieldRules
    {
        return new FieldRules();
    }
}
