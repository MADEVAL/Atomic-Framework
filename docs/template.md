## Template System ##

Atomic theme rendering helpers are provided by `Engine\Atomic\Theme\Theme`.

### Boot a theme

```php
use Engine\Atomic\Theme\Theme;

Theme::instance();          // uses THEME.envname
Theme::instance('Telemetry'); // switch theme explicitly
```

When the theme boots, Atomic:

- sets the F3 `UI` path to the selected theme
- includes `functions.atom.php` if present
- parses `theme.json`
- exposes theme metadata under `THEME.*`

### Render partials

Global helper functions map to theme partials:

```php
get_head();
get_header();
get_sidebar('catalog');
get_section('hero', ['title' => 'Atomic']);
get_footer();
```

They render files such as:

- `partials/head.atom.php`
- `partials/header.atom.php`
- `partials/sidebar.atom.php`
- `partials/hero.atom.php`
- `partials/footer.atom.php`

### Theme paths

```php
$theme = Theme::instance();

$dir = $theme->getThemeDir();
$url = $theme->getThemeUrl();
$publicDir = $theme->getPublicDir();
$publicUrl = $theme->getPublicUrl();
$meta = $theme->getThemeMeta();
```

### Theme metadata

Values from `theme.json` are exposed in the hive as `THEME.*`.

Useful built-ins include:

- `THEME._dir`
- `THEME._url`
- `THEME._theme`
- `THEME._url_public`
- `THEME._dir_public`

### Safe includes

`Theme::include()` only includes files that resolve inside the active theme directory.
