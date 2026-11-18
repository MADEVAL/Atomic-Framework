<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class HiveNode
{
    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly int $depth,
        public readonly ?string $size = null,
        public readonly ?string $value = null,
        public readonly bool $is_string = false,
    ) {}

    public static function branch(string $key, string $path, int $depth, bool $is_list, int $count): self
    {
        return new self($key, $path, $depth, $is_list ? '[' . $count . ']' : '{' . $count . '}');
    }

    public static function leaf(string $key, string $path, int $depth, string|int|float|bool|null $value): self
    {
        return new self($key, $path, $depth, value: self::text($value), is_string: is_string($value));
    }

    public function is_branch(): bool
    {
        return $this->size !== null;
    }

    public function shown_value(): string
    {
        return $this->is_string ? '"' . $this->value . '"' : (string)$this->value;
    }

    private static function text(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string)(int)$value,
            is_float($value) => (string)json_encode($value),
            default => (string)$value,
        };
    }
}
