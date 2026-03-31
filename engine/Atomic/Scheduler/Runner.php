<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

class Runner
{
    protected Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function run_due_tasks(bool $force_run = false): array
    {
        $due_events = $this->scheduler->due_events();
        
        $response = [
            'due_count' => \count($due_events),
            'results' => [],
            'summary' => [
                'successful' => 0,
                'failed' => 0,
            ],
        ];

        if (empty($due_events)) {
            return $response;
        }

        $results = $this->scheduler->run($force_run);
        
        $response['results'] = $results;
        $response['summary']['successful'] = \count(\array_filter($results, fn($r) => $r['success']));
        $response['summary']['failed'] = \count($results) - $response['summary']['successful'];

        return $response;
    }

    public function format_result(array $result): string
    {
        $status = $result['success'] ? '✓' : '✗';
        $name = $result['description'] ?? $result['id'];
        $duration = $result['duration'] . 'ms';

        $output = "[{$status}] {$name} ({$duration})";

        if (!empty($result['output'])) {
            $output .= "\n    Output: " . \substr($result['output'], 0, 100);
        }

        if (!empty($result['error'])) {
            $output .= "\n    Error: {$result['error']}";
        }

        return $output;
    }
}
