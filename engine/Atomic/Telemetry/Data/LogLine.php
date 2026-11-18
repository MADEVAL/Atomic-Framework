<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class LogLine
{
    private const MARKER = '/(\[(?:ONERROR|HIVE)\])/i';

    public function __construct(
        public readonly ?string $ts,
        public readonly string $level,
        public readonly string $channel,
        public readonly string $message,
        public readonly ?string $dump_id,
    ) {}

    /** @param array<string, mixed> $line a line of LogsReporter::page() */
    public static function from_array(array $line, string $channel): self
    {
        return new self(
            ts: isset($line['ts']) ? (string)$line['ts'] : null,
            level: strtolower((string)($line['level'] ?? '')) ?: 'info',
            channel: $channel,
            message: (string)($line['message'] ?? ''),
            dump_id: isset($line['dump_id']) ? (string)$line['dump_id'] : null,
        );
    }

    public function is_error(): bool
    {
        return $this->level === 'error' || $this->level === 'critical';
    }

    public function filter(): string
    {
        return $this->is_error() ? 'error' : $this->level;
    }

    public function status(): Status
    {
        [$icon, $tone] = match ($this->level) {
            'critical', 'error' => ['circle-x', Tone::Bad],
            'warning' => ['alert', Tone::Warn],
            'debug' => ['terminal', Tone::Mute],
            default => ['info', Tone::Neutral],
        };

        return new Status($icon, $tone, $this->level);
    }

    public function time(): string
    {
        return preg_match('/(\d{2}:\d{2}:\d{2})/', (string)$this->ts, $match) ? $match[1] : (string)$this->ts;
    }

    public function is_multiline(): bool
    {
        return str_contains($this->message, "\n");
    }

    /** @return list<string> the message split around its markers; odd entries are markers */
    public function parts(): array
    {
        return preg_split(self::MARKER, $this->message, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$this->message];
    }

    public function text(): string
    {
        return '[' . $this->ts . '] ' . $this->channel . '.' . strtoupper($this->level) . ': ' . $this->message
            . ($this->dump_id !== null ? ' [dump:' . $this->dump_id . ']' : '');
    }

    /** Identifies a line across pages and refreshes; identical lines in one second share it. */
    public function key(): string
    {
        return substr(md5($this->ts . '|' . $this->channel . '|' . $this->message), 0, 16);
    }
}
