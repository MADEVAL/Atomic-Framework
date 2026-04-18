<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Scheduler\Scheduler as SchedulerCore;
use Engine\Atomic\Scheduler\Runner;
use Engine\Atomic\Scheduler\Worker;
use Engine\Atomic\Scheduler\Lister;
use Engine\Atomic\Scheduler\Tester;

trait Scheduler
{
    public function schedule_run(): void
    {
        $this->output->writeln('Running scheduled tasks...');
        $this->output->writeln();

        $scheduler = $this->get_scheduler();
        $runner = new Runner($scheduler);
        
        $result = $runner->run_due_tasks();
        
        if ($result['due_count'] === 0) {
            $this->output->writeln('No scheduled tasks are due.');
            return;
        }

        $this->output->writeln("Found {$result['due_count']} due task(s)");
        $this->output->writeln();

        foreach ($result['results'] as $task_result) {
            $this->output->writeln($runner->format_result($task_result));
        }

        $this->output->writeln();
        $this->output->writeln("Completed: {$result['summary']['successful']} successful, {$result['summary']['failed']} failed");
    }

    public function schedule_work(): void
    {
        $args = $this->get_cli_args();
        $sleep_seconds = isset($args[0]) ? (int)$args[0] : 60;

        $this->output->writeln('Starting scheduler worker...');
        $this->output->writeln('Press Ctrl+C to stop.');
        $this->output->writeln();

        $scheduler = $this->get_scheduler();
        $worker = new Worker($scheduler, $sleep_seconds);
        
        $worker->run(function($message) {
            $this->output->writeln($message);
        });
    }

    public function schedule_list(): void
    {
        $this->output->writeln('Scheduled Tasks');
        $this->output->writeln(\str_repeat('=', 80));
        $this->output->writeln();

        $scheduler = $this->get_scheduler();
        $lister = new Lister($scheduler);
        
        $list_data = $lister->get_formatted_list();

        if ($list_data['total'] === 0) {
            $this->output->writeln('No scheduled tasks defined.');
            $this->output->writeln();
            $this->output->writeln('To define tasks, add them to the file:');
            $this->output->writeln('  routes/schedule.php');
            return;
        }

        $column_widths = [20, 35, 20, 8];

        // Print header
        $this->output->write(\sprintf(
            "%-{$column_widths[0]}s %-{$column_widths[1]}s %-{$column_widths[2]}s %-{$column_widths[3]}s\n",
            $list_data['headers'][0], $list_data['headers'][1], $list_data['headers'][2], $list_data['headers'][3]
        ));
        $this->output->writeln(\str_repeat('-', \array_sum($column_widths) + 3));

        foreach ($list_data['rows'] as $row) {
            $description = $row['description'];
            
            // Truncate long descriptions
            if (\strlen($description) > $column_widths[1] - 3) {
                $description = \substr($description, 0, $column_widths[1] - 3) . '...';
            }

            $this->output->write(\sprintf(
                "%-{$column_widths[0]}s %-{$column_widths[1]}s %-{$column_widths[2]}s %-{$column_widths[3]}s\n",
                $row['expression'], $description, $row['next_run'], $row['is_due']
            ));
        }

        $this->output->writeln();
        $this->output->writeln("Total: {$list_data['total']} task(s)");
        $this->output->writeln();
        $this->output->writeln('Cron Expression Format: minute hour day-of-month month day-of-week');
        $this->output->writeln("Example: '0 3 * * *' = Every day at 3:00 AM");
    }

    public function schedule_test(): void
    {
        $args = $this->get_cli_args();
        $tester = new Tester();

        if (isset($args[0])) {
            // Test a specific cron expression
            $expression = $args[0];
            
            $this->output->writeln("Testing cron expression: {$expression}");
            $this->output->writeln();

            $result = $tester->test_expression($expression);

            if (!$result['valid']) {
                $this->output->err('Error: Invalid cron expression');
                $this->output->err('Format: minute hour day-of-month month day-of-week');
                $this->output->err("Example: '0 * * * *' (every hour)");
                return;
            }

            $this->output->writeln('Valid: Yes');
            $this->output->writeln("Description: {$result['description']}");
            $this->output->writeln('Due now: ' . ($result['is_due'] ? 'Yes' : 'No'));
            $this->output->writeln('Next run: ' . ($result['next_run'] ?? 'N/A'));

            // Show upcoming runs
            if (!empty($result['upcoming_runs'])) {
                $this->output->writeln();
                $this->output->writeln('Upcoming runs:');
                foreach ($result['upcoming_runs'] as $i => $run_time) {
                    $this->output->writeln('  ' . ($i + 1) . ". {$run_time}");
                }
            }

            return;
        }

        // Test the full scheduler configuration
        $this->output->writeln('Testing scheduler configuration...');
        $this->output->writeln();

        $scheduler = $this->get_scheduler();
        $lister = new Lister($scheduler);
        
        $validation = $lister->validate_configuration();

        if ($validation['total'] === 0) {
            $this->output->err('Warning: No scheduled tasks found.');
            return;
        }

        $this->output->writeln("Found {$validation['total']} scheduled task(s)");
        $this->output->writeln();

        foreach ($validation['results'] as $result) {
            $this->output->writeln("[{$result['number']}] {$result['description']}");
            $this->output->writeln("    Expression: {$result['expression']}");

            if (!$result['valid']) {
                $this->output->err('    ✗ Invalid cron expression!');
            } else {
                $this->output->writeln('    ✓ Valid');
                $this->output->writeln("    Readable: {$result['readable']}");
            }

            $this->output->writeln();
        }

        if (!$validation['valid']) {
            $this->output->err('Warning: Some tasks have invalid configurations.');
        } else {
            $this->output->writeln('All tasks configured correctly!');
        }
    }

