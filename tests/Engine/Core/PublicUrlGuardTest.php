<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\PublicUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicUrlGuardTest extends TestCase
{
    #[DataProvider('unsafe_urls')]
    public function test_rejects_unsafe_urls_without_dns(string $url): void
    {
        $guard = new PublicUrlGuard(function (string $host): array {
            self::fail("Unexpected DNS lookup for {$host}");
        });

        self::assertSame([], $guard->resolve($url));
        self::assertFalse($guard->is_allowed($url));
    }

    public static function unsafe_urls(): array
    {
        return [
            'unsupported scheme' => ['ftp://example.test/image.jpg'],
            'missing host' => ['https:///image.jpg'],
            'userinfo' => ['https://user:pass@example.test/image.jpg'],
            'empty userinfo' => ['https://@example.test/image.jpg'],
            'encoded hostname' => ['https://ex%61mple.test/image.jpg'],
            'localhost domain' => ['https://example.localhost./image.jpg'],
            'private IPv4' => ['http://192.168.1.1/image.jpg'],
            'IPv4 loopback' => ['http://127.0.0.2/image.jpg'],
            'metadata' => ['http://169.254.169.254/image.jpg'],
            'CGNAT' => ['http://100.127.255.255/image.jpg'],
            'this network' => ['http://0.1.2.3/image.jpg'],
            'IPv4 unspecified' => ['http://0.0.0.0/image.jpg'],
            'IPv4 multicast' => ['http://224.0.0.1/image.jpg'],
            'IPv4 reserved' => ['http://240.0.0.1/image.jpg'],
            'IPv4 documentation' => ['http://198.51.100.1/image.jpg'],
            'IPv6 unspecified' => ['http://[::]/image.jpg'],
            'IPv6 loopback' => ['http://[::1]/image.jpg'],
            'IPv6 ULA' => ['http://[fd00::1]/image.jpg'],
            'IPv6 link-local' => ['http://[fe80::1]/image.jpg'],
            'IPv6 multicast' => ['http://[ff02::1]/image.jpg'],
            'IPv6 documentation' => ['http://[2001:db8::1]/image.jpg'],
            'mapped IPv4 loopback' => ['http://[::ffff:127.0.0.1]/image.jpg'],
            'mapped IPv4 CGNAT' => ['http://[::ffff:6440:1]/image.jpg'],
            'decimal IPv4' => ['http://134744072/image.jpg'],
            'octal IPv4' => ['http://010.010.010.010/image.jpg'],
            'hex IPv4' => ['http://0x08080808/image.jpg'],
            'short IPv4' => ['http://8.8.2056/image.jpg'],
        ];
    }

    public function test_returns_public_a_and_aaaa_addresses(): void
    {
        $guard = new PublicUrlGuard(static function (string $host): array {
            self::assertSame('example.test', $host);
            return ['8.8.8.8', '2606:4700::1111', '8.8.8.8'];
        });

        self::assertSame(['8.8.8.8', '2606:4700::1111'], $guard->resolve('https://EXAMPLE.test:8443/image.jpg'));
    }

    public function test_allows_public_aaaa_only_host_and_public_literals(): void
    {
        $guard = new PublicUrlGuard(static fn(string $host): array => ['2606:4700::1111']);

        self::assertTrue($guard->is_allowed('http://example.test/image.jpg'));
        self::assertSame(['8.8.8.8'], $guard->resolve('http://8.8.8.8/'));
        self::assertSame(['2606:4700::1111'], $guard->resolve('https://[2606:4700::1111]/'));
        self::assertSame(['::ffff:8.8.8.8'], $guard->resolve('https://[::ffff:8.8.8.8]/'));
    }

    public function test_rejects_any_unsafe_dns_answer(): void
    {
        foreach ([['8.8.8.8', 'fd00::1'], ['2606:4700::1111', '10.0.0.1'], ['8.8.8.8', 'invalid']] as $addresses) {
            $guard = new PublicUrlGuard(static fn(string $host): array => $addresses);

            self::assertSame([], $guard->resolve('https://example.test/image.jpg'));
        }
    }

    public function test_rejects_unresolvable_hostname(): void
    {
        $guard = new PublicUrlGuard(static fn(string $host): array => []);

        self::assertFalse($guard->is_allowed('https://unknown.test/image.jpg'));
    }
}
