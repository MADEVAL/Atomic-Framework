## ErrorHandler ##

Atomic error handling is centered on:

- `Engine\Atomic\Core\ExceptionHandlerRegistrar`
- `Engine\Atomic\Core\ErrorHandler`
- `Engine\Atomic\App\Error`

### Registration

`ExceptionHandlerRegistrar::register($atomic)` stores an `ONERROR` callback in the F3 hive.

Inside that callback the handler:

1. reads the current `ERROR.*` values from the hive
2. temporarily lowers `DEBUG` from `3+` to `2` while handling the error
3. increments `ERROR.recursion_counter`
4. formats the trace with `ErrorHandler::format_trace(...)`
5. stores the formatted trace in `ERROR.formatted_trace`
6. attempts to dump the hive with `Log::dumpHive()`
7. stores `ERROR.dump_id` and `ERROR.dump_path` when a dump was created
8. logs a structured `[ONERROR][code][level][status][text]` message
9. selects the response mode based on request context

### Recursion protection

If `ERROR.recursion_counter` is already greater than `2`, the handler aborts with:

`Fatal error: too many error handler recursions`

### Trace formatting

`ErrorHandler::format_trace(int $code, string $text, string $trace): string`

Behavior:

- starts output with the error text
- scans trace lines for `[...]` segments containing `file:line`
- when the file is readable, includes a small source excerpt around the target line
- marks the target line with `>>>`

If formatting itself throws, it returns a fallback string instead of propagating another exception.

### Dump behavior

`Log::dumpHive()` is conditional:

- dumps are only created when logger debug mode is enabled
- dump files go under the configured `DUMPS` directory
- if a dump is created, the dump id is appended to the structured log message

The error handler does not guarantee that every error will have a dump id.

### Response modes

#### API and AJAX requests

A request is treated as API/AJAX when any of these are true:

- `AJAX` is truthy
- the path starts with `/api/`
- `HTTP_ACCEPT` contains `application/json`

Response shape:

```json
{
  "error": {
    "status": "500 Internal Server Error",
    "code": 500,
    "text": "Internal Server Error",
    "trace": null
  }
}
```

Important detail:

- `text` is the real error text only when debug was enabled before the handler ran
- `trace` is included only when debug was enabled

#### Web requests

- `500` creates `Engine\Atomic\App\Error` and calls `error500(...)`
- other codes are matched against registered `/error/<code>` routes and dispatched to a method like `error404(...)` when present
- if the current path already starts with `/error/<code>`, the handler avoids redispatching

#### CLI requests

- the handler writes the structured error message to CLI stderr through `CLI\Console\Output`

### Error page controller

`Engine\Atomic\App\Error`:

- boots the `ErrorPages` theme in its constructor
- sets the HTTP status and relevant headers per method
- sets `PAGE.title` and `PAGE.color`
- renders a layout such as `layout/404.atom.php` or `layout/500.atom.php`

For `error500(...)` specifically:

- if `ERROR.formatted_trace` is empty, it sets `No trace available`
- if rendering the theme layout fails, it falls back to a minimal inline HTML page

### Manual usage

Trigger an error through F3:

```php
\Base::instance()->error(404, 'Resource not found');
```

Once `ONERROR` has been registered, centralized handling runs automatically.

### Operational notes

1. Keep debug disabled in production if you do not want trace text in API responses.
2. Ensure `LOGS` and the derived dumps directory are writable if you rely on dump files.
3. Keep error templates available in the `ErrorPages` theme.
4. Do not assume dumps always exist for every error; they depend on logger debug mode.
