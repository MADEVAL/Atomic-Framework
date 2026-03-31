<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\App;

class Scheduler extends \Prefab
{
    protected array $events = [];
    protected bool $logging = true;
    protected int $max_execution_time = 300;

    public function call(callable|array $callback, array $parameters = []): Event
    {
        $event = new Event($callback, $parameters);
        $this->events[] = $event;
        return $event;
    }

    public function job(string $class, string $method = 'handle', array $parameters = []): Event
    {
        return $this->call([$class, $method], $parameters);
    }

    public function exec(string $command): Event
    {
        return $this->call(function () use ($command) {
            $output = [];
            $returnCode = 0;
            \exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \RuntimeException(
                    "Command failed with exit code {$returnCode}: " . \implode("\n", $output)
                );
            }
            
            return \implode("\n", $output);
        })->description("Command: {$command}");
    }

    public function events(): array
    {
        return $this->events;
    }

    public function due_events(): array
    {
        return \array_filter($this->events, function (Event $event) {
            return $event->is_due() && $event->filters_pass();
        });
    }

    public function run(bool $force_run = false): array
    {
        $results = [];
        $start_time = \time();

        $atomic = App::atomic();
        $maintenance_mode = (bool)$atomic->get('MAINTENANCE_MODE');

        foreach ($this->events as $event) {
            if ((\time() - $start_time) > $this->max_execution_time) {
                Log::warning('[Scheduler] Maximum execution time reached, stopping');
                break;
            }

            if (!$force_run && !$event->is_due()) {
                continue;
            }

            if (!$event->filters_pass()) {
                $this->log("Skipping event (filters failed): " . ($event->get_description() ?? $event->get_id()));
                continue;
            }

            if ($maintenance_mode && !$event->runs_in_maintenance_mode()) {
                $this->log("Skipping event (maintenance mode): " . ($event->get_description() ?? $event->get_id()));
                continue;
            }

            $event_result = [
                'id' => $event->get_id(),
                'description' => $event->get_description(),
                'expression' => $event->get_expression(),
                'started_at' => \date('Y-m-d H:i:s'),
                'success' => false,
                'output' => '',
                'error' => null,
                'duration' => 0,
            ];

            $event_start = \microtime(true);

            try {
                $this->log("Running event: " . ($event->get_description() ?? $event->get_id()));
                
                $event->run();
                
                $event_result['success'] = true;
                $event_result['output'] = $event->get_output();
                
                $this->log("Event completed: " . ($event->get_description() ?? $event->get_id()));

            } catch (\Throwable $e) {
                $event_result['error'] = $e->getMessage();
                $this->log("Event failed: " . ($event->get_description() ?? $event->get_id()) . " - " . $e->getMessage(), 'error');
            }

            $event_result['duration'] = \round((\microtime(true) - $event_start) * 1000, 2);
            $event_result['finished_at'] = \date('Y-m-d H:i:s');
            
            $results[] = $event_result;
        }

        return $results;
    }

    public function clear(): self
    {
        $this->events = [];
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
        if (\file_exists($path)) {
            $scheduler = $this;
            require $path;
        }
        return $this;
    }

    public function register_schedule(): self
    {
        $atomic = App::atomic();
        
        $path = \dirname($atomic->get('ROOT')) . '/src/routes/schedule.php';

        if (\file_exists($path)) {
            $this->load_from($path);
        }

        return $this;
    }
}
