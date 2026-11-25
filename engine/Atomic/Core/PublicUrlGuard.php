<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Closure;

final class PublicUrlGuard
{
    // IANA IPv4 special-purpose ranges, plus multicast.
    private const BLOCKED_IPV4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.31.196.0', 24],
        ['192.52.193.0', 24],
        ['192.88.99.0', 24],
        ['192.168.0.0', 16],
        ['192.175.48.0', 24],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    // Special-purpose ranges within the IPv6 global unicast allocation.
    private const BLOCKED_IPV6 = [
        ['2001::', 23],
        ['2001:db8::', 32],
        ['2002::', 16],
        ['2620:4f:8000::', 48],
        ['3fff::', 20],
    ];

    private readonly Closure $resolver;

    /** @var array<string, true> */
    private readonly array $private_hosts;

    /**
     * @param (callable(string): list<string>)|null $resolver Resolves all A and AAAA addresses.
     * @param iterable<mixed> $private_hosts Exact hostnames that may resolve to private addresses.
     */
    public function __construct(?callable $resolver = null, iterable $private_hosts = [])
    {
        $this->resolver = $resolver === null ? self::lookup(...) : Closure::fromCallable($resolver);
        $this->private_hosts = array_fill_keys(self::host_list($private_hosts), true);
    }

    /**
     * Guard for the running app: HTTP_ALLOWED_PRIVATE_HOSTS is honoured only while DEBUG_MODE is on.
     *
     * @param (callable(string): list<string>)|null $resolver
     */
    public static function from_config(?\Base $atomic = null, ?callable $resolver = null): self
    {
        $atomic ??= \Base::instance();
        $debug = filter_var($atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN);
        $hosts = $atomic->get('HTTP_ALLOWED_PRIVATE_HOSTS');

        return new self($resolver, $debug && is_iterable($hosts) ? $hosts : []);
    }

    /**
     * @param iterable<mixed> $hosts
     * @return list<string> Lowercased, without trailing dots, empty entries dropped.
     */
    public static function host_list(iterable $hosts): array
    {
        $list = [];
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                continue;
            }
            $host = rtrim(strtolower(trim($host)), '.');
            if ($host !== '') {
                $list[] = $host;
            }
        }
        return array_values(array_unique($list));
    }

    public function is_allowed(string $url): bool
    {
        return $this->resolve($url) !== [];
    }

    /**
     * Returns every validated address, or an empty list if the URL or any answer is unsafe.
     * Pin the connection to a returned address and validate each redirect separately.
     *
     * @return list<string>
     */
    public function resolve(string $url): array
    {
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return [];
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 1) === 0) {
            return [];
        }

        $host = strtolower($parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $address = substr($host, 1, -1);
            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && self::is_public_address($address) ? [$address] : [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::is_public_address($host) ? [$host] : [];
        }

        $hostname = rtrim($host, '.');
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || $hostname === 'localhost' || str_ends_with($hostname, '.localhost')
            || preg_match('/\A(?:[0-9]+|0x[0-9a-f]*)(?:\.(?:[0-9]+|0x[0-9a-f]*))*\z/i', $hostname)) {
            return [];
        }

        $addresses = ($this->resolver)($host);
        $private_ok = isset($this->private_hosts[$hostname]);
        foreach ($addresses as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false
                || (!$private_ok && !self::is_public_address($address))) {
                return [];
            }
        }

        return array_values(array_unique($addresses));
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        return array_merge(array_column($records, 'ip'), array_column($records, 'ipv6'));
    }

    private static function is_public_address(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($address);

        // Apply IPv4 policy to the embedded address in an IPv4-mapped IPv6 address.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }

        $is_ipv4 = strlen($packed) === 4;
        if (!$is_ipv4 && !self::matches_prefix($packed, '2000::', 3)) {
            return false;
        }

        foreach ($is_ipv4 ? self::BLOCKED_IPV4 : self::BLOCKED_IPV6 as [$network, $prefix]) {
            if (self::matches_prefix($packed, $network, $prefix)) {
                return false;
            }
        }
        return true;
    }

    private static function matches_prefix(string $address, string $network, int $prefix): bool
    {
        $network = inet_pton($network);
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        return substr($address, 0, $bytes) === substr($network, 0, $bytes)
            && ($bits === 0 || (ord($address[$bytes]) >> (8 - $bits)) === (ord($network[$bytes]) >> (8 - $bits)));
    }
}
