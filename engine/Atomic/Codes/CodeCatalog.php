<?php
declare(strict_types=1);
namespace Engine\Atomic\Codes;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Lang\I18n;

final class CodeCatalog
{
    private static array $cache = [];

    public function __construct(
        public readonly string $codes_class = Code::class,
        public readonly string $prefix = 'errors.codes.',
        private readonly ?I18n $i18n = null,
    ) {
        if (!class_exists($codes_class) && !interface_exists($codes_class)) {
            throw new \InvalidArgumentException('Codes class not found: ' . $codes_class);
        }
    }

    public function code_name(string $code): ?string
    {
        return $this->catalog()['names'][$code] ?? null;
    }

    public function duplicate_codes(): array
    {
        return $this->catalog()['duplicates'];
    }

    public function translation_key(string $code): string
    {
        $name = $this->code_name($code);
        return $this->prefix . ($name !== null ? strtolower($name) : 'server_error');
    }

    public function message(string $code, array $vars = [], ?string $lang = null): string
    {
        return ($this->i18n ?? I18n::instance())->t($this->translation_key($code), $vars, null, $lang);
    }

    private function catalog(): array
    {
        $class = $this->codes_class;
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $names = [];
        $all = [];
        foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $value = (string) $value;
            $names[$value] ??= $constant->getName();
            $all[$value][] = $constant->getName();
        }

        return self::$cache[$class] = [
            'names' => $names,
            'duplicates' => array_filter($all, static fn(array $list): bool => count($list) > 1),
        ];
    }
}
