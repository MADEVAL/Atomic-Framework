# Scheduler Debug Report

Re-verified against the current worktree on 2026-11-06. Only this report and test
wording were changed; no framework bugs were fixed during this review.
Environment: Windows, PHP 8.4.11, PHPUnit 10.5.66, installed vendor dependencies.
Locations below are repository-relative; scheduler source paths are abbreviated
as `Scheduler/…` for `engine/Atomic/Scheduler/…`.

## Current findings

| ID | Status | Current cause / resolution | Source evidence | Verification |
|---|---|---|---|---|
| S1 | Fixed | App delegates registration; CLI only returns the singleton. Both loader entry points share canonical-path deduplication. Raw `require` still bypasses the loader, which is expected. | `engine/Atomic/Core/App.php:208-214`; `engine/Atomic/CLI/Scheduler.php:271-274`; `Scheduler/Scheduler.php:187-208` | Registration tests at lines 84, 110, 129 and 194 pass. |
| S2 | Fixed | Registration state is keyed by `realpath`, not a global boolean; a second file loads normally. `load_from()` and `register_schedule()` share this state. | `Scheduler/Scheduler.php:189-200,219-229` | `SchedulerRegistrationTest.php:163` and entry-point combinations at :194 pass. |
| S3 | Fixed | Event exposes per-run skip state. Scheduler preserves result keys and adds `skipped`, with `success=false` for overlap refusal. Runner counts skipped rows separately and CLI displays `[skipped]`. | `Scheduler/Event.php:192-195`; `Scheduler/Scheduler.php:119-122`; `Scheduler/Runner.php:37-38` | Both overlap regression tests pass; added coverage checks skip state reset, summary counts, formatting and CLI output. |
| S4 | Fixed | Next-run search skips month/day/hour fields, with a ten-year horizon. `527040` limits search steps, not elapsed minutes. The old minimum-minute-window assertion is no longer applicable and is absent. | `Scheduler/CronExpression.php:16-68` | Cron tests :170, :186 and :195 pass; century-gap probe returns `2104-02-29 00:00:00` from `2096-02-29`. |
| S5 | Fixed | `clear()` resets both events and loaded-file state, allowing all schedule files to reload. | `Scheduler/Scheduler.php:140-144` | Registration tests :143 and :217 pass. |
| S6 | Fixed | `due_events()` now applies maintenance policy and supports forced cron bypass while retaining filters and maintenance restrictions. Runner and Worker report only eligible tasks. | `Scheduler/Scheduler.php`; `Scheduler/Runner.php` | Original maintenance reporting test and new Worker/forced-run regression tests pass. |
| S7 | Fixed | Selection evaluates cron, filters and maintenance once; Runner and Worker execute the same selected events through `run_events()`. Standalone `run()` selects once. | `Scheduler/Scheduler.php`; `Scheduler/Runner.php`; `Scheduler/Worker.php` | Original Runner regression and Worker/standalone single-evaluation tests pass. |
| S8 | Fixed dependency; silent missing-file behavior remains | App no longer concatenates an undefined routes constant. Scheduler chooses routes constant, directory fallback, or no path. Missing/unreadable files still return silently; that is an informational behavior, not a demonstrated new defect. | `engine/Atomic/Core/App.php:213`; `Scheduler/Scheduler.php:189-191,219-229` | Fresh-process probe without `ATOMIC_APP_ROUTES` returns normally; missing-file retry test at registration :242 passes. |
| S9 | Fixed | CLI test explicitly asserts the application schedule is non-empty before checking that CLI does not append events. It tests explicit App registration, not the entire service-provider bootstrap chain. | `tests/Engine/CLI/SchedulerTest.php:26-36` | `OK (1 test, 3 assertions)`, including `assertNotEmpty` at :31. |
| S10 | Still open; old description partly incorrect | Tester has its own bounded minute search and does not call the improved field-skipping next-run method. Weekly/monthly results are zero **or one**, not always zero. | `Scheduler/Tester.php:37-61`; compare `Scheduler/CronExpression.php:40-64` | Tester minute test passes; weekly/monthly count tests fail. Probes below include hourly/daily and near-occurrence examples. |

## S10: exact behavior and root cause

There are two different implementations. `CronExpression::get_next_run_date()`
skips calendar fields when they cannot match: next month, next day, next hour,
or next minute (`CronExpression.php:40-64`). That is the method fixed for S4.
`Tester::test_expression()` uses it for the single `next_run` value (:22-23),
but uses `get_upcoming_runs()` separately for the list (:25).

`Tester::get_upcoming_runs()` currently **does inspect consecutive minutes**:

1. Start at the next whole local minute (`Tester.php:37-39`).
2. For each requested occurrence, clone that cursor (:41-43).
3. Test candidates at offsets 0 through 999 inclusive (:45-50), advancing by
   exactly one minute after each mismatch. It does not skip hours or days.
4. On a match, append it and restart one minute after that occurrence (:53-56).
5. On exhausting the 1000 candidates, stop the entire list (:57-59). It does
   not resume another batch or jump to the next matching date.

Thus the first search tests minutes 1 through 1000 after the current minute.
After a match it can reach another occurrence at most 1000 minutes later.
Ordinary daily, weekly and monthly schedules can therefore return at most one
occurrence, and return zero if the first occurrence is outside that window.
Minute and hourly schedules return all five. No valid occurrences are skipped
inside the inspected window; all dates beyond it are omitted when the search
stops. The root cause is a separate, bounded search in Tester that never reuses
the improved next-run search. S4's field-skipping fix did not change Tester.

Actual probe output (UTC, 2026-11-06 12:45:16; count requested = 5):

```text
minute  [* * * * *]   -> 5, 12:46 through 12:50
hourly  [0 * * * *]   -> 5, 13:00 through 17:00
daily   [0 3 * * *]   -> 1, 2026-11-07 03:00:00
weekly  [0 9 * * 1]   -> 0
monthly [0 0 1 * *]   -> 0
daily   [46 12 * * *] -> 1, 2026-11-06 12:46:00
weekly  [46 12 * * 5] -> 1, 2026-11-06 12:46:00
monthly [46 12 6 * *] -> 1, 2026-11-06 12:46:00
leap next-run from 2096-02-29 -> 2104-02-29 00:00:00
App without ATOMIC_APP_ROUTES -> returned normally
```

The old weekly/monthly assertions expecting five runs remain valid regression
expectations for the open bug. Their messages were corrected to allow zero or
one actual occurrence. They do not assert that the list is always empty.
The CLI only prints its upcoming-runs section for a non-empty list
(`engine/Atomic/CLI/Scheduler.php:133-139`). Its single next-run value may be
correct while the list is incomplete.

## Reproduction and test evidence

```powershell
php vendor/bin/phpunit tests/Engine/Scheduler
php vendor/bin/phpunit tests/Engine/Scheduler/SchedulerRegistrationTest.php
php vendor/bin/phpunit tests/Engine/Scheduler/CronExpressionTest.php
php vendor/bin/phpunit tests/Engine/CLI/SchedulerTest.php
```

Historical isolated results before the S3 fix:

```text
SchedulerRegistrationTest: OK (17 tests, 52 assertions)
CronExpressionTest:        OK (27 tests, 92 assertions)
CLI/SchedulerTest:         OK (1 test, 3 assertions)
Scheduler directory:      Tests: 87, Assertions: 220, Failures: 6.
```

The six historical scheduler failures correspond to S3 (two), S6, S7 and S10 (two).
No failing scheduler test currently asserts the obsolete global latch or
minute-based leap-window implementation. The result-row error key remains
present at `Scheduler/Scheduler.php:110`; its reporting test at :35 passes.
Historical skeleton CLI transcripts and old full-suite/queue-failure counts
have been removed because they were not evidence from the current code run.

Recommended remaining work: S10 search reuse, then S6/S7 runner
selection consistency. Missing-file diagnostics under S8 are optional policy
work. No framework code was changed and no commit was made by this review.

Full-suite verification after the report/test-message edit:
`Tests: 2343, Assertions: 13518, Failures: 8, Skipped: 126.`
The six scheduler failures above also occur in isolation. The other two
failures are queue tests, listed by the full run as
`QueueDbDriverEdgeTest::test_monitor_adapter_loads_and_handles_active_jobs` and
`QueueTelemetryDbTest::test_public_fetch_methods_report_db_job_states_and_pagination`.
The review changed no assertions or framework code; scheduler failure counts
were identical before and after the wording correction (6).

## S3 implementation verification

Overlap refusal now has explicit per-run skip state, a backward-compatible
`skipped` result key, a separate Runner summary count, and `[skipped]` CLI output.
The original two overlap assertions remain unchanged and pass.

```text
php vendor/bin/phpunit tests/Engine/Scheduler tests/Engine/CLI
Tests: 191, Assertions: 645, Failures: 4.

php vendor/bin/phpunit
Tests: 2346, Assertions: 13379, Failures: 6, Skipped: 126.
```

The four remaining scheduler failures are S6, S7 and S10 (two). The full run
also retains the two queue failures named above. No commit was made.
