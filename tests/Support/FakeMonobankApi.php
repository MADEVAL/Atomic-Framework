<?php
declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\Plugins\Monopay\Api;

/**
 * In-memory fake of the monobank acquiring API. Overrides the network
 * methods of Engine\Atomic\Plugins\Monopay\Api while keeping the real
 * verify_signature() implementation, so webhook tests exercise genuine
 * ECDSA verification.
 */
final class FakeMonobankApi extends Api
{
    /** create_invoice script token: monobank creates the invoice, then the response is lost. */
    public const TIMEOUT_AFTER_CREATE = 'timeout_after_create';

    /*
     * Pre-generated P-256 (prime256v1) test keypairs, generated once with
     * the openssl CLI and embedded verbatim — PHP's openssl_pkey_new() is
     * unreliable on Windows hosts without an openssl.cnf, while key
     * parsing/signing works everywhere. These are throwaway test keys.
     */
    private const KEY_A_PRIVATE = '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIAeMLp00IQHHBNMwWgHMabo/40p+zsr1ZpWlw2QErVEGoAoGCCqGSM49
AwEHoUQDQgAEVukG+mGr6YURgIp1x94iXtbBL28V9+CdRSOl9cC/EhqoGMC4c5Yg
ywz1dW7k2zTBX4CfdOiqI/bsd0f14adRrA==
-----END EC PRIVATE KEY-----';

    private const KEY_A_PUBLIC = '-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEVukG+mGr6YURgIp1x94iXtbBL28V
9+CdRSOl9cC/EhqoGMC4c5Ygywz1dW7k2zTBX4CfdOiqI/bsd0f14adRrA==
-----END PUBLIC KEY-----';

    private const KEY_B_PRIVATE = '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIMd23zkCbUoZZHXjk1Es4N236EliberzJu/OB3ABqBJioAoGCCqGSM49
AwEHoUQDQgAEXICHdTLSMwJ/WbLu8bsGjhJeyWXujaFrogi55IrxOgS7htt/Ts9u
6qOCgZg180L9+MJfunnfzdtqVVnY/VGokA==
-----END EC PRIVATE KEY-----';

    private const KEY_B_PUBLIC = '-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEXICHdTLSMwJ/WbLu8bsGjhJeyWXu
jaFrogi55IrxOgS7htt/Ts9u6qOCgZg180L9+MJfunnfzdtqVVnY/VGokA==
-----END PUBLIC KEY-----';

    /** @var array<string, array<string, mixed>> invoiceId => record */
    private array $invoices = [];

    /** @var list<string> queued outcomes for create_invoice; empty => success */
    public array $create_script = [];

    public bool $status_endpoint_failing = false;

    /** @var list<string> invoice ids whose status lookup must fail */
    public array $failing_invoice_ids = [];

    public int $pubkey_fetches = 0;

    /** Total invoice/status API calls (including failures). */
    public int $status_requests = 0;

    /** @var array<string, int> invoiceId => number of status requests */
    public array $status_requests_by_invoice = [];

    /** @var array<string, mixed>|null last create_invoice() body */
    private ?array $last_create_payload = null;

    private int $invoice_counter = 0;
    private bool $current_is_key_a = true;

    /** @var array<string, \OpenSSLAsymmetricKey> PEM => parsed key */
    private static array $parsed_keys = [];

    public function __construct(string $token)
    {
        parent::__construct($token);
    }

    public function create_invoice(array $data): array
    {
        $this->last_create_payload = $data;
        $scripted = array_shift($this->create_script);

        if ($scripted === self::TIMEOUT_AFTER_CREATE) {
            // Monobank accepted the request and created the invoice, but
            // the response never reaches the client (network timeout).
            $invoice_id = $this->store_invoice($data);

            return $this->transport_failure("invoice {$invoice_id} created, response lost");
        }

        if (is_array($scripted) && ($scripted[0] ?? null) === 'err') {
            return $this->api_error((string)$scripted[1], (string)$scripted[2]);
        }

        $invoice_id = $this->store_invoice($data);

        return [
            'ok' => true,
            'data' => ['invoiceId' => $invoice_id, 'pageUrl' => 'https://pay.mbnk.biz/' . $invoice_id],
            'error' => null,
        ];
    }

