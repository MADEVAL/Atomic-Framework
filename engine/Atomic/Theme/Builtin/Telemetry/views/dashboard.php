<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\EnvGroup;
use Engine\Atomic\Telemetry\Data\EnvItem;
use Engine\Atomic\Telemetry\Data\Environment;
use Engine\Atomic\Telemetry\Data\JobState;
use Engine\Atomic\Telemetry\Data\Status;
use Engine\Atomic\Telemetry\Data\Summary;
use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, Summary $summary, Environment $environment): void {
    $totals = $summary->queues;
    $failed = $totals->count(JobState::Failed);
    $to_queues = static fn (JobState $state): array => ['data-goto' => 'queues', 'data-goto-filter' => $state->value];

    echo Html::el('div', ['class' => 'dash-hero'],
        Html::el('div', [],
            $ui->label('Queue states'),
            Html::el('div', ['class' => ['dash-big', $failed ? 'is-bad' : null]], $failed . ' failed jobs'),
            Html::el('div', ['class' => 'muted'], $totals->total() . ' jobs · ' . $totals->count(JobState::Pending) . ' waiting · '
                . $totals->count(JobState::Running) . ' running')),
        Html::el('div', ['class' => 'dash-stackwrap'],
            Html::el('div', ['class' => 'dash-stack'], array_map(static fn (JobState $state): Html => Html::el('button', [
                'class' => 'dash-seg st-' . $state->value,
                'vars' => ['n' => $totals->count($state)],
                'title' => $state->label() . ': ' . $totals->count($state),
            ] + $to_queues($state)), array_values(array_filter(JobState::in_flow(), static fn (JobState $state): bool => $totals->count($state) > 0)))),
            $ui->legend(array_map(static fn (JobState $state): array
                => [$state->value, $state->label(), $totals->count($state), $to_queues($state)], JobState::in_flow()))));

    $attention = array_filter([
        ['queues', 'x', 'Failed jobs', $failed],
        ['scheduled', 'x', 'Scheduled tasks whose last run failed', $summary->failing_tasks],
        ['exceptions', 'alert', 'New exceptions today', $summary->new_exceptions_today],
        ['plugins', 'alert', 'Plugins with problems', $summary->plugin_problems],
        ['migrations', 'clock', 'Pending migrations', $summary->pending_migrations],
        ['migrations', 'alert', 'Migrations needing attention', $summary->migrations_needing_attention],
        ['mutex', 'alert', 'Expired locks not released', $summary->expired_locks],
        ['security', 'ban', 'Locked accounts', $summary->locked_accounts],
        ['ratelimit', 'ban', 'Rate-limited keys', $summary->limited_keys],
    ], static fn (array $item): bool => $item[3] > 0);
    $tone = static fn (string $icon): Tone => match ($icon) {
        'x' => Tone::Bad,
        'clock' => Tone::Mute,
        default => Tone::Warn,
    };

    echo $ui->label('Needs attention');
    echo $attention
        ? Html::el('div', ['class' => 'dash-attn'], array_map(static fn (array $item): Html => Html::el('button', ['class' => 'dash-attn-row', 'data-goto' => $item[0]],
            $ui->status(new Status($item[1], $tone($item[1]), '')),
            Html::el('span', [], $item[2]),
            Html::el('b', [], $item[3]),
            $ui->icon('chevron')), $attention))
        : Html::el('div', ['class' => 'muted'], 'Nothing needs attention.');

    $lists = [];
    foreach ($environment->shown() as $group) {
        foreach ($group->items as $item) {
            if ($item->list !== null) {
                $lists[] = [$group->title() . ' ' . strtolower($item->label()), $item->list];
            }
        }
    }
    $sorted = static function (array $values): array {
        usort($values, 'strcasecmp');
        return $values;
    };

    echo Html::el('div', ['class' => 'dash-env'],
        $ui->section_head('Environment', $ui->copy($environment->text(), 'Copy the environment as text, for bug reports')),
        Html::el('div', ['class' => 'dash-groups'], array_map(static fn (EnvGroup $group): Html => Html::el('div', ['class' => 'dash-group'],
            Html::el('h3', [], $group->title()),
            array_map(static fn (EnvItem $item): Html => $item->list !== null
                ? $ui->kv($item->label(), count($item->list) . ' loaded')
                : $ui->kv($item->label(), $item->text(), mono: true), $group->items)), $environment->shown())),
        array_map(static fn (array $list): Html => Html::el('div', ['class' => 'dash-list'],
            $ui->section_head($list[0] . ' · ' . count($list[1]), Html::join(
                $ui->button('Show all', attrs: ['data-expand' => true]),
                $ui->copy(implode(', ', $list[1]), 'Copy list'),
            )),
            Html::el('div', ['class' => 'chips is-clipped', 'data-clip' => true],
                array_map(static fn (string $value): Html => Html::el('span', ['class' => 'count'], $value), $sorted($list[1])))), $lists));
};
