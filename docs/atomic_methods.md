## Methods ##

Helpers in this page map to `Engine\Atomic\Core\Methods` and the global wrappers from `Support/helpers.php`.

```php
if (is_home()) { /* ... */ }
if (is_page('/login')) { /* ... */ }
if (is_page(['/login','/auth/*'])) { /* ... */ }
if (is_section('seller')) { /* ... */ }        // /seller and /seller/page etc
$parts = url_segments();                        // ['seller','page'] for /en/seller/page
```

### Path helpers

```php
$path = current_path();              // current request path, language prefix removed by default
$parts = url_segments();
$first = get_segment(0);
```

### Match helpers

```php
is_home();
is_page('/account');
is_page(['/account', '/account/*']);
is_section('blog');
```

`is_page()` supports exact paths and `*` wildcards.

### Request and environment helpers

```php
is_ssl();
is_ajax();
is_mobile();
is_404();
is_telegram();
is_botblocker();
is_gs();
get_encoding();
get_error_trace();
```

### Small utility helpers

```php
get_year();
get_copyright_years(2024);
get_date('Y-m-d');
get_copy();
```
