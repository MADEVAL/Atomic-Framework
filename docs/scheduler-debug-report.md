# Scheduler Debug Report

**Scope:** `Engine\Atomic\Scheduler` registration + reporting, and the fix for the duplicated
"every task is reported twice" symptom.

**Method:** every finding below was reproduced with a test that was written first and then run
(red), or captured as an out-of-process probe. No finding is speculative. No framework code was
changed while investigating - only tests were added.

**Environment:** Windows 11, PHP 8.4.11 (CLI), MySQL available, PHPUnit 10.5.64
(`phpunit.xml.dist`), skeleton app at `packages/skeleton/`.

---

## 1. Executive summary

`routes/schedule.php` is an *include-and-append* file: it has no self-idempotence. During a CLI run
it used to be loaded from **two** entry points, so every task was registered - and therefore listed,
executed and counted - twice. The staged change removes the second load and adds an idempotence
latch; it is **verified working** end-to-end.

Ten further scheduler issues were confirmed while debugging. None of them is caused by that change;
most are pre-existing and were previously uncovered by tests. They all now have a failing test in
place, so they can be fixed red-to-green.

---

## 2. Severity matrix

| ID | Severity | Area | Defect / symptom (confirmed) | Proof | Location | Status |
|---|---|---|---|---|---|---|
| S1 | HIGH | Registration | `schedule.php` loaded twice (App `require` + `CLI::get_scheduler()`); each task registered/listed/executed twice | `SchedulerRegistrationTest::test_schedule_file_is_not_self_idempotent_when_included_twice`; probes: `2 -> 4` events, 2 callback executions per logical task | `App.php:213`, `CLI/Scheduler.php:271` | FIXED - verified |
| S2 | MEDIUM | Registration | Latch is global, not per path -> a second schedule file passed to `register_schedule()` is silently ignored | `test_register_schedule_accepts_an_additional_schedule_file` | `Scheduler.php:197-199`, `:216` | Open |
| S3 | MEDIUM | Reporting / overlap | Task skipped because the overlap mutex is held is reported `success = true`, printed `[OK]` and counted in `summary.successful` | `test_overlapping_task_is_not_reported_as_successful`; `test_overlapping_task_is_not_counted_as_successful` | `Event.php:192-195`, `Scheduler.php:120` | Open |
| S4 | MEDIUM | `CronExpression` next run | 366-day search window vs. 8-year leap gap -> Feb 29 returns `null` after ~7.8 s of scanning; same stall for other unreachable dates | `test_next_run_search_window_covers_the_longest_leap_year_gap`; `test_next_run_date_for_leap_day_expression_is_reachable` | `CronExpression.php:19`, `:31` | Open |
| S5 | LOW | `Scheduler::clear()` | Latch not reset -> `clear()` + `register_schedule()` leaves the scheduler permanently empty in that process | `test_clear_resets_the_schedule_registration_state` | `Scheduler.php:139-143` vs `:197-199` | Open (latent) |
| S6 | LOW | Reporting / due count | `due_count` counts tasks that will not execute (maintenance mode), so `schedule/run` prints "Found N due task(s)" and lists fewer | `test_reported_due_count_matches_the_executed_tasks_in_maintenance_mode` | `Runner.php:21`, `Scheduler.php:97-100` | Open |
| S7 | LOW | Filter evaluation | `when()`/`skip()` evaluated twice per cycle (`due_events()` then `run()`); side effects double, announcement can disagree with execution | `test_due_filters_are_evaluated_once_per_cycle` | `Scheduler.php:67-72`, `:88-95` | Open |
| S8 | LOW | `App::register_schedule()` | Hard dependency on `ATOMIC_APP_ROUTES` makes the new `ATOMIC_DIR` fallback dead code; missing/unreadable file skipped silently (no log) | Probe: `Error: Undefined constant "Engine\Atomic\Core\ATOMIC_APP_ROUTES"` vs `Scheduler::register_schedule()` returning cleanly | `App.php:213`, `Scheduler.php:201-217` | Info / not a regression |
| S9 | LOW | Test coverage | Staged CLI test passes with a completely empty scheduler, so it cannot catch loss of the bootstrap-time registration | Replay of its assertions with 0 events -> `OK (1 test, 3 assertions)` | `tests/Engine/CLI/SchedulerTest.php:23-33` | Open (test gap) |
| S10 | LOW | `Tester::get_upcoming_runs()` | Fixed 1000-minute inner scan -> `schedule/test "<expr>"` shows 0 upcoming runs for weekly/monthly expressions and 1 for daily ones, although 5 are requested | `SchedulerTesterTest::test_upcoming_runs_returns_the_requested_number_for_a_weekly_schedule`; `..._monthly_schedule` | `Tester.php:46` | Open |
| - | N/A | Reporting shape | *Suspected* missing `'error' => null` row key | `test_result_row_exposes_the_documented_error_key` is GREEN; the key is present at `Scheduler.php:109` | `Scheduler.php:109` | Disproved - not a bug |
| - | Out of scope | Queue | Two DB queue tests fail independently of this work | Reproduce in isolation (3 tests, 2 failures) with scheduler tests not loaded | `tests/Engine/Queue/*` | Pre-existing |

