# Telemetry Statistics — Analysis & Implementation Plan

**Branch:** `feature/telemetry-statistics` (worktree `C:\DEV\AF-telemetry-stats`)
**Input:** `telemetry-redesign-reference/` (redesigned Telemetry theme: `telemetry/index.html`, `telemetry.js`, `telemetry.css`, `telemetry.mock.js`, `telemetry/API.md`, design screenshots)
**Status:** IMPLEMENTED — see §"Implementation notes" at the bottom of this file for what shipped and the decisions taken.

---

## Revision: rare events only (2026-11-18)

Statistics now record only events that are rare and worth investigating, so a normal request buffers nothing and writes nothing:

- **Kept:** exceptions, failed logins and lockouts, rate-limit denials, quota denials and renewals (plus the quota scope registry, written on metered operations only).
- **Removed:** cache hits/misses (`CountingCacheStore` and the Cache tab), `ratelimit.allowed`, mutex acquired/released/contended and the acquisition event (Redis locks show no age), `quota.requests`/`quota.spent`/`quota.spent_op` (spend charts).
- **Write path, after Laravel Pulse/Telescope:** under PHP-FPM/LiteSpeed the shutdown flush runs last and calls `fastcgi_finish_request()`/`litespeed_finish_request()` first, so the client never waits on it. Queue workers flush after each job instead of waiting for the 500-row buffer cap.

Sections below describe the original plan; where they mention the removed metrics, this revision wins.

---

## Implementation notes (post-implementation)

Shipped on this branch, in delivery order:

- **Stats core** (`Engine\Atomic\Telemetry\Stats`): `StatsRecorder` (buffered, flush-on-shutdown), `StatsQuery` (24h/7d/30d bucketing straight from hourly counters — no rollup job), `StatsStoreInterface` + `Adapters\DB` (MySQL source of truth; a Redis fast-path adapter can be added later without touching recorders), `TagHash`, `TrimJob` (registered in the skeleton `routes/schedule.php` at 03:20), `ExceptionRecorder` (fires `atomic_exception_recorded`), `SecurityStats`.
- **Migrations** (`updates/`): `1_create_telemetry_stats_tables` (counters, totals, events, exception groups incl. trace, rate keys, lockouts, quota scopes), `2_create_scheduler_stats_tables` (scheduler_runs + scheduler_tasks registry), `3_add_session_user_columns` (user_uuid/created_at; session drivers write them column-gated so un-migrated installs keep working).
- **Instrumentation** (rare events only, see the revision above): `ExceptionHandlerRegistrar` → exception capture; `AccountLockout`/`AuthService` → auth.failed/auth.lock/auth.login; `RateLimitMiddleware` → key registry + denials; `QuotaLimiter` → scope registry + denial/renewal counters + denial events. Cache and mutex record nothing.
- **Scheduler**: stable `Event::get_task_key()` (sha1 of cron+callback), `owner()`, `timeout()` (pcntl alarm + post-hoc detection), `RunLogger` persistence (runs + task registry with persisted pause flag checked in `run()`; scheduler state, so it works with `TELEMETRY_STATS_ENABLED` off and its runs are trimmed regardless of that switch), overlap-skipped runs report `skipped` (S3), `Runner` due_count excludes maintenance-skipped tasks (S6), results carry `status`.
- **Reporters + endpoints** (`Engine\Atomic\Telemetry\Reporters` + `App\Telemetry`): /summary (60s Transient cache), /schedule, /migrations (via new reusable `Migrations::entries()`), /plugins (PluginManager lifecycle, dependencies and errors), /mutex, /rate-limits, /quota, /exceptions, /security, /jobs — all behind the existing telemetry access middleware.
- **Theme**: redesigned SPA installed as the builtin `Telemetry` theme (v2.0.0) — SPA shell layout, dependency-free CSS/JS (mock file not shipped), server-side injection of `window.TELEMETRY_CONFIG`/`window.TELEMETRY_JOBS` via `Assets::localize_script` (now hex-escapes JSON for inline script context — XSS hardening kept from the old job-list tests).
- **Config**: `TELEMETRY_STATS_ENABLED` (default true), `TELEMETRY_STATS_RETENTION_DAYS` (35), `TELEMETRY_STATS_EVENT_RETENTION_DAYS` (30). PHP-loader mode reads the same values from `config/app.php` → `telemetry.stats.*`; `ConfigParityTest` holds both loaders to the same output.
- **UI is read-only**: no CLI snippets for admin actions (end session, rollback, force release, reset lockout, install deps). Those become admin-panel buttons later. Nothing writes the scheduler pause flag yet.
- **Fix (debug pass): cross-platform job state classification.** The DB driver claims a job in two steps — `pop_batch` sets `pid = -1` (placeholder), then `set_pid` writes the real pid **and** `process_start_ticks` atomically. Start ticks come from `/proc/<pid>/stat`, which does not exist on Windows, so ticks stayed NULL there and every claimed job was classified `pending` (telemetry adapter + `Monitor` adapter `load_active_jobs` filtered on ticks). All classification points now key off `pid` instead (`pid > 0` = running; NULL/placeholder = pending) — provably equivalent on Linux (real pid ⟺ ticks set, same atomic write) and correct on Windows. Covered by `QueueTelemetryDbTest::test_running_state_classified_by_pid_when_process_start_ticks_missing` and `QueueDbDriverEdgeTest::test_monitor_adapter_loads_active_jobs_without_process_start_ticks`, which force the no-ticks state explicitly, so the pin holds on every platform.

Decisions taken on the open questions (§9): persisted pause flag (yes, registry column, checked per run); DB-driver queue cancellation left as-is (UI degrades — out of stats scope); retention defaults 35d counters / 30d events + lifetime totals; write actions (Phase 3) deferred; theme shipped as builtin update.

---

## 1. What the redesign is

The new theme is a self-contained, dependency-free SPA (vanilla JS) that replaces the current 4-tab server-rendered telemetry page with **13 sidebar tabs**, all fed by JSON:

| Tab | Endpoint | Notes |
|---|---|---|
| Dashboard | `/summary` + `/dashboard` | badges, "Needs attention" list, php/f3/atomic/db/system info |
| Queues | `/jobs` (or `window.TELEMETRY_JOBS` injected by PHP) + `/events/{driver}/{uuid}` + `/dumps/{id}` | job list keyed by uuid, state filters, detail drawer |
| Scheduled | `/schedule` | cron monitoring table: status ok/failed/timeout/paused, last-20-runs strip, next run, duration, owner, enable toggle |
| Migrations | `/migrations` | source/name/status/integrity/batch_uuid/applied_at per entry |
| Quota | `/quota?range=` | tiers, per-scope balance/denials/pacing, usage + denied **series**, by-op breakdown, lifetime totals |
| Rate limits | `/rate-limits` | active keys with strategy/limit/used/reset_in/blocked_hour |
| Plugins | `/plugins` | state (booted/failed/skipped/disabled), load order, deps, requirements |
| Exceptions | `/exceptions?range=` | groups by class+message+file:line, usage **series**, first/last seen, trace, dump_id, lifetime |
| Mutex | `/mutex` | active locks (name/token/age/ttl/remaining), driver availability |
| Cache | `/cache?range=` | drivers + latency, stats, hit-rate **series**, counters |
| Security | `/security?range=` | sessions, lockouts, failed-login **series**, top offenders, policy |
| Hive | `/hive` | exists today |
| Logs | `/log-channels` + `/logs` + `/dumps/{id}` | exists today (adds per-channel counts + "all channels" merge, done client-side) |

Key contract facts (`telemetry/API.md`):

- **Read-only.** All routes are `GET` + JSON. The shipped `telemetry.js` has **no write actions** — every `data-a` action is navigation/filter/range (the "Pause/Run Now/Save Changes" buttons in the screenshots are design inspiration from cron-monitoring products, *not* wired).
- **History model** (tabs quota/exceptions/cache/security — the `HIST` set in JS): `?range=24h|7d|30d` → series of **24 hourly / 28 six-hourly / 30 daily bucket counts**, oldest first, **history kept 30 days**, plus a `lifetime` object of all-time counters (never trimmed).
- Badges on the sidebar come from a single `/summary` call, refreshed on load/refresh.
- Config: `window.TELEMETRY_CONFIG = { base: '/telemetry' }`; the queue list can be injected server-side as `window.TELEMETRY_JOBS` to skip the first request.

The API contract is effectively a **requirements spec for the framework**. The UI itself is sound and does not need redesigning — but its data expectations can only be met by adding a statistics layer that does not exist today.

---

## 2. Gap analysis: what exists vs. what the contract needs

Legend: ✅ exists · 🟡 partial (needs work) · ❌ missing (must be built)

| Endpoint | Status | What exists today | What's missing |
|---|---|---|---|
| `/dashboard` | 🟡 | `App\Telemetry::dashboard()` (`engine/Atomic/App/Telemetry.php:136`) | reshape to flat groups; add env, hostname, uptime |
| `/hive` | ✅ | `Telemetry::hive()` + `Redactor` | — |
| `/logs`, `/log-channels`, `/dumps/{id}` | 🟡 | `Telemetry::logs/log_channels/dump` | per-channel **counts** for the rail; contract wants `[{name,count}]` (a `log-stat` per channel) |
| `/jobs` | 🟡 | `TelemetryManager::fetch_all_jobs()` → items keyed by uuid + `state_totals` (`engine/Atomic/Queue/Managers/TelemetryManager.php:101`) | JSON route (today the list is DOM-embedded in HTML); DB driver has **no cancel states / reason / cancelled_at** (Redis-only, `Queue/Drivers/DB.php:324`); `fetch_events` returns `[]` for any non-configured driver |
| `/events/{driver}/{uuid}` | ✅ | `Telemetry::events()` | queue param hardcoded `'default'` (`Telemetry.php:128`) — harmless but should be fixed |
| `/migrations` | 🟡 | full data exists: `MigrationCatalog::discover()` + `MigrationLedger` + integrity logic in `Migrations::status()` (`engine/Atomic/Core/Migrations.php:217-291`); exact integrity values match the contract (`verified/modified/legacy/unverified/legacy published alias/file unavailable/not applied`) | **JSON endpoint** — the logic is CLI-only |
| `/schedule` | ❌ | registration API, cron parser, next-run calc, duration/error capture in memory (`Scheduler.php:103-134`) | **no run persistence at all**, no stable task identity (id is a fresh UUID per process, `Event.php:37`), no pause/enable, no owner, no timeout detection, no last-20-runs |
| `/mutex` | 🟡 | drivers + `Mutex::info()`, DB driver table `mutex_locks` (name/token/expires_at/created_at), file driver meta.json | Redis/Memcached hold only token+TTL (no created_at → no `age`); no enumeration helper at all |
| `/rate-limits` | ❌ | 4 strategy engines + counters in Redis (`RateLimit/`) | **no key registry** — counters are anonymous, there is no way to list "which keys exist"; no denial (blocked) counters |
| `/quota` | ❌ | full enforcement in Redis (balance, reservations, pacing zsets, machine-readable denial reasons in `QuotaResult`) | **nothing persisted**: no usage/denial series, no lifetime totals, no scope registry, no recent-denial log |
| `/plugins` | 🟡 | `PluginManager` in-memory state: `$plugins/$registered/$booted/$errors`, deps, versions, load order (all protected — needs getters) | new accessors for registered/booted maps |
| `/exceptions` | ❌ | central ONERROR handler, `[ONERROR]` text log line on dated `error` channel, hive dump JSONs (DEBUG_MODE only) | **zero structured persistence** — no DB table, class/file/line not parseable reliably from log text; no grouping, no series, no lifetime |
| `/cache` | ❌ | 4 drivers + cascade + health checks; F3 bridge with `f3_hive_ttl` | **no hit/miss counters anywhere**, no latency measurement, no stats of any kind |
| `/security` | ❌ | `AccountLockout` (cache-only counters), `AUTH_RATE_LIMIT` policy config, session rows with ip/agent/stamp, impersonation support, `PasswordPolicy`, CSRF | **no enumeration of lockouts/failed logins** (scattered TTL cache keys), no failed-login persistence beyond `auth` log channel text, no time-series, no lifetime counters, sessions lack user/created_at columns (buried in serialized payload) |
| `/summary` | ❌ | pieces exist per-subsystem | **new composition endpoint**; needs cheap counts from everything above + caching |

**The single structural finding: every subsystem is current-state only.** Not one of them (queue excepted, via `queue_telemetry`) records history, time-series, or lifetime counters. The redesign's core value — the four history tabs and the lifetime strips — is exactly the missing dimension.

---

## 3. The shared-statistics question → **yes, build one collector**

Four of the five new data-heavy tabs (quota, exceptions, cache, security) want the *same* shape: `usage[]` ints per bucket + `lifetime{}` all-time totals. The scheduler wants per-run rows (last 20), and exceptions want grouped detail — but the time-series dimension is identical everywhere.

**Recommendation: one shared statistics subsystem, not ten ad-hoc stores.** Build it in the existing (queue-only) telemetry namespace so the pattern is a natural extension:

```
engine/Atomic/Telemetry/
├── Queue/…                        (exists today — job events)
└── Stats/                         (NEW)
    ├── StatsRecorder.php          record(metric, tags[], value) — buffered, flushed once per request
    ├── StatsStoreInterface.php
    ├── Adapters/DB.php            MySQL counter table (primary; MySQL is a hard requirement)
    ├── Adapters/Redis.php         fast-path HINCRBY when Redis is configured (optional)
    ├── StatsQuery.php             series(metric, tags, range) → int[]; lifetime(metric, tags)
    ├── Snapshot/                  per-subsystem "probe" classes (see §6)
    └── Jobs/TrimJob.php           retention trim (mirrors Scheduler\Jobs\LogCleanupJob)
```

### 3.1 Storage model (one table + two special cases)

**a) Generic counters — `atomic_telemetry_counters`** (framework migration, `updates/{version}_create_telemetry_stats_tables.php`):

```
metric        VARCHAR(64)   -- e.g. 'quota.denied', 'cache.hits', 'auth.failed'
tag_hash      CHAR(16)      -- xxh/md5 of the sorted tag set (join key)
tags          JSON/VARCHAR  -- {"scope":"user:1042","op":"ai.generate"}
bucket_start  INT UNSIGNED  -- unix ts aligned to hour (UTC)
value         BIGINT        -- upsert: value = value + ?
PRIMARY KEY (metric, tag_hash, bucket_start)
```

- **Write path:** `StatsRecorder::record()` appends to an in-memory buffer; flushed on `register_unload_handler` (bootstrap chain already has this hook) → one multi-row upsert per request instead of per event. Redis adapter: `HINCRBY stats.{metric}.{tag_hash} {bucket} 1` with 35-day TTL when Redis is the cache driver.
- **Read path (aggregation — no cron needed):** hourly buckets are the source of truth.
  - `24h` → 24 rows read directly.
  - `7d` → `GROUP BY FLOOR(bucket_start / 21600)` → 28 buckets.
  - `30d` → `GROUP BY FLOOR(bucket_start / 86400)` → 30 buckets.
  - This exactly matches the contract's 24/28/30-point series without any rollup job.
- **Lifetime:** separate `atomic_telemetry_totals` table (metric, tag_hash, tags, value) upserted in the same flush — never trimmed. (Lifetime *cannot* come from log parsing: dated logs are deleted after `max_days`, dumps after `LOG_DUMPS_MAX_DAYS`.)
- **Retention:** `TrimJob` (daily scheduler task, mirrors `LogCleanupJob`) deletes `bucket_start < now - RETENTION` (default 35 days). Optional compaction: fold hourly → daily after 8 days to keep the table tiny.
- **Cardinality policy per metric** (important): high-cardinality tags (rate-limit keys, quota scopes, failed-login identifiers) are allowed on counters but trimmed aggressively (48–72h) and surfaced through "top-N in range" queries; low-cardinality metrics (cache hits, quota totals) keep the full 30 days.

**b) Per-run rows — `atomic_scheduler_runs`** (see §5.1): the Scheduled tab needs the last 20 runs *per task* with duration and status — that's entity history, not a counter. One row per run, trimmed after 30 days.

**c) Occurrence rows — `atomic_telemetry_events`** (generic "recent events" log): used for quota `recent[]` denials (`{ts, op, reason}`), exception raw occurrences (fingerprint column for grouping), and mutex acquisition records if we want `age` on Redis locks. Trimmed (30d default). Exceptions additionally get an upserted `atomic_telemetry_exception_groups` table (fingerprint → class, message, file, line, code, level, first_seen, last_seen, total) so lifetime + first_seen survive trimming.

### 3.2 Why this shape and not alternatives

- **Log-file parsing** (count errors from the `error` channel): rejected as primary source — lines don't reliably contain class/file/line (`[ONERROR][code][level][status][text][dump_id]`, bracket-splitting is lossy), lifetime is impossible (log rotation deletes history), and grouping would be O(file) per request. Keep parsing only as a *backfill* for the `error` channel (one-time import of the last N days into the counters/groups tables — nice-to-have, not required).
- **Per-subsystem tables everywhere**: more code, 10 trim jobs, 10 query APIs — the contract's four history tabs would still be normalized in the controller anyway. One store wins.
- **Redis-only stats**: Redis is optional in Atomic (cache cascades to folder); telemetry must not depend on it. Redis is a fast-path adapter, not the source of truth.

### 3.3 Storage policy: hooks, not reshapes (one small exception)

**Decision:** statistics ride on hooks at existing write paths and persist into Stats-owned tables. Existing subsystem storage is **not** modified for statistics — with one exception (sessions). Rationale per store:

| Existing store | Change for stats? | Reasoning / instead |
|---|---|---|
| Quota Redis (balances, pacing zsets, Lua) | **No** | Correctness-critical scripted path. Denial reasons already structured in `QuotaResult`; capture via hook → counters. Scope registry is a sidecar table. |
| RateLimit Redis counters | **No** | Anonymous by design, hot path. The missing capability is *enumeration* → sidecar key-registry table, not richer counters. |
| Mutex Redis/Memcached token values | **No** | Release = compare-and-del on the bare token; changing the stored format touches the Lua script and all drivers. `age`/hold-time comes from acquisition-event rows (sidecar). DB driver's `mutex_locks` table is already complete — read directly. |
| Queue tables (`jobs*`, `telemetry`) | **No** | `queue_telemetry` + state derivation cover the dashboard. DB-driver cancellation columns are a queue *feature parity* decision, explicitly out of stats scope. |
| Migrations ledger | **No** | Already has `source/checksum/batch_uuid/applied_at` — reporters read it directly, no duplication. |
| Log files / dump files | **No** (optional line enrichment) | Append-only artifacts; the DB capture is the source of truth for exception stats. Optional nicety: append class/file:line to the `[ONERROR]` line for greppability — never a data source. |
| Sessions table | **YES — small** | Only place where the alternative is parsing serialized payloads at read time. Add `user_uuid` + `created_at` columns (one `updates/` migration), written by `Session\Drivers\DB` and mirrored into the Redis blob; `AuthSessionService` already produces both values. Turns the `/security` sessions listing into a plain indexed query that survives payload-format changes. |
| Folder cache payload (`Payload::pack`) | **No (v1)** | Key name is absent by design (sha256 filenames + HMAC'd payload); adding it invalidates the payload format and existing entries. `/cache` returns bytes/counts for folder, key names only for redis/db. Revisit later with a tolerant reader. |

Related **code-shape** changes that are required but touch no tables: Scheduler stable task key (`Event::$id` is per-process), `PluginManager` getters for `$registered`/`$booted`. Where existing data suffices (ledger, mutex DB rows, sessions), reporters read it directly — hooks exist only to add the *history* dimension that no subsystem records.

---

## 4. Recorder hook points (one line each, all inside the framework)

| Metric | Hook point | What we get |
|---|---|---|
| `auth.failed` / `auth.lock` / `auth.login` | `AuthService` failure/reset/success branches (`engine/Atomic/Auth/Services/AuthService.php:103,114,124,144`) + `AccountLockout::isLocked()` lock event | `failed[]`, `lock_events[]`, `lifetime.failed_logins/lockouts` |
| `quota.denied` / `quota.spent` | `QuotaLimiter::quota_reserve/settle` (`Quota/QuotaLimiter.php:151,183`) or `QuotaMiddleware::process` (`Quota/Middleware/QuotaMiddleware.php:50`) — denial reasons already structured in `QuotaResult` | usage/denied series, by-op, denials by reason, lifetime, recent denials |
| `ratelimit.allowed/.denied` | `RateLimitMiddleware::check()` (`RateLimit/Middleware/RateLimitMiddleware.php:57-77`) — policy name/strategy/key all known there | per-policy denial counts, `blocked_hour` |
| `cache.hits/.misses` | decorator around `CacheStoreInterface` resolved in `CacheManager::store()/cascade()` (`Core/CacheManager.php:122-190`) — or `incr` counters inside the Redis/DB drivers | `hit_rate[]`, `lifetime.hits/misses`, per-driver hits |
| `exceptions` | inside the ONERROR closure after the dump/log step (`Core/ExceptionHandlerRegistrar.php:50-60`) — `EXCEPTION` hive var holds the Throwable (class/file/line for free); fire a new `atomic_exception_recorded` hook so plugins can listen too | occurrences → groups, series, lifetime |
| `mutex.acquired/.released` | `Mutex::acquire/release/force_release` (`Mutex/Mutex.php:27,65,115`) | contention counts, hold-time (needs release timestamp join), optional age for non-DB drivers |
| scheduler runs | `Scheduler::run()` result loop (`Scheduler/Scheduler.php:103-134`) — duration/success/error already computed | the whole `/schedule` tab |
| `queue.*` (optional) | queue already persists events; add counters for throughput if wanted | — |

All recorders must be **failure-isolated** (try/catch → `Log::debug`) and **gated** by a config flag so a broken stats store can never take down a request or the error handler.

---

## 5. Subsystem work items (beyond the collector)

### 5.1 Scheduler — the biggest gap, and a prerequisite for honest stats

1. **Stable task identity.** `Event::$id` is a fresh UUID per process (`Scheduler/Event.php:37`). Add an optional explicit `name()`-derived slug (or `TaskKey = sha1(callback_description + cron)` from `Event::get_callback_description()`, Event.php:166) as the runs-table key.
2. **Persist runs.** New `atomic_scheduler_runs` table (`scheduler_source` — framework migration in `updates/`): task_key, name, cron, status (`ok|failed|timeout|skipped`), duration_ms, error, started_at, finished_at. Insert in the `Scheduler::run()` loop where `started_at/duration/success/error` are already computed (`Scheduler.php:114-133`).
3. **Fix the known accuracy bugs first** — they corrupt stats directly (documented in `docs/scheduler-debug-report.md`): S3 overlap-skipped runs reported as success (`Event.php:192-195`), S6 `due_count` counts maintenance-skipped tasks (`Runner.php:21`), S7 `when()/skip()` double evaluation.
4. **Timeout status.** The contract (and mock) has a `timeout` state; today a hung task blocks the loop and nothing records it. Minimum viable: per-task wall-clock budget checked after the run + `pcntl_alarm` when available (guard with `markTestSkipped` per repo conventions); record `timeout` when exceeded.
5. **Pause/enable.** Add `enabled` flag persisted per task key (`atomic_scheduler_tasks` table or a JSON blob option row), checked in `Scheduler::run()`. The UI renders an enable toggle (`sc-toggle` action, currently client-side only). The dashboard reads the persisted enabled flag; it never infers pause state from run age.
6. **Owner metadata.** No owner concept exists. Add optional `owner()` on `Event` (skeleton schedule.php can then annotate); show `null` when absent.
7. Endpoint: `SchedulerReporter` composes registration metadata + `CronExpression::describe()/get_next_run_date()` (already reusable, `CronExpression.php:16-39,233`) + last 20 runs per task → `/schedule`.

### 5.2 Exceptions

- Capture in `ExceptionHandlerRegistrar` (attach point §4): fingerprint = `sha1(class|message|file:line)`, class, message, file, line, code/level, request PATH, dump_id (nullable — dumps only written when DEBUG_MODE), timestamp.
- Upsert group row + insert occurrence row + counter (`exceptions.occurrences`).
- Query: groups in range via `GROUP BY fingerprint` on occurrences (or read the groups table + per-range counts), `usage[]` via bucket GROUP BY, `first_seen/last_seen/total` from the groups table. Redact message/trace through `Redactor` like everything else.
- Retention: occurrences 30d; groups table is the lifetime source (contract: `lifetime:{occurrences, distinct}`).

### 5.3 Cache

- **Hit/miss instrumentation:** wrap the resolved store in `CacheManager` with a `CountingCacheStore` decorator (implements `CacheStoreInterface`, delegates, records `cache.{driver}.hits|misses` via `StatsRecorder`). Zero changes to driver code; memcached/db/folder all get counted for free.
- **Latency:** time the existing `health_check()` probes (`CacheManager.php:140-183`) + `ConnectionManager::probe_*()`; report ms per driver (the mock shows one decimal).
- Endpoint reporter composes: default/active driver, bridge info (`CACHE_CONFIG.f3_hive_ttl`), driver states, stats, hit-rate series (counters).

### 5.4 Rate limits

- **Key registry** (prerequisite for "which keys exist"): on first use of a rate-limit key, record `{key, strategy, limit, window, first_seen, last_seen}` in a small `atomic_telemetry_rate_keys` table (upsert on access; prune keys not seen for > 24h). Without it the endpoint literally cannot enumerate keys.
- `used`/`reset_in` read live from the Redis store at request time (single `TTL`+counter read per key, capped to top-N keys); `blocked_hour` = hourly denial counter per key (high-cardinality metric, 48h retention).

### 5.5 Quota

- Scope registry: on first `quota_reserve`, upsert `{scope → tier}` so the dashboard can enumerate scopes without SCANing Redis.
- Balance/ttl read live from the existing Redis store (`quota_get/quota_ttl`) — current-state, cheap.
- Series/denials/by-op/lifetime/recent all come from the Stats collector (§4) — **no quota table needed beyond the registry**.

### 5.6 Security

- Sessions: enumerate `sessions` table rows (db driver) or `SCAN {prefix}` (redis driver) → `ip`, `agent`, `stamp` → `last_ago`; `user` + `created_ago` by parsing the serialized payload (`SESSION.user_uuid`, `SESSION.created_at` are written by `AuthSessionService.php:31-38`); `current` via `session_id()`; `impersonating` via `SESSION.admin_uuid`. Cap the listing (e.g. 200 newest). **Nice-to-have:** promote `user_uuid`/`created_at` to real columns in a future session migration — the endpoint should not require it.
- Lockouts: `AccountLockout` counters are unlistable cache keys. Mirror lock *events* to the collector (`auth.lock` with identifier tag) and keep an `atomic_telemetry_lockouts` snapshot table (identifier, count, first_at, locked_until) upserted by `AccountLockout` — that gives `lockouts[]` with `count/max/first_ago/locked/remaining` and `top_failed` via GROUP BY on failure occurrences.
- `policy{}`: composed from `AUTH_RATE_LIMIT.*`, `SESSION_CONFIG.{driver,lifetime}`, `PasswordPolicy::default()` values, CSRF key `'_csrf_token'` — all exist (config-exposed except password/CSRF, which are code constants).

### 5.7 Mutex

- DB driver: `SELECT * FROM mutex_locks` → name/token/`age = now-created_at`/`ttl = expires_at-created_at`/`remaining = expires_at-now`. Full contract data, free.
- File driver: read `meta.json` per lock dir — same fields.
- Redis/Memcached: token + remaining TTL only (`TTL mutex.{name}` via SCAN) — `age` needs original TTL, which isn't stored. Options: (a) report `age: null` for these drivers and let the UI degrade, or (b) write an acquisition event row at `Mutex::acquire` (collector §4) and join by name — recommendation: (b), it also gives contention stats.
- `drivers[]` availability: `MutexDriverInterface::is_available()` per driver + which is active (`Mutex::info()`).

### 5.8 Plugins

- `PluginManager`: add public getters for `$registered`/`$booted` maps; state derivation: `disabled` (not enabled), `failed` (in `$errors`), `booted`, `registered` (registered, boot pending/failed), `skipped` (enabled but absent from registered due to dependency skip). Steps breakdown (construct/register/boot ok|failed|skipped) is derivable from `$errors` keys + state. Requirements come from `Plugin::required_dependencies()`.

### 5.9 Migrations & queue (small)

- `/migrations`: extract the entry-building logic of `Migrations::status()` (`Core/Migrations.php:224-291`) into a reusable method returning arrays (CLI keeps printing, telemetry returns JSON) — graceful degradation on missing files (do **not** call `find_checksum_mismatches()`, which throws).
- `/jobs`: add JSON route returning `fetch_all_jobs()` shape; fix `fetch_events` driver param semantics (document that it only serves the configured driver, or make TelemetryManager fall back). DB-driver cancel support (reason/cancelled_at) is a real queue feature gap — decide: implement cancellation columns for the DB driver, or let the UI degrade for `db`.

---

## 6. Composition layer: `/summary` and the reporters

New interface + one probe class per subsystem, all consumed by the controller:

```
Engine\Atomic\Telemetry\Stats\Snapshot\SnapshotProviderInterface  // snapshot(): array
  ├─ QueueSnapshot        (state_totals → queues{failed,pending,running,completed,cancel_requested,cancelled})
  ├─ SchedulerSnapshot    (from runs table + task registry: total/failing/paused)
  ├─ MigrationsSnapshot   (pending / needs-attention / legacy_mode)
  ├─ QuotaSnapshot        (denied_24h from counters)
  ├─ RateLimitSnapshot    (limited from registry+live reads, blocked_1h)
  ├─ PluginsSnapshot      (total/problems from PluginManager — in-memory, free)
  ├─ ExceptionsSnapshot   (distinct/count_24h/new_today from groups table)
  ├─ MutexSnapshot        (total/expired from lock enumeration)
  ├─ CacheSnapshot        (hit_rate from counters)
  └─ SecuritySnapshot     (locked/failed_24h/sessions)
```

- `/summary` wraps all probes in one `Transient` cache entry (60s TTL) so refreshes don't hammer Redis; individual tabs are lazy-loaded by the UI anyway (the JS only loads a tab when opened).
- Controller: keep `App\Telemetry` thin — it validates params, calls the reporter, `Redactor::redact()`, `send_json()`. If it grows past ~800 lines, split into `App\Telemetry` (HTML entry + queue) and `App\Telemetry\StatsController`-style classes per group.
- Routes: append to `engine/Atomic/Core/Routes/telemetry.php` inside the existing `$telemetry_access` middleware block — all new endpoints inherit the access modes (none|config|auth) automatically.

---

## 7. Theme installation

Recommendation: **ship the redesign as the updated builtin `engine/Atomic/Theme/Builtin/Telemetry/`**, with the skeleton `public/themes/` override as the documented per-app escape hatch (already supported: a custom `<ENQ_UI_FIX>/Telemetry/` with `theme.json` wins over builtin, `Theme.php:49-60`).

Concretely:

1. `layout/telemetry-queue.atom.php` becomes the SPA shell: the redesign's `index.html` body (sidebar + tab containers), keeping the `.atom.php` wrapper (F3 renders via `UI` hive) and `partials/head.atom.php` (meta/CSRF).
2. Assets: `functions.atom.php` enqueues `~assets/js/telemetry.js` + `~assets/css/telemetry.css` (drop-in replacements; filemtime versioning already applies). No jQuery/w3 dependency needed by the new JS — it's dependency-free.
3. Server-side injection: `Assets::localize_script()` (exists, `Theme/Assets.php:127-131`) emits `window.TELEMETRY_JOBS = {…}` (first page of jobs) and `window.TELEMETRY_CONFIG = { base: '/telemetry' }` — saves the initial `/jobs` round-trip and hard-codes nothing in JS.
4. Delete `telemetry.mock.js` from the shipped theme (it's the standalone-demo data source; `TELEMETRY_CONFIG.mock` stays `false`).
5. The existing 1400-line `telemetry.js` and the current tab behavior are replaced wholesale; keep the URL-sync (`?tab=`), client cache, and lazy-tab patterns — the redesign already implements them.

The theme redesign itself does **not** need to change shape given the shared Stats helper: the four history endpoints map 1:1 to `StatsQuery::series()`. Two small asks back to the theme (cheap, optional): render `age: null` / missing fields gracefully per driver (mutex/cache best-effort), and keep the schema documented in `API.md` as the single contract for both sides.

---

## 8. Delivery phases (each shippable, tests-first per AGENTS.md)

**Phase 0 — theme shell on existing endpoints (no framework behavior change).** Install the SPA as builtin theme; wire `/dashboard`, `/hive`, `/logs`, `/log-channels`, `/dumps`, `/jobs` (new thin JSON route + `window.TELEMETRY_JOBS`), `/events`. Tabs with no backend yet (Scheduled/Migrations/Quota/…) show their "no data / coming soon" states. *Pure UI PR, zero risk.*

**Phase 1 — the statistics core.** `Telemetry\Stats` (tables + recorder + flush-on-unload + query + trim job + Redis fast path) + migration + config flags (`TELEMETRY_STATS_ENABLED`, retention) + first consumers: **exceptions** (capture hook + groups), **security** (auth failures/lockouts), **cache** hit-rate (decorator), `/summary`. Tests: `tests/Engine/Telemetry/Stats/*` (MySQL present in test env; Redis paths `markTestSkipped` when absent).

**Phase 2 — state reporters.** `/schedule` (needs §5.1: stable keys, runs table, bug fixes S3/S6, pause flag), `/migrations` JSON extraction, `/plugins` (+ Hook/Event accessors), `/mutex`, `/rate-limits` (+ key registry), `/quota` (+ scope registry). `/summary` completed.

**Phase 3 — polish & writes (optional, needs a scope decision).** Write endpoints behind the telemetry middleware: schedule pause/resume, job retry/cancel, cache flush, mutex force-release, migrations "run from CLI" stays CLI (the UI already shows the command — keep it that way). Sampling config for high-traffic counters; backfill of the `error` channel into exception groups.

---

## 9. Open decisions for you

1. **Pause/enable for scheduled tasks** — real persisted flag (recommended, small) vs. read-only "paused" inference in v1?
2. **DB-driver queue cancellation** — implement (columns + mark_cancel on DB driver) or let `/jobs` degrade for `db`?
3. **Retention defaults** — 30 days of hourly buckets + lifetime totals matches the contract; confirm (storage cost is tiny: roughly rows = metrics × tags × hours).
4. **Write actions** (Phase 3) — in scope for this release or later?
5. **Theme delivery** — confirm builtin-update (recommended) so the redesign ships with the framework package on the next tag (skeleton sync unaffected).

---

## 10. Reference anchors (from subsystem inventories)

| Area | Files |
|---|---|
| Scheduler capture point | `engine/Atomic/Scheduler/Scheduler.php:103-134`; runner `Scheduler/Runner.php:16-40`; cron parser `Scheduler/CronExpression.php:16-48,233` |
| Exception capture point | `engine/Atomic/Core/ExceptionHandlerRegistrar.php:50-60` (Throwable in hive `EXCEPTION`); dump writer `Core/Log.php:264-294` |
| Quota hooks | `engine/Atomic/Quota/QuotaLimiter.php:151,183`; `Quota/Middleware/QuotaMiddleware.php:50-101`; reasons `Quota/QuotaResult.php:9-13` |
| RateLimit hooks | `engine/Atomic/RateLimit/Middleware/RateLimitMiddleware.php:57-77` |
| Cache instrumentation | `engine/Atomic/Core/CacheManager.php:122-190` (store resolution + health checks) |
| Auth/lockout | `engine/Atomic/Auth/Services/AuthService.php:103-144,369-389`; `engine/Atomic/Security/AccountLockout.php` |
| Sessions | `engine/Atomic/Session/Drivers/{DB,Redis}.php`; schema `Core/Database/Migrations/initial/atomic_create_session_table.php` |
| Mutex | `engine/Atomic/Mutex/Mutex.php:27-115,270-277`; DB schema `initial/atomic_create_mutex_table.php` |
| Plugins/Hooks/Events | `engine/Atomic/App/PluginManager.php` (states :49-95, errors :606-609); `engine/Atomic/Hook/Hook.php:99-107`; `engine/Atomic/Event/Event.php:215-241` |
| Migrations | `engine/Atomic/Core/Migrations.php:217-291` (status logic), ledger `Core/Migrations/MigrationLedger.php` |
| Queue telemetry | `engine/Atomic/Queue/Managers/TelemetryManager.php:101-225`; event types `engine/Atomic/Telemetry/Queue/EventType.php` |
| Routes/access | `engine/Atomic/Core/Routes/telemetry.php` (`$telemetry_access` middleware block) |
| Theme | `engine/Atomic/Theme/Theme.php:49-88`; `Theme/Assets.php:127-131,217-265`; builtin `engine/Atomic/Theme/Builtin/Telemetry/` |

Telemetry displays queue state totals independently of the paginated job inventory. Scheduler tasks expose enabled/paused state, absolute last-run time, last recorded status and outcome (including unknown and never run), and the next three cron matches computed in the task timezone. The browser only formats these timestamps. Exception locations and fingerprints use Throwable or structured frame sources; text traces have an unknown source.

Unknown exception sources use null in capture and endpoint payloads; the existing database represents an absent file/line as empty string/zero. Cron lookahead retains the scheduler engine limit of 527040 minute candidates per next occurrence; unavailable matches are omitted rather than extrapolated.
