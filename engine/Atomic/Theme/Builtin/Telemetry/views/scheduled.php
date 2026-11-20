<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\Data\JobOutcome;
use Engine\Atomic\Telemetry\Data\JobRun;
use Engine\Atomic\Telemetry\Data\ScheduledJob;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Html;
use Engine\Atomic\Telemetry\View\Pill;
use Engine\Atomic\Telemetry\View\Ui;

return static function (Ui $ui, ScheduledJob ...$jobs): void {
    // Paused jobs filter as paused whatever their last run did.
    $filter_key = static fn (ScheduledJob $job): string => $job->enabled ? $job->last_outcome->key() : 'paused';
    $count = static fn (string $key): int
        => count(array_filter($jobs, static fn (ScheduledJob $job): bool => $filter_key($job) === $key));
    $failing = count(array_filter($jobs, static fn (ScheduledJob $job): bool => $job->last_outcome->is_failing()));

    $next_runs = [];
    foreach ($jobs as $job) {
        if ($job->enabled && $job->next_runs) {
            $next_runs[] = $job->next_runs[0];
        }
    }
    $next_run = $next_runs ? min($next_runs) : null;

    $run_line = static fn (JobRun $run): Html => Html::join(
        $ui->kv(
            Fmt::utc($run->started_at),
            Html::join(
                $run->status->is_failing() ? $ui->danger($run->status->value) : $ui->muted($run->status->value),
                ' · ' . Fmt::run_ms($run->duration_ms),
            ),
            key_mono: true,
        ),
        $run->error === null || $run->error === '' ? null : Html::el('div', ['class' => 'sched-run-err'],
            $ui->mono($run->error),
            $ui->copy($run->error, 'Copy error')),
    );

    $drawer = static fn (ScheduledJob $job): Html => Html::join(
        $ui->head(title: $job->display_name(), kicker: $job->enabled ? 'Enabled' : 'Paused', sub: $job->human . ' UTC'),
        $ui->facts(
            $ui->fact('Last outcome', $job->last_outcome->is_failing()
                ? $ui->danger($job->last_outcome->text($job->last_status))
                : Html::el('span', [], $job->last_outcome->text($job->last_status))),
            $ui->fact('Last run', $job->never_run() ? 'Never' : Html::join(
                Fmt::utc($job->last_run_at),
                Html::el('div', ['class' => 'muted small'], 'took ' . Fmt::dur((float)$job->duration)),
            )),
            $ui->fact('Cron', $job->cron, mono: true, copy: $job->cron, copy_title: 'Copy cron expression'),
            $ui->fact('Owner', $job->internal ? 'Atomic framework' : ($job->owner ?: $ui->muted('Not set'))),
            $ui->fact('Task key', $job->id, wide: true, mono: true, copy: $job->id, copy_title: 'Copy task key'),
        ),
        $ui->label('Next 3 runs'),
        $job->enabled
            ? $ui->kv_list(...array_map(static fn (\DateTimeImmutable $time): Html => $ui->kv(Fmt::utc($time), '', key_mono: true), $job->next_runs))
            : $ui->kv_list(Html::el('div', ['class' => 'muted'], 'Paused, no upcoming runs.')),
        $ui->code('Command', $job->command, wrap: true),
        $job->never_run() ? null : Html::join(
            $ui->section_head('Last ' . count($job->runs) . ' runs', $ui->runs(...$job->runs)),
            $ui->kv_list(...array_map($run_line, $job->runs)),
        ),
    );

    $row = static fn (ScheduledJob $job): Html => $ui->row(
        id: $job->id,
        cells: [
            $ui->main(Html::join($job->display_name(), $job->internal ? $ui->tag('Framework') : null), $job->command, sub_mono: true),
            Html::el('span', ['class' => 'sched-sch col-dim'],
                Html::el('div', [], $job->human),
                Html::el('div', ['class' => 'row-sub mono'], $job->cron)),
            Html::el('span', ['class' => 'sched-last'],
                $ui->status($job->last_outcome->status()),
                Html::el('span', [],
                    Html::el('div', ['class' => 'small', 'title' => $job->never_run() ? null : Fmt::utc($job->last_run_at)],
                        $job->never_run() ? 'Never' : Fmt::short($job->last_run_at)),
                    Html::el('div', ['class' => 'row-sub'], $job->never_run() ? '' : Fmt::dur((float)$job->duration)))),
            $ui->cell('sched-runs', $job->never_run() ? null : $ui->runs(...$job->runs)),
            $ui->cell('sched-next', $job->enabled
                ? Html::el('div', ['title' => Fmt::utc($job->next_runs[0] ?? null)], Fmt::short($job->next_runs[0] ?? null))
                : $ui->muted('Paused')),
        ],
        filters: [$filter_key($job), $job->internal ? 'framework' : null],
        search: [$job->name, $job->command, $job->command],
        drawer: $drawer($job),
        off: !$job->enabled,
    );

    echo $ui->list_page(
        summary: $ui->summary(count($jobs) . ' scheduled jobs',
            // Counts every job, not just the ones the search shows.
            $failing ? $ui->part($ui->danger($failing . ' with a failed last run')) : null,
            $ui->part('next run ' . Fmt::utc($next_run)),
            $ui->part('times in UTC'),
        ),
        pills: [
            new Pill('all', 'All', count($jobs)),
            new Pill(JobOutcome::Ok->key(), 'Last run succeeded', $count(JobOutcome::Ok->key())),
            new Pill(JobOutcome::Failed->key(), 'Last run failed', $count(JobOutcome::Failed->key()), hot: true),
            new Pill(JobOutcome::Timeout->key(), 'Timed out', $count(JobOutcome::Timeout->key()), hot: true),
            new Pill(JobOutcome::Never->key(), 'Never run', $count(JobOutcome::Never->key())),
            new Pill(JobOutcome::Unknown->key(), 'Unknown outcome', $count(JobOutcome::Unknown->key())),
            new Pill(JobOutcome::Skipped->key(), 'Skipped', $count(JobOutcome::Skipped->key())),
            new Pill('paused', 'Paused', $count('paused')),
            new Pill('framework', 'Framework', count(array_filter($jobs, static fn (ScheduledJob $job): bool => $job->internal))),
        ],
        search_placeholder: 'Search jobs or commands',
        columns: [
            ['col-main', 'Job'], ['sched-sch', 'Schedule'], ['sched-last', 'Last run'],
            ['sched-runs', 'Last 20 runs'], ['sched-next', 'Next run'],
        ],
        rows: array_map($row, $jobs),
        empty: 'No jobs match.',
        foot: Html::join('Showing ', $ui->visible_count(count($jobs)), ' of ' . count($jobs) . ' jobs'),
    );
};
