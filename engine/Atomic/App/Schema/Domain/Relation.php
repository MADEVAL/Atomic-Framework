<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Domain;

if (!defined('ATOMIC_START')) exit;

/**
 * A Cortex relation declared in domain_schema(), recorded exactly as written.
 *
 * Nothing is validated or defaulted here: the builder does not know the model
 * or field it belongs to. RelationTranslator validates it against the live
 * structure and turns it into the legacy fieldConf keys.
 */
final class Relation
{
    public const BELONGS_TO_ONE = 'belongs-to-one';
    public const BELONGS_TO_MANY = 'belongs-to-many';
    public const HAS_ONE = 'has-one';
    public const HAS_MANY = 'has-many';

    public function __construct(
        public readonly string $kind,
        public readonly string $model,
        public readonly ?string $field,
    ) {}

    /** Virtual relations have no column of their own. */
    public function is_virtual(): bool
    {
        return $this->kind === self::HAS_ONE || $this->kind === self::HAS_MANY;
    }
}
