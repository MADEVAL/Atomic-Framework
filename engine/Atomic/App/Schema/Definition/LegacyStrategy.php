<?php
declare(strict_types=1);
namespace Engine\Atomic\App\Schema\Definition;

if (!defined('ATOMIC_START')) exit;

/**
 * Backward-compatible definition source: the classic $fieldConf array.
 * Behaviour is unchanged from before the split; this class exists so the
 * new domain path never touches it.
 */
final class LegacyStrategy
{
    public static function contributes(string $class_name): bool
    {
        $ref = new \ReflectionClass($class_name);

        return $ref->hasProperty('fieldConf')
            && $ref->getProperty('fieldConf')->getDeclaringClass()->getName() === $class_name;
    }

    public static function collect(string $class_name): array
    {
        $ref = new \ReflectionClass($class_name);
        $merged = [];

        if ($ref->hasProperty('fieldConf')
            && $ref->getProperty('fieldConf')->getDeclaringClass()->getName() === $class_name) {
            $conf = ($ref->getDefaultProperties()['fieldConf'] ?? null);
            if (is_array($conf) && $conf !== []) {
                $merged = array_replace_recursive($merged, $conf);
            }
        }

        return $merged;
    }
}
