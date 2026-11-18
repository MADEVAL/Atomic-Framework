<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\ExceptionGroup;
use Engine\Atomic\Telemetry\Data\ExceptionsReport;
use Engine\Atomic\Telemetry\Data\Range;
use Engine\Atomic\Telemetry\Data\Status;
use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Highlight;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, ExceptionsReport $report, Range $range, Links $links): void {
    $groups = $report->groups;
    $count = static fn (callable $test): int => count(array_filter($groups, $test));
    $fresh = $count(static fn (ExceptionGroup $group): bool => $group->is_fresh());
    $occurrences = array_sum(array_map(static fn (ExceptionGroup $group): int => $group->count, $groups));

    $by_class = [];
    $by_status = [];
    foreach ($groups as $group) {
        $by_class[$group->short_class()] = ($by_class[$group->short_class()] ?? 0) + $group->count;
        $by_status[$group->code] = ($by_status[$group->code] ?? 0) + $group->count;
    }
    ksort($by_status); // equal counts list the lower status first
    $status_labels = [];
    foreach ($by_status as $code => $total) {
        $status_labels[$code === 0 ? 'no HTTP status' : $code] = $total;
    }

    $overview = Html::join(
        $ui->lifetime($report->lifetime),
        Html::el('div', ['class' => 'overview'],
            Html::el('div', [], $ui->label('Occurrences, ' . $range->text()), $ui->chart($report->occurrences(), $range)),
            Html::el('div', [], $ui->label('By class'), $ui->hbars($by_class)),
            Html::el('div', [], $ui->label('By HTTP status'), $ui->hbars($status_labels))),
    );

    $drawer = static function (ExceptionGroup $group) use ($ui, $range, $links): Html {
        $where = $group->where();

        return Html::join(
            $ui->head(
                title: $group->message,
                kicker: Html::el('span', ['class' => 'mono break'], $group->class),
                actions: $ui->copy($group->report(), 'Copy exception report (class, message, location, trace)'),
            ),
            $ui->facts(
                $ui->fact('Location', $where ?? $ui->muted('Not recorded'), wide: true, mono: $where !== null, copy: $where, copy_title: 'Copy file and line'),
                $ui->fact('Total', Fmt::n($group->total)),
                $ui->fact('First seen', Fmt::age($group->first_seen_h * 3600) . ' ago'),
                $ui->fact('Level', $group->level),
                $ui->fact('HTTP status', $group->code ? (string)$group->code : $ui->muted('None')),
                $group->path === '' ? null : $ui->fact('Last request', $group->path, wide: true, mono: true),
                $group->dump_id === null ? null : $ui->fact('Hive dump', $group->dump_id, wide: true, mono: true, copy: $group->dump_id, copy_title: 'Copy dump id'),
            ),
            $group->dump_id === null ? null : Html::el('div', ['class' => 'block'],
                $ui->button('View hive dump', 'eye', $ui->modal_attrs($links->part('dump', ['id' => $group->dump_id])))),
            $ui->label('Occurrences, ' . $range->text() . ' · ' . $group->count),
            Html::el('div', ['class' => 'block'], $ui->chart($group->usage, $range, height: 90)),
            $group->trace !== ''
                ? $ui->code('Stack trace', $group->trace, wrap: true, tall: true, highlighted: Highlight::trace($group->trace))
                : $ui->p('No stack trace was recorded.'),
        );
    };

    $row = static fn (ExceptionGroup $group): Html => $ui->row(
        id: $group->id,
        cells: [
            $ui->cell('col-st', $ui->status(new Status(
                $group->is_server() ? 'circle-x' : 'alert',
                $group->is_severe() ? Tone::Bad : Tone::Warn,
                (string)$group->code,
            ))),
            $ui->main($group->short_class(), $group->message),
            $ui->cell('exc-sp', $ui->spark($group->usage)),
            $ui->cell('exc-n mono', $group->count),
            $ui->cell('exc-seen cell', $group->last_seen()),
        ],
        filters: [
            $group->is_server() ? 'server' : null,
            $group->is_client() ? 'client' : null,
            $group->is_fresh() ? 'fresh' : null,
        ],
        search: [$group->class, $group->message, $group->file],
        drawer: $drawer($group),
    );

    echo $ui->list_page(
        summary: $ui->summary(count($groups) . ' distinct exceptions',
            $ui->part($occurrences . ' occurrences, ' . $range->text()),
            $ui->counted_part('fresh', $fresh, 'first seen today'),
        ),
        extras: $overview,
        pills: [
            new Pill('all', 'All', count($groups)),
            new Pill('server', '5xx', $count(static fn (ExceptionGroup $group): bool => $group->is_server())),
            new Pill('client', '4xx', $count(static fn (ExceptionGroup $group): bool => $group->is_client())),
            new Pill('fresh', 'New today', $fresh),
        ],
        search_placeholder: 'Search exceptions',
        columns: [['col-st', ''], ['col-main', 'Exception'], ['exc-sp', 'Trend'], ['exc-n', 'Count'], ['exc-seen', 'Last seen']],
        rows: array_map($row, $groups),
        empty: 'No exceptions match.',
        foot: Html::join('Showing ', $ui->visible_count(count($groups)), ' of ' . count($groups) . ' exceptions'),
    );
};
