<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Telemetry\View\Fmt;

final class ExceptionGroup
{
    private const DAY_HOURS = 24;
    private const SERVER_ERROR = 500;
    private const CLIENT_ERROR = 400;

    /** @param list<int> $usage occurrences per point of the range */
    public function __construct(
        public readonly string $id,
        public readonly string $class,
        public readonly string $message,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly int $code,
        public readonly string $level,
        public readonly int $count,
        public readonly int $total,
        public readonly int $first_seen_h,
        public readonly int $last_seen_m,
        public readonly array $usage,
        public readonly string $path,
        public readonly ?string $dump_id,
        public readonly string $trace,
    ) {}

    /** @param array<string, mixed> $group a group of ExceptionsReporter::report() */
    public static function from_array(array $group): self
    {
        return new self(
            id: (string)($group['id'] ?? ''),
            class: (string)($group['class'] ?? ''),
            message: (string)($group['message'] ?? ''),
            file: isset($group['file']) ? (string)$group['file'] : null,
            line: isset($group['line']) ? (int)$group['line'] : null,
            code: (int)($group['code'] ?? 0),
            level: (string)($group['level'] ?? ''),
            count: (int)($group['count'] ?? 0),
            total: (int)($group['total'] ?? 0),
            first_seen_h: (int)($group['first_seen_h'] ?? 0),
            last_seen_m: (int)($group['last_seen_m'] ?? 0),
            usage: array_map('intval', array_values((array)($group['usage'] ?? []))),
            path: (string)($group['path'] ?? ''),
            dump_id: isset($group['dump_id']) ? (string)$group['dump_id'] : null,
            trace: (string)($group['trace'] ?? ''),
        );
    }

    public function short_class(): string
    {
        $pos = strrpos($this->class, '\\');
        return $pos === false ? $this->class : substr($this->class, $pos + 1);
    }

    public function is_server(): bool
    {
        return $this->code >= self::SERVER_ERROR;
    }

    public function is_client(): bool
    {
        return $this->code >= self::CLIENT_ERROR && $this->code < self::SERVER_ERROR;
    }

    public function is_fresh(): bool
    {
        return $this->first_seen_h <= self::DAY_HOURS;
    }

    public function is_severe(): bool
    {
        return $this->level === 'critical' || $this->is_server();
    }

    public function where(): ?string
    {
        return $this->file ? $this->file . ':' . $this->line : null;
    }

    public function last_seen(): string
    {
        return Fmt::age($this->last_seen_m * 60) . ' ago';
    }

    public function report(): string
    {
        $where = $this->where();

        return $this->class . ': ' . $this->message . "\n"
            . ($where ? 'at ' . $where . "\n" : '')
            . ($this->path !== '' ? 'Request: ' . $this->path . "\n" : '')
            . ($this->code ? 'HTTP status: ' . $this->code . "\n" : '')
            . 'Seen ' . $this->total . ' times, last ' . $this->last_seen()
            . ($this->trace !== '' ? "\n\n" . $this->trace : '');
    }

    /** @return array<string, mixed> the JSON shape of GET /telemetry/exceptions */
    public function to_array(): array
    {
        return [
            'id' => $this->id,
            'class' => $this->class,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'code' => $this->code,
            'level' => $this->level,
            'count' => $this->count,
            'total' => $this->total,
            'first_seen_h' => $this->first_seen_h,
            'last_seen_m' => $this->last_seen_m,
            'usage' => $this->usage,
            'path' => $this->path,
            'dump_id' => $this->dump_id,
            'trace' => $this->trace,
        ];
    }
}
