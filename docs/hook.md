## Hook ##

Atomic provides WordPress-style actions and filters through `Engine\Atomic\Hook\Hook`, backed by the event system.

### Actions

```php
add_action('init', function () {
    // boot code
});

add_action('user_registered', function ($userId, $userData) {
    echo $userData['username'];
}, 10, 2);

do_action('init');
do_action('user_registered', 123, ['username' => 'john_doe']);
```

Behavior:

- lower numeric priority runs first
- `accepted_args` limits how many emitted arguments reach the callback
- `has_action('tag')` checks whether the tag has listeners

### Filters

```php
add_filter('the_content', function ($content) {
    return '<div class="content-wrapper">' . $content . '</div>';
});

add_filter('the_title', function ($title) {
    return strtoupper($title);
}, 10, 1);

$content = apply_filters('the_content', '<p>Original</p>');
$title = apply_filters('the_title', 'welcome post');
```

Extra values after the first argument are passed to filter callbacks as additional parameters:

```php
add_filter('price_label', function ($label, $currency) {
    return $label . ' ' . $currency;
}, 10, 2);

echo apply_filters('price_label', 'Price:', 'USD');
```

### Removal

```php
remove_action('init');
remove_filter('the_content');
```

Current implementation note:

- `remove_action()` and `remove_filter()` clear listeners by tag through the underlying event bus
- the optional callback and priority arguments are accepted for API compatibility, but are not used to remove individual callbacks

### Application lifecycle hooks

Framework lifecycle hook names are defined in `Engine\Atomic\Hook\ApplicationHook`.

- `ApplicationHook::BEFORE_SERVER_START`: fires once before `App::run()` starts the main server loop.

Bootstrap lifecycle hooks:

- `ApplicationHook::CONFIG_LOADED`: configuration is loaded; receives `App` and loader name.
- `ApplicationHook::PREFLY_FAILED`: environment validation failed before output and exit; receives `App`, failed messages, and raw checks.
- `ApplicationHook::CORE_READY`: core services are ready after middleware registration; receives `App`.
- `ApplicationHook::ROUTES_REGISTERED`: route files for one request type are loaded; receives `App`, request type, loaded files, and source (`app` or `plugin`).
- `ApplicationHook::PLUGINS_LOADED`: plugins are registered and booted before plugin routes load; receives `App` and `PluginManager`.
- `ApplicationHook::APP_BOOTSTRAPPED`: bootstrap is complete and about to return the app; receives `App`.

Example:

```php
use Engine\Atomic\Core\App;
use Engine\Atomic\Hook\ApplicationHook;
use Engine\Atomic\Hook\Hook;

Hook::instance()->add_action(
    ApplicationHook::ROUTES_REGISTERED,
    function (App $app, string $request_type, array $files, string $source): void {
        $app->set("PLUGIN.MyPlugin.routes.{$request_type}.{$source}", $files);
    },
    10,
    4
);
```