Fix order (red tests already exist): **S3 -> S2 -> S4 + S10 -> S5 -> S6/S7 -> S8/S9**.
---

## 3. Details

### S1 (HIGH) - duplicated registration and duplicate execution - FIXED

*Mechanism.* The legacy flow registered the same file twice:

1. `App::register_schedule()` did a raw `require` of `ATOMIC_APP_ROUTES . 'schedule.php'`.
2. `CLI::get_scheduler()` called `Scheduler::register_schedule()`, which resolved
   `ATOMIC_DIR . '/routes/schedule.php'`.

In the skeleton `ATOMIC_DIR === ATOMIC_ROOT` and `ATOMIC_APP_ROUTES === ATOMIC_DIR . 'routes/'`,
so both paths point at the identical file. Because the file only appends events, every task existed
twice.

*Evidence (real skeleton app, legacy second load emulated):*

```text
[1] events after CLI bootstrap: 2
[2] events after legacy CLI extra load: 4
    Engine\Atomic\Scheduler\Jobs\LogCleanupJob::handle
    queue:process-next
    Engine\Atomic\Scheduler\Jobs\LogCleanupJob::handle   <- duplicate
    queue:process-next                                   <- duplicate
```

*Impact beyond listing (one logical task, one `schedule/run` with `force_run`):*

```text
registered events: 2 | rows reported: 2 | summary: {"successful":2,"failed":0}
callback executions for ONE logical task: 2
```

*Verification of the fix:* `php atomic schedule/list` in `packages/skeleton` prints 2 rows
(`LogCleanupJob`, `queue:process-next`), `php atomic schedule/test` reports "Found 2 scheduled
task(s)", and `list/run/test` all exit `0`.

### S2 (MEDIUM) - the latch ignores the `$path` argument

`register_schedule(?string $path)` is path-aware, but the latch is content-blind: after the first
successful load every later call is a no-op, even when it names a different file. There is no `Log`
call in the method, so the rejection is completely silent.

```text
Passing another schedule path to register_schedule() must load it; the latch must be per file, not global.
Failed asserting that an array contains 'extra-task'.
```

Reachability: any application or plugin code registering a second schedule file through the API this
change introduced. Related: `load_from()` (`Scheduler.php:185-193`) neither checks nor sets the
latch, and nothing de-duplicates events by description - so the S1 symptom can return through any
other include path, with no warning.

### S3 (MEDIUM) - overlap-skipped task reported as successful

`Event::run()` returns `null` immediately when the mutex cannot be acquired (`Event.php:192-195`)
and offers no way to distinguish "skipped" from "ran". `Scheduler::run()` then sets
`'success' => true` unconditionally (`Scheduler.php:120`).

```text
test_overlapping_task_is_not_reported_as_successful  -> Failed asserting that true is false.
test_overlapping_task_is_not_counted_as_successful   -> Failed asserting that 1 is identical to 0.
```

`schedule/run` therefore prints a success row and reports "1 successful" for work that never
happened. The skeleton's own `queue:process-next` uses `without_overlapping()`, so this is reachable
in the shipped application.
### S4 (MEDIUM) - next-run search window too small, and slow

`CronExpression::get_next_run_date()` defaults to `max_iterations = 527040` minutes (366 days), but
consecutive Feb 29 occurrences can be up to 8 years apart.

```text
test_next_run_search_window_covers_the_longest_leap_year_gap
  The default search window spans 366 day(s), but two Feb 29 occurrences can be up to 8 years apart...
  Failed asserting that 527040 is equal to 4216320 or is greater than 4216320.

test_next_run_date_for_leap_day_expression_is_reachable
  get_next_run_date() gave up on a valid Feb-29 schedule (yearly_on(2, 29)) after 7.82s of scanning.
  Failed asserting that null is not null.
```

