# TODO: log the error location and trace in production

Status: todo, not scheduled. Written 2026-11-21.

## Problem

With `DEBUG = 0` an error log line says what failed but not where. A real case
from the Atomic app on PHP 8.5:

```
[ERROR] [ONERROR][500][8192][Internal Server Error][Method ReflectionMethod::setAccessible() is deprecated since 8.5, as it has no effect]
```

No file, no line, no trace. Finding the call took a vendor-wide grep.

Two causes in `ExceptionHandlerRegistrar::register()`:

- The handler formats the trace (`ErrorHandler::format_trace()`) and stores it in
  `ERROR.formatted_trace`, but the log line is built from code, level, status and
  text only. The trace is never written.
- `Log::dump_hive()` returns `null` unless debug mode is on, so production lines
  also lack `dump_id`. There is nothing to look up afterwards.

`record_exception_stats()` already extracts file and line for telemetry; the log
does not get them.

## Target

1. The ONERROR log line always carries `file:line` of the origin (from
   `EXCEPTION` or the first `ERROR.trace` frame), at every debug level.
2. The formatted trace goes to the error log as a follow-up entry, capped to a
   fixed number of frames, at every debug level. Logs are server-side, so this
   leaks nothing to the client; the HTTP response keeps hiding the trace when
   `DEBUG = 0`.
3. Hive dumps stay debug-only (they hold config and secrets).

## Done when

- A deprecation raised in production logs its file, line and a short trace.
- The client response for `DEBUG = 0` is unchanged.
- A test covers the log output for both debug levels.
