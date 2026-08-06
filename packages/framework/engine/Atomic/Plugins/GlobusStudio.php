<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\Core\Request as HTTP;
use Engine\Atomic\Core\Log;

class GlobusStudio extends Plugin
{
    protected function get_name(): string
    {
        return 'GlobusStudio';
    }

    public function register(): void
    {
        $this->atomic->set('PLUGIN.GlobusStudio.registered', true);
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.GlobusStudio.booted', true);
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.GlobusStudio.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.GlobusStudio.active', false);
    }

    public function get_currencies(string $base = 'USD', string $format = 'json'): array
    {
        $base = strtoupper($base);
        $url = 'https://api.globus.studio/v2/currencies?base=' . urlencode($base) . '&format=' . urlencode($format);
        $result = HTTP::instance()->remote_get($url, ['timeout' => 10]);

        if (!$result['ok']) {
            Log::error('GlobusStudio API error: ' . ($result['error'] ?? 'Unknown error'));
            return ['ok' => false, 'error' => $result['error'] ?? 'Failed to fetch currencies'];
        }

        $decoded = json_decode((string)$result['body'], true) ?? [];
        $mapped = array_map(function($r) {
            return [
                'currency_code_a' => strtoupper((string)($r['currencyCodeA'] ?? '')),
                'currency_code_b' => strtoupper((string)($r['currencyCodeB'] ?? '')),
                'rate_buy'        => (float)($r['rateBuy'] ?? 0),
                'rate_sell'       => (float)($r['rateSell'] ?? 0),
                'rate_cross'      => (float)($r['rateCross'] ?? 0),
            ];
        }, $decoded);

        return ['ok' => true, 'data' => $mapped];
    }

    public function find_rate(string $from, string $to, array $rates): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) return 1.0;
        foreach ($rates as $r) {
            $a = strtoupper((string)($r['currency_code_a'] ?? ''));
            $b = strtoupper((string)($r['currency_code_b'] ?? ''));
            if ($a === $from && $b === $to) {
                $buy = (float)($r['rate_buy'] ?? 0);
                $sell = (float)($r['rate_sell'] ?? 0);
                $cross = (float)($r['rate_cross'] ?? 0);
                if ($buy > 0 && $sell > 0) return ($buy + $sell) / 2.0;
                if ($buy > 0) return $buy;
                if ($sell > 0) return $sell;
                if ($cross > 0) return $cross;
            }
        }

        foreach ($rates as $r) {
            $a = strtoupper((string)($r['currency_code_a'] ?? ''));
            $b = strtoupper((string)($r['currency_code_b'] ?? ''));
            if ($a === $to && $b === $from) {
                $buy = (float)($r['rate_buy'] ?? 0);
                $sell = (float)($r['rate_sell'] ?? 0);
                $cross = (float)($r['rate_cross'] ?? 0);
                $rate = null;
                if ($buy > 0 && $sell > 0) $rate = ($buy + $sell) / 2.0;
                elseif ($buy > 0) $rate = $buy;
                elseif ($sell > 0) $rate = $sell;
                if ($rate === null && $cross > 0) $rate = $cross;
                if ($rate !== null && $rate > 0) return 1.0 / $rate;
            }
        }
        return null;
    }

    public function convert_amount(float $amount, string $from, string $to = 'USD', ?array $rates = null): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) return ['ok' => true, 'converted' => $amount, 'rate' => 1.0];

        if ($rates === null) {
            $got = $this->get_currencies($to);
            if (!$got['ok']) return ['ok' => false, 'error' => $got['error'] ?? 'Failed to get rates'];
            $rates = $got['data'];
        }

        $rate = $this->find_rate($from, $to, $rates);
        if ($rate === null && $from !== 'UAH' && $to !== 'UAH') {
            $r1 = $this->find_rate($from, 'UAH', $rates);
            $r2 = $this->find_rate('UAH', $to, $rates);
            if ($r1 !== null && $r2 !== null) {
                $rate = $r1 * $r2;
            }
        }
        if ($rate === null) return ['ok' => false, 'error' => 'Rate not found'];

        return ['ok' => true, 'converted' => $amount * $rate, 'rate' => $rate];
    }

    public function get_qr(string $data, string $type = 'png', int $size = 5): array
    {
        $type = strtolower($type ?: 'png');
        $size = max(1, min(10, (int)$size));
        $url = 'https://api.globus.studio/v2/qr?data=' . urlencode($data) . '&type=' . urlencode($type) . '&size=' . (int)$size;
        $result = HTTP::instance()->remote_get($url, ['timeout' => 10, 'binary' => true]);
        if (!$result['ok']) {
            Log::error('GlobusStudio QR API error: ' . ($result['error'] ?? 'Unknown error'));
            return ['ok' => false, 'error' => $result['error'] ?? 'Failed to fetch QR'];
        }

        $body = $result['body'] ?? '';
        if ($body === '') {
            return ['ok' => false, 'error' => 'Empty QR response'];
        }

        $base64 = base64_encode($body);
        $data_uri = 'data:image/' . $type . ';base64,' . $base64;
        return ['ok' => true, 'raw' => $body, 'type' => $type, 'data' => $data_uri];
    }
}

function gs_get_currencies(string $base = 'USD', string $format = 'json'): array
{
    return get_plugin('GlobusStudio')->get_currencies($base, $format);
}

function gs_find_rate(string $from, string $to, array $rates): ?float
{
    return get_plugin('GlobusStudio')->find_rate($from, $to, $rates);
}

function gs_convert_amount(float $amount, string $from, string $to = 'USD', ?array $rates = null): array
{
    return get_plugin('GlobusStudio')->convert_amount($amount, $from, $to, $rates);
}

function gs_get_qr(string $data, string $type = 'png', int $size = 5): array
{
    return get_plugin('GlobusStudio')->get_qr($data, $type, $size);
}
