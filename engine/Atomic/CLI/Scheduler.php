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
        echo "Running scheduled tasks...\n\n";

        $scheduler = $this->get_scheduler();
        $runner = new Runner($scheduler);
        
        $result = $runner->run_due_tasks();
        
        if ($result['due_count'] === 0) {
            echo "No scheduled tasks are due.\n";
            return;
        }

        echo "Found {$result['due_count']} due task(s)\n\n";

        foreach ($result['results'] as $task_result) {
            echo $runner->format_result($task_result) . "\n";
        }

        echo "\nCompleted: {$result['summary']['successful']} successful, {$result['summary']['failed']} failed\n";
    }

    public function schedule_work(): void
    {
        $args = $this->get_cli_args();
        $sleep_seconds = isset($args[0]) ? (int)$args[0] : 60;

        echo "Starting scheduler worker...\n";
        echo "Press Ctrl+C to stop.\n\n";

        $scheduler = $this->get_scheduler();
        $worker = new Worker($scheduler, $sleep_seconds);
        
        $worker->run(function($message) {
            echo $message . "\n";
        });
    }

    public function schedule_list(): void
    {
        echo "Scheduled Tasks\n";
        echo \str_repeat('=', 80) . "\n\n";

        $scheduler = $this->get_scheduler();
        $lister = new Lister($scheduler);
        
        $list_data = $lister->get_formatted_list();

        if ($list_data['total'] === 0) {
            echo "No scheduled tasks defined.\n";
            echo "\nTo define tasks, create a schedule.php file in your app directory:\n";
            echo "  app/schedule.php\n";
            echo "  app/Console/schedule.php\n";
            echo "  routes/schedule.php\n";
            return;
        }

        $column_widths = [20, 35, 20, 8];

        // Print header
        echo \sprintf(
            "%-{$column_widths[0]}s %-{$column_widths[1]}s %-{$column_widths[2]}s %-{$column_widths[3]}s\n",
            $list_data['headers'][0], $list_data['headers'][1], $list_data['headers'][2], $list_data['headers'][3]
        );
        echo \str_repeat('-', \array_sum($column_widths) + 3) . "\n";

        foreach ($list_data['rows'] as $row) {
            $description = $row['description'];
            
            // Truncate long descriptions
            if (\strlen($description) > $column_widths[1] - 3) {
                $description = \substr($description, 0, $column_widths[1] - 3) . '...';
            }

            echo \sprintf(
                "%-{$column_widths[0]}s %-{$column_widths[1]}s %-{$column_widths[2]}s %-{$column_widths[3]}s\n",
                $row['expression'], $description, $row['next_run'], $row['is_due']
            );
        }

        echo "\nTotal: {$list_data['total']} task(s)\n";
        echo "\nCron Expression Format: minute hour day-of-month month day-of-week\n";
        echo "Example: '0 3 * * *' = Every day at 3:00 AM\n";
    }

    public function schedule_test(): void
    {
        $args = $this->get_cli_args();
        $tester = new Tester();

        if (isset($args[0])) {
            // Test a specific cron expression
            $expression = $args[0];
            
            echo "Testing cron expression: {$expression}\n\n";

            $result = $tester->test_expression($expression);

            if (!$result['valid']) {
                echo "Error: Invalid cron expression\n";
                echo "Format: minute hour day-of-month month day-of-week\n";
                echo "Example: '0 * * * *' (every hour)\n";
                return;
            }

            echo "Valid: Yes\n";
            echo "Description: {$result['description']}\n";
            echo "Due now: " . ($result['is_due'] ? 'Yes' : 'No') . "\n";
            echo "Next run: " . ($result['next_run'] ?? 'N/A') . "\n";

            // Show upcoming runs
            if (!empty($result['upcoming_runs'])) {
                echo "\nUpcoming runs:\n";
                foreach ($result['upcoming_runs'] as $i => $run_time) {
                    echo "  " . ($i + 1) . ". {$run_time}\n";
                }
            }

            return;
        }

        // Test the full scheduler configuration
        echo "Testing scheduler configuration...\n\n";

        $scheduler = $this->get_scheduler();
        $lister = new Lister($scheduler);
        
        $validation = $lister->validate_configuration();

        if ($validation['total'] === 0) {
            echo "Warning: No scheduled tasks found.\n";
            return;
        }

        echo "Found {$validation['total']} scheduled task(s)\n\n";

        foreach ($validation['results'] as $result) {
            echo "[{$result['number']}] {$result['description']}\n";
            echo "    Expression: {$result['expression']}\n";

            if (!$result['valid']) {
                echo "    ✗ Invalid cron expression!\n";
            } else {
                echo "    ✓ Valid\n";
                echo "    Readable: {$result['readable']}\n";
            }

            echo "\n";
        }

        if (!$validation['valid']) {
            echo "Warning: Some tasks have invalid configurations.\n";
        } else {
            echo "All tasks configured correctly!\n";
        }
    }

    public function schedule_help(): void
    {
        echo "Atomic Scheduler - Task Scheduling System\n";
        echo \str_repeat('=', 50) . "\n\n";

        echo "Commands:\n";
        echo "  schedule/run          Run all due scheduled tasks\n";
        echo "  schedule/work [sec]   Run scheduler daemon (default: 60s)\n";
        echo "  schedule/list         List all scheduled tasks\n";
        echo "  schedule/test [expr]  Test scheduler or cron expression\n";
        echo "  schedule/help         Show this help message\n";

        echo "\nSetup:\n";
        echo "  1. Create a schedule.php file in your app directory\n";
        echo "  2. Define tasks using the Scheduler API\n";
        echo "  3. Add a cron job: * * * * * php atomic schedule/run\n";

        echo "\nExample schedule.php:\n";
        echo "  <?php\n";
        echo "  use Engine\\Atomic\\Scheduler\\Scheduler;\n";
        echo "  \n";
        echo "  \$scheduler = Scheduler::instance();\n";
        echo "  \n";
        echo "  \$scheduler->call(function () {\n";
        echo "      // Clean up old logs\n";
        echo "  })->daily()->at('03:00')->description('Cleanup logs');\n";
        echo "  \n";
        echo "  \$scheduler->call('App\\Tasks\\SendReports::run')\n";
        echo "      ->weeklyOn(1, '09:00')\n";
        echo "      ->description('Send weekly reports');\n";

        echo "\nFrequency Methods:\n";
        echo "  every_minute()        Every minute\n";
        echo "  every_five_minutes()   Every 5 minutes\n";
        echo "  every_ten_minutes()    Every 10 minutes\n";
        echo "  every_fifteen_minutes() Every 15 minutes\n";
        echo "  every_thirty_minutes() Every 30 minutes\n";
        echo "  hourly()             Every hour at :00\n";
        echo "  hourly_at(30)         Every hour at :30\n";
        echo "  daily()              Every day at midnight\n";
        echo "  daily_at('13:00')     Every day at 13:00\n";
        echo "  twice_daily(1, 13)    Twice daily at 1:00 and 13:00\n";
        echo "  weekly()             Every Sunday at midnight\n";
        echo "  weekly_on(1, '8:00')  Every Monday at 8:00\n";
        echo "  monthly()            First day of month at midnight\n";
        echo "  monthly_on(15, '9:00') 15th of month at 9:00\n";
        echo "  quarterly()          First day of quarter\n";
        echo "  yearly()             First day of year\n";
        echo "  cron('* * * * *')    Custom cron expression\n";

        echo "\nConstraints:\n";
        echo "  weekdays()           Only run on weekdays\n";
        echo "  weekends()           Only run on weekends\n";
        echo "  sundays()            Only run on Sundays\n";
        echo "  mondays()            Only run on Mondays\n";
        echo "  tuesdays()           Only run on Tuesdays\n";
        echo "  wednesdays()         Only run on Wednesdays\n";
        echo "  thursdays()          Only run on Thursdays\n";
        echo "  fridays()            Only run on Fridays\n";
        echo "  saturdays()          Only run on Saturdays\n";
        echo "  between('09:00', '17:00') Only between times\n";

        echo "\nOptions:\n";
        echo "  without_overlapping() Prevent overlapping runs\n";
        echo "  description('name')  Set task name\n";

        echo "\nCallbacks:\n";
        echo "  before(fn)           Run before task\n";
        echo "  after(fn)            Run after task\n";
        echo "  on_success(fn)        Run on success\n";
        echo "  on_failure(fn)        Run on failure\n";
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
