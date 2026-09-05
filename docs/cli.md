## CLI ##

Atomic has a built-in CLI layer centered around `Engine\Atomic\CLI\CLI`.

In most projects it is used through the `php atomic ...` entry point.

### Quick example

```bash
php atomic help
```

This prints the list of available framework commands, including init, access, queue, schedule, file, and other system helpers.

Help is grouped by topic. To inspect one topic in detail, pass it after `help`:

```bash
php atomic help queue
php atomic help migrations
php atomic help scheduler
```

Available topics include `project`, `plugins`, `authentication`, `migrations`, `cache`, `system`, `queue`, `scheduler`, and `files`.

If a command is not recognized, Atomic reports it as a CLI error, suggests close matches when possible, and points back to `php atomic help`. Unknown commands exit with status `1`.

### Health check

```bash
php atomic health
```

The health command runs after configuration loading but before the configuration gate and provider lifecycle, so it remains available when required application settings are incomplete. It checks the PHP version, required extensions, configuration source, application keys, public domain, writable runtime directories, MySQL connectivity using the configured credentials, and configured cache-service connectivity.

Configuration is loaded without initializing the runtime cache. Backend warnings and connection errors become failed checks instead of raw errors. The selected folder-cache path, configured daily log files, and PHP error log are also checked for write access. Invalid PHP configuration produces an unhealthy report indicating that the remaining checks could not complete.

Checks use the PHP process and filesystem user running the command; a CLI result does not verify PHP-FPM's extension configuration or permissions. PHP and the Composer autoloader must be able to start. Connectivity probes verify MySQL with `SELECT 1` and cache services with a read; they do not verify migrations, table privileges, or cache write permissions.

Redis is always reported as highly recommended. Missing or unreachable Redis produces a warning when no active driver uses it, and a failure when cache, session, mutex, or queue configuration selects it. Sodium is optional and is only required when `Engine\\Atomic\\Core\\Crypto` is used; Memcached is checked when selected.

The command exits with `0` when healthy and `1` when a required or selected dependency fails. For load balancers and uptime monitors, `GET /health` returns only `{"status":"ok"}` with HTTP 200 or `{"status":"unhealthy"}` with HTTP 503; diagnostic details remain CLI-only.

### Plugin scaffold

```bash
php atomic plugin/make MyPlugin
```

This creates a user plugin under `USER_PLUGINS` with `plugin.php`, the plugin class, and an initial `routes/api.php` file. The default plugin directory is `plugins/` at the project root.

### Command groups

- `init` prepares a project and framework defaults.
- `access/user/*` manages config-backed users; full usage and parameters are in [`telemetry.md#config-user-commands`](telemetry.md#config-user-commands), and HTTP `access:<guard>` behavior is in [`middleware.md#config-backed-access-middleware`](middleware.md#config-backed-access-middleware).
- `queue/*` runs and inspects queue workers.
- `schedule/*` runs and inspects scheduled tasks.
- `plugin/*` scaffolds and manages plugins.
- `file/*` provides file and storage helpers.
