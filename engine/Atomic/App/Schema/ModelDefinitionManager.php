<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Schema\Definition\DomainStrategy;
use Engine\Atomic\App\Schema\Definition\FieldConfLinter;
use Engine\Atomic\App\Schema\Definition\LegacyStrategy;
use Engine\Atomic\App\Schema\Structure\LiveSchemaSource;

/**
 * Coordinates the two fully separate definition sources.
 *
 * For each class in a model's hierarchy it picks exactly one strategy —
 * legacy ($fieldConf) or domain (domain_schema() + live database structure) — collects that class's contribution and merges parent-first.
 * Neither strategy knows about the other.
 */
final class ModelDefinitionManager
{
    private static ?self $instance = null;

    /** @var array<class-string, array<string, array<string, mixed>>> */
    private array $collected = [];

    /** @var array<class-string, array> */
    private array $resolved = [];

    /** @var array<class-string, string[]> */
    private array $problems = [];

    public function resolve_configuration(string $class_name, callable $factory): array
    {
        return $this->resolved[$class_name] ??= $factory();
    }

    private function __construct(private readonly LiveSchemaSource $source) {}

    public static function instance(): self
    {
        return self::$instance ??= new self(new LiveSchemaSource());
    }

    public static function swap(?self $manager): void
    {
        self::$instance?->source->invalidate();
        $manager?->source->invalidate();
        if ($manager !== null) {
            $manager->collected = [];
            $manager->resolved = [];
            $manager->problems = [];
        }
        \DB\Cortex::$schema_cache = [];
        self::$instance = $manager;
    }

    public static function reset(): void
    {
        self::$instance?->source->invalidate();
        \DB\Cortex::$schema_cache = [];
        self::$instance = null;
    }

    public static function with(LiveSchemaSource $source): self
    {
        return new self($source);
    }

    public function source(): LiveSchemaSource
    {
        return $this->source;
    }

    /**
     * Merged field configuration for a model class.
     *
     * @return array<string, array<string, mixed>>
     */
    public function collect(string $class_name, ?string $table = null): array
    {
        $key = $class_name;
        if ($this->uses_domain_schema($class_name)) {
            $key .= '::' . ($table ?? DomainStrategy::table_of($class_name));
        }
        if (isset($this->collected[$key])) {
            return $this->collected[$key];
        }

        $chain = array_reverse(class_parents($class_name));
        $chain[] = $class_name;

        $merged = [];
        $problems = [];
        foreach ($chain as $class) {
            if (DomainStrategy::contributes($class)) {
                if (LegacyStrategy::contributes($class)) {
                    throw new \LogicException(sprintf(
                        '%s declares both fieldConf and domain_schema(). Use one definition style per model.',
                        $class
                    ));
                }
                $merged = array_replace_recursive($merged, DomainStrategy::collect($class, $this->source, $class_name, $table));
                $problems = array_replace($problems, \Engine\Atomic\App\Schema\Definition\StructurePolicyMerger::skipped($class_name));
            } elseif (LegacyStrategy::contributes($class)) {
                $merged = array_replace_recursive($merged, LegacyStrategy::collect($class));
            }
        }

        $problems = array_diff_key($problems, $merged);
        \Engine\Atomic\App\Schema\Definition\StructurePolicyMerger::warn_skipped($class_name, $problems);
        $this->problems[$class_name] = array_values($problems);
        $this->collected[$key] = $merged;
        return $merged;
    }

    /**
     * @return string[]
     */
    public function lint(string $class_name): array
    {
        try {
            $conf = $this->collect($class_name);
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }

        return array_merge(FieldConfLinter::lint($conf), $this->problems[$class_name] ?? []);
    }

    public function uses_domain_schema(string $class_name): bool
    {
        $chain = array_reverse(class_parents($class_name));
        $chain[] = $class_name;

        foreach ($chain as $class) {
            if (DomainStrategy::contributes($class)) {
                return true;
            }
        }

        return false;
    }
}
