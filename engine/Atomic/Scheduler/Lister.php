<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

class Lister
{
    protected Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function get_formatted_list(): array
    {
        $events = $this->scheduler->events();
        
        if (empty($events)) {
            return [
                'headers' => ['Expression', 'Description', 'Next Run', 'Due Now'],
                'rows' => [],
                'total' => 0,
            ];
        }

        $rows = [];
        foreach ($events as $event) {
            $summary = $event->get_summary();
            
            $rows[] = [
                'expression' => $summary['expression'],
                'description' => $summary['description'] ?? 'N/A',
                'next_run' => $summary['next_run'] ?? 'N/A',
                'is_due' => $summary['is_due'] ? 'Yes' : 'No',
            ];
        }

        return [
            'headers' => ['Expression', 'Description', 'Next Run', 'Due Now'],
            'rows' => $rows,
            'total' => \count($events),
        ];
    }

    public function get_summary(): array
    {
        return $this->scheduler->summary();
    }

    public function validate_configuration(): array
    {
        $events = $this->scheduler->events();
        
        if (empty($events)) {
            return [
                'valid' => true,
                'total' => 0,
                'results' => [],
            ];
        }

        $has_errors = false;
        $results = [];

        foreach ($events as $i => $event) {
            $num = $i + 1;
            $summary = $event->get_summary();
            $expression = $summary['expression'];
            $description = $summary['description'] ?? "Task #{$num}";

            $is_valid = CronExpression::is_valid($expression);
            
            $result = [
                'number' => $num,
                'description' => $description,
                'expression' => $expression,
                'valid' => $is_valid,
            ];

            if ($is_valid) {
                $result['readable'] = CronExpression::describe($expression);
            }

            $results[] = $result;

            if (!$is_valid) {
                $has_errors = true;
            }
        }

        return [
            'valid' => !$has_errors,
            'total' => \count($events),
            'results' => $results,
        ];
    }
}
