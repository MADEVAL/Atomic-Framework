<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\Core\Log;

final class RssReader extends Plugin
{
    private array $feeds = [];
    private array $cache = [];
    public string $version = '1.0.0';

    protected function getName(): string
    {
        return 'RSS Reader';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function register(): void
    {
        $this->atomic->set('PLUGIN.RssReader.registered', true);
        $this->atomic->set('PLUGIN.RssReader.version', $this->version);
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.RssReader.booted', true);
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.RssReader.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.RssReader.active', false);
    }

    public function addFeed(string $name, string $url): self
    {
        $this->feeds[$name] = $url;
        return $this;
    }

    public function read(string $url, ?int $count = null, ?array $tags = null): array
    {
        try {
            $web = \Web::instance();
            $tagsList = $tags ? implode(',', $tags) : null;
            $items = $web->rss($url, $count, $tagsList);
            
            if (empty($items)) {
                return ['ok' => false, 'error' => 'Feed is empty or unavailable'];
            }

            return ['ok' => true, 'data' => $items, 'count' => count($items)];
        } catch (\Throwable $e) {
            Log::error('RSS Reader error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function readFeed(string $name, ?int $count = null, ?array $tags = null): array
    {
        if (!isset($this->feeds[$name])) {
            return ['ok' => false, 'error' => "Feed '{$name}' not found"];
        }

        return $this->read($this->feeds[$name], $count, $tags);
    }

    public function parse(array $item): array
    {
        return [
            'title' => $item['title'] ?? '',
            'link' => $item['link'] ?? '',
            'description' => $item['description'] ?? '',
            'content' => $item['content:encoded'] ?? $item['content'] ?? '',
            'pubDate' => $item['pubDate'] ?? '',
            'guid' => $item['guid'] ?? '',
            'author' => $item['author'] ?? $item['dc:creator'] ?? '',
            'category' => is_array($item['category'] ?? null) ? $item['category'] : [$item['category'] ?? ''],
            'enclosure' => $item['enclosure'] ?? null,
            'thumbnail' => $item['media:thumbnail'] ?? null,
        ];
    }

    public function sync(string $url, ?int $count = null, ?array $tags = null): array
    {
        $result = $this->read($url, $count, $tags);
        if (!$result['ok']) return $result;

        $parsed = array_map([$this, 'parse'], $result['data']);
        $hash = md5($url);
        $this->cache[$hash] = $parsed;
        
        return ['ok' => true, 'count' => count($parsed), 'data' => $parsed];
    }

    public function syncFeed(string $name, ?int $count = null, ?array $tags = null): array
    {
        if (!isset($this->feeds[$name])) {
            return ['ok' => false, 'error' => "Feed '{$name}' not found"];
        }

        $result = $this->sync($this->feeds[$name], $count, $tags);
        if ($result['ok']) {
            $this->cache[$name] = $result['data'];
        }
        
        return $result;
    }

    public function syncAll(?int $count = null, ?array $tags = null): array
    {
        $results = [];
        foreach ($this->feeds as $name => $url) {
            $results[$name] = $this->syncFeed($name, $count, $tags);
        }
        
        $total = array_sum(array_column(array_filter($results, fn($r) => $r['ok']), 'count'));
        return ['ok' => true, 'feeds' => count($results), 'total' => $total, 'results' => $results];
    }

    public function getCached(string $key): array
    {
        return $this->cache[$key] ?? [];
    }

    public function getAllCached(): array
    {
        return $this->cache;
    }

    public function clearCache(?string $key = null): self
    {
        if ($key === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$key]);
        }
        return $this;
    }

    public function getFeeds(): array
    {
        return $this->feeds;
    }
}

function rss_add_feed(string $name, string $url): RssReader
{
    return get_plugin('RssReader')->addFeed($name, $url);
}

function rss_read(string $url, ?int $count = null, ?array $tags = null): array
{
    return get_plugin('RssReader')->read($url, $count, $tags);
}

function rss_read_feed(string $name, ?int $count = null, ?array $tags = null): array
{
    return get_plugin('RssReader')->readFeed($name, $count, $tags);
}

function rss_parse(array $item): array
{
    return get_plugin('RssReader')->parse($item);
}

function rss_sync(string $url, ?int $count = null, ?array $tags = null): array
{
    return get_plugin('RssReader')->sync($url, $count, $tags);
}

function rss_sync_feed(string $name, ?int $count = null, ?array $tags = null): array
{
    return get_plugin('RssReader')->syncFeed($name, $count, $tags);
}

function rss_sync_all(?int $count = null, ?array $tags = null): array
{
    return get_plugin('RssReader')->syncAll($count, $tags);
}

function rss_cached(string $key): array
{
    return get_plugin('RssReader')->getCached($key);
}

function rss_all_cached(): array
{
    return get_plugin('RssReader')->getAllCached();
}

function rss_clear_cache(?string $key = null): RssReader
{
    return get_plugin('RssReader')->clearCache($key);
}

function rss_feeds(): array
{
    return get_plugin('RssReader')->getFeeds();
}
