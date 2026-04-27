<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\Core\Request as HTTP;
use Engine\Atomic\Core\Log;

class WooCommerce extends Plugin
{
    private ?string $url = null;
    private ?string $key = null;
    private ?string $secret = null;
    private array $cache = [];
    public string $version = '1.0.0';

    protected function get_name(): string
    {
        return 'WooCommerce Atomic Integration';
    }

    public function get_version(): string
    {
        return $this->version;
    }

    public function register(): void
    {
        $this->atomic->set('PLUGIN.WooCommerce.registered', true);
        $this->atomic->set('PLUGIN.WooCommerce.version', $this->version);
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.WooCommerce.booted', true);
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.WooCommerce.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.WooCommerce.active', false);
    }

    public function connect(string $url, string $key, string $secret): self
    {
        $this->url = rtrim($url, '/');
        $this->key = $key;
        $this->secret = $secret;
        return $this;
    }

    private function request(string $endpoint, string $method = 'GET', ?array $data = null): array
    {
        if (!$this->url || !$this->key || !$this->secret) {
            return ['ok' => false, 'error' => 'Not connected'];
        }

        $url = $this->url . '/wp-json/wc/v3/' . ltrim($endpoint, '/');
        $args = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->key . ':' . $this->secret),
            ],
            'timeout' => 30,
        ];

        $result = match($method) {
            'POST'  => HTTP::instance()->remote_post($url, $data, $args),
            'PUT'   => HTTP::instance()->remote_put($url, $data, $args),
            default => HTTP::instance()->remote_get($url, $args),
        };

        if (!$result['ok']) {
            $http_status = $result['status'];
            $http_error  = $result['error'];
            $body        = $result['body'];
    
            $json       = json_decode($body, true);
            $wc_code    = $json['code']    ?? '';
            $wc_message = $json['message'] ?? '';
    
            if ($wc_message) {
                $error_msg = $wc_code ? "[{$wc_code}] {$wc_message}" : $wc_message;
            } elseif ($wc_code) {
                $error_msg = $http_status ? "[{$wc_code}] HTTP {$http_status}" : "[{$wc_code}]";
            } elseif ($http_error) {
                $error_msg = $http_error;
            } elseif ($http_status >= 300 && $http_status < 400) {
                $location  = $result['headers']['location'] ?? '';
                $error_msg = $location
                    ? "HTTP {$http_status} redirect to {$location} (check URL, auth keys, or permalink settings)"
                    : "HTTP {$http_status} redirect (check store URL and API credentials)";
            } elseif (!empty($body) && $json === null) {
                $title = '';
                if (preg_match('/<title>([^<]*)<\/title>/i', $body, $m)) {
                    $title = trim($m[1]);
                }
                $error_msg = $title
                    ? "HTTP {$http_status}: {$title}"
                    : "HTTP {$http_status} (non-JSON response — check store URL and API path)";
            } else {
                $error_msg = $http_status
                    ? "HTTP {$http_status}"
                    : 'Request failed (no status, no body)';
            }
    
            Log::error('WooCommerce API error: ' . $error_msg);
            return ['ok' => false, 'error' => $error_msg];
        }

        $decoded = json_decode($result['body'], true);
        return ['ok' => true, 'data' => $decoded ?? []];
    }

    public function get_products(int $page = 1, int $per_page = 100): array
    {
        return $this->request("products?per_page={$per_page}&page={$page}");
    }

    public function get_product(int $id): array
    {
        return $this->request("products/{$id}");
    }

    public function get_categories(int $page = 1, int $per_page = 100): array
    {
        return $this->request("products/categories?per_page={$per_page}&page={$page}");
    }

    public function get_all_products(): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->get_products($page, 100);
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

    public function parse_product(array $raw): array
    {
        $to_float = fn($v) => (float)str_replace(',', '.', trim((string)($v ?? 0)));

        $regular_price_field = !empty($raw['regular_price']) ? $to_float($raw['regular_price']) : $to_float($raw['price'] ?? 0);
        $sale_price = !empty($raw['sale_price']) ? $to_float($raw['sale_price']) : 0.0;
        
        $stock_quantity = null;
        if (isset($raw['stock_quantity']) && $raw['stock_quantity'] !== null && $raw['stock_quantity'] !== '') {
            $stock_quantity = (int)$raw['stock_quantity'];
        } elseif ($raw['manage_stock'] ?? false) {
            $stock_quantity = 0;
        }
        
        return [
            'id' => (int)($raw['id'] ?? 0),
            'sku' => (string)($raw['sku'] ?? ''),
            'name' => (string)($raw['name'] ?? ''),
            'slug' => (string)($raw['slug'] ?? ''),
            'type' => (string)($raw['type'] ?? 'simple'),
            'price' => $regular_price_field,
            'regular_price' => $regular_price_field,
            'sale_price' => $sale_price,
            'on_sale' => (bool)($raw['on_sale'] ?? false),
            'stock_quantity' => $stock_quantity,
            'stock_status' => (string)($raw['stock_status'] ?? 'instock'),
            'manage_stock' => (bool)($raw['manage_stock'] ?? false),
            'downloadable' => (bool)($raw['downloadable'] ?? false),
            'virtual' => (bool)($raw['virtual'] ?? false),
            'images' => array_map(fn($i) => (string)($i['src'] ?? ''), $raw['images'] ?? []),
            'short_description' => (string)($raw['short_description'] ?? ''),
            'description' => (string)($raw['description'] ?? ''),
            'permalink' => (string)($raw['permalink'] ?? ''),
            'categories' => array_map(fn($c) => ['id' => (int)($c['id'] ?? 0), 'name' => (string)($c['name'] ?? ''), 'slug' => (string)($c['slug'] ?? '')], $raw['categories'] ?? []),
            'attributes' => $raw['attributes'] ?? [],
        ];
    }

    public function create_order(array $order_data): array
    {
        return $this->request('orders', 'POST', $order_data);
    }

    public function update_order(int $order_id, array $data): array
    {
        return $this->request("orders/{$order_id}", 'PUT', $data);
    }

    public function get_order(int $order_id): array
    {
        return $this->request("orders/{$order_id}");
    }

    public function update_order_status(int $order_id, string $status): array
    {
        return $this->update_order($order_id, ['status' => $status]);
    }

    public function apply_coupon(int $order_id, string $coupon_code): array
    {
        $order = $this->get_order($order_id);
        if (!$order['ok']) return $order;

        $coupons = $order['data']['coupon_lines'] ?? [];
        $coupons[] = ['code' => $coupon_code];
        
        return $this->update_order($order_id, ['coupon_lines' => $coupons]);
    }

    public function get_customer(int $customer_id): array
    {
        return $this->request("customers/{$customer_id}");
    }

    public function create_customer(array $customer_data): array
    {
        return $this->request('customers', 'POST', $customer_data);
    }

    public function update_customer(int $customer_id, array $data): array
    {
        return $this->request("customers/{$customer_id}", 'PUT', $data);
    }

    public function get_customer_by_email(string $email): array
    {
        $result = $this->request("customers?email=" . urlencode($email));
        if ($result['ok'] && !empty($result['data'][0])) {
            return ['ok' => true, 'data' => $result['data'][0]];
        }
        return ['ok' => false, 'error' => 'Customer not found'];
    }

    public function register_customer(string $email, string $username, ?string $first_name = null, ?string $last_name = null, ?string $phone = null): array
    {
        $data = [
            'email' => $email,
            'username' => $username,
            'first_name' => $first_name ?? '',
            'last_name' => $last_name ?? '',
        ];

        if ($phone) {
            $data['billing'] = ['phone' => $phone];
        }

        return $this->create_customer($data);
    }

    public function register_customer_from_telegram(int $telegram_id, ?string $username = null, ?string $first_name = null, ?string $last_name = null): array
    {
        $email = "tg_{$telegram_id}@telegram.local";
        $wcUsername = $username ?: "tg_{$telegram_id}";
        
        return $this->register_customer($email, $wcUsername, $first_name, $last_name);
    }

    public function update_customer_address(int $customer_id, array $billing = [], array $shipping = []): array
    {
        $data = [];
        if (!empty($billing)) $data['billing'] = $billing;
        if (!empty($shipping)) $data['shipping'] = $shipping;
        
        return $this->update_customer($customer_id, $data);
    }

    public function test_connection(string $url, string $key, string $secret): array
    {
        $this->connect($url, $key, $secret);
        $result = $this->request('settings/general');

        if (!$result['ok']) {
            return ['success' => false, 'error' => 'Failed to connect to WooCommerce: ' . ($result['error'] ?? 'Unknown error')];
        }

        return ['success' => true, 'message' => 'WooCommerce connection successful'];
    }

    public function sync_products(): array
    {
        $result = $this->get_all_products();
        if (!$result['ok']) return $result;

        $parsed = array_map([$this, 'parse_product'], $result['data']);
        $this->cache['products'] = $parsed;
        
        return ['ok' => true, 'count' => count($parsed), 'data' => $parsed];
    }

    public function sync_categories(): array
    {
        $result = $this->get_all_categories();
        if (!$result['ok']) return $result;

        $this->cache['categories'] = $result['data'];
        
        return ['ok' => true, 'count' => count($result['data']), 'data' => $result['data']];
    }

    public function get_cached_products(): array
    {
        return $this->cache['products'] ?? [];
    }

    public function get_cached_categories(): array
    {
        return $this->cache['categories'] ?? [];
    }
}

