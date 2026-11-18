<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Reporters;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Scheduler\CronExpression;
use Engine\Atomic\Scheduler\Event;
use Engine\Atomic\Scheduler\RunLogger;
use Engine\Atomic\Scheduler\Scheduler;
use Engine\Atomic\Telemetry\Data\JobOutcome;
use Engine\Atomic\Telemetry\Data\JobRun;
use Engine\Atomic\Telemetry\Data\ScheduledJob;

class SchedulerReporter implements SnapshotProviderInterface
{
    /** @param string|null $schedule_path schedule file; null = the app's routes/schedule.php */
    public function __construct(private readonly ?string $schedule_path = null)
    {
    }

    private const RUN_HISTORY = 20;
    private const NEXT_RUNS = 3;
    private const MS_PER_SECOND = 1000;

    /** @return list<array<string, mixed>> */
    public function report(): array
    {
        return array_map(static fn (ScheduledJob $job): array => $job->to_array(), $this->jobs());
    }

    /** @return list<ScheduledJob> */
    public function jobs(): array
    {
        // App::register_schedule() only runs under CLI, so web requests (the
        // dashboard) load the schedule here. load_from() is idempotent.
        try {
            Scheduler::instance()->register_schedule($this->schedule_path);
        } catch (\Throwable $e) {
            // A broken schedule file must not take the dashboard down.
        }

        $tasks = RunLogger::tasks();

        return array_map(fn (Event $event): ScheduledJob => $this->describe_event($event, $tasks), Scheduler::instance()->events());
    }

    private function describe_event(Event $event, array $tasks): ScheduledJob
    {
        $task_key = $event->get_task_key();
        $registry = $tasks[$task_key] ?? null;
        $enabled = (bool)($registry['enabled'] ?? true);
        $history = RunLogger::last_runs($task_key, self::RUN_HISTORY);
        $last_run = $history[0] ?? null;
        $runs = array_map(static fn (array $run): JobRun => new JobRun(
            status: JobOutcome::of($run['status']),
            started_at: $run['started_at'],
            duration_ms: $run['duration_ms'],
            error: $run['error'],
        ), $history);
        $command = $event->get_callback_description();

        return new ScheduledJob(
            id: $task_key,
            name: $event->get_description() ?? $command,
            command: $command,
            cron: $event->get_expression(),
            human: CronExpression::describe($event->get_expression()),
            owner: $event->get_owner() ?? ($registry['owner'] ?? null),
            enabled: $enabled,
            last_outcome: JobOutcome::of($last_run['status'] ?? null),
            last_status: $last_run['status'] ?? null,
            last_run_at: $last_run['started_at'] ?? null,
            duration: $last_run !== null ? round($last_run['duration_ms'] / self::MS_PER_SECOND, 1) : null,
            next_runs: $enabled ? array_map(
                static fn (\DateTimeInterface $date): \DateTimeImmutable => \DateTimeImmutable::createFromInterface($date),
                $event->get_next_run_dates(self::NEXT_RUNS),
            ) : [],
            runs: $runs,
        );
    }

    public function snapshot(): array
    {
        $total = 0;
        $failing = 0;
        $paused = 0;
        try {
            $jobs = $this->jobs();
        } catch (\Throwable $e) {
            $jobs = [];
        }
        foreach ($jobs as $job) {
            $total++;
            if (!$job->enabled) {
                $paused++;
            } elseif ($job->last_outcome->is_failing()) {
                $failing++;
            }
        }

        return ['total' => $total, 'failing' => $failing, 'paused' => $paused];
    }
}
