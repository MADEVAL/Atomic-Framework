<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Scheduler\CronExpression;
use Engine\Atomic\Scheduler\Event;
use Engine\Atomic\Scheduler\RunLogger;
use Engine\Atomic\Scheduler\Scheduler;

class SchedulerReporter implements SnapshotProviderInterface
{
    /** @param string|null $schedule_path schedule file; null = the app's routes/schedule.php */
    public function __construct(private readonly ?string $schedule_path = null)
    {
    }

    /** @return list<array<string, mixed>> */
    public function report(): array
    {
        // App::register_schedule() only runs under CLI, so web requests (the
        // dashboard) load the schedule here. load_from() is idempotent.
        try {
            Scheduler::instance()->register_schedule($this->schedule_path);
        } catch (\Throwable $e) {
            // A broken schedule file must not take the dashboard down.
        }

        $tasks = RunLogger::tasks();
        $out = [];

        foreach (Scheduler::instance()->events() as $event) {
            $out[] = $this->describe_event($event, $tasks);
        }

        return $out;
    }

    private function describe_event(Event $event, array $tasks): array
    {
        $task_key = $event->get_task_key();
        $registry = $tasks[$task_key] ?? null;
        $runs = RunLogger::last_runs($task_key, 20);

        $enabled = $registry['enabled'] ?? true;
        $last_run = $runs[0] ?? null;
        $last_status = $last_run['status'] ?? null;
        $outcome = $last_run === null ? 'never run'
            : (in_array($last_status, ['ok', 'failed', 'timeout', 'skipped'], true) ? $last_status : 'unknown');
        $next_runs = $enabled ? $event->get_next_run_dates(3) : [];

        $runs_out = [];
        foreach ($runs as $run) {
            $runs_out[] = [
                's' => $run['status'] === 'skipped' ? 'skipped' : $run['status'],
                'h' => max(1, (int)round($run['duration_ms'] / 1000)),
                'at' => $run['started_at'],
                'ms' => $run['duration_ms'],
                'error' => $run['error'],
            ];
        }

        return [
            'id' => $task_key,
            'name' => $event->get_description() ?? $event->get_callback_description(),
            'command' => $event->get_callback_description(),
            'cron' => $event->get_expression(),
            'human' => CronExpression::describe($event->get_expression()),
            'target' => $event->get_callback_description(),
            'owner' => $event->get_owner() ?? ($registry['owner'] ?? null),
            'enabled' => $enabled,
            'state' => $enabled ? 'enabled' : 'paused',
            'last_outcome' => $outcome,
            'last_status' => $last_status,
            'last_run_at' => $last_run['started_at'] ?? null,
            'dur' => $last_run !== null ? round($last_run['duration_ms'] / 1000, 1) : null,
            'next_runs' => array_map(static fn (\DateTimeInterface $date): string => $date->format(DATE_ATOM), $next_runs),
            'runs' => $runs_out,
        ];
    }

    public function snapshot(): array
    {
        $total = 0;
        $failing = 0;
        $paused = 0;
        try {
            $report = $this->report();
        } catch (\Throwable $e) {
            $report = [];
        }
        foreach ($report as $task) {
            $total++;
            if (!$task['enabled']) {
                $paused++;
            } elseif ($task['last_outcome'] === 'failed' || $task['last_outcome'] === 'timeout') {
                $failing++;
            }
        }

        return ['total' => $total, 'failing' => $failing, 'paused' => $paused];
    }
}
