<?php
declare(strict_types=1);
namespace Engine\Atomic\Plugins\Monopay;

use Engine\Atomic\App\Plugin;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Migrations;
use Engine\Atomic\Enums\Currency;
use Engine\Atomic\Plugins\Monopay\Api;
use Engine\Atomic\Plugins\Monopay\Order;

if (!defined('ATOMIC_START')) exit;

class Monopay extends Plugin
{
    public string $version = '1.0.0';

    public const CURRENCY_UAH = 980;
    public const CURRENCY_USD = 840;
    public const CURRENCY_EUR = 978;
    public const CURRENCY_DEFAULT = self::CURRENCY_UAH;

    private ?Api $api = null;
    private ?Order $order = null;
    private ?string $public_key = null;

    protected function get_name(): string
    {
        return 'Monopay';
    }

    public function get_migrations_path(): ?string
    {
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'Monopay' . DIRECTORY_SEPARATOR . 'Migrations';
        return is_dir($path) ? $path : null;
    }

    public function publish_migrations(): void
    {
        (new Migrations())->publish_from_plugin($this->get_name());
    }

    public function register(): void
    {
        $this->atomic->set('PLUGIN.Monopay.registered', true);
        $this->atomic->set('PLUGIN.Monopay.version', $this->version);
        
        $this->register_helpers();
    }

    public function boot(): void
    {
        $this->atomic->set('PLUGIN.Monopay.booted', true);
        
        $token = $this->atomic->get('MONOPAY.TOKEN');
        
        if ($token) {
            $cms_version = $this->atomic->get('MONOPAY.CMS_VERSION') ?: $this->version;

            $this->api = new Api($token, null, $cms_version);
            $this->order = new Order($this->api);
        } else {
            Log::warning('Monopay: No API token configured. Set MONOPAY.TOKEN in configuration.');
        }
    }

    public function activate(): void
    {
        $this->atomic->set('PLUGIN.Monopay.active', true);
    }

    public function deactivate(): void
    {
        $this->atomic->set('PLUGIN.Monopay.active', false);
    }
    
    public function get_api(): ?Api
    {
        return $this->api;
    }
    
    public function get_order(): ?Order
    {
        return $this->order;
    }
    
    public static function currency_symbol_from_code(int $code): string
    {
        return match ($code) {
            self::CURRENCY_UAH => Currency::UAH->symbol(),
            self::CURRENCY_USD => Currency::USD->symbol(),
            self::CURRENCY_EUR => Currency::EUR->symbol(),
            default => ''
        };
    }

    public function configure(string $token, array $options = []): void
    {
        $this->atomic->set('MONOPAY.TOKEN', $token);
        
        if (isset($options['cms_version'])) {
            $this->atomic->set('MONOPAY.CMS_VERSION', $options['cms_version']);
        }
        
        if (isset($options['webhook_url'])) {
            $this->atomic->set('MONOPAY.WEBHOOK_URL', $options['webhook_url']);
        }
        
        if (isset($options['redirect_url'])) {
            $this->atomic->set('MONOPAY.REDIRECT_URL', $options['redirect_url']);
        }
        
        $this->api = new Api(
            $token,
            null,
            $options['cms_version'] ?? $this->version
        );
        
        $this->order = new Order($this->api);
    }
    
    public function create_payment(
        float $amount,
        string $destination,
        array $options = []
    ): array {
        if (!$this->order) {
            return [
                'ok' => false,
                'error' => 'Monopay not configured. Call configure() first.'
            ];
        }
        
        if (!isset($options['webHookUrl'])) {
            $webhook_url = $this->atomic->get('MONOPAY.WEBHOOK_URL');
            if ($webhook_url) {
                $options['webHookUrl'] = $webhook_url;
            }
        }
        
        if (!isset($options['redirectUrl'])) {
            $redirect_url = $this->atomic->get('MONOPAY.REDIRECT_URL');
            if ($redirect_url) {
                $options['redirectUrl'] = $redirect_url;
            }
        }
        
        return $this->order->create($amount, $destination, $options);
    }
    
    public function handle_webhook(string $x_sign, string $raw_body): array
    {
        if (!$this->api) {
            return [
                'ok' => false,
                'error' => 'Monopay not configured'
            ];
        }
        
        try {
            if (!$this->public_key) {
                $result = $this->api->get_public_key();
                
                if (!$result['ok']) {
                    Log::error('Monopay: Failed to get public key ' . json_encode(['error' => $result['error']]));
                    return [
                        'ok' => false,
                        'error' => 'Failed to verify webhook signature'
                    ];
                }
                
                $this->public_key = $result['data']['key'];
            }
            
            $is_valid = $this->api->verify_signature($this->public_key, $x_sign, $raw_body);

            if (!$is_valid) {
                Log::info('Monopay: Signature verification failed, refreshing public key and retrying');
                $this->public_key = null;

                $result = $this->api->get_public_key();

                if (!$result['ok']) {
                    Log::error('Monopay: Failed to refresh public key ' . json_encode(['error' => $result['error']]));
                    return [
                        'ok' => false,
                        'error' => 'Failed to verify webhook signature'
                    ];
                }

                $this->public_key = $result['data']['key'];
                $is_valid = $this->api->verify_signature($this->public_key, $x_sign, $raw_body);
            }

            if (!$is_valid) {
                Log::warning('Monopay: Invalid webhook signature');
                return [
                    'ok' => false,
                    'error' => 'Invalid webhook signature'
                ];
            }
            
            $data = json_decode($raw_body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Monopay: Failed to parse webhook data ' . json_encode([
                    'error' => json_last_error_msg()
                ]));
                
                return [
                    'ok' => false,
                    'error' => 'Invalid webhook data'
                ];
            }
            
            $parsed = $this->order->parse_webhook($data);
            
            Log::info('Monopay: Webhook received ' . json_encode([
                'invoice_id' => $parsed['invoice_id'],
                'status' => $parsed['status'],
                'reference' => $parsed['reference']
            ]));
            
            return [
                'ok' => true,
                'data' => $parsed
            ];
            
        } catch (\Throwable $e) {
            Log::error('Monopay: Webhook handling error - ' . $e->getMessage());
            
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function register_helpers(): void
    {
        require_once __DIR__ . '/global_functions.php';
    }
}