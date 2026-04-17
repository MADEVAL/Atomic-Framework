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

    protected function get_name(): string
    {
        return 'WordPress';
    }

    public function get_version(): string
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

    public function get_posts(int $page = 1, int $per_page = 100, array $params = []): array
    {
        $query = array_merge(['per_page' => $per_page, 'page' => $page], $params);
        $qs = http_build_query($query, '', '&');
        return $this->request("posts?{$qs}");
    }

    public function get_post(int $id): array
    {
        return $this->request("posts/{$id}");
    }

    public function get_categories(int $page = 1, int $per_page = 100): array
    {
        return $this->request("categories?per_page={$per_page}&page={$page}");
    }

    public function get_tags(int $page = 1, int $per_page = 100): array
    {
        return $this->request("tags?per_page={$per_page}&page={$page}");
    }

    public function get_media(int $page = 1, int $per_page = 100): array
    {
        return $this->request("media?per_page={$per_page}&page={$page}");
    }

    public function get_all_posts(array $params = []): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->get_posts($page, 100, $params);
            if (!$result['ok'] || empty($result['data'])) break;
            $all = array_merge($all, $result['data']);
            $page++;
        } while (count($result['data']) === 100);
        
        return ['ok' => true, 'data' => $all];
    }

    public function get_all_categories(): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->get_categories($page, 100);
            if (!$result['ok'] || empty($result['data'])) break;
            $all = array_merge($all, $result['data']);
            $page++;
        } while (count($result['data']) === 100);
        
        return ['ok' => true, 'data' => $all];
    }

    public function parse_post(array $raw): array
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

    public function get_rss_feed(?int $count = null, ?array $tags = null): array
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

    public function parse_rss_item(array $item): array
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

    public function sync_posts(array $params = []): array
    {
        $result = $this->get_all_posts($params);
        if (!$result['ok']) return $result;

        $parsed = array_map([$this, 'parse_post'], $result['data']);
        $this->cache['posts'] = $parsed;
        
        return ['ok' => true, 'count' => count($parsed), 'data' => $parsed];
    }

    public function sync_categories(): array
    {
        $result = $this->get_all_categories();
        if (!$result['ok']) return $result;

        $this->cache['categories'] = $result['data'];
        
        return ['ok' => true, 'count' => count($result['data']), 'data' => $result['data']];
    }

    public function sync_rss_feed(?int $count = null): array
    {
        $result = $this->get_rss_feed($count);
        if (!$result['ok']) return $result;

        $parsed = array_map([$this, 'parse_rss_item'], $result['data']);
        $this->cache['rss'] = $parsed;
        
        return ['ok' => true, 'count' => count($parsed), 'data' => $parsed];
    }

    public function get_cached_posts(): array
    {
        return $this->cache['posts'] ?? [];
    }

    public function get_cached_categories(): array
    {
        return $this->cache['categories'] ?? [];
    }

    public function get_cached_rss(): array
    {
        return $this->cache['rss'] ?? [];
    }
}

function wp_connect(string $url, ?string $key = null, ?string $secret = null): WordPress
{
    return get_plugin('WordPress')->connect($url, $key, $secret);
}

function wp_get_posts(int $page = 1, int $per_page = 100, array $params = []): array
{
    return get_plugin('WordPress')->get_posts($page, $per_page, $params);
}

function wp_get_post(int $id): array
{
    return get_plugin('WordPress')->get_post($id);
}

function wp_get_categories(int $page = 1, int $per_page = 100): array
{
    return get_plugin('WordPress')->get_categories($page, $per_page);
}

function wp_get_tags(int $page = 1, int $per_page = 100): array
{
    return get_plugin('WordPress')->get_tags($page, $per_page);
}

function wp_get_media(int $page = 1, int $per_page = 100): array
{
    return get_plugin('WordPress')->get_media($page, $per_page);
}

function wp_get_all_posts(array $params = []): array
{
    return get_plugin('WordPress')->get_all_posts($params);
}

function wp_get_all_categories(): array
{
    return get_plugin('WordPress')->get_all_categories();
}

function wp_parse_post(array $raw): array
{
    return get_plugin('WordPress')->parse_post($raw);
}

function wp_get_rss_feed(?int $count = null, ?array $tags = null): array
{
    return get_plugin('WordPress')->get_rss_feed($count, $tags);
}

function wp_parse_rss_item(array $item): array
{
    return get_plugin('WordPress')->parse_rss_item($item);
}

function wp_sync_posts(array $params = []): array
{
    return get_plugin('WordPress')->sync_posts($params);
}

function wp_sync_categories(): array
{
    return get_plugin('WordPress')->sync_categories();
}

function wp_sync_rss_feed(?int $count = null): array
{
    return get_plugin('WordPress')->sync_rss_feed($count);
}

function wp_cached_posts(): array
{
    return get_plugin('WordPress')->get_cached_posts();
}

function wp_cached_categories(): array
{
    return get_plugin('WordPress')->get_cached_categories();
}

function wp_cached_rss(): array
{
    return get_plugin('WordPress')->get_cached_rss();
}
