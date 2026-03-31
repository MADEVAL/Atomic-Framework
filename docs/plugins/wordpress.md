# WordPress Plugin

Connect to WordPress REST API and sync content.

## Connection

```php
// Public API (no auth)
wp_connect('https://example.com');

// With authentication
wp_connect('https://example.com', 'username', 'app_password');
```

## Get Posts

```php
// Get posts (paginated)
$result = wp_get_posts(1, 10);

// Get all posts
$result = wp_get_all_posts();

// Get specific post
$post = wp_get_post(123);

// With filters
$result = wp_get_posts(1, 10, ['status' => 'publish', 'categories' => [1,2]]);
```

## Get Categories & Tags

```php
$categories = wp_get_categories();
$allCategories = wp_get_all_categories();
$tags = wp_get_tags();
```

## RSS Feed

```php
// Get RSS feed
$result = wp_get_rss_feed(10);

// Parse item
foreach ($result['data'] as $item) {
    $parsed = wp_parse_rss_item($item);
    echo $parsed['title'];
}
```

## Sync & Cache

```php
// Sync posts to cache
wp_sync_posts();
$posts = wp_cached_posts();

// Sync categories
wp_sync_categories();
$cats = wp_cached_categories();

// Sync RSS feed
wp_sync_rss_feed(20);
$rss = wp_cached_rss();
```

## Examples

```php
// Display latest posts
wp_connect('https://blog.example.com');
$result = wp_get_posts(1, 5);

if ($result['ok']) {
    foreach ($result['data'] as $post) {
        $parsed = wp_parse_post($post);
        echo '<h2>' . $parsed['title'] . '</h2>';
        echo $parsed['excerpt'];
    }
}
```
