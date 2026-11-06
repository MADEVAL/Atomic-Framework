<?php
declare(strict_types=1);

namespace Tests\Engine\Scheduler;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\CLI;
use Engine\Atomic\Core\App;
use Engine\Atomic\Scheduler\Scheduler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

/**
 * Regression coverage for the "every scheduled task is reported twice" bug.
 *
 * `routes/schedule.php` used to be loaded from two entry points during a CLI
 * run:
 *
 *   1. `App::register_schedule()` - a raw `require` of ATOMIC_APP_ROUTES . 'schedule.php'
 *   2. `CLI::get_scheduler()`      - `Scheduler::register_schedule()`, which loaded
 *                                    ATOMIC_DIR . '/routes/schedule.php'
 *
 * In the application skeleton both constants resolve to the same directory
 * (`ATOMIC_DIR === ATOMIC_ROOT` and `ATOMIC_APP_ROUTES === ATOMIC_DIR . 'routes/'`),
 * so the very same file was included twice. A schedule file only appends events,
 * therefore every task was registered - and listed/executed - twice.
 */
final class SchedulerRegistrationTest extends TestCase
{
    private string $schedule_file;

    /** @var list<string> */
    private array $temp_files = [];

    protected function setUp(): void
    {
        Scheduler::reset();
        $this->schedule_file = $this->make_schedule_file('application-task');
    }

    protected function tearDown(): void
    {
        foreach ($this->temp_files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temp_files = [];
        Scheduler::reset();
    }

    /**
     * @return list<string>
     */
    private function descriptions(Scheduler $scheduler): array
    {
        return array_map(
            static fn($event): string => (string)($event->get_summary()['description'] ?? ''),
            $scheduler->events()
        );
    }

    private function schedule_source(string $description, string $extra = ''): string
    {
        return "<?php\ndeclare(strict_types=1);\nif (!defined('ATOMIC_START')) exit;\n"
            . '$scheduler->call(static function (): void {})->description('
            . var_export($description, true) . ");\n" . $extra;
    }

    private function make_schedule_file(string $description, string $extra = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'atomic_schedule_');
        if ($path === false) {
            throw new \RuntimeException('Cannot create schedule fixture.');
        }
        $this->temp_files[] = $path;
        file_put_contents($path, $this->schedule_source($description, $extra));

        return $path;
    }

    public function test_schedule_file_is_not_self_idempotent_when_included_twice(): void
    {
        $scheduler = Scheduler::instance();

        // Entry point #1 of the legacy flow: the raw require done by App::register_schedule().
        require $this->schedule_file;
        $after_first_include = count($scheduler->events());

        // A raw include bypasses the loader's registration bookkeeping.
        require $this->schedule_file;

        $this->assertGreaterThan(0, $after_first_include);
        $this->assertCount(
            $after_first_include * 2,
            $scheduler->events(),
            'Including the same schedule file twice registers every task twice - the root cause of the duplicated report.'
        );

        $descriptions = $this->descriptions($scheduler);
        $this->assertNotSame(
            count($descriptions),
            count(array_unique($descriptions)),
            'The duplicate registration surfaces as two rows carrying the same description.'
        );
    }

    public function test_app_and_cli_entry_points_register_the_schedule_only_once(): void
    {
        $app = App::instance();
        $app->register_schedule();

        $scheduler = Scheduler::instance();
        $after_bootstrap = $this->descriptions($scheduler);
        $this->assertNotEmpty($after_bootstrap, 'The application schedule must be registered during bootstrap.');

        // The CLI helper must no longer load the schedule a second time.
        $cli_scheduler = ReflectionHelper::invoke(new CLI(), 'get_scheduler');
        $this->assertSame($scheduler, $cli_scheduler);
        $this->assertSame($after_bootstrap, $this->descriptions($scheduler));

        // ... and neither may a repeated bootstrap-time registration.
        $app->register_schedule();
        $this->assertSame($after_bootstrap, $this->descriptions($scheduler));
    }

    public function test_repeated_register_schedule_calls_are_idempotent(): void
    {
        $scheduler = Scheduler::instance();

        $scheduler->register_schedule($this->schedule_file);
        $registered = $this->descriptions($scheduler);
        $this->assertNotEmpty($registered);

        $scheduler->register_schedule($this->schedule_file);
        $scheduler->load_from($this->schedule_file);

        $this->assertSame($registered, $this->descriptions($scheduler));
    }

    public function test_clear_resets_the_schedule_registration_state(): void
    {
        $scheduler = Scheduler::instance();

        $scheduler->register_schedule($this->schedule_file);
        $this->assertNotEmpty($scheduler->events());

        // clear() drops the registered events; reloading them is a reasonable
        // follow-up (long-running worker reloading its definition, tests, ...).
        $scheduler->clear();
        $this->assertSame([], $scheduler->events());

        $scheduler->register_schedule($this->schedule_file);

        $this->assertNotEmpty(
            $scheduler->events(),
            'clear() must reset the "already registered" latch, otherwise the scheduler stays empty for the rest of the process.'
        );
    }

    public function test_register_schedule_accepts_an_additional_schedule_file(): void
    {
        $scheduler = Scheduler::instance();
        $scheduler->register_schedule($this->schedule_file);
        $application_tasks = count($scheduler->events());

        $extra_file = $this->make_schedule_file('extra-task');
        $scheduler->register_schedule($extra_file);

        $descriptions = $this->descriptions($scheduler);

        $this->assertGreaterThan(0, $application_tasks);
        $this->assertContains(
            'extra-task',
            $descriptions,
            'Passing another schedule path to register_schedule() must load it; the latch must be per file, not global.'
        );
        $this->assertCount($application_tasks + 1, $scheduler->events());
    }