function wc_connect(string $url, string $key, string $secret): WooCommerce
{
    return get_plugin('WooCommerce Atomic Integration')->connect($url, $key, $secret);
}

function wc_get_products(int $page = 1, int $per_page = 100): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_products($page, $per_page);
}

function wc_get_product(int $id): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_product($id);
}

function wc_get_categories(int $page = 1, int $per_page = 100): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_categories($page, $per_page);
}

function wc_get_all_products(): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_all_products();
}

function wc_get_all_categories(): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_all_categories();
}

function wc_parse_product(array $raw): array
{
    return get_plugin('WooCommerce Atomic Integration')->parse_product($raw);
}

function wc_create_order(array $order_data): array
{
    return get_plugin('WooCommerce Atomic Integration')->create_order($order_data);
}

function wc_update_order(int $order_id, array $data): array
{
    return get_plugin('WooCommerce Atomic Integration')->update_order($order_id, $data);
}

function wc_get_order(int $order_id): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_order($order_id);
}

function wc_update_order_status(int $order_id, string $status): array
{
    return get_plugin('WooCommerce Atomic Integration')->update_order_status($order_id, $status);
}

function wc_apply_coupon(int $order_id, string $coupon_code): array
{
    return get_plugin('WooCommerce Atomic Integration')->apply_coupon($order_id, $coupon_code);
}

