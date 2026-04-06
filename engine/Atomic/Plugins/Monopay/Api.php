<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Request;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Plugins\Monopay;

class Api
{
    private const API_URL = 'https://api.monobank.ua';
    
    private Request $http;
    private string $token;
    private bool $test_mode;
    private ?string $cms_name;
    private ?string $cms_version;
    
    public function __construct(
        string $token,
        bool $test_mode = false,
        ?string $cms_name = null,
        ?string $cms_version = null
    ) {
        $this->http = Request::instance();
        $this->token = $token;
        $this->test_mode = $test_mode;
        $this->cms_name = $cms_name;
        $this->cms_version = $cms_version;
    }
    
    public function create_invoice(array $data): array
    {
        return $this->request('POST', '/api/merchant/invoice/create', $data);
    }

    public function get_invoice_status(string $invoice_id): array
    {
        return $this->request('GET', '/api/merchant/invoice/status?invoiceId=' . urlencode($invoice_id));
    }
    
    public function cancel_invoice(string $invoice_id, array $options = []): array
    {
        $data = array_merge([
            'invoiceId' => $invoice_id,
        ], $options);
        
        return $this->request('POST', '/api/merchant/invoice/cancel', $data);
    }
    
    public function remove_invoice(string $invoice_id): array
    {
        return $this->request('POST', '/api/merchant/invoice/remove', [
            'invoiceId' => $invoice_id
        ]);
    }
    
    public function finalize_hold(string $invoice_id, ?int $amount = null, array $items = []): array
    {
        $data = ['invoiceId' => $invoice_id];
        
        if ($amount !== null) {
            $data['amount'] = $amount;
        }
        
        if (!empty($items)) {
            $data['items'] = $items;
        }
        
        return $this->request('POST', '/api/merchant/invoice/finalize', $data);
    }
    
    public function get_public_key(): array
    {
        return $this->request('GET', '/api/merchant/pubkey');
    }
    
    public function verify_signature(string $public_key, string $x_sign, string $body): bool
    {
        try {
            $pem = base64_decode($public_key, strict: true);

            if ($pem === false) {
                Log::warning('Monopay: Failed to base64-decode public key');
                return false;
            }

            $key = openssl_pkey_get_public($pem);
            
            if ($key === false) {
                Log::warning('Monopay: Invalid public key format');
                return false;
            }
            
            $signature = base64_decode($x_sign);
            
            if ($signature === false) {
                Log::warning('Monopay: Failed to decode signature');
                return false;
            }
            
            $result = openssl_verify($body, $signature, $key, OPENSSL_ALGO_SHA256);
            
            return $result === 1;
            
        } catch (\Throwable $e) {
            Log::error('Monopay: Signature verification error - ' . $e->getMessage());
            return false;
        }
    }
    
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = rtrim(self::API_URL, '/') . '/' . ltrim((string)$endpoint, '/');
        
        $headers = [
            'X-Token' => $this->token,
            'Accept' => 'application/json',
        ];
        
        if ($this->cms_name) {
            $headers['X-Cms'] = $this->cms_name;
        }
        
        if ($this->cms_version) {
            $headers['X-Cms-Version'] = $this->cms_version;
        }
        
        $args = [
            'headers' => $headers,
            'timeout' => 30,
            'follow' => true,
        ];
        
        try {
            $response = match(strtoupper($method)) {
                'GET' => $this->http->remote_get($url, array_merge($args, $data)),
                'POST' => $this->http->remote_post($url, json_encode($data), array_merge($args, [
                    'headers' => array_merge($headers, ['Content-Type' => 'application/json']),
                    'raw' => true
                ])),
                'DELETE' => $this->http->remote_post($url, null, array_merge($args, [
                    'headers' => array_merge($headers, ['X-HTTP-Method-Override' => 'DELETE'])
                ])),
                default => ['ok' => false, 'error' => 'Unsupported HTTP method']
            };
            
            if (!$response['ok']) {
                Log::warning("Monopay API request failed: {$method} {$endpoint} " . json_encode([
                    'url' => $url,
                    'status' => $response['status'],
                    'error' => $response['error'],
                    'body' => substr((string)($response['body'] ?? ''), 0, 200)
                ]));
                
                return [
                    'ok' => false,
                    'error' => $response['error'] ?: 'HTTP ' . $response['status'],
                    'status' => $response['status'],
                    'data' => null,
                    'raw_body' => $response['body'] ?? null,
                    'url' => $url
                ];
            }
            
            $body = $response['body'];

            if ($body === '' || $body === null) {
                return [
                    'ok' => true,
                    'data' => null,
                    'error' => null
                ];
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Monopay: Failed to decode JSON response. Error: ' . json_last_error_msg() . '. Body: ' . substr($body, 0, 500));

                return [
                    'ok' => false,
                    'error' => 'Invalid JSON response',
                    'data' => null
                ];
            }
            
            if (isset($decoded['errCode']) || isset($decoded['errText'])) {
                return [
                    'ok' => false,
                    'error' => $decoded['errText'] ?? 'API Error',
                    'errorCode' => $decoded['errCode'] ?? null,
                    'data' => $decoded
                ];
            }
            
            return [
                'ok' => true,
                'data' => $decoded,
                'error' => null
            ];
            
        } catch (\Throwable $e) {
            Log::error('Monopay API exception: ' . $e->getMessage() . ' ' . json_encode([
                'method' => $method,
                'endpoint' => $endpoint
            ]));
            
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'data' => null
            ];
        }
    }
}
