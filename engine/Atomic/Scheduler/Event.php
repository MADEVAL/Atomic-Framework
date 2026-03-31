<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Mutex\Mutex;

class Event
{
    use ManagesFrequencies;

    protected string $id;
    /** @var callable|array */
    protected         $callback;
    protected array   $parameters = [];
    protected ?string $description = null;
    protected array   $filters = [];
    protected array   $rejects = [];
    protected array   $before_callbacks = [];
    protected array   $after_callbacks = [];
    protected array   $success_callbacks = [];
    protected array   $failure_callbacks = [];
    protected bool    $without_overlapping = false;
    protected int     $expires_at = 1440;
    protected ?int    $exit_code = null;
    protected string  $output = '';
    protected ?string $mutex_name = null;
    protected ?string $mutex_token = null;

    public function __construct(callable|array $callback, array $parameters = [])
    {
        $this->id = ID::uuid_v4();
        $this->callback = $callback;
        $this->parameters = $parameters;
    }

    public function get_id(): string
    {
        return $this->id;
    }

    public function get_expression(): string
    {
        return $this->expression;
    }

    public function get_description(): ?string
    {
        return $this->description;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function name(string $name): self
    {
        return $this->description($name);
    }

    public function when(callable|bool $callback): self
    {
        $this->filters[] = \is_callable($callback) ? $callback : fn () => $callback;
        return $this;
    }

    public function skip(callable|bool $callback): self
    {
        $this->rejects[] = \is_callable($callback) ? $callback : fn () => $callback;
        return $this;
    }

    public function without_overlapping(int $expires_at = 60): self
    {
        $this->without_overlapping = true;
        $this->expires_at = $expires_at;
        return $this;
    }

    public function before(callable $callback): self
    {
        $this->before_callbacks[] = $callback;
        return $this;
    }

    public function after(callable $callback): self
    {
        $this->after_callbacks[] = $callback;
        return $this;
    }

    public function on_success(callable $callback): self
    {
        $this->success_callbacks[] = $callback;
        return $this;
    }

    public function on_failure(callable $callback): self
    {
        $this->failure_callbacks[] = $callback;
        return $this;
    }

    public function is_due(): bool
    {
        return CronExpression::is_due($this->expression, $this->timezone);
    }

    public function filters_pass(): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter()) {
                return false;
            }
        }

        foreach ($this->rejects as $reject) {
            if ($reject()) {
                return false;
            }
        }

        return true;
    }

    public function get_mutex_name(): string
    {
        if ($this->mutex_name) {
            return $this->mutex_name;
        }

        return 'schedule-' . \sha1($this->expression . $this->get_callback_description());
    }

    protected function get_callback_description(): string
    {
        if (\is_string($this->callback)) {
            return $this->callback;
        }

        if (\is_array($this->callback)) {
            if (\is_object($this->callback[0])) {
                return \get_class($this->callback[0]) . '::' . $this->callback[1];
            }
            return $this->callback[0] . '::' . $this->callback[1];
        }

        if ($this->callback instanceof \Closure) {
            $reflection = new \ReflectionFunction($this->callback);
            return 'Closure@' . $reflection->getFileName() . ':' . $reflection->getStartLine();
        }

        return 'callable';
    }

    public function run(): mixed
    {
        $this->output = '';
        $this->exit_code = null;

        if ($this->without_overlapping && !$this->acquire_mutex()) {
            Log::debug('[Scheduler] Skipping overlapping event: ' . ($this->description ?? $this->get_mutex_name()));
            return null;
        }

        try {
            $this->run_callbacks($this->before_callbacks);

            \ob_start();

            $result = $this->execute_callback();

            $this->output = \ob_get_clean() ?: '';
            $this->exit_code = 0;

            $this->run_callbacks($this->success_callbacks);
            $this->run_callbacks($this->after_callbacks);

            return $result;

        } catch (\Throwable $e) {
            $this->output = \ob_get_clean() ?: '';
            $this->exit_code = 1;

            Log::error('[Scheduler] Event failed: ' . $e->getMessage());

            foreach ($this->failure_callbacks as $callback) {
                try {
                    $callback($e);
                } catch (\Throwable $callbackError) {
                    Log::error('[Scheduler] Failure callback error: ' . $callbackError->getMessage());
                }
            }

            $this->run_callbacks($this->after_callbacks);

            throw $e;

        } finally {
            if ($this->without_overlapping) {
                $this->release_mutex();
            }
        }
    }

    protected function execute_callback(): mixed
    {
        if (\is_string($this->callback) && \strpos($this->callback, '->') !== false) {
            [$class, $method] = \explode('->', $this->callback, 2);
            $instance = new $class();
            return $instance->$method(...$this->parameters);
        }

        if (\is_string($this->callback) && \strpos($this->callback, '::') !== false) {
            [$class, $method] = \explode('::', $this->callback, 2);
            return $class::$method(...$this->parameters);
        }

        return \call_user_func_array($this->callback, $this->parameters);
    }

    protected function run_callbacks(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback($this);
            } catch (\Throwable $e) {
                Log::error('[Scheduler] Callback error: ' . $e->getMessage());
            }
        }
    }

    protected function acquire_mutex(): bool
    {
        $mutex_key = $this->get_mutex_name();
        $ttl_seconds = $this->expires_at;
        $token = Mutex::acquire($mutex_key, $ttl_seconds);
        
        if ($token === null) {
            Log::info('[Scheduler] Mutex held, skipping: ' . ($this->description ?? $mutex_key));
            return false;
        }
        
        $this->mutex_token = $token;
        return true;
    }

    protected function release_mutex(): void
    {
        if ($this->mutex_token === null) return;
        
        $mutex_key = $this->get_mutex_name();
        
        $released = Mutex::release($mutex_key, $this->mutex_token);
        
        if (!$released) {
            Log::warning('[Scheduler] Failed to release mutex (token mismatch or expired): ' . $mutex_key);
        }
        
        $this->mutex_token = null;
    }

    public function get_exit_code(): ?int
    {
        return $this->exit_code;
    }

    public function get_output(): string
    {
        return $this->output;
    }

    public function get_next_run_date(): ?\DateTimeInterface
    {
        return CronExpression::get_next_run_date($this->expression, $this->timezone);
    }

    public function get_summary(): array
    {
        $summary = [
            'id' => $this->id,
            'description' => $this->description ?? $this->get_callback_description(),
            'expression' => $this->expression,
            'next_run' => $this->get_next_run_date()?->format('Y-m-d H:i:s'),
            'is_due' => $this->is_due(),
            'without_overlapping' => $this->without_overlapping,
        ];
        
        if ($this->without_overlapping) {
            $summary['mutex_name'] = $this->get_mutex_name();
            $summary['mutex_ttl_minutes'] = $this->expires_at;
            $summary['mutex_is_locked'] = Mutex::exists($this->get_mutex_name());
        }
        
        return $summary;
    }
}
