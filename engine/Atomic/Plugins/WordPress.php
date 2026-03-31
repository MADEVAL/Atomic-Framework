<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\Core\Request as HTTP;
use Engine\Atomic\Core\Log;

class WordPress extends Plugin
{
    private ?string $url = null;
    private ?string $key = null;
    private ?string $secret = null;
    private array $cache = [];
    public string $version = '1.0.0';

    protected function getName(): string
    {
        return 'WordPress';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function register(): void
    {
        $this->atomic->set('PLUGIN.WordPress.registered', true);
        $this->atomic->set('PLUGIN.WordPress.version', $this->version);
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.WordPress.booted', true);
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.WordPress.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.WordPress.active', false);
    }

    public function connect(string $url, ?string $key = null, ?string $secret = null): self
    {
        $this->url = rtrim($url, '/');
        $this->key = $key;
        $this->secret = $secret;
        return $this;
    }

    private function request(string $endpoint, string $method = 'GET', ?array $data = null): array
    {
        if (!$this->url) {
            return ['ok' => false, 'error' => 'Not connected'];
        }

        $url = $this->url . '/wp-json/wp/v2/' . ltrim($endpoint, '/');
        $args = ['timeout' => 30];

        if ($this->key && $this->secret) {
            $args['headers'] = [
                'Authorization' => 'Basic ' . base64_encode($this->key . ':' . $this->secret),
            ];
        }

        $result = match($method) {
            'POST' => HTTP::instance()->remote_post($url, $data, $args),
            'PUT' => HTTP::instance()->remote_put($url, $data, $args),
            default => HTTP::instance()->remote_get($url, $args),
        };

        if (!$result['ok']) {
            Log::error('WordPress API error: ' . $result['error']);
            return ['ok' => false, 'error' => $result['error']];
        }

        $decoded = json_decode($result['body'], true);
        return ['ok' => true, 'data' => $decoded ?? []];
    }

    public function getPosts(int $page = 1, int $perPage = 100, array $params = []): array
    {
        $query = array_merge(['per_page' => $perPage, 'page' => $page], $params);
        $qs = http_build_query($query, '', '&');
        return $this->request("posts?{$qs}");
    }

    public function getPost(int $id): array
    {
        return $this->request("posts/{$id}");
    }

    public function getCategories(int $page = 1, int $perPage = 100): array
    {
        return $this->request("categories?per_page={$perPage}&page={$page}");
    }

    public function getTags(int $page = 1, int $perPage = 100): array
    {
        return $this->request("tags?per_page={$perPage}&page={$page}");
    }

    public function getMedia(int $page = 1, int $perPage = 100): array
    {
        return $this->request("media?per_page={$perPage}&page={$page}");
    }

    public function getAllPosts(array $params = []): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->getPosts($page, 100, $params);
            if (!$result['ok'] || empty($result['data'])) break;
            $all = array_merge($all, $result['data']);
            $page++;
        } while (count($result['data']) === 100);
        