    public static function loading_methods(): array
    {
        return [
            'load then load' => ['load_from', 'load_from'],
            'register then register' => ['register_schedule', 'register_schedule'],
            'load then register' => ['load_from', 'register_schedule'],
            'register then load' => ['register_schedule', 'load_from'],
        ];
    }

    #[DataProvider('loading_methods')]
    public function test_loading_entry_points_share_file_registration(string $first, string $second): void
    {
        $scheduler = Scheduler::instance();
        $this->assertSame($scheduler, $scheduler->$first($this->schedule_file));
        $events = $scheduler->events();
        $this->assertCount(1, $events);

        $this->assertSame($scheduler, $scheduler->$second($this->schedule_file));
        $this->assertSame($events, $scheduler->events());
    }

    public function test_load_from_resolves_path_aliases_before_registration(): void
    {
        $scheduler = Scheduler::instance();
        $alias = dirname($this->schedule_file) . DIRECTORY_SEPARATOR . '.'
            . DIRECTORY_SEPARATOR . basename($this->schedule_file);
        $this->assertSame(realpath($this->schedule_file), realpath($alias));

        $scheduler->load_from($this->schedule_file)->load_from($alias);

        $this->assertSame(['application-task'], $this->descriptions($scheduler));
    }

    public function test_clear_allows_all_loaded_files_to_register_again(): void
    {
        $scheduler = Scheduler::instance();
        $extra = $this->make_schedule_file('extra-task');
        $scheduler->load_from($this->schedule_file)->load_from($extra);
        $this->assertSame(['application-task', 'extra-task'], $this->descriptions($scheduler));

        $this->assertSame($scheduler, $scheduler->clear());
        $this->assertSame([], $scheduler->events());
        $scheduler->register_schedule($this->schedule_file)->register_schedule($extra);

        $this->assertSame(['application-task', 'extra-task'], $this->descriptions($scheduler));
    }

    public function test_new_scheduler_instance_can_load_previously_loaded_file(): void
    {
        $original = Scheduler::instance()->load_from($this->schedule_file);
        Scheduler::reset();
        $replacement = Scheduler::instance()->load_from($this->schedule_file);

        $this->assertNotSame($original, $replacement);
        $this->assertSame(['application-task'], $this->descriptions($replacement));
        $this->assertNotSame($original->events()[0], $replacement->events()[0]);
    }

    public function test_missing_file_can_be_loaded_after_it_is_created(): void
    {
        $scheduler = Scheduler::instance();
        unlink($this->schedule_file);
        $this->assertSame($scheduler, $scheduler->register_schedule($this->schedule_file));
        $this->assertSame([], $scheduler->events());

        file_put_contents($this->schedule_file, $this->schedule_source('created-later'));
        $scheduler->register_schedule($this->schedule_file);

        $this->assertSame(['created-later'], $this->descriptions($scheduler));
    }

    public function test_empty_file_is_registered_until_the_scheduler_is_cleared(): void
    {
        $scheduler = Scheduler::instance();
        file_put_contents($this->schedule_file, "<?php\ndeclare(strict_types=1);\n");
        $scheduler->load_from($this->schedule_file);
        file_put_contents($this->schedule_file, $this->schedule_source('added-later'));

        $scheduler->load_from($this->schedule_file);
        $this->assertSame([], $scheduler->events());

        $scheduler->clear()->load_from($this->schedule_file);
        $this->assertSame(['added-later'], $this->descriptions($scheduler));
    }

    public function test_recursive_load_does_not_register_the_same_file_again(): void
    {
        $scheduler = Scheduler::instance();
        $path = $this->make_schedule_file('recursive-task', <<<'PHP'
// Bound recursion so a broken loader fails the assertion instead of exhausting memory.
if (count($scheduler->events()) < 3) {
    $scheduler->load_from(__FILE__);
}
PHP);

        $scheduler->load_from($path);

        $this->assertSame(['recursive-task'], $this->descriptions($scheduler));
    }

    public function test_failed_load_rolls_back_events_and_nested_file_registrations(): void
    {
        $scheduler = Scheduler::instance()->load_from($this->schedule_file);
        $original_events = $scheduler->events();
        $child = $this->make_schedule_file('child-task');
        $nested_load = '$scheduler->load_from(' . var_export($child, true) . ");\n";
        $parent = $this->make_schedule_file(
            'parent-task',
            $nested_load . "throw new \\RuntimeException('broken schedule');\n"
        );

        try {
            $scheduler->load_from($parent);
            $this->fail('The schedule exception must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('broken schedule', $e->getMessage());
        }

        $this->assertSame($original_events, $scheduler->events());
        $scheduler->load_from($this->schedule_file);
        $this->assertSame($original_events, $scheduler->events(), 'Previously loaded files must stay registered.');

        file_put_contents($parent, $this->schedule_source('parent-task', $nested_load));
        $scheduler->load_from($parent)->register_schedule($parent)->load_from($child);

        $this->assertSame(['application-task', 'parent-task', 'child-task'], $this->descriptions($scheduler));
    }

    public function test_parse_error_does_not_mark_the_file_as_loaded(): void
    {
        $scheduler = Scheduler::instance();
        file_put_contents($this->schedule_file, '<?php this is invalid PHP');
        try {
            $scheduler->load_from($this->schedule_file);
            $this->fail('The parse error must propagate.');
        } catch (\ParseError) {
            $this->assertSame([], $scheduler->events());
        }

        file_put_contents($this->schedule_file, $this->schedule_source('fixed-task'));
        $scheduler->load_from($this->schedule_file)->load_from($this->schedule_file);

        $this->assertSame(['fixed-task'], $this->descriptions($scheduler));
    }
}