    public function schedule_help(): void
    {
        $this->output->writeln('Atomic Scheduler - Task Scheduling System');
        $this->output->writeln(\str_repeat('=', 50));
        $this->output->writeln();

        $this->output->writeln('Commands:');
        $this->output->writeln('  schedule/run          Run all due scheduled tasks');
        $this->output->writeln('  schedule/work [sec]   Run scheduler daemon (default: 60s)');
        $this->output->writeln('  schedule/list         List all scheduled tasks');
        $this->output->writeln('  schedule/test [expr]  Test scheduler or cron expression');
        $this->output->writeln('  schedule/help         Show this help message');

        $this->output->writeln();
        $this->output->writeln('Setup:');
        $this->output->writeln('  1. Create routes/schedule.php');
        $this->output->writeln('  2. Define tasks using the Scheduler API');
        $this->output->writeln('  3. Add a cron job: * * * * * php atomic schedule/run');

        $this->output->writeln();
        $this->output->writeln('Example schedule.php:');
        $this->output->writeln('  <?php');
        $this->output->writeln('  use Engine\\Atomic\\Scheduler\\Scheduler;');
        $this->output->writeln('  ');
        $this->output->writeln('  $scheduler = Scheduler::instance();');
        $this->output->writeln('  ');
        $this->output->writeln('  $scheduler->call(function () {');
        $this->output->writeln('      // Clean up old logs');
        $this->output->writeln("  })->daily()->at('03:00')->description('Cleanup logs');");
        $this->output->writeln('  ');
        $this->output->writeln('  $scheduler->call(\'App\\Tasks\\SendReports::run\')');
        $this->output->writeln("      ->weeklyOn(1, '09:00')");
        $this->output->writeln("      ->description('Send weekly reports');");

        $this->output->writeln();
        $this->output->writeln('Frequency Methods:');
        $this->output->writeln('  every_minute()        Every minute');
        $this->output->writeln('  every_five_minutes()   Every 5 minutes');
        $this->output->writeln('  every_ten_minutes()    Every 10 minutes');
        $this->output->writeln('  every_fifteen_minutes() Every 15 minutes');
        $this->output->writeln('  every_thirty_minutes() Every 30 minutes');
        $this->output->writeln('  hourly()             Every hour at :00');
        $this->output->writeln('  hourly_at(30)         Every hour at :30');
        $this->output->writeln('  daily()              Every day at midnight');
        $this->output->writeln("  daily_at('13:00')     Every day at 13:00");
        $this->output->writeln('  twice_daily(1, 13)    Twice daily at 1:00 and 13:00');
        $this->output->writeln('  weekly()             Every Sunday at midnight');
        $this->output->writeln("  weekly_on(1, '8:00')  Every Monday at 8:00");
        $this->output->writeln('  monthly()            First day of month at midnight');
        $this->output->writeln("  monthly_on(15, '9:00') 15th of month at 9:00");
        $this->output->writeln('  quarterly()          First day of quarter');
        $this->output->writeln('  yearly()             First day of year');
        $this->output->writeln("  cron('* * * * *')    Custom cron expression");

        $this->output->writeln();
        $this->output->writeln('Constraints:');
        $this->output->writeln('  weekdays()           Only run on weekdays');
        $this->output->writeln('  weekends()           Only run on weekends');
        $this->output->writeln('  sundays()            Only run on Sundays');
        $this->output->writeln('  mondays()            Only run on Mondays');
        $this->output->writeln('  tuesdays()           Only run on Tuesdays');
        $this->output->writeln('  wednesdays()         Only run on Wednesdays');
        $this->output->writeln('  thursdays()          Only run on Thursdays');
        $this->output->writeln('  fridays()            Only run on Fridays');
        $this->output->writeln('  saturdays()          Only run on Saturdays');
        $this->output->writeln("  between('09:00', '17:00') Only between times");

        $this->output->writeln();
        $this->output->writeln('Options:');
        $this->output->writeln('  without_overlapping() Prevent overlapping runs');
        $this->output->writeln("  description('name')  Set task name");

        $this->output->writeln();
        $this->output->writeln('Callbacks:');
        $this->output->writeln('  before(fn)           Run before task');
        $this->output->writeln('  after(fn)            Run after task');
        $this->output->writeln('  on_success(fn)        Run on success');
        $this->output->writeln('  on_failure(fn)        Run on failure');
    }

    /**
     * Get the scheduler instance with registered tasks.
     *
     * @return SchedulerCore
     */
    protected function get_scheduler(): SchedulerCore
    {
        $scheduler = SchedulerCore::instance();
        $scheduler->register_schedule();
        return $scheduler;
    }
}