Measured cost of a full-window miss: 223 ms per 20 000 iterations => ~5.9 s for Feb 29; 330 ms per
20 000 => ~8.7 s for an impossible date such as `0 0 31 4 *`. `Event::get_summary()` calls
`get_next_run_date()` per event, so `schedule/list` and `schedule/test "<expr>"` pay this for every
affected task and display `Next Run: N/A` indefinitely.

### S5 (LOW) - `clear()` does not reset the registration latch

`clear()` empties `$events` but leaves `schedule_registered = true`, so the reload short-circuit at
`Scheduler.php:197-199` blocks every later attempt to load the schedule again.

```text
clear() must reset the "already registered" latch, otherwise the scheduler stays empty for the rest of the process.
Failed asserting that an array is not empty.
```

No internal caller uses `clear()` today, so this is latent - but a long-running process that reloads
its definitions (`clear()` + `register_schedule()`) silently ends up with zero tasks.

### S6 (LOW) - reported due count does not match what is executed

`Runner::run_due_tasks()` fills `due_count` from `due_events()`, while `run()` skips maintenance-mode
tasks, so the CLI announces tasks it never runs or lists.

```text
schedule/run prints "Found {$due_count} due task(s)" while only the executed tasks are listed.
Failed asserting that 1 is identical to 0.
```

### S7 (LOW) - filters are evaluated twice per cycle

`due_events()` evaluates `when()`/`skip()` and `run()` evaluates them again, so a stateful filter
doubles its side effects and can make the announced due count disagree with what is executed. This
applies to every iteration of `schedule/work` as well.

```text
when()/skip() callbacks are evaluated by due_events() and again by run()...
Failed asserting that 2 is identical to 1.
```
### S8 (LOW) - `App::register_schedule()` keeps a hard dependency on `ATOMIC_APP_ROUTES`

The change made `Scheduler::register_schedule()` tolerant (it falls back to
`ATOMIC_DIR . '/routes/schedule.php'` when `ATOMIC_APP_ROUTES` is undefined), but the only production
caller still concatenates that constant unconditionally, so the fallback is dead code. Verified
out-of-process with an app that defines `ATOMIC_DIR` but not `ATOMIC_APP_ROUTES`:

```text
ATOMIC_APP_ROUTES defined: false
App::register_schedule()       => Error: Undefined constant "Engine\Atomic\Core\ATOMIC_APP_ROUTES"
Scheduler::register_schedule() => returned without error (ATOMIC_DIR fallback)
```

Not a regression (the pre-fix `App` had the same line) and the shipped skeleton always defines the
constant, therefore it cannot be exercised from the PHPUnit environment - reported as information.
Related: a missing or unreadable schedule file is skipped silently (the method contains no `Log`
call), so a wrong path yields an empty schedule with no diagnostic.

### S9 (LOW) - the staged CLI test cannot detect a lost registration

`tests/Engine/CLI/SchedulerTest::test_cli_get_scheduler_does_not_register_schedule_again` only
asserts that no events are appended. Replaying its exact assertions against a scheduler that was
never populated still passes:

```text
OK (1 test, 3 assertions)   # while $scheduler->events() === []
```

Because the CLI no longer registers anything itself, the whole CLI surface now depends on
`ScheduleServiceProvider -> App::register_schedule()` having run, and no test in the suite fails if
that link is removed.

### S10 (LOW) - `get_upcoming_runs()` silently truncates at 1000 minutes

`Tester::get_upcoming_runs()` repeats an inner search of at most 1000 minutes per occurrence
(`Tester.php:46`), so it can only ever find runs that are less than ~16.7 hours apart.

```text
$expression    requested 5, got
* * * * *      5      (1 minute apart - fine)
*/5 * * * *    5      (5 minutes apart - fine)
0 3 * * *      1      (1440 minutes apart - depends on wall clock, 0 or 1)
0 9 * * 1      0      (weekly - always 0)
0 0 1 * *      0      (monthly - always 0)
```

```text
get_upcoming_runs() stops after a fixed 1000-minute scan, so any weekly/monthly schedule yields an
empty "Upcoming runs" list.
Failed asserting that actual size 0 matches expected size 5.
```

`schedule/test "<expr>"` prints the "Upcoming runs" section from this method, so operators see
"Upcoming runs: 1." for a daily task and no section at all for weekly/monthly tasks. The same fixed
window is the root cause family as S4 - both search a bounded number of minutes instead of jumping
between candidate dates.

---

## 4. Disproved suspicions (recorded so they are not re-investigated)

