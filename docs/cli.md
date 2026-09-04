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