        return ['ok' => true, 'data' => $all];
    }

    public function getAllCategories(): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->getCategories($page, 100);
            if (!$result['ok'] || empty($result['data'])) break;
            $all = array_merge($all, $result['data']);
            $page++;
        } while (count($result['data']) === 100);
        
        return ['ok' => true, 'data' => $all];
    }

    public function parsePost(array $raw): array
    {
        return [
            'id' => $raw['id'] ?? 0,
            'title' => $raw['title']['rendered'] ?? '',
            'content' => $raw['content']['rendered'] ?? '',
            'excerpt' => $raw['excerpt']['rendered'] ?? '',
            'slug' => $raw['slug'] ?? '',
            'link' => $raw['link'] ?? '',
            'author' => $raw['author'] ?? 0,
            'featured_media' => $raw['featured_media'] ?? 0,
            'date' => $raw['date'] ?? '',
            'modified' => $raw['modified'] ?? '',
            'status' => $raw['status'] ?? 'publish',
            'categories' => $raw['categories'] ?? [],
            'tags' => $raw['tags'] ?? [],
        ];
    }

    public function getRssFeed(?int $count = null, ?array $tags = null): array
    {
        if (!$this->url) {
            return ['ok' => false, 'error' => 'Not connected'];
        }

        $feedUrl = $this->url . '/feed/';
        
        try {
            $web = \Web::instance();
            $tagsList = $tags ? implode(',', $tags) : null;
            $feed = $web->rss($feedUrl, $count, $tagsList);
            
            if (empty($feed)) {
                return ['ok' => false, 'error' => 'Feed is empty'];
            }

            return ['ok' => true, 'data' => $feed];
        } catch (\Throwable $e) {
            Log::error('WordPress RSS feed error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function parseRssItem(array $item): array
    {
        return [
            'title' => $item['title'] ?? '',
            'link' => $item['link'] ?? '',
            'description' => $item['description'] ?? '',
            'pubDate' => $item['pubDate'] ?? '',
            'guid' => $item['guid'] ?? '',
            'author' => $item['author'] ?? $item['dc:creator'] ?? '',
            'category' => $item['category'] ?? [],
        ];
    }

    public function syncPosts(array $params = []): array
    {
        $result = $this->getAllPosts($params);
        if (!$result['ok']) return $result;

        $parsed = array_map([$this, 'parsePost'], $result['data']);
        $this->cache['posts'] = $parsed;
        
        return ['ok' => true, 'count' => count($parsed), 'data' => $parsed];
    }

    public function syncCategories(): array
    {
        $result = $this->getAllCategories();
        if (!$result['ok']) return $result;

        $this->cache['categories'] = $result['data'];
        
        return ['ok' => true, 'count' => count($result['data']), 'data' => $result['data']];
    }

    public function syncRssFeed(?int $count = null): array
    {
        $result = $this->getRssFeed($count);
        if (!$result['ok']) return $result;

        $parsed = array_map([$this, 'parseRssItem'], $result['data']);
        $this->cache['rss'] = $parsed;
        
        return ['ok' => true, 'count' => count($parsed), 'data' => $parsed];
    }

    public function getCachedPosts(): array
    {
        return $this->cache['posts'] ?? [];
    }

    public function getCachedCategories(): array
    {
        return $this->cache['categories'] ?? [];
    }

    public function getCachedRss(): array
    {
        return $this->cache['rss'] ?? [];
    }
}

function wp_connect(string $url, ?string $key = null, ?string $secret = null): WordPress
{
    return get_plugin('WordPress')->connect($url, $key, $secret);
}

function wp_get_posts(int $page = 1, int $perPage = 100, array $params = []): array
{
    return get_plugin('WordPress')->getPosts($page, $perPage, $params);
}

function wp_get_post(int $id): array
{
    return get_plugin('WordPress')->getPost($id);
}

function wp_get_categories(int $page = 1, int $perPage = 100): array
{
    return get_plugin('WordPress')->getCategories($page, $perPage);
}

function wp_get_tags(int $page = 1, int $perPage = 100): array
{
    return get_plugin('WordPress')->getTags($page, $perPage);
}

function wp_get_media(int $page = 1, int $perPage = 100): array
{
    return get_plugin('WordPress')->getMedia($page, $perPage);
}

function wp_get_all_posts(array $params = []): array
{
    return get_plugin('WordPress')->getAllPosts($params);
}

function wp_get_all_categories(): array
{
    return get_plugin('WordPress')->getAllCategories();
}

function wp_parse_post(array $raw): array
{
    return get_plugin('WordPress')->parsePost($raw);
}

function wp_get_rss_feed(?int $count = null, ?array $tags = null): array
{
    return get_plugin('WordPress')->getRssFeed($count, $tags);
}

function wp_parse_rss_item(array $item): array
{
    return get_plugin('WordPress')->parseRssItem($item);
}

function wp_sync_posts(array $params = []): array
{
    return get_plugin('WordPress')->syncPosts($params);
}

function wp_sync_categories(): array
{
    return get_plugin('WordPress')->syncCategories();
}

function wp_sync_rss_feed(?int $count = null): array
{
    return get_plugin('WordPress')->syncRssFeed($count);
}

function wp_cached_posts(): array
{
    return get_plugin('WordPress')->getCachedPosts();
}

function wp_cached_categories(): array
{
    return get_plugin('WordPress')->getCachedCategories();
}

function wp_cached_rss(): array
{
    return get_plugin('WordPress')->getCachedRss();
}
