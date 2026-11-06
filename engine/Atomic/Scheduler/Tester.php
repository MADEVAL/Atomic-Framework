<?php
declare(strict_types=1);
namespace Engine\Atomic\Scheduler;

if (!defined('ATOMIC_START')) exit;

class Tester
{
    public function test_expression(string $expression): array
    {
        $is_valid = CronExpression::is_valid($expression);
        
        $result = [
            'expression' => $expression,
            'valid' => $is_valid,
        ];

        if ($is_valid) {
            $result['description'] = CronExpression::describe($expression);
            $result['is_due'] = CronExpression::is_due($expression);
            
            $next_run = CronExpression::get_next_run_date($expression);
            $result['next_run'] = $next_run ? $next_run->format('Y-m-d H:i:s') : null;
            
            $result['upcoming_runs'] = $this->get_upcoming_runs($expression, 5);
        }

        return $result;
    }

    public function get_upcoming_runs(string $expression, int $count = 5, ?\DateTimeInterface $from = null): array
    {
        if (!CronExpression::is_valid($expression)) {
            return [];
        }

        $runs = [];
        $current = $from ?? new \DateTime();

        for ($i = 0; $i < $count; $i++) {
            $next_run = CronExpression::get_next_run_date(
                $expression,
                $current->getTimezone(),
                from: $current
            );
            if ($next_run === null) {
                break;
            }

            $runs[] = $next_run->format('Y-m-d H:i:s');
            $current = $next_run;
        }

        return $runs;
    }
}
