<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\LogLine;
use Engine\Atomic\Telemetry\Data\LogPage;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, LogPage $page, Links $links): void {
    $count = static fn (string $level): int => count(array_filter($page->lines, static fn (LogLine $line): bool => $level === 'all' || $line->filter() === $level));
    $query = static fn (string $channel, string $date): string => http_build_query(['channel' => $channel, 'date' => $date]);
    $rail_item = static fn (string $label, bool $on, string $query, ?int $count = null, bool $hot = false): Html => Html::el('button', [
        'class' => ['log-item', $on ? 'is-on' : null],
        'data-tab-query' => $query,
    ],
        Html::el('span', [], $label),
        $count === null ? null : Html::el('span', ['class' => ['count', $hot && $count ? 'is-hot' : null]], $count));

    $rail = Html::el('aside', ['class' => 'log-rail'],
        $ui->label('Channels'),
        $rail_item('All channels', $page->is_all(), $query(LogPage::ALL, $page->date)),
        array_map(static fn (string $channel, int $lines): Html => $rail_item($channel, $page->channel === $channel, $query($channel, $page->date), $lines, $channel === 'error'),
            array_keys($page->channels), $page->channels),
        $page->dates ? Html::join(
            $ui->label('Date'),
            array_map(static fn (string $date): Html => $rail_item($date, $page->date === $date, $query($page->channel, $date)), $page->dates),
        ) : null);

    $seen = [];
    $lines = array_map(static function (LogLine $line) use ($ui, $links, &$seen): Html {
        $seen[$line->key()] = ($seen[$line->key()] ?? -1) + 1;
        return $ui->log_line($line, $links, $seen[$line->key()]);
    }, $page->lines);

    $status = match (true) {
        $page->has_more() => 'Scroll for older lines',
        $page->total > 0 => 'Start of log',
        default => '',
    };

    $main = Html::el('div', ['class' => ['log-main', $page->is_all() ? null : 'is-one-channel']],
        Html::el('div', ['class' => 'toolbar'],
            $ui->pills([
                new Pill('all', 'All levels', $count('all')),
                new Pill('error', 'Errors', $count('error'), hot: true),
                new Pill('warning', 'Warnings', $count('warning')),
                new Pill('info', 'Info', $count('info')),
                new Pill('debug', 'Debug', $count('debug')),
            ]),
            Html::el('div', ['class' => 'toolbar-end'],
                Html::el('input', ['class' => 'input', 'placeholder' => 'Search loaded lines', 'data-search' => true]),
                $ui->button('Wrap lines', attrs: ['data-wrap' => true]),
                $ui->icon_button('copy', 'Copy every loaded line that matches the filters, full messages', ['data-copy-shown' => true]))),
        Html::el('div', ['class' => 'log-head'],
            Html::el('span'), Html::el('span', [], 'Time'), Html::el('span'),
            Html::el('span', ['class' => 'log-ch'], 'Channel'), Html::el('span', [], 'Message'), Html::el('span')),
        Html::el('div', ['data-lines' => true],
            $lines,
            Html::el('div', ['class' => ['empty', $page->lines ? 'hide' : null], 'data-empty' => true], 'No log lines match.')),
        $page->has_more() ? $ui->log_more($links->part('log-lines', $page->next())) : null,
        Html::el('div', ['class' => 'list-foot log-foot'],
            Html::el('span', [], $page->total
                ? Html::join(Html::el('span', ['data-count' => '@loaded'], Fmt::n(count($page->lines))), ' of ' . Fmt::n($page->total) . ' lines loaded')
                : '0 lines'),
            Html::el('span', ['class' => 'muted', 'data-more-status' => true], $status)));

    echo Html::el('div', [
        'class' => 'log-layout',
        'data-list' => true,
        'data-inline' => true,
        'data-count-all' => true,
        'data-sort' => $page->is_all() ? 'ts' : null,
        'data-log-scope' => $page->scope(),
    ], $rail, $main);
};
