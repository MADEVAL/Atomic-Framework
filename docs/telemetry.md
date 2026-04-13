## Telemetry ##

Atomic telemetry is implemented by `Engine\Atomic\App\Telemetry` and routed from `engine/Atomic/Core/Routes/telemetry.php`.

### Routes

Registered telemetry endpoints:

- `GET /telemetry`
- `GET /telemetry/events/@driver/@job_uuid`
- `GET /telemetry/dashboard`
- `GET /telemetry/hive`
- `GET /telemetry/logs`
- `GET /telemetry/dumps/@dump_id`

### Theme boot and access control

Controller construction:

- calls `Theme::instance('Telemetry')`
- sets `__theme_booted` in the app hive so the theme is only booted once per request lifecycle

`beforeroute(...)` behavior:

- `Sanitizer::syncFromHive($atomic)` runs first
- if `TELEMETRY_ADMIN_ONLY` is truthy and the current user is not admin, the request is rerouted to `/login`

There is no separate JSON authorization response in telemetry routes. The access rule is a reroute.

### Queue view

`queue()` renders `layout/telemetry-queue.atom.php`.

Accepted filters from `GET`:

- `driver`
- `status`
- `queue`
- `uuid`
- `state`
- `date_from`
- `date_to`

How data is built:

- filter values are cleaned with `$atomic->clean(...)`
- `TelemetryManager::fetch_all_jobs(...)` is used
- jobs are normalized through `Sanitizer::normalize(...)`
- status counters are computed for `failed`, `queued`, `running`, `completed`, and `total`

### Events endpoint

`events()` validates:

- `driver` must be `redis` or `database`
- `job_uuid` must be a valid UUID v4

Example:

```json
{
  "job_uuid": "uuid-v4",
  "driver": "redis",
  "events": []
}
```

### Dashboard endpoint

`dashboard()` returns JSON with:

- `php`: version, sapi, memory limit, max execution time, opcache flag, loaded extensions
- `f3`: framework version when available
- `atomic`: debug mode, debug level, logs dir, dumps dir, base
- `system`: OS family/name and timezone
- `db`: driver, server version, client version when a SQL connection is available

### Hive endpoint

`hive()` returns the current hive after `Sanitizer::normalize(...)`.

Sanitizer behavior relevant here:

- masks sensitive leaf values
- masks matching secrets embedded in strings
- masks the home/root path when `HOME` or `ROOT` is available
- limits recursion depth and item count

If hive extraction itself throws, the endpoint returns a sanitized error payload instead.

### Logs endpoint

`logs()` reads `LOGS/atomic.log`.

Behavior:

- if the log file does not exist, it returns `{"lines":[]}`
- reads at most the last 200 KB of the file
- returns at most 300 parsed log lines
- extracts `ts`, `level`, `message`, and optional `dump_id`
- supports multiple timestamp formats and a level-only fallback
- returns newest lines first

### Dump endpoint

`dump()` validates `dump_id` as UUID v4 and reads:

`DUMPS/<dump_id>.json`

Behavior:

- invalid UUID -> `400`
- missing file -> `404`
- valid JSON dump -> decoded JSON payload
- invalid JSON dump file -> `{"dump_id":"...","error":"dump file could not be decoded"}`

### TelemetryManager behavior

`TelemetryManager`:

- resolves its default driver from `QUEUE_DRIVER`
- can still fetch events from an explicitly requested driver
- merges in-progress, failed, and completed jobs for queue listing
- applies runtime filters for driver, queue, uuid, status, state, and date range
- pushes telemetry events with queue context from the current queue execution hive keys

### Usage notes

1. Set `TELEMETRY_ADMIN_ONLY` in production.
2. Ensure `LOGS` is writable; `DUMPS` is derived from the logger setup.
3. Use telemetry output for diagnostics, not as a raw dump of unsanitized runtime state.
