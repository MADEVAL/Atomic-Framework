<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Definition;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Schema\Domain\DomainDefinition;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\Structure\LiveSchemaSource;

/** Models supply policy; the database supplies structure. */
final class DomainStrategy
{
    public static function contributes(string $class_name): bool
    {
        $ref = new \ReflectionClass($class_name);

        return $ref->hasMethod('domain_schema')
            && $ref->getMethod('domain_schema')->getDeclaringClass()->getName() === $class_name;
    }

    public static function collect(string $class_name, LiveSchemaSource $source, ?string $model_class = null, ?string $table = null): array
    {
        $definition = self::definition($class_name);
        if ($definition === null) {
            throw new \LogicException(sprintf(
                '%s does not declare its own domain_schema() method.',
                $class_name
            ));
        }

        $model_class ??= $class_name;
        $table ??= self::table_of($model_class);
        $defaults = (new \ReflectionClass($model_class))->getDefaultProperties();
        $ttl = !empty($defaults['fluid']) ? 0 : (int) ($defaults['ttl'] ?: 60);
        $structure = $source->for_table($table, $ttl);

        return StructurePolicyMerger::merge($structure, $definition, $model_class);
    }

    public static function definition(string $class_name): ?DomainDefinition
    {
        if (!self::contributes($class_name)) {
            return null;
        }

        $ref = new \ReflectionClass($class_name);
        $method = $ref->getMethod('domain_schema');
        $result = $method->invoke(null, DomainSchema::create());

        if (!$result instanceof DomainDefinition) {
            throw new \LogicException(sprintf(
                '%s::domain_schema() must return the DomainDefinition produced by $schema->define([...]), %s given.',
                $class_name,
                get_debug_type($result)
            ));
        }

        return $result;
    }

    /** Resolve the effective inherited table declaration and Atomic prefix. */
    public static function table_of(string $class_name, ?string $table = null): string
    {
        $table ??= (new \ReflectionClass($class_name))->getDefaultProperties()['table'] ?? null;
        if ($table === null || $table === '') {
            $table = strtolower((new \ReflectionClass($class_name))->getShortName());
        }
        $prefix = (string) \Engine\Atomic\Core\App::instance()->get('DB_CONFIG.prefix');
        return $prefix !== '' && !str_starts_with($table, $prefix) ? $prefix . $table : $table;
    }
}