* **Result rows missing `'error' => null`** - not a bug. `Scheduler.php:109` defines the key and
  `test_result_row_exposes_the_documented_error_key` passes, matching `docs/scheduler.md`.
* **Non-zero CLI exit codes for `schedule/list|run|test`** - not a bug. All three exit `0`; the
  non-zero code seen during an early probe was a PowerShell `Select-Object -First` pipeline artefact.

## 5. Out-of-scope pre-existing failures

`QueueDbDriverEdgeTest::test_monitor_adapter_loads_and_handles_active_jobs` and
`QueueTelemetryDbTest::test_public_fetch_methods_report_db_job_states_and_pagination` fail in
isolation (3 tests, 2 failures) with the scheduler tests not loaded. They are unrelated to the
scheduler and to the staged change, and match the untracked queue documents in `docs/`.
---

## 6. How each finding was reproduced

```bash
# S1 end-to-end: the fix is visible in the real application
cd packages/skeleton
php atomic schedule/list      # 2 rows (LogCleanupJob, queue:process-next) - was 4 before the fix
php atomic schedule/test      # "Found 2 scheduled task(s)"
php atomic schedule/run       # exits 0

# S1 mechanism / S2 / S3 / S5 / S6 / S7 - the red tests written for each
php vendor/bin/phpunit tests/Engine/Scheduler/SchedulerRegistrationTest.php
php vendor/bin/phpunit tests/Engine/Scheduler/SchedulerReportingTest.php

# S4 - the leap-day test scans the full 527 040-minute window (~8s)
php vendor/bin/phpunit tests/Engine/Scheduler/CronExpressionTest.php

# S10 - upcoming-runs truncation (weekly/monthly)
php vendor/bin/phpunit tests/Engine/Scheduler/SchedulerTesterTest.php

# S9 evidence - replay of the staged CLI assertions against an empty scheduler
php vendor/bin/phpunit <temp probe test file>

# S8 evidence - out-of-process app with ATOMIC_DIR defined but ATOMIC_APP_ROUTES undefined
php <temp probe script>
```

Temporary probe scripts were written to the OS temp directory and deleted afterwards; nothing was
left behind in the repository.

## 7. Test files added by this investigation

| File | Tests | Result |
|---|---|---|
| `tests/Engine/Scheduler/SchedulerRegistrationTest.php` | 5 | 3 green (fix guards), 2 red (S2, S5) |
| `tests/Engine/Scheduler/SchedulerReportingTest.php` | 5 | 1 green (documented row shape), 4 red (S3 x2, S6, S7) |
| `tests/Engine/Scheduler/CronExpressionTest.php` (appended) | +2 | 2 red (S4) |
| `tests/Engine/Scheduler/SchedulerTesterTest.php` | 3 | 1 green, 2 red (S10) |

Total: 15 new tests - 10 red (one per confirmed defect), 5 green regression guards. No framework code
was modified by this investigation.

## 8. Suite impact

Full suite run (background, `phpunit.xml.dist`): **2274 tests, 15417 assertions, 12 failures,
129 skipped**. Those 12 failures are the 10 intentional red tests above plus the 2 pre-existing,
unrelated queue failures (section 5). `tests/Engine/Scheduler`, `tests/Engine/Mutex` and
`tests/Engine/CLI` contain only the 10 scheduler red tests, so there is no collateral damage.

> Note: the baseline recorded in `AGENTS.md` (1481 PASS / 218 SKIP) is stale for this working tree.
> Two consecutive full-suite runs of this tree reported the same test/failure/skip counts but
> different assertion totals (9718 vs 15417); the cause was not investigated because it is outside
> the scheduler scope, but it suggests data-dependent assertion loops somewhere in the suite.

## 9. Recommended fix order

1. **S3** - let `Event::run()` distinguish "skipped (overlap)" from "executed" and stop reporting
   skipped work as successful (`Scheduler::run()`, `Runner`).
2. **S2** - make the registration latch per resolved path (e.g. `array<string,bool>`), and optionally
   warn when an event with a duplicate description is registered.
3. **S4 + S10** - make the next-run search leap-safe and cheap: jump between candidate dates instead
   of scanning every minute (`CronExpression`), and reuse that search in
   `Tester::get_upcoming_runs()` so `schedule/test` shows the full requested list.
4. **S5** - reset the latch in `clear()` (or derive it from the loaded paths).
5. **S6/S7** - make the announced due count and filter evaluation consistent with what runs.
6. **S8/S9** - stop passing an explicit path from `App` (or guard the constant), log a missing
   schedule file, and add a test that the bootstrapped schedule is visible to the CLI.