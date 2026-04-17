## Telemetry ##

Atomic telemetry is a built-in diagnostics panel that provides live visibility into the application's runtime state, queue jobs, and log output. It is a separate read-only interface served under `/telemetry`, distinct from the main application.

Access can be restricted to admin users only via `TELEMETRY_ADMIN_ONLY`. All data returned by the telemetry endpoints is sanitized through the Redactor before being served - sensitive values, secrets, and filesystem paths are masked.

### What it provides

**Queue view** - a paginated, filterable list of all queue jobs across drivers (database, Redis). Jobs can be filtered by status (pending, running, completed, failed), UUID, queue name, and time range. The header shows global status totals that remain accurate regardless of which filter is active.

**Log viewer** - paginated log output for any configured log channel. Supports channel switching, per-day file selection, pagination, and live stat polling (line count, last modified). Log lines with an attached dump can be expanded inline.

**Dump viewer** - opens a structured JSON dump by its UUID, as written by `Log::dump()`. Useful for inspecting large payloads that would be impractical to inline in a log message.

**Dashboard** - a snapshot of the current runtime environment: PHP version and configuration, framework version, debug settings, database connection info, and system details.

**Hive inspector** - a sanitized view of the current F3 hive state. Useful for inspecting what is in scope at request time without access to a debugger.

### How it works

The telemetry panel is a theme (`Telemetry`) that boots alongside the controller. All data is fetched via JSON endpoints and rendered client-side - the queue and log views update without full page reloads. Queue filter changes update the URL and trigger a server-side re-render of the job list, keeping the browser URL shareable and the back button functional.

Queue status totals in the header are always computed against the full dataset, not just the filtered view. Applying a status filter scopes the job list but never affects the global counts.

Log pages are cached based on file modification time. Unchanged pages are served from cache; a changed file invalidates cached pages for that channel.

### Routes

| Route | Purpose |
|---|---|
| `GET /telemetry` | Queue view |
| `GET /telemetry/events/@driver/@job_uuid` | Job event timeline |
| `GET /telemetry/dashboard` | Runtime environment snapshot |
| `GET /telemetry/hive` | Sanitized hive dump |
| `GET /telemetry/logs` | Paginated log output (`channel`, optional `date=YYYY-MM-DD`) |
| `GET /telemetry/log-channels` | List of configured log channels and available dates per channel |
| `GET /telemetry/log-stat` | Line count and mtime for a channel/date |
| `GET /telemetry/dumps/@dump_id` | Retrieve a JSON dump by UUID |

### Usage notes

- Set `TELEMETRY_ADMIN_ONLY=true` in production.
- `LOGS` must be writable for the log viewer to function.
- Telemetry output is diagnostic - it reflects live runtime state and should not be used as an application data source.
