<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class LogPage
{
    public const ALL = 'all';

    /**
     * @param list<string> $dates days with a log file, newest first
     * @param list<LogLine> $lines
     * @param array<string, int> $channels channel => line count of its newest file
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $date,
        public readonly array $dates,
        public readonly array $lines,
        public readonly int $page,
        public readonly int $last_page,
        public readonly int $total,
        public readonly array $channels,
    ) {}

    public function is_all(): bool
    {
        return $this->channel === self::ALL;
    }

    public function has_more(): bool
    {
        return $this->page < $this->last_page;
    }

    public function scope(): string
    {
        return $this->channel . '|' . $this->date;
    }

    /** @return array{channel:string, date:string, page:int} the query of the next page */
    public function next(): array
    {
        return ['channel' => $this->channel, 'date' => $this->date, 'page' => $this->page + 1];
    }
}
