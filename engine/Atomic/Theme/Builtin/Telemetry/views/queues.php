<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\Job;
use Engine\Atomic\Telemetry\Data\JobState;
use Engine\Atomic\Telemetry\Data\Json;
use Engine\Atomic\Telemetry\Data\QueueTotals;
use Engine\Atomic\Telemetry\View\Highlight;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

/* $totals count every stored job; the list holds only the newest page. */
return static function (Ui $ui, QueueTotals $totals, Links $links, int $now, Job ...$jobs): void {
    $hours = 24;
    $axis_step = 6; // hour labels every 6 hours, plus the current hour
    $hour_seconds = 3600;
    $midnight = intdiv($now, 86400) * 86400;
    $current_hour = intdiv($now - $midnight, $hour_seconds);
    $pad = static fn (int $hour): string => str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
    // Completed cells darken with volume: 1 job is faint, 3 or more is full.
    $opacity = static fn (int $count): float => min(1, 0.4 + $count * 0.2);

    $today = array_values(array_filter($jobs, static fn (Job $job): bool => $job->created_at >= $midnight));
    $with_state = static fn (array $list, JobState $state): array => array_values(array_filter($list, static fn (Job $job): bool => $job->state === $state));
    $failed_today = count($with_state($today, JobState::Failed));

    $cell = static function (array $in_hour, int $hour) use ($with_state, $current_hour, $opacity, $pad): Html {
        if (!$in_hour) {
            return Html::el('i', ['class' => $hour > $current_hour ? 'is-future' : 'is-empty']);
        }
        $present = array_values(array_filter(JobState::by_severity(), static fn (JobState $state): bool => (bool)$with_state($in_hour, $state)));
        $worst = $present[0];
        $tip = implode(', ', array_map(static fn (JobState $state): string
            => count($with_state($in_hour, $state)) . ' ' . strtolower($state->label()), $present));

        return Html::el('i', [
            'class' => 'st-' . $worst->value,
            'vars' => ['o' => $worst === JobState::Completed ? $opacity(count($in_hour)) : null],
            'title' => $pad($hour) . ':00 · ' . $tip,
        ]);
    };
    $lane = static function (string $queue) use ($today, $cell, $hours, $midnight, $hour_seconds, $with_state): Html {
        $in_queue = array_values(array_filter($today, static fn (Job $job): bool => $job->queue === $queue));
        $failed = count($with_state($in_queue, JobState::Failed));
        $cells = [];
        for ($hour = 0; $hour < $hours; $hour++) {
            $cells[] = $cell(array_values(array_filter($in_queue, static fn (Job $job): bool
                => intdiv($job->created_at - $midnight, $hour_seconds) === $hour)), $hour);
        }

        return Html::el('div', ['class' => 'queue-lane', 'data-search-toggle' => $queue],
            Html::el('span', ['class' => 'n'], $queue),
            Html::el('span', ['class' => 'queue-cells'], $cells),
            Html::el('span', ['class' => 'tot'], count($in_queue)),
            Html::el('span', ['class' => 'bad'], $failed ? $failed . ' failed' : ''));
    };
    $axis = [];
    for ($hour = 0; $hour < $hours; $hour++) {
        $axis[] = Html::el('span', ['class' => $hour === $current_hour ? 'is-now' : null],
            $hour % $axis_step === 0 || $hour === $current_hour ? $pad($hour) : '');
    }
    $queues = array_values(array_unique(array_map(static fn (Job $job): string => $job->queue, $jobs)));
    sort($queues);
    $legend = array_values(array_filter(array_reverse(JobState::by_severity()), static fn (JobState $state): bool => (bool)$with_state($today, $state)));

    $lanes = Html::el('div', ['class' => 'queue-viz'],
        $ui->summary('Today, ' . gmdate('d M', $now),
            $ui->part(count($today) . ' jobs'),
            $failed_today ? $ui->part($ui->danger($failed_today . ' failed')) : null,
            $ui->part('one cell per hour, UTC')),
        Html::el('div', ['class' => 'queue-lanes'],
            Html::el('div', ['class' => 'queue-lane is-axis'],
                Html::el('span', ['class' => 'n']),
                Html::el('span', ['class' => 'queue-cells'], $axis),
                Html::el('span', ['class' => 'tot']),
                Html::el('span', ['class' => 'bad'])),
            array_map($lane, $queues)),
        $ui->legend(array_map(static fn (JobState $state): array
            => [$state->value, $state->label(), count($with_state($today, $state)), ['data-filter' => $state->value]], $legend)));

    $status = static fn (JobState $state): Html => $ui->status($state->status(), spin: $state === JobState::Running);
    $drawer = static fn (Job $job): Html => Html::join(
        $ui->head(
            title: $job->title(),
            kicker: Html::join($status($job->state), Html::el('b', [], $job->state->label())),
            actions: $ui->copy($job->report(), 'Copy job report (state, error, payload)'),
        ),
        $ui->facts(
            $ui->fact('UUID', $job->uuid, wide: true, mono: true, copy: $job->uuid, copy_title: 'Copy UUID'),
            $ui->fact('Queue', $job->queue),
            $ui->fact('Driver', $job->driver),
            $ui->fact('Attempts', $job->attempts . ' of ' . ($job->max_attempts ?? '-')),
            $ui->fact('Timeout', $job->timeout . ' s'),
            $ui->fact('Retry delay', $job->retry_delay . ' s'),
            $ui->fact('Created', $job->created),
            $job->cancel_requested_at === null ? null : $ui->fact('Cancel requested', $job->cancel_requested_at),
            $job->cancelled_at === null ? null : $ui->fact('Cancelled', $job->cancelled_at),
            $job->reason === null ? null : $ui->fact('Reason', $job->reason, wide: true),
        ),
        $job->error === null ? null : Html::join(
            $ui->error_box($job->error->message, $job->error->file ? $job->error->file . ':' . $job->error->line : null),
            $job->error->trace === null ? null
                : $ui->code('Stack trace', $job->error->text(), wrap: true, tall: true, highlighted: Highlight::trace($job->error->trace)),
        ),
        $ui->code('Payload', Json::pretty($job->payload), highlighted: Highlight::json($job->payload)),
        $ui->label('Timeline'),
        Html::el('div', ['data-load' => $links->part('job-events', ['driver' => $job->driver, 'uuid' => $job->uuid])], $ui->empty('Loading…')),
    );

    $row = static fn (Job $job): Html => $ui->row(
        id: $job->uuid,
        cells: [
            $ui->cell('col-st', $status($job->state)),
            $ui->main($job->title(), $job->error?->message ?? $job->uuid, sub_mono: $job->error === null, sub_bad: $job->error !== null),
            $ui->cell('queue-q', $job->queue),
            $ui->cell('queue-t', $job->attempts . '/' . ($job->max_attempts ?? '-')),
            $ui->cell('queue-age cell', $job->ago($now)),
        ],
        filters: [$job->state->value],
        search: [$job->uuid, $job->title(), $job->queue],
        drawer: $drawer($job),
    );

    // Pills show the server totals; with a search active they count the loaded jobs.
    $pill = static fn (JobState $state, string $label, bool $hot = false): Pill
        => new Pill($state->value, $label, count($with_state($jobs, $state)), $hot, $totals->count($state));

    echo $ui->badge('q-badge', $totals->count(JobState::Failed));
    echo $ui->list_page(
        extras: $lanes,
        pills: [
            new Pill('all', 'All jobs', count($jobs), total: $totals->total()),
            $pill(JobState::Failed, 'Failed', hot: true),
            $pill(JobState::Pending, 'Waiting'),
            $pill(JobState::Running, 'Running'),
            $pill(JobState::Completed, 'Completed'),
            $pill(JobState::CancelRequested, 'Cancel requested'),
            $pill(JobState::Cancelled, 'Cancelled'),
        ],
        search_placeholder: 'Search job or UUID',
        columns: [['col-st', ''], ['col-main', 'Job'], ['queue-q', 'Queue'], ['queue-t', 'Tries'], ['queue-age', 'Created']],
        rows: array_map($row, $jobs),
        empty: 'No jobs match these filters.',
        foot: Html::join('Showing ', $ui->visible_count(count($jobs)), ' of ' . count($jobs) . ' jobs'),
    );
};
