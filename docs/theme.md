## Themes ##

Atomic theme runtime is implemented by `Engine\Atomic\Theme\Theme`.

### Theme boot

```php
use Engine\Atomic\Theme\Theme;

Theme::instance();            // first call uses THEME.envname or "default"
Theme::instance('marketing'); // replaces the current singleton and boots that theme
Theme::reset();               // clears the singleton
```

**Automatic default theme boot:** `Controller::beforeroute()` calls `Theme::instance()` implicitly. Every controller gets the default theme (`THEME.envname`) without explicit initialization. Controllers that need a specific theme (e.g., `ErrorPages`, `Telemetry`) call `Theme::instance('name')` in their constructor — this replaces the singleton and boots the named theme. `Theme::instance()` with no arguments on subsequent calls returns the existing singleton without reinitializing.

Runtime behavior:

1. Reads the custom-theme root from `ENQ_UI_FIX`.
2. Resolves the theme name from the explicit argument or `THEME.envname`.
3. Uses `<ENQ_UI_FIX>/<theme_name>/` when that custom theme contains `theme.json`.
4. Otherwise falls back to the bundled theme in `engine/Atomic/Theme/Builtin/<theme_name>/`.
5. Sets `UI` to the resolved theme directory and logs an error if neither location exists.
6. Loads `functions.atom.php` through `Theme::include(...)`.
7. Parses `theme.json`.
8. Publishes theme metadata into the hive as `THEME.*`.

Notes:

- A no-argument `Theme::instance()` reuses the existing singleton once one has been created.
- To switch themes after boot, call `Theme::instance('name')` or `Theme::reset()` first.

### Theme folder layout

Recommended layout:

```text
public/themes/
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

Bundled themes are framework-internal and are never exposed as source files. Their CSS, JavaScript, fonts, and other static files are served through `/__atomic/themes/<theme_name>/...`. Custom themes remain public and use the configured public theme path. A custom theme with the same name overrides its bundled counterpart when it contains at least `theme.json`; an empty legacy directory does not.

Built-in metadata added by `Theme::parse()`:

- `THEME._file`: absolute path to `theme.json`
- `THEME._dir`: active theme directory
- `THEME._url`: themes-root URL (for example, `<public_url>themes/`); this remains the public custom-theme root even when the active theme is bundled
- `THEME._theme`: active theme name
- `THEME._url_public`: public base URL from `Methods::get_public_url()`
- `THEME._dir_public`: public base directory from `Methods::get_public_dir()`

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
Theme::get_custom_head();
Theme::get_header();
Theme::get_footer();
Theme::get_sidebar();
Theme::get_section('hero', ['title' => 'Atomic']);
```

Resolution:

- `get_head()` -> `partials/head.atom.php`
- `get_custom_head()` -> `partials/head.custom.atom.php` if the file exists
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

$meta      = $theme->get_theme_meta();
$themeDir  = $theme->get_theme_dir();
$themeUrl  = $theme->get_theme_url();
$themeName = $theme->get_theme_name();
$publicUrl = $theme->get_public_url();
$publicDir = $theme->get_public_dir();
```

Getter details:

- `get_theme_dir()` returns the active theme directory with a trailing directory separator.
- `get_theme_url()` returns the active theme asset URL without an added trailing slash. Unlike `THEME._url`, it follows the resolved theme: bundled themes use `/__atomic/themes/<theme_name>`, while custom themes use `<THEME._url><theme_name>`.
- `is_builtin()` reports whether the active theme came from the framework bundle.
- `get_public_url()` and `get_public_dir()` are the generic public paths, not theme-specific paths.

Color helpers:

- `get_theme_color()` returns `theme.json` `color` when present, otherwise `#ffffff`.
- `set_theme_color($fallback)` returns `PAGE.color` when it exists, otherwise the provided fallback.

### Theme development notes

1. Put custom themes under the configured public theme root (the skeleton default is `public/themes/`).
2. Put shared theme bootstrap code in `functions.atom.php`.
3. Keep `theme.json` for metadata only; it is copied into the hive unchanged except for the built-in `THEME._*` keys.
4. Use `Theme::instance('name')` when a controller must force a specific theme.
5. Use `Theme::include(...)` instead of raw includes for theme-local PHP files.
6. Keep partial names aligned with the helper that renders them.
