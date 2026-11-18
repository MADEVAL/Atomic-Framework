<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class EnvGroup
{
    private const TITLES = ['php' => 'PHP', 'f3' => 'Fat-Free Framework', 'atomic' => 'Atomic', 'db' => 'Database', 'system' => 'System'];

    public const ORDER = ['php', 'f3', 'atomic', 'db', 'system'];

    /** @param list<EnvItem> $items */
    public function __construct(
        public readonly string $key,
        public readonly array $items,
    ) {}

    public function title(): string
    {
        return self::TITLES[$this->key] ?? $this->key;
    }

    /** @return array<string, mixed> */
    public function to_array(): array
    {
        $out = [];
        foreach ($this->items as $item) {
            $out[$item->key] = $item->raw();
        }

        return $out;
    }

    public function text(): string
    {
        return $this->title() . "\n" . implode("\n", array_map(
            static fn (EnvItem $item): string => '  ' . $item->label() . ': ' . $item->text(),
            $this->items,
        ));
    }
}
