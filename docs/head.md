## HEAD ##

Head helpers are implemented by `Engine\Atomic\Theme\Head` and render HTML tags directly.

```php
get_favicon();
get_title();
get_iconset();
get_manifest();

// Canonical
get_canonical_link();

// Preconnect
get_preconnect('cloudflare');
get_preconnect('google-fonts');
add_preconnect('https://example.com', true);

// Preload
add_preload('/assets/fonts/main.woff2', 'font', 'font/woff2', true);
get_preload_links();

// Analytics
get_analytics('google', 'G-XXXXXXXXXX');
get_analytics('yandex', '12345678');

// Schema
get_schema('organization', [
    'name' => 'My Company',
    'url' => 'https://example.com',
    'email' => 'info@example.com'
]);

get_schema('product', [
    'name' => 'Product Name',
    'price' => '99.99',
    'currency' => 'USD'
]);
```

### What each helper does

- `get_favicon()` renders the main favicon link, using `FAVICON` or `/favicon.ico`
- `get_title()` prints the current page title plus `APP_NAME`
- `get_iconset()` renders common favicon and touch-icon variants
- `get_manifest()` links `site.webmanifest`
- `get_canonical_link()` builds the canonical URL from scheme, host, base path, and current path

### Resource hints

Use `add_preconnect()` and `add_preload()` to queue hints, then print them with:

```php
get_preconnect();
get_preload_links();
```

### Analytics presets

Current built-in analytics presets:

- `google`
- `ga4`
- `yandex`