function wc_get_customer(int $customer_id): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_customer($customer_id);
}

function wc_create_customer(array $customer_data): array
{
    return get_plugin('WooCommerce Atomic Integration')->create_customer($customer_data);
}

function wc_update_customer(int $customer_id, array $data): array
{
    return get_plugin('WooCommerce Atomic Integration')->update_customer($customer_id, $data);
}

function wc_get_customer_by_email(string $email): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_customer_by_email($email);
}

function wc_register_customer(string $email, string $username, ?string $first_name = null, ?string $last_name = null, ?string $phone = null): array
{
    return get_plugin('WooCommerce Atomic Integration')->register_customer($email, $username, $first_name, $last_name, $phone);
}

function wc_register_customer_from_telegram(int $telegram_id, ?string $username = null, ?string $first_name = null, ?string $last_name = null): array
{
    return get_plugin('WooCommerce Atomic Integration')->register_customer_from_telegram($telegram_id, $username, $first_name, $last_name);
}

function wc_update_customer_address(int $customer_id, array $billing = [], array $shipping = []): array
{
    return get_plugin('WooCommerce Atomic Integration')->update_customer_address($customer_id, $billing, $shipping);
}

function wc_sync_products(): array
{
    return get_plugin('WooCommerce Atomic Integration')->sync_products();
}

function wc_sync_categories(): array
{
    return get_plugin('WooCommerce Atomic Integration')->sync_categories();
}

function wc_cached_products(): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_cached_products();
}

function wc_cached_categories(): array
{
    return get_plugin('WooCommerce Atomic Integration')->get_cached_categories();
}

function wc_test_connection(string $url, string $key, string $secret): array
{
    return get_plugin('WooCommerce Atomic Integration')->test_connection($url, $key, $secret);
}