<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class DbColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly ?string $default,
        public readonly string $key,
        public readonly string $extra,
    ) {}

    /** @param array<string, mixed> $column */
    public static function from_array(array $column): self
    {
        return new self(
            name: (string)($column['name'] ?? ''),
            type: (string)($column['type'] ?? ''),
            nullable: (bool)($column['nullable'] ?? false),
            default: isset($column['default']) ? (string)$column['default'] : null,
            key: (string)($column['key'] ?? ''),
            extra: (string)($column['extra'] ?? ''),
        );
    }

    public function is_primary(): bool
    {
        return $this->key === 'PRI';
    }

    public function key_tag(): ?string
    {
        return match ($this->key) {
            'PRI' => 'PK',
            'UNI' => 'unique',
            'MUL' => 'indexed',
            default => null,
        };
    }

    public function notes(): string
    {
        return ($this->nullable ? ' · null' : '')
            . ($this->default !== null ? ' · default ' . $this->default : '')
            . ($this->extra !== '' ? ' · ' . $this->extra : '');
    }

    public function definition(): string
    {
        return $this->name . ' ' . $this->type
            . ($this->nullable ? ' NULL' : ' NOT NULL')
            . ($this->default !== null ? ' DEFAULT ' . $this->default : '')
            . ($this->extra !== '' ? ' ' . strtoupper($this->extra) : '');
    }
}
