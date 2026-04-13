## Themes ##

Atomic theme runtime is implemented by `Engine\Atomic\Theme\Theme`.

### Theme boot

```php
use Engine\Atomic\Theme\Theme;

Theme::instance();            // first call uses THEME.envname or "default"
Theme::instance('marketing'); // replaces the current singleton and boots that theme
Theme::reset();               // clears the singleton
```

Runtime behavior:

1. Reads the UI root from `ENQ_UI_FIX`.
2. Resolves the theme name from the explicit argument or `THEME.envname`.
3. Sets `UI` to `<ENQ_UI_FIX>/<theme_name>/`.
4. Logs an error if that directory does not exist.
5. Loads `functions.atom.php` through `Theme::include(...)`.
6. Parses `theme.json`.
7. Publishes theme metadata into the hive as `THEME.*`.

Notes:

- A no-argument `Theme::instance()` reuses the existing singleton once one has been created.
- To switch themes after boot, call `Theme::instance('name')` or `Theme::reset()` first.

### Theme folder layout

Recommended layout:

```text
app/UI/
  default/
    functions.atom.php
    theme.json
    partials/
      head.atom.php
      head.custom.atom.php
      header.atom.php
      footer.atom.php
      sidebar.atom.php
```

Only `functions.atom.php` and `theme.json` are loaded directly by `Theme`. Partials are rendered later through `View`.

### Theme metadata

If `theme.json` exists and contains valid JSON, its keys are exposed as `THEME.<key>`.

Built-in metadata added by `Theme::parse()`:

- `THEME._file`: absolute path to `theme.json`
- `THEME._dir`: active theme directory
- `THEME._url`: base themes URL, currently `<public_url>themes/`
- `THEME._theme`: active theme name
- `THEME._url_public`: public base URL from `Methods::get_publicUrl()`
- `THEME._dir_public`: public base directory from `Methods::get_publicDir()`

Example:

```json
{
  "title": "Default Theme",
  "author": "Atomic Team",
  "version": "1.2.0",
  "color": "#f7f7f7"
}
```

Becomes:

- `THEME.title`
- `THEME.author`
- `THEME.version`
- `THEME.color`

If `theme.json` is missing, unreadable, or invalid, boot continues and a warning is logged.

### Render helpers

Theme methods render exact partial paths under `partials/`:

```php
Theme::get_head();
Theme::get_customHead();
Theme::get_header();
Theme::get_footer();
Theme::get_sidebar();
Theme::get_section('hero', ['title' => 'Atomic']);
```

Resolution:

- `get_head()` -> `partials/head.atom.php`
- `get_customHead()` -> `partials/head.custom.atom.php` if the file exists
- `get_header('header')` -> `partials/header.atom.php`
- `get_footer('footer')` -> `partials/footer.atom.php`
- `get_sidebar('sidebar')` -> `partials/sidebar.atom.php`
- `get_section('hero')` -> `partials/hero.atom.php`

Global helpers from `helpers.php`:

- `get_head()`
- `get_custom_head()`
- `get_header()`
- `get_footer()`
- `get_sidebar()`
- `get_section()`

### Safe includes inside a theme

Use `Theme::include(...)` for PHP files that live inside the active theme:

```php
$theme = Theme::instance();
$ok = $theme->include('inc/hooks.php');
```

Behavior:

- Relative paths are resolved inside the active theme directory.
- Absolute paths are allowed only when they still resolve inside the theme directory.
- Escaping the theme root is rejected and logged.
- Only readable regular files are included.
- Files are loaded with `include_once`.

### Runtime getters

```php
$theme = Theme::instance();

$meta      = $theme->getThemeMeta();
$themeDir  = $theme->getThemeDir();
$themeUrl  = $theme->getThemeUrl();
$themeName = $theme->getThemeName();
$publicUrl = $theme->getPublicUrl();
$publicDir = $theme->getPublicDir();
```

Getter details:

- `getThemeDir()` returns the active theme directory with a trailing directory separator.
- `getThemeUrl()` returns `<public_url>themes/<theme_name>` without an added trailing slash.
- `getPublicUrl()` and `getPublicDir()` are the generic public paths, not theme-specific paths.

Color helpers:

- `getThemeColor()` returns `theme.json` `color` when present, otherwise `#ffffff`.
- `setThemeColor($fallback)` returns `PAGE.color` when it exists, otherwise the provided fallback.

### Theme development notes

1. Put shared theme bootstrap code in `functions.atom.php`.
2. Keep `theme.json` for metadata only; it is copied into the hive unchanged except for the built-in `THEME._*` keys.
3. Use `Theme::instance('name')` when a controller must force a specific theme.
4. Use `Theme::include(...)` instead of raw includes for theme-local PHP files.
5. Keep partial names aligned with the helper that renders them.
