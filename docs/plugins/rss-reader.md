# RSS Reader Plugin

Read and parse RSS/Atom feeds.

## Basic Usage

```php
// Read feed
$result = rss_read('https://example.com/feed.rss', 10);

// Read specific tags only
$result = rss_read('https://example.com/feed.rss', 10, ['title', 'link', 'pubDate']);
```

## Named Feeds

```php
// Add named feeds
rss_add_feed('blog', 'https://blog.example.com/feed.rss');
rss_add_feed('news', 'https://news.example.com/rss');

// Read by name
$result = rss_read_feed('blog', 5);
```

## Parsing

```php
$result = rss_read('https://example.com/feed.rss', 10);

if ($result['ok']) {
    foreach ($result['data'] as $item) {
        $parsed = rss_parse($item);
        echo $parsed['title'];
        echo $parsed['link'];
        echo $parsed['description'];
    }
}
```

## Caching

```php
// Sync and cache
rss_sync('https://example.com/feed.rss', 20);

// Sync named feed
rss_sync_feed('blog', 10);

// Sync all feeds
rss_sync_all(15);

// Get cached
$cached = rss_cached('blog');
```

## Examples

```php
// Aggregate feeds
rss_add_feed('tech', 'https://techcrunch.com/feed/');
rss_add_feed('dev', 'https://dev.to/feed');
rss_sync_all(10);

foreach (rss_all_cached() as $feedName => $items) {
    echo "<h2>{$feedName}</h2>";
    foreach ($items as $item) {
        echo '<p><a href="' . $item['link'] . '">' . $item['title'] . '</a></p>';
    }
}
```