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

    protected function getName(): string
    {
        return 'WooCommerce Atomic Integration';
    }

    public function getVersion(): string
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
            'POST' => HTTP::instance()->remote_post($url, $data, $args),
            'PUT' => HTTP::instance()->remote_put($url, $data, $args),
            default => HTTP::instance()->remote_get($url, $args),
        };

        if (!$result['ok']) {
            Log::error('WooCommerce API error: ' . $result['error']);
            return ['ok' => false, 'error' => $result['error']];
        }

        $decoded = json_decode($result['body'], true);
        return ['ok' => true, 'data' => $decoded ?? []];
    }

    public function getProducts(int $page = 1, int $perPage = 100): array
    {
        return $this->request("products?per_page={$perPage}&page={$page}");
    }

    public function getProduct(int $id): array
    {
        return $this->request("products/{$id}");
    }

    public function getCategories(int $page = 1, int $perPage = 100): array
    {
        return $this->request("products/categories?per_page={$perPage}&page={$page}");
    }

    public function getAllProducts(): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->getProducts($page, 100);
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

    public function parseProduct(array $raw): array
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

    public function createOrder(array $orderData): array
    {
        return $this->request('orders', 'POST', $orderData);
    }

    public function updateOrder(int $orderId, array $data): array
    {
        return $this->request("orders/{$orderId}", 'PUT', $data);
    }

    public function getOrder(int $orderId): array
    {
        return $this->request("orders/{$orderId}");
    }

    public function updateOrderStatus(int $orderId, string $status): array
    {
        return $this->updateOrder($orderId, ['status' => $status]);
    }

    public function applyCoupon(int $orderId, string $couponCode): array
    {
        $order = $this->getOrder($orderId);
        if (!$order['ok']) return $order;

        $coupons = $order['data']['coupon_lines'] ?? [];
        $coupons[] = ['code' => $couponCode];
        
        return $this->updateOrder($orderId, ['coupon_lines' => $coupons]);
    }

    public function getCustomer(int $customerId): array
    {
        return $this->request("customers/{$customerId}");
    }

    public function createCustomer(array $customerData): array
    {
        return $this->request('customers', 'POST', $customerData);
    }

    public function updateCustomer(int $customerId, array $data): array
    {
        return $this->request("customers/{$customerId}", 'PUT', $data);
    }

    public function getCustomerByEmail(string $email): array
    {
        $result = $this->request("customers?email=" . urlencode($email));
        if ($result['ok'] && !empty($result['data'][0])) {
            return ['ok' => true, 'data' => $result['data'][0]];
        }
        return ['ok' => false, 'error' => 'Customer not found'];
    }

    public function registerCustomer(string $email, string $username, ?string $firstName = null, ?string $lastName = null, ?string $phone = null): array
    {
        $data = [
            'email' => $email,
            'username' => $username,
            'first_name' => $firstName ?? '',
            'last_name' => $lastName ?? '',
        ];

        if ($phone) {
            $data['billing'] = ['phone' => $phone];
        }

        return $this->createCustomer($data);
    }

    public function registerCustomerFromTelegram(int $telegramId, ?string $username = null, ?string $firstName = null, ?string $lastName = null): array
    {
        $email = "tg_{$telegramId}@telegram.local";
        $wcUsername = $username ?: "tg_{$telegramId}";
        
        return $this->registerCustomer($email, $wcUsername, $firstName, $lastName);
    }

    public function updateCustomerAddress(int $customerId, array $billing = [], array $shipping = []): array
    {
        $data = [];
        if (!empty($billing)) $data['billing'] = $billing;
        if (!empty($shipping)) $data['shipping'] = $shipping;
        
        return $this->updateCustomer($customerId, $data);
    }

    public function testConnection(string $url, string $key, string $secret): array
    {
        $this->connect($url, $key, $secret);
        $result = $this->request('settings/general');

        if (!$result['ok']) {
            return ['success' => false, 'error' => 'Failed to connect to WooCommerce: ' . ($result['error'] ?? 'Unknown error')];
        }

        return ['success' => true, 'message' => 'WooCommerce connection successful'];
    }

    public function syncProducts(): array
    {
        $result = $this->getAllProducts();
        if (!$result['ok']) return $result;

        $parsed = array_map([$this, 'parseProduct'], $result['data']);
        $this->cache['products'] = $parsed;
        
        return ['ok' => true, 'count' => count($parsed), 'data' => $parsed];
    }

    public function syncCategories(): array
    {
        $result = $this->getAllCategories();
        if (!$result['ok']) return $result;

        $this->cache['categories'] = $result['data'];
        
        return ['ok' => true, 'count' => count($result['data']), 'data' => $result['data']];
    }

    public function getCachedProducts(): array
    {
        return $this->cache['products'] ?? [];
    }

    public function getCachedCategories(): array
    {
        return $this->cache['categories'] ?? [];
    }
}

function wc_connect(string $url, string $key, string $secret): WooCommerce
{
    return get_plugin('WooCommerce Atomic Integration')->connect($url, $key, $secret);
}

function wc_get_products(int $page = 1, int $perPage = 100): array
{
    return get_plugin('WooCommerce Atomic Integration')->getProducts($page, $perPage);
}

function wc_get_product(int $id): array
{
    return get_plugin('WooCommerce Atomic Integration')->getProduct($id);
}

function wc_get_categories(int $page = 1, int $perPage = 100): array
{
    return get_plugin('WooCommerce Atomic Integration')->getCategories($page, $perPage);
}

function wc_get_all_products(): array
{
    return get_plugin('WooCommerce Atomic Integration')->getAllProducts();
}

function wc_get_all_categories(): array
{
    return get_plugin('WooCommerce Atomic Integration')->getAllCategories();
}

function wc_parse_product(array $raw): array
{
    return get_plugin('WooCommerce Atomic Integration')->parseProduct($raw);
}

function wc_create_order(array $orderData): array
{
    return get_plugin('WooCommerce Atomic Integration')->createOrder($orderData);
}

function wc_update_order(int $orderId, array $data): array
{
    return get_plugin('WooCommerce Atomic Integration')->updateOrder($orderId, $data);
}

function wc_get_order(int $orderId): array
{
    return get_plugin('WooCommerce Atomic Integration')->getOrder($orderId);
}

function wc_update_order_status(int $orderId, string $status): array
{
    return get_plugin('WooCommerce Atomic Integration')->updateOrderStatus($orderId, $status);
}

function wc_apply_coupon(int $orderId, string $couponCode): array
{
    return get_plugin('WooCommerce Atomic Integration')->applyCoupon($orderId, $couponCode);
}

function wc_get_customer(int $customerId): array
{
    return get_plugin('WooCommerce Atomic Integration')->getCustomer($customerId);
}

function wc_create_customer(array $customerData): array
{
    return get_plugin('WooCommerce Atomic Integration')->createCustomer($customerData);
}

function wc_update_customer(int $customerId, array $data): array
{
    return get_plugin('WooCommerce Atomic Integration')->updateCustomer($customerId, $data);
}

function wc_get_customer_by_email(string $email): array
{
    return get_plugin('WooCommerce Atomic Integration')->getCustomerByEmail($email);
}

function wc_register_customer(string $email, string $username, ?string $firstName = null, ?string $lastName = null, ?string $phone = null): array
{
    return get_plugin('WooCommerce Atomic Integration')->registerCustomer($email, $username, $firstName, $lastName, $phone);
}

function wc_register_customer_from_telegram(int $telegramId, ?string $username = null, ?string $firstName = null, ?string $lastName = null): array
{
    return get_plugin('WooCommerce Atomic Integration')->registerCustomerFromTelegram($telegramId, $username, $firstName, $lastName);
}

function wc_update_customer_address(int $customerId, array $billing = [], array $shipping = []): array
{
    return get_plugin('WooCommerce Atomic Integration')->updateCustomerAddress($customerId, $billing, $shipping);
}

function wc_sync_products(): array
{
    return get_plugin('WooCommerce Atomic Integration')->syncProducts();
}

function wc_sync_categories(): array
{
    return get_plugin('WooCommerce Atomic Integration')->syncCategories();
}

function wc_cached_products(): array
{
    return get_plugin('WooCommerce Atomic Integration')->getCachedProducts();
}

function wc_cached_categories(): array
{
    return get_plugin('WooCommerce Atomic Integration')->getCachedCategories();
}

function wc_test_connection(string $url, string $key, string $secret): array
{
    return get_plugin('WooCommerce Atomic Integration')->testConnection($url, $key, $secret);
}