<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\DatabaseSchema;
use Engine\Atomic\Telemetry\Data\DbColumn;
use Engine\Atomic\Telemetry\Data\DbDiagram;
use Engine\Atomic\Telemetry\Data\DbForeignKey;
use Engine\Atomic\Telemetry\Data\DbIndex;
use Engine\Atomic\Telemetry\Data\DbTable;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, DatabaseSchema $schema): void {
    if (!$schema->available) {
        echo $ui->empty($schema->reason ?? 'The schema is not available.');
        return;
    }

    $plural = static fn (int $count, string $word): string => $count . ' ' . $word . ($count === 1 ? '' : 's');
    // The app prefix is drawn muted, so the meaningful part of the name stands out.
    $name = static fn (string $table): string|Html => $schema->in_app($table)
        ? Html::join($ui->muted($schema->prefix), $schema->short_name($table))
        : $table;
    $scopes = $schema->prefix !== '' ? ['app' => $schema->app_tables(), 'all' => $schema->tables] : ['all' => $schema->tables];
    // Summary and footer follow the scope pill: one copy per scope, only the open one shows.
    $when = static fn (string $scope): array => count($scopes) > 1
        ? ['class' => $scope === array_key_first($scopes) ? null : 'hide', 'data-when' => $scope]
        : [];
    $per_scope = static fn (callable $render): Html => Html::join(array_map($render, $scopes, array_keys($scopes)));

    $summary = $per_scope(static function (array $tables, string $scope) use ($ui, $schema, $plural, $when): Html {
        $keys = array_sum(array_map(static fn (DbTable $table): int => count($table->references), $tables));
        $size = array_sum(array_map(static fn (DbTable $table): int => $table->size, $tables));
        $attrs = $when($scope);

        return Html::el('div', ['class' => ['summary', $attrs['class'] ?? null]] + $attrs,
            Html::el('b', [], count($tables) . ' tables'),
            Html::el('span', ['class' => 'muted'],
                $ui->mono((string)$schema->database),
                $schema->host === null ? null : Html::join(' on ', $ui->mono($schema->host)),
                ' · ' . Fmt::bytes($size) . ' · ' . $plural($keys, 'foreign key') . ' · read-only'));
    });

    $diagram = static function (DbTable $table) use ($ui, $schema): Html {
        $diagram = DbDiagram::of($table);
        $box = static fn (string $edge) => static fn (array $columns, string $other): Html => Html::el('div', ['class' => 'db-box', 'title' => $other, 'data-select' => $other],
            Html::el('b', [], $schema->short_name($other)),
            array_map(static fn (string $column): Html => Html::el('div', ['data-anchor' => $edge . '|' . $other . '|' . $column], Html::el('span', [], $column)), $columns));

        return Html::el('div', ['class' => 'db-erd', 'data-links' => json_encode($diagram->links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            Html::el('svg', ['aria-hidden' => 'true']),
            Html::el('div', ['class' => 'db-erd-col'], array_map($box('L'), $diagram->left, array_keys($diagram->left))),
            Html::el('div', ['class' => 'db-erd-col'], Html::el('div', ['class' => 'db-box is-self', 'title' => $table->name],
                Html::el('b', [], $schema->short_name($table->name)),
                array_map(static fn (array $shown): Html => Html::el('div', ['data-anchor' => 'C|' . $shown[0]->name],
                    Html::el('span', [], $shown[0]->name), Html::el('small', [], $shown[1])), $diagram->shown),
                $diagram->hidden ? Html::el('div', ['title' => implode(', ', $diagram->hidden)],
                    Html::el('small', [], '+ ' . count($diagram->hidden) . ' other column' . (count($diagram->hidden) === 1 ? '' : 's') . ' (listed below)')) : null)),
            Html::el('div', ['class' => 'db-erd-col'], array_map($box('R'), $diagram->right, array_keys($diagram->right))));
    };

    $tag = static fn (string $text): Html => Html::el('span', ['class' => 'db-tag'], $text);
    $relation = static fn (array $columns, string $arrow, string $other, array $other_columns, DbForeignKey $key): Html => $ui->kv(
        implode(', ', $columns) . ' ' . $arrow,
        Html::join(
            $ui->button($other, attrs: ['title' => 'Open ' . $other, 'data-select' => $other]),
            ' ',
            Html::el('span', ['class' => 'mono muted'], '(' . implode(', ', $other_columns) . ') · on delete ' . strtolower($key->on_delete))),
        key_mono: true,
    );

    $drawer = static fn (DbTable $table): Html => Html::join(
        $ui->head(
            title: $table->name,
            kicker: 'Table',
            sub: implode(' · ', array_filter([$table->engine, $table->collation, $table->comment])) ?: null,
            mono: true,
            actions: $ui->copy($table->schema_text(), 'Copy schema as text'),
        ),
        $ui->facts(
            $ui->fact('Rows (estimate)', $table->rows !== null ? Fmt::n($table->rows) : '-'),
            $ui->fact('Size', Fmt::bytes($table->size)),
            $ui->fact('Columns', (string)count($table->columns)),
            $ui->fact('Indexes', (string)count($table->indexes)),
        ),
        $ui->label('Relations'),
        $table->has_relations()
            ? Html::join(
                $diagram($table),
                $ui->kv_list(
                    ...array_map(static fn (DbForeignKey $key): Html => $relation($key->columns, '→', $key->ref_table, $key->ref_columns, $key), $table->references),
                    ...array_map(static fn (DbForeignKey $key): Html => $relation($key->ref_columns, '←', $key->table, $key->columns, $key), $table->referenced_by),
                ))
            : $ui->p('No foreign keys point to or from this table. Any relations are enforced in application code, not in the database.'),
        $ui->label('Columns'),
        $ui->kv_list(...array_map(static fn (DbColumn $column): Html => $ui->kv(
            Html::join(Html::el('span', ['class' => 'fg'], $column->name), $column->key_tag() === null ? null : Html::join(' ', $tag($column->key_tag()))),
            Html::join($column->type, $ui->muted($column->notes())),
            key_mono: true,
            mono: true,
        ), $table->columns)),
        $table->indexes ? Html::join(
            $ui->label('Indexes'),
            $ui->kv_list(...array_map(static fn (DbIndex $index): Html => $ui->kv(
                Html::join($index->name, $index->unique ? Html::join(' ', $tag('unique')) : null),
                implode(', ', $index->columns),
                key_mono: true,
                mono: true,
            ), $table->indexes)),
        ) : null,
    );

    $row = static function (DbTable $table) use ($ui, $schema, $name, $drawer): Html {
        $outgoing = count($table->references);
        $incoming = count($table->referenced_by);
        $links = implode(' · ', array_filter([$outgoing ? '→ ' . $outgoing : null, $incoming ? '← ' . $incoming : null]));
        $details = count($table->columns) . ' columns · ' . count($table->indexes) . ' indexes' . ($table->comment !== '' ? ' · ' . $table->comment : '');

        return $ui->row(
            id: $table->name,
            cells: [
                $ui->main(Html::join($name($table->name), $table->framework ? $ui->tag('Framework') : null), $details, mono: true),
                $ui->cell('db-links cell', $links, title: 'Foreign keys out → / in ←'),
                $ui->cell('db-rows', $table->rows !== null ? '~' . Fmt::n($table->rows) : '-', title: 'Estimate from information_schema'),
                $ui->cell('db-size muted', Fmt::bytes($table->size)),
            ],
            filters: [$schema->in_app($table->name) ? 'app' : null],
            search: [$table->name, ...array_map(static fn (DbColumn $column): string => $column->name, $table->columns)],
            drawer: $drawer($table),
        );
    };

    echo $ui->list_page(
        summary: $summary,
        pills: $schema->prefix === '' ? [] : [
            new Pill('app', 'Prefix ' . $schema->prefix, count($scopes['app']), fixed: true),
            new Pill('all', 'Whole database', count($schema->tables), fixed: true),
        ],
        search_placeholder: 'Search tables and columns',
        columns: [['col-main', 'Table'], ['db-links', 'Links'], ['db-rows', 'Rows'], ['db-size', 'Size']],
        rows: array_map($row, $schema->tables),
        empty: 'No tables match.',
        foot: Html::join('Showing ', $ui->visible_count(count(reset($scopes))), $per_scope(static fn (array $tables, string $scope): Html
            => Html::el('span', $when($scope), ' of ' . count($tables) . ' tables · row counts are estimates'))),
        view: $schema->prefix === '' ? null : 'app',
    );
};
