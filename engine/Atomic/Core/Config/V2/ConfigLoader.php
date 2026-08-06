<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Config\V2;

use Engine\Atomic\Core\Config\ConfigSchema;

final class ConfigLoader
{
    /** @var list<callable(): array<string, mixed>> */
    private array $sources = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function fromDefaults(): self
    {
        $this->sources[] = fn(): array => ConfigSchema::defaults();
        return $this;
    }

    public function fromEnvFile(string $path): self
    {
        $this->sources[] = function () use ($path): array {
            if (!file_exists($path)) {
                return [];
            }
            return self::parseEnvFile($path);
        };
        return $this;
    }

    public function fromArray(array $values): self
    {
        $this->sources[] = fn(): array => $values;
        return $this;
    }

    public function load(): Config
    {
        $values = [];

        foreach ($this->sources as $source) {
            $values = array_merge($values, $source());
        }

        $config = new Config($values, ConfigSchema::definitions());
        $config->validate();

        return $config;
    }

    /** @return array<string, string> */
    public static function parseEnvFile(string $path): array
    {
        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $values;
    }
}
