<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

/** Column rows are anchors ("L|table|column", "C|column", "R|table|column"); each link joins two anchors, left to right. */
final class DbDiagram
{
    private const SMALL_TABLE_COLUMNS = 8;

    /**
     * @param array<string, list<string>> $left table => its linked columns
     * @param array<string, list<string>> $right table => its linked columns
     * @param list<array{0:DbColumn, 1:string}> $shown middle columns and their tag ("PK", "FK", "↻ self", "")
     * @param list<string> $hidden middle columns left out of the box
     * @param list<array{0:string, 1:string}> $links anchor pairs
     */
    public function __construct(
        public readonly array $left,
        public readonly array $right,
        public readonly array $shown,
        public readonly array $hidden,
        public readonly array $links,
    ) {}

    public static function of(DbTable $table): self
    {
        $links = [];
        $side = static function (array $keys, string $edge, bool $incoming) use ($table, &$links): array {
            $boxes = [];
            foreach ($keys as $key) {
                $other = $incoming ? $key->table : $key->ref_table;
                if ($other === $table->name) {
                    continue;
                }
                $other_columns = $incoming ? $key->columns : $key->ref_columns;
                $own_columns = $incoming ? $key->ref_columns : $key->columns;
                $boxes[$other] ??= [];
                foreach ($other_columns as $i => $column) {
                    if (!in_array($column, $boxes[$other], true)) {
                        $boxes[$other][] = $column;
                    }
                    $outer = $edge . '|' . $other . '|' . $column;
                    $inner = 'C|' . ($own_columns[$i] ?? '');
                    $links[] = $incoming ? [$outer, $inner] : [$inner, $outer];
                }
            }

            return $boxes;
        };
        $left = $side($table->referenced_by, 'L', true);
        $right = $side($table->references, 'R', false);

        $self = [];
        foreach ($table->references as $key) {
            if ($key->ref_table === $table->name) {
                array_push($self, ...$key->columns);
            }
        }
        $keys = array_map(static fn (DbColumn $column): string => $column->name, array_filter($table->columns, static fn (DbColumn $column): bool => $column->is_primary()));
        foreach ($links as $link) {
            foreach ($link as $anchor) {
                if (str_starts_with($anchor, 'C|')) {
                    $keys[] = substr($anchor, 2);
                }
            }
        }
        $keys = [...$keys, ...$self];

        $small = count($table->columns) <= self::SMALL_TABLE_COLUMNS;
        $shown = [];
        $hidden = [];
        foreach ($table->columns as $column) {
            if (!$small && !in_array($column->name, $keys, true)) {
                $hidden[] = $column->name;
                continue;
            }
            $shown[] = [$column, match (true) {
                $column->is_primary() => 'PK',
                in_array($column->name, $self, true) => '↻ self',
                in_array($column->name, $keys, true) => 'FK',
                default => '',
            }];
        }

        return new self($left, $right, $shown, $hidden, $links);
    }
}
