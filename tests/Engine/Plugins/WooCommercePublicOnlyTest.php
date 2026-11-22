<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\Request;
use Engine\Atomic\Plugins\WooCommerce;
use PHPUnit\Framework\TestCase;

use function Engine\Atomic\Plugins\wc_test_connection;

class WooCommercePublicOnlyTest extends TestCase
{
    private const NOT_ALLOWED = 'URL not allowed: only public http/https URLs are permitted';

    /** @var list<array{string, array<int, mixed>}> */
    private array $calls = [];
    private bool $registered = false;

    protected function setUp(): void
    {
        $this->calls = [];
        Request::reset();
        // Shared client: shop.example.test is public, everything else is refused; no real network.
        Request::instance(
            static fn(string $url): array => parse_url($url, PHP_URL_HOST) === 'shop.example.test' ? ['93.184.216.34'] : [],
            function (string $url, array $options): array {
                $this->calls[] = [$url, $options];
                return ['headers' => ['HTTP/1.1 200 OK', 'Content-Type: application/json'], 'body' => '[{"id":7}]', 'error' => ''];
            }
        );
    }

    protected function tearDown(): void
    {
        Request::reset();
        if ($this->registered) {
            $plugins = new \ReflectionProperty(PluginManager::class, 'plugins');
            $list = $plugins->getValue(PluginManager::instance());
            unset($list['WooCommerce Atomic Integration']);
            $plugins->setValue(PluginManager::instance(), $list);
        }
    }

    public function test_shop_requests_are_pinned_to_the_validated_address(): void
    {
        $result = (new WooCommerce())->connect('https://shop.example.test/', 'ck', 'cs')->get_products(2, 10);

        self::assertSame(['ok' => true, 'data' => [['id' => 7]]], $result);
        [[$url, $options]] = $this->calls;
        self::assertSame('https://shop.example.test/wp-json/wc/v3/products?per_page=10&page=2', $url);
        self::assertSame(['::93.184.216.34:'], $options[CURLOPT_CONNECT_TO]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function test_private_shop_url_is_refused_with_a_clear_error(): void
    {
        $shop = (new WooCommerce())->connect('http://10.0.0.5', 'ck', 'cs');

        self::assertSame(['ok' => false, 'error' => self::NOT_ALLOWED], $shop->create_order(['x' => 1]));
        self::assertSame(['ok' => false, 'error' => self::NOT_ALLOWED], $shop->update_order(1, ['x' => 1]));
        self::assertSame([], $this->calls);
    }

    public function test_wc_test_connection_uses_public_only(): void
    {
        $manager = PluginManager::instance();
        if (!$manager->has('WooCommerce Atomic Integration')) {
            $manager->register(new WooCommerce());
            $this->registered = true;
        }

        self::assertSame(
            ['success' => false, 'error' => 'Failed to connect to WooCommerce: ' . self::NOT_ALLOWED],
            wc_test_connection('http://localhost:8080', 'ck', 'cs')
        );
        self::assertSame([], $this->calls);

        self::assertSame(
            ['success' => true, 'message' => 'WooCommerce connection successful'],
            wc_test_connection('https://shop.example.test', 'ck', 'cs')
        );
        self::assertSame('https://shop.example.test/wp-json/wc/v3/settings/general', $this->calls[0][0]);
    }
}
