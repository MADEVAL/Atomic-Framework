## Config ##

Atomic provides a small configuration layer that loads application settings into the F3 hive.

At a high level, the framework supports:

- environment-style config through `Engine\Atomic\Core\Config\ConfigLoader`
- PHP config files through `Engine\Atomic\Core\Config\PhpConfigLoader`

Those loaders are used to prepare the values consumed by the rest of the framework, such as database, queue, mail, session, cache, i18n, and other runtime settings.

Typical bootstrap usage:

```php
use Engine\Atomic\Core\Config\ConfigLoader;

$f3 = \Base::instance();
ConfigLoader::init($f3, realpath(__DIR__ . '/../.env'));
```

If a project uses structured PHP config files instead, `PhpConfigLoader` can be used to load them into the same shared runtime config space.

### Custom application config

Application-specific config belongs under the `CONFIG` hive key. This keeps custom values separate from framework-owned root keys such as `DB_CONFIG`, `QUEUE`, `RATE_LIMITER`, `MAIL`, and `SESSION_CONFIG`.

#### PHP config mode

When using `PhpConfigLoader`, Atomic loads the known framework config files first. It then scans only the top level of `config/` for additional `.php` files.

Custom files must return arrays:

```php
// config/feature_flags.php
return [
    'enabled' => true,
    'providers' => ['primary', 'backup'],
    'monthly_quota' => 1000,
];
```

The file is available at:

```php
$features = $f3->get('CONFIG.feature_flags');
$quota = $f3->get('CONFIG.feature_flags.monthly_quota');
```

Framework-owned files are not treated as custom config. Files such as `app.php`, `database.php`, `queue.php`, `rate_limiter.php`, `tools.php`, and `index.php` keep their existing framework behavior and do not override custom config namespaces.

If a custom PHP config file does not return an array, Atomic logs a warning and skips that file.

#### Environment config mode

When using `.env` config, only explicit keys prefixed with `CONFIG_` are loaded as custom application config.

The format is:

```dotenv
CONFIG_<NAMESPACE>_<KEY>=value
```

Examples:

```dotenv
CONFIG_FEATURES_MONTHLY_QUOTA=1000
CONFIG_FEATURES_ENDPOINTS_ENABLED=true
CONFIG_BILLING_MODE=live
CONFIG_REPORTING_RECIPIENTS=ops@example.com,admin@example.com
```

These become:

```php
$f3->get('CONFIG.features.monthly_quota');      // 1000
$f3->get('CONFIG.features.endpoints_enabled');  // true
$f3->get('CONFIG.billing.mode');                // "live"
$f3->get('CONFIG.reporting.recipients');        // ["ops@example.com", "admin@example.com"]
```

Custom env values are parsed conservatively:

- `true` and `false` become booleans
- integer strings become integers
- decimal numeric strings become floats
- comma-separated values become arrays of trimmed strings
- all other values remain strings

Structured config should use PHP files. `.env` custom config is intended for scalar overrides only.
