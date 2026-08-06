<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;

class Worker
{
    protected Scheduler $scheduler;
    protected int $sleep_seconds;
    protected int $max_iterations;
    protected bool $shutdown = false;

    public function __construct(Scheduler $scheduler, int $sleep_seconds = 60, int $max_iterations = 0)
    {
        $this->scheduler = $scheduler;
        $this->sleep_seconds = \max(1, $sleep_seconds);
        $this->max_iterations = \max(0, $max_iterations);
    }

    public function handle_signal(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
                Log::info('[Scheduler Worker] Received SIGTERM, shutting down...');
                $this->shutdown = true;
                break;
            case SIGINT:
                Log::info('[Scheduler Worker] Received SIGINT, shutting down...');
                $this->shutdown = true;
                break;
            default:
                Log::warning('[Scheduler Worker] Received unknown signal: ' . $signal);
                break;
        }
    }

    public function run(callable|null $output_callback = null): void
    {
        if (\function_exists('pcntl_signal')) {
            \pcntl_async_signals(true);
            \pcntl_signal(SIGTERM, [$this, 'handle_signal']);
            \pcntl_signal(SIGINT, [$this, 'handle_signal']);
        }

        $iteration = 0;
        $last_minute = null;

        $this->log_output("Scheduler worker started. Checking every {$this->sleep_seconds} seconds.", $output_callback);

        while (!$this->shutdown) {
            Log::debug('[Scheduler Worker] Beginning new loop iteration.');
            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }

            $current_time = \date('Y-m-d H:i:s');
            $current_minute = \date('Y-m-d H:i');
            
            if ($current_minute !== $last_minute) {
                $iteration++;
                $last_minute = $current_minute;
                
                $this->log_output("[{$current_time}] Checking for due tasks (iteration #{$iteration})...", $output_callback);

                $due_events = $this->scheduler->due_events();

                if (empty($due_events)) {
                    $this->log_output("  No tasks due.", $output_callback);
                } else {
                    $this->log_output("  Found " . \count($due_events) . " due task(s)", $output_callback);

                    $results = $this->scheduler->run();

                    foreach ($results as $result) {
                        $status = $result['success'] ? '✓' : '✗';
                        $name = $result['description'] ?? $result['id'];
                        $this->log_output("    [{$status}] {$name}", $output_callback);
                    }
                }

                if ($this->max_iterations > 0 && $iteration >= $this->max_iterations) {
                    $this->log_output("Maximum iterations ({$this->max_iterations}) reached, stopping", $output_callback);
                    break;
                }
            }

            \sleep($this->sleep_seconds);
        }

        $this->log_output("Scheduler worker stopped.", $output_callback);
    }

    protected function log_output(string $message, ?callable $callback = null): void
    {
        if ($callback !== null) {
            $callback($message);
        }
    }

    public function stop(): void
    {
        $this->shutdown = true;
    }
}
