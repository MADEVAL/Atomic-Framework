## Plugins ##

Atomic plugins extend `Engine\Atomic\App\Plugin` and are coordinated by `Engine\Atomic\App\PluginManager`.

### Bootstrap flow

Core plugin bootstrap happens in two stages:

1. `App::register_core_plugins(...)`
   - If no classes are passed explicitly, it reads `config/providers.php` and uses the `plugins` key.
   - Each class is instantiated as `new $pluginClass($this)` and registered in the manager.
2. `App::register_plugins()`
   - `PluginManager::load_user_plugins()`
   - `PluginManager::register_all()`
   - `PluginManager::boot_all()`

User plugins are loaded from the configured `USER_PLUGINS` directory. The manager scans each direct subdirectory for `plugin.php` and only loads files that resolve inside the real `USER_PLUGINS` path.

### Base plugin API

All plugins extend the abstract base class:

```php
abstract class Plugin
{
    protected App $atomic;
    protected string $name;
    protected string $version = '1.0.0';
    protected string $path;
    protected bool $enabled = true;
    protected array $dependencies = [];

    public function __construct(?App $atomic = null);

    abstract protected function get_name(): string;
    protected function get_path(): string;

    public function register(): void {}
    public function boot(): void {}
    public function activate(): void {}
    public function deactivate(): void {}

    public function is_enabled(): bool;
    public function set_enabled(bool $enabled): void;
    public function get_version(): string;
    public function get_dependencies(): array;
    public function get_plugin_name(): string;
    public function get_plugin_path(): string;
    public function get_migrations_path(): ?string;
}
```

Notes:

- `get_name()` is the canonical plugin identifier used by the manager.
- `get_path()` is derived from the plugin class file via reflection.
- `get_migrations_path()` returns `<plugin_path>/Migrations` only when that directory exists; otherwise it returns `null`.

### Lifecycle behavior

- `register()`: called by `PluginManager::register_all()` for enabled plugins.
- `boot()`: called by `PluginManager::boot_all()` for plugins that registered successfully.
- `activate()`: called by `enable_plugin(...)` / `PluginManager::enable(...)`.
- `deactivate()`: called by `disable_plugin(...)` / `PluginManager::disable(...)`.

Important runtime rules:

- Duplicate plugin names are ignored. The first registered plugin wins.
- Disabled plugins are kept in the manager but skipped by `register_all()`.
- Dependency failures do not stop the bootstrap. They are caught and logged, and registration continues for other plugins.
- Boot failures are also caught and logged per plugin.
- `enable_plugin(...)` only sets the plugin as enabled and calls `activate()`. It does not automatically run `register()` or `boot()`.
- `disable_plugin(...)` calls `deactivate()`, marks the plugin disabled, and removes it from the internal `registered` and `booted` sets.

### Dependencies

Declare dependencies by plugin name:

```php
protected array $dependencies = ['Google'];
```

Current dependency checking is minimal:

- The dependency name must already exist in the manager.
- The dependency plugin must be enabled.

It does **not** guarantee that the dependency has already finished `register()` or `boot()`. If your plugin needs another plugin's fully initialized services, do that work in `boot()` and keep registration order in mind.

### Global plugin helpers

The following helpers are defined in `engine/Atomic/Support/helpers.php`:

```php
plugin_manager(): PluginManager
get_plugin(string $name): mixed
has_plugin(string $name): bool
enable_plugin(string $name): bool
disable_plugin(string $name): bool
```

Example:

```php
$plugin = get_plugin('Monopay');

if ($plugin !== null) {
    // interact with the plugin instance
}
```

### Plugin routes

After `boot_all()` finishes its boot loop, the manager loads route files from each booted plugin's `routes/` directory.

The filenames come from `RouteLoader::ROUTE_TYPE_MAP` and depend on the detected request type:

- `web`: `web.php`, `web.error.php`
- `api`: `api.php`
- `cli`: `cli.php`
- `telemetry`: `telemetry.php`

Only existing files are required. Exceptions while loading a route file are logged and do not abort the rest of plugin route loading.

### Plugin migrations

Default migration discovery is:

`<plugin_path>/Migrations`

If your plugin stores migrations elsewhere, override `get_migrations_path()`.

To publish migrations from a registered plugin:

```bash
php atomic migrations/publish <plugin-name>
```

Behavior of `publish_from_plugin(...)`:

- It first looks up the plugin by exact name.
- If that fails, it retries with case-insensitive matching.
- If the plugin has no migrations directory, it prints a warning.
- Existing migrations with the same logical name are skipped during publish.

### Recommended plugin layout

```text
MyPlugin/
  plugin.php
  MyPlugin.php
  routes/
    web.php
    api.php
    cli.php
  Migrations/
    create_myplugin_tables.php
```

### User plugin entrypoint

`plugin.php` is the file discovered by `load_user_plugins()`. A minimal entrypoint looks like:

```php
<?php
declare(strict_types=1);

use Engine\Atomic\App\PluginManager;
use App\Plugins\MyPlugin\MyPlugin;

if (!defined('ATOMIC_START')) exit;

PluginManager::instance()->register(new MyPlugin());
```

### Minimal plugin example

```php
<?php
declare(strict_types=1);

namespace App\Plugins\MyPlugin;

use Engine\Atomic\App\Plugin;

if (!defined('ATOMIC_START')) exit;

final class MyPlugin extends Plugin
{
    protected string $version = '1.0.0';
    protected array $dependencies = [];

    protected function get_name(): string
    {
        return 'MyPlugin';
    }

    public function register(): void
    {
        $this->atomic->set('PLUGIN.MyPlugin.registered', true);
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.MyPlugin.booted', true);
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.MyPlugin.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.MyPlugin.active', false);
    }
}
```

### Development notes

1. Keep `get_name()` stable. It is the lookup key used by dependencies and helpers.
2. Use exact dependency names, not class names.
3. Keep `register()` lightweight when it depends on other plugins; defer cross-plugin work to `boot()`.
4. Add only the route files needed for the request types you support.
5. Add a `Migrations/` directory only if the plugin actually ships migrations.
6. Register core plugins from `providers.php`, and user plugins from `plugin.php`.
