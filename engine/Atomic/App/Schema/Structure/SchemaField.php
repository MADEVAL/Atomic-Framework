<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Structure;
if (!defined('ATOMIC_START')) exit;

/** A live column descriptor; validation policy is merged separately. */
final class SchemaField
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly array $metadata = [],
    ) {}
}
