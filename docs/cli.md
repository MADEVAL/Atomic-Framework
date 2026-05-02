## CLI ##

Atomic has a built-in CLI layer centered around `Engine\Atomic\CLI\CLI`.

In most projects it is used through the `php atomic ...` entry point.

### Quick example

```bash
php atomic help
```

This prints the list of available framework commands, including queue, scheduler, file, and other system helpers.

### Plugin scaffold

```bash
php atomic plugin/make MyPlugin
```

This creates a user plugin under `USER_PLUGINS` with `plugin.php`, the plugin class, and an initial `routes/api.php` file. The default plugin directory is `plugins/` at the project root.
