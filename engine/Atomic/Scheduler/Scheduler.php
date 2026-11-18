<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Traits\Singleton;

class Scheduler
{
    use Singleton;

    private function __construct() {}

    protected array $events = [];
    protected bool $logging = true;
    protected int $max_execution_time = 300;
    /** @var array<string, true> Canonical paths currently loading or already loaded. */
    protected array $loaded_schedule_files = [];

    public function call(callable|array $callback, array $parameters = []): Event
    {
        $event = new Event($callback, $parameters);
        $this->events[] = $event;
        return $event;
    }

    public function job(string $class, string $method = 'handle', array $parameters = []): Event
    {
        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException $e) {
            return $this->call([$class, $method], $parameters);
        }

        if (!$reflection->isStatic()) {
            return $this->call([new $class(), $method], $parameters);
        }

        return $this->call([$class, $method], $parameters);
    }

    public function exec(string $command): Event
    {
        $escaped_command = \escapeshellcmd($command);
        return $this->call(function () use ($escaped_command) {
            $output = [];
            $returnCode = 0;
            \exec($escaped_command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \RuntimeException(
                    "Command failed with exit code {$returnCode}: " . \implode("\n", $output)
                );
            }
            
            return \implode("\n", $output);
        })->identity("exec:{$command}")->description("Command: {$command}");
    }

    public function events(): array
    {
        return $this->events;
    }

    public function due_events(bool $force_run = false): array
    {
        $maintenance_mode = (bool)App::atomic()->get('MAINTENANCE_MODE');
        $paused = RunLogger::paused_keys();

        return \array_filter($this->events, function (Event $event) use ($force_run, $maintenance_mode, $paused) {
            if (!$force_run && !$event->is_due()) {
                return false;
            }
            if (!$event->filters_pass()) {
                $this->log("Skipping event (filters failed): " . ($event->get_description() ?? $event->get_id()));
                return false;
            }
            if ($maintenance_mode && !$event->runs_in_maintenance_mode()) {
                $this->log("Skipping event (maintenance mode): " . ($event->get_description() ?? $event->get_id()));
                return false;
            }
            if (isset($paused[$event->get_task_key()])) {
                $this->log("Skipping event (paused): " . ($event->get_description() ?? $event->get_id()));
                return false;
            }
            return true;
        });
    }

    /**
     * Run events and return per-run result rows. Results carry a `status`
     * (ok|failed|timeout|skipped) alongside the legacy `success` boolean —
     * overlap-skipped runs report `skipped`, not success.
     *
     * @param array|null $events Optional pre-filtered event list. When the
     *   caller already computed the due set (e.g. the Runner), passing it here
     *   avoids evaluating `when()`/`skip()` predicates a second time.
     */
    public function run(bool $force_run = false, ?array $events = null): array
    {
        return $this->run_events($events ?? $this->due_events($force_run));
    }

    /**
     * Execute a previously selected set without re-evaluating eligibility.
     *
     * @param Event[] $events Events returned by due_events().
     */
    public function run_events(array $events): array
    {
        $results = [];
        $start_time = \time();

        foreach ($events as $event) {
            if ((\time() - $start_time) > $this->max_execution_time) {
                Log::warning('[Scheduler] Maximum execution time reached, stopping');
                break;
            }

            $event_result = [
                'id' => $event->get_id(),
                'task_key' => $event->get_task_key(),
                'description' => $event->get_description(),
                'expression' => $event->get_expression(),
                'started_at' => \date('Y-m-d H:i:s'),
                'success' => false,
                'status' => 'ok',
                'skipped' => false,
                'output' => '',
                'error' => null,
                'duration' => 0,
            ];

            $event_start = \microtime(true);

            try {
                $this->log("Running event: " . ($event->get_description() ?? $event->get_id()));

                $event->run();

                $event_result['output'] = $event->get_output();
                $event_result['duration'] = \round((\microtime(true) - $event_start) * 1000, 2);

                if ($event->was_skipped()) {
                    $event_result['status'] = 'skipped';
                    $event_result['skipped'] = true;
                    $this->log("Event skipped (overlapping): " . ($event->get_description() ?? $event->get_id()));
                } elseif ($this->overshot_budget($event, $event_result['duration'])) {
                    $event_result['status'] = 'timeout';
                    $event_result['error'] = "Run exceeded its {$event->get_timeout()}s wall-clock budget.";
                    $this->log("Event timed out: " . ($event->get_description() ?? $event->get_id()), 'error');
                } else {
                    $event_result['success'] = true;
                    $this->log("Event completed: " . ($event->get_description() ?? $event->get_id()));
                }

            } catch (\Throwable $e) {
                $event_result['error'] = $e->getMessage();
                $event_result['status'] = $e instanceof TaskTimeoutException ? 'timeout' : 'failed';
                $this->log("Event failed: " . ($event->get_description() ?? $event->get_id()) . " - " . $e->getMessage(), 'error');
            }

            $event_result['duration'] = \round((\microtime(true) - $event_start) * 1000, 2);
            $event_result['finished_at'] = \date('Y-m-d H:i:s');

            RunLogger::record($event, $event_result);

            $results[] = $event_result;
        }

        return $results;
    }

    /** True when a run outlived its explicit wall-clock budget (pcntl absent or alarm missed). */
    private function overshot_budget(Event $event, float $duration_ms): bool
    {
        $budget = $event->get_timeout();
        if ($budget === null) {
            return false;
        }

        return $duration_ms > $budget * 1000;
    }

    public function clear(): self
    {
        $this->events = [];
        $this->loaded_schedule_files = [];
        return $this;
    }

    public function max_execution_time(int $seconds): self
    {
        $this->max_execution_time = $seconds;
        return $this;
    }

    public function logging(bool $enabled): self
    {
        $this->logging = $enabled;
        return $this;
    }

    public function summary(): array
    {
        return \array_map(fn(Event $event) => $event->get_summary(), $this->events);
    }

    public function events_at(\DateTimeInterface $date): array
    {
        return \array_filter($this->events, function (Event $event) use ($date) {
            return CronExpression::matches($event->get_expression(), $date);
        });
    }

    protected function log(string $message, string $level = 'debug'): void
    {
        if (!$this->logging) {
            return;
        }

        $formatted_message = '[Scheduler] ' . $message;

        match ($level) {
            'error' => Log::error($formatted_message),
            'warning' => Log::warning($formatted_message),
            'info' => Log::info($formatted_message),
            default => Log::debug($formatted_message),
        };
    }

    public function load_from(string $path): self
    {
        return $this->load_schedule_file($path, true);
    }

    private function load_schedule_file(string $path, bool $explicit): self
    {
        $resolved_path = \realpath($path);
        $reason = match (true) {
            $resolved_path === false => 'does not exist or cannot be resolved',
            !\is_file($resolved_path) => 'not a file',
            !\is_readable($resolved_path) => 'not readable',
            default => null,
        };
        if ($reason !== null) {
            if ($explicit) {
                // Configuration errors must remain visible even with routine scheduler logging disabled.
                Log::warning('[Scheduler] Cannot load schedule "' . $path . '": ' . $reason . '.');
            }
            return $this;
        }
        if (isset($this->loaded_schedule_files[$resolved_path])) {
            return $this;
        }

        $previous_events = $this->events;
        $previous_files = $this->loaded_schedule_files;
        // Guard the file before executing it to stop recursive includes.
        $this->loaded_schedule_files[$resolved_path] = true;

        try {
            // Keep schedule-local variables separate from the rollback snapshots.
            (function (string $schedule_path): void {
                $scheduler = $this;
                require $schedule_path;
            })($resolved_path);
            $this->loaded_schedule_files[$resolved_path] = true;
        } catch (\Throwable $e) {
            // Nested loads belong to the same registration attempt.
            $this->events = $previous_events;
            $this->loaded_schedule_files = $previous_files;
            throw $e;
        }

        return $this;
    }

    public function register_schedule(?string $path = null): self
    {
        $explicit = $path !== null;
        if ($path === null) {
            if (\defined('ATOMIC_APP_ROUTES')) {
                $path = \ATOMIC_APP_ROUTES . 'schedule.php';
            } elseif (\defined('ATOMIC_DIR')) {
                $path = \ATOMIC_DIR . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'schedule.php';
            }
        }

        return $path === null ? $this : $this->load_schedule_file($path, $explicit);
    }
}
