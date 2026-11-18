<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\MigrationEntry;
use Engine\Atomic\Telemetry\Data\MigrationsReport;
use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, MigrationsReport $report, Links $links): void {
    $entries = $report->entries;
    $pending = count($report->pending());
    $attention = count(array_filter($entries, static fn (MigrationEntry $entry): bool => $entry->needs_attention()));
    $batches = count(array_unique(array_filter(array_map(static fn (MigrationEntry $entry): ?string => $entry->batch_uuid, $entries))));
    $minutes = static fn (?string $time): string => $time === null ? '' : substr($time, 0, 16); // "2026-11-18 03:20"
    $view_file = static fn (MigrationEntry $entry): array => $ui->modal_attrs($links->migration_source($entry));

    $groups = [];
    foreach ($entries as $entry) {
        $key = $entry->is_pending() ? 'pending' : ($entry->batch_uuid ?? 'legacy');
        $groups[$key] ??= ['key' => $key, 'at' => $entry->applied_at, 'rows' => []];
        $groups[$key]['rows'][] = $entry;
    }
    usort($groups, static fn (array $a, array $b): int => match (true) {
        $a['key'] === 'pending' => -1,
        $b['key'] === 'pending' => 1,
        default => strcmp((string)$b['at'], (string)$a['at']),
    });

    $group_head = static function (array $group) use ($ui, $minutes): Html {
        [$title, $when] = match ($group['key']) {
            'pending' => ['Pending', 'not applied yet'],
            'legacy' => ['Legacy (no batch)', $group['at'] ? $minutes($group['at']) . ' UTC' : 'recorded before batches'],
            default => ['Batch ' . substr($group['key'], 0, 8), $group['at'] ? $minutes($group['at']) . ' UTC' : 'recorded before batches'],
        };

        return $ui->group($group['key'], $title, $when, count($group['rows']));
    };

    $drawer = static fn (MigrationEntry $entry): Html => Html::join(
        $ui->head(
            title: $entry->name,
            kicker: Html::join($ui->status($entry->status()), Html::el('b', [], $entry->is_pending() ? 'Pending' : 'Applied')),
            sub: $entry->source,
            mono: true,
        ),
        $ui->facts(
            $ui->fact('Integrity', Html::el('span', ['class' => $entry->integrity->tone() === Tone::Bad ? 'danger' : null],
                $entry->is_pending() ? 'Not applied' : $entry->integrity->label())),
            $ui->fact('Applied at', $entry->applied_at !== null ? $entry->applied_at . ' UTC' : '-'),
            $ui->fact('File', $entry->file ?? '(unavailable)', wide: true, mono: true, copy: $entry->file, copy_title: 'Copy file name'),
            $entry->batch_uuid === null ? null
                : $ui->fact('Batch', $entry->batch_uuid, wide: true, mono: true, copy: $entry->batch_uuid, copy_title: 'Copy batch UUID'),
        ),
        $entry->integrity->help() === null ? null : $ui->p($entry->integrity->help()),
        $entry->file !== null
            ? Html::el('div', ['class' => 'block'], $ui->button('View migration file', 'eye', $view_file($entry)))
            : $ui->p('The migration file is not available.'),
    );

    $row = static fn (MigrationEntry $entry, string $group): Html => $ui->row(
        id: $entry->source . '|' . $entry->name,
        cells: [
            $ui->cell('col-st', $ui->status($entry->status())),
            $ui->main($entry->name, $entry->source, mono: true, title_class: 'mig-name'),
            Html::el('span', ['class' => 'mig-int', 'data-tone' => $entry->integrity->tone()->value ?: null],
                $entry->is_pending() ? '' : $entry->integrity->label()),
            Html::el('span', ['class' => 'mig-at muted'], $minutes($entry->applied_at)),
            $ui->cell('col-act mig-act', $entry->file === null ? null : $ui->icon_button('eye', 'View migration file', $view_file($entry))),
        ],
        filters: [$entry->applied ? 'applied' : 'pending', $entry->needs_attention() ? 'attention' : null],
        search: [$entry->name, $entry->source],
        drawer: $drawer($entry),
        group: $group,
    );

    $rows = [];
    foreach ($groups as $group) {
        $rows[] = $group_head($group);
        foreach ($group['rows'] as $entry) {
            $rows[] = $row($entry, $group['key']);
        }
    }
    $applied = count($entries) - $pending;

    echo $ui->badge('m-badge', $pending);
    echo $ui->list_page(
        summary: $ui->summary(count($entries) . ' migrations',
            $pending ? $ui->part($pending . ' pending') : null,
            $attention ? $ui->part($ui->danger($attention . ' need attention')) : null,
            $ui->part($batches . ' batches'),
        ),
        extras: $report->legacy_mode ? $ui->note(Html::join(
            Html::el('b', [], 'Migration history is in legacy mode.'),
            ' The ledger still uses nullable source and checksum columns.',
        )) : null,
        pills: [
            new Pill('all', 'All', count($entries)),
            new Pill('applied', 'Applied', $applied),
            new Pill('pending', 'Pending', $pending),
            new Pill('attention', 'Needs attention', $attention, hot: true),
        ],
        search_placeholder: 'Search migrations',
        columns: [['col-st', ''], ['col-main', 'Migration'], ['mig-int', 'Integrity'], ['mig-at', 'Applied'], ['col-act mig-act', '']],
        rows: $rows,
        empty: 'No migrations match.',
        foot: null,
    );
};
