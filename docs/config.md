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