    public function get_invoice_status(string $invoice_id): array
    {
        $this->status_requests++;
        $this->status_requests_by_invoice[$invoice_id] = ($this->status_requests_by_invoice[$invoice_id] ?? 0) + 1;

        if ($this->status_endpoint_failing || in_array($invoice_id, $this->failing_invoice_ids, true)) {
            return $this->api_error('TMR', 'too many requests');
        }

        if (!isset($this->invoices[$invoice_id])) {
            return $this->api_error('NOT_FOUND', "invalid 'invoiceId'");
        }

        $record = $this->invoices[$invoice_id];

        return [
            'ok' => true,
            'data' => [
                'invoiceId' => $invoice_id,
                'status' => $record['status'],
                'amount' => $record['amount'],
                'ccy' => $record['ccy'],
                'finalAmount' => $record['amount'],
                'createdDate' => '2026-09-06T10:00:00Z',
                'modifiedDate' => '2026-09-06T10:01:00Z',
                'reference' => $record['reference'],
                'destination' => 'Stress order',
                'cancelList' => [],
            ],
            'error' => null,
        ];
    }

    public function get_public_key(): array
    {
        $this->pubkey_fetches++;

        return ['ok' => true, 'data' => ['key' => base64_encode($this->current_public_pem())], 'error' => null];
    }

    /** Swaps the signing key, exactly like a monobank key rotation. */
    public function rotate_key(): void
    {
        $this->current_is_key_a = !$this->current_is_key_a;
    }

    public function current_key(): \OpenSSLAsymmetricKey
    {
        return $this->parse_key($this->current_is_key_a ? self::KEY_A_PRIVATE : self::KEY_B_PRIVATE);
    }

    public function stale_key(): \OpenSSLAsymmetricKey
    {
        return $this->parse_key($this->current_is_key_a ? self::KEY_B_PRIVATE : self::KEY_A_PRIVATE);
    }

    private function current_public_pem(): string
    {
        return $this->current_is_key_a ? self::KEY_A_PUBLIC : self::KEY_B_PUBLIC;
    }

    private function parse_key(string $pem): \OpenSSLAsymmetricKey
    {
        $key = self::$parsed_keys[$pem] ??= openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new \RuntimeException('Failed to parse embedded EC test key');
        }

        return $key;
    }

    public function invoice_count(): int
    {
        return count($this->invoices);
    }

    /** @return list<string> invoiceIds in creation order */
    public function invoice_ids(): array
    {
        return array_keys($this->invoices);
    }

    public function reference_of(string $invoice_id): string
    {
        return (string)($this->invoices[$invoice_id]['reference'] ?? '');
    }

    public function invoice_id_of(string $reference): string
    {
        foreach ($this->invoices as $invoice_id => $record) {
            if ($record['reference'] === $reference) {
                return $invoice_id;
            }
        }

        return '';
    }

    public function set_invoice_status(string $reference, string $status): void
    {
        $invoice_id = $this->invoice_id_of($reference);

        if ($invoice_id !== '') {
            $this->invoices[$invoice_id]['status'] = $status;
        }
    }

    public function reset_requests(): void
    {
        $this->create_script = [];
        $this->status_requests = 0;
        $this->status_requests_by_invoice = [];
    }

    /** @return array<string, mixed> the body of the last create_invoice() call */
    public function last_create_payload(): array
    {
        if ($this->last_create_payload === null) {
            throw new \LogicException('create_invoice() was never called on the fake');
        }

        return $this->last_create_payload;
    }

    /** @return string UUIDv4 without dashes (matches merchantPaymInfo.reference style) */
    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s%s%s%s', str_split(bin2hex($data), 4));
    }

    private function store_invoice(array $data): string
    {
        $this->invoice_counter++;
        $invoice_id = 'p2_STRESS' . $this->invoice_counter;
        $this->invoices[$invoice_id] = [
            'invoiceId' => $invoice_id,
            'status' => 'created',
            'amount' => $data['amount'],
            'ccy' => $data['ccy'] ?? 980,
            'reference' => (string)($data['merchantPaymInfo']['reference'] ?? ''),
        ];

        return $invoice_id;
    }

    /** @return array<string, mixed> shape of Api::request() on a transport failure */
    private function transport_failure(string $detail): array
    {
        return [
            'ok' => false,
            'error' => 'cURL error 28: Connection timed out after 30 seconds (' . $detail . ')',
            'status' => 0,
            'data' => null,
            'raw_body' => null,
            'url' => 'https://api.monobank.ua/api/merchant/invoice/create',
        ];
    }

    /** @return array<string, mixed> shape of Api::request() for an errCode body */
    private function api_error(string $code, string $text): array
    {
        return [
            'ok' => false,
            'error' => $text,
            'errorCode' => $code,
            'status' => $code === 'TMR' ? 429 : 400,
            'data' => ['errCode' => $code, 'errText' => $text],
            'raw_body' => json_encode(['errCode' => $code, 'errText' => $text]),
        ];
    }
}
