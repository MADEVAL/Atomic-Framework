<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Domain;

if (!defined('ATOMIC_START')) exit;

/**
 * Immutable result of DomainSchema::define() — field name to policy map.
 */
final class DomainDefinition
{
    /** @param array<string, FieldRules> $fields */
    private function __construct(private array $fields) {}

    /** @param array<string, FieldRules> $fields */
    public static function from(array $fields): self
    {
        foreach ($fields as $name => $field) {
            if (!$field instanceof FieldRules) {
                throw new \InvalidArgumentException(sprintf(
                    'Field "%s" must be a FieldRules builder (use $schema->field()), %s given.',
                    (string) $name,
                    get_debug_type($field)
                ));
            }
        }

        return new self($fields);
    }

    /** @return array<string, FieldRules> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function field(string $field): ?FieldRules
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * Merge another definition on top of this one (child overrides parent).
     */
    public function merge(self $child): self
    {
        return new self(array_replace_recursive($this->fields, $child->fields));
    }
}
