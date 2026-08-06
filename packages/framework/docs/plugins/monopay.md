# Monopay Plugin for Atomic Framework

Monobank Acquiring API integration plugin for processing payments in Ukrainian Hryvnia (UAH) and other currencies.

## Features

- **Payment Invoice Creation** - Generate payment links for customers
- **Order Status Tracking** - Monitor payment status in real-time
- **Webhook Support** - Automatic payment status notifications
- **Refunds & Cancellations** - Full and partial refund capabilities
- **Hold Payments** - Authorization hold with later finalization
- **Test Environment** - Full sandbox support for development

## Installation

The plugin is located at `engine/Atomic/Plugins/Monopay/` and includes:

- `Monopay.php` - Main plugin class
- `Api.php` - Monobank API client
- `Order.php` - Order management
- `WebhookHandler.php` - Webhook processing
- `global_functions.php` - global helper wrappers
- `Models/Payment.php` - payment record model
- `Models/PaymentHistory.php` - payment webhook history logging

## Configuration

### Basic Setup

```php
// In your bootstrap or configuration file
$monopay = get_plugin('Monopay');

if ($monopay) {
    $monopay->configure(
        token: 'your_api_token_here',
        options: [
            'cms_version' => '1.0.0',
            'webhook_url' => 'https://yourdomain.com/monopay/webhook',
            'redirect_url' => 'https://yourdomain.com/payment/result'
        ]
    );
}
```

### Getting API Token

1. **Production**: Get token from https://web.monobank.ua/
2. **Testing**: Get test token from https://api.monobank.ua/

### Configuration Variables

Alternatively, set configuration in your app config:

```php
$f3->set('MONOPAY.TOKEN', 'your_token');
$f3->set('MONOPAY.TEST_MODE', true);
$f3->set('MONOPAY.WEBHOOK_URL', 'https://yourdomain.com/monopay/webhook');
$f3->set('MONOPAY.REDIRECT_URL', 'https://yourdomain.com/payment/result');
```

`MONOPAY.TEST_MODE` is read by config loader but the current plugin implementation uses the same Monobank API host for all requests.

## Usage

### Creating a Payment

#### Simple Payment

```php
// Create payment for 100.00 UAH
$result = monopay_create_payment(
    amount: 100.00,
    destination: 'Payment for order #12345'
);

if ($result['ok']) {
    $invoiceId = $result['data']['invoiceId'];
    $paymentUrl = $result['data']['pageUrl'];
    
    // Redirect customer to payment page
    header('Location: ' . $paymentUrl);
    exit;
} else {
    echo "Error: " . $result['error'];
}
```

#### Advanced Payment with Options

```php
$result = monopay_create_payment(
    amount: 250.50,
    destination: 'Premium subscription',
    options: [
        'ccy' => 980, // UAH (default)
        'comment' => 'Annual subscription payment',
        'customerEmails' => ['customer@example.com'],
        'validity' => 3600, // Valid for 1 hour
        'paymentType' => 'debit', // or 'hold' for authorization
        'webHookUrl' => 'https://yourdomain.com/webhook/custom',
        'redirectUrl' => 'https://yourdomain.com/thank-you',
        
        // Basket items for detailed receipt
        'basketOrder' => [
            [
                'name' => 'Premium Plan',
                'qty' => 1,
                'sum' => 25050, // In minor units (kopiykas)
                'unit' => 'шт',
                'code' => 'PREMIUM-001'
            ]
        ],
        
        // Discounts
        'discounts' => [
            [
                'type' => 'DISCOUNT',
                'mode' => 'PERCENT',
                'value' => 1000 // 10.00%
            ]
        ],
        
        // Card tokenization for recurring payments
        'saveCardData' => [
            'saveCard' => true,
            'walletId' => 'customer_wallet_123'
        ],
        
        // Additional optional fields
        'qrId' => 'your_qr_id',
        'code' => 'ORDER-123',
        'tipsEmployeeId' => 'employee_321',
        'agentFeePercent' => 5.0
    ]
);
```

### Checking Payment Status

#### Using Helper Function

```php
$invoiceId = 'p2_9ZgpZVsl3';

// Check if paid
if (monopay_is_paid($invoiceId)) {
    echo "Payment successful!";
}

// Get detailed status
$status = monopay_get_status($invoiceId);

if ($status['ok']) {
    echo "Status: " . $status['status'];
    echo "Amount: " . $status['amount'] . " " . $status['currency'];
    echo "Reference: " . $status['reference'];
    
    if ($status['paymentInfo']) {
        echo "Card: " . $status['paymentInfo']['maskedPan'];
        echo "Bank: " . $status['paymentInfo']['bank'];
    }
}
```

#### Using Order Manager

```php
$monopay = monopay();
$order = $monopay->get_order();

// You can also use the global helper directly:
// $order = monopay_get_order();

// Check various statuses
if ($order->is_paid($invoiceId)) {
    // Payment successful
}

if ($order->is_pending($invoiceId)) {
    // Still processing
}

if ($order->is_failed($invoiceId)) {
    // Payment failed
}

if ($order->is_hold($invoiceId)) {
    // Payment on hold (authorized but not captured)
}
```

### Payment Status Values

- `created` - Invoice created, awaiting payment
- `processing` - Payment is being processed
- `hold` - Payment authorized (hold)
- `success` - Payment completed successfully
- `failure` - Payment failed
- `reversed` - Payment cancelled/refunded
- `expired` - Invoice expired (not paid within validity period)

### Refunds and Cancellations

#### Full Refund

```php
$result = monopay_cancel($invoiceId);

if ($result['ok']) {
    echo "Refund processed: " . $result['data']['status'];
}
```

#### Partial Refund

```php
// Refund 50.00 UAH from original payment
$result = monopay_cancel(
    invoiceId: $invoiceId,
    amount: 50.00
);
```

#### With External Reference

```php
$result = monopay_cancel(
    invoiceId: $invoiceId,
    amount: null, // Full refund
    options: [
        'extRef' => 'REFUND-12345'
    ]
);
```

### Hold Payments (Authorization)

#### Create Hold

```php
$result = monopay_create_payment(
    amount: 200.00,
    destination: 'Product reservation',
    options: [
        'paymentType' => 'hold'
    ]
);
```

#### Finalize Hold (Capture)

```php
$monopay = monopay();
$order = $monopay->get_order();

// Capture full amount
$result = $order->finalize_hold($invoiceId);

// Capture partial amount
$result = $order->finalize_hold($invoiceId, amount: 150.00);
```

**Note**: Hold payments expire after 9 days if not finalized.

### Webhook Integration

#### Setting Up Webhook Route

```php
// In your routes file (e.g., routes/web.php)

use Engine\Atomic\Plugins\Monopay\WebhookHandler;

$f3->route('POST /monopay/webhook', function() {
    WebhookHandler::handle();
});
```

#### Webhook URL Configuration

Make sure your webhook URL is:
- Publicly accessible (not localhost for production)
- Uses HTTPS (required by Monobank)
- Returns HTTP 200 status on success

#### Webhook Handler

The `WebhookHandler` class automatically:
- Verifies webhook signature using RSA-SHA256
- Parses incoming payment data
- Updates payment records in the database
- Logs payment history
- Handles payment status changes (success, failure, hold, reversed, pending)

The webhook handler processes the following statuses:
- `success` - Payment completed successfully
- `failure` - Payment failed
- `processing` or `created` - Payment pending
- `reversed` - Payment cancelled/refunded
- `hold` - Payment authorized but not captured

## Hooks

The Monopay plugin exposes hooks for invoice creation and webhook lifecycle
events. Use these to customize invoice payloads, react to payment updates, or
change whether a successful payment is marked fulfilled.

### Filters

- `monopay.payment.invoice_data` - Filter invoice payload before the API
    request is sent.
    - Arguments: `($invoice_data, $payment, $options)`
- `monopay.payment.should_mark_fulfilled` - Decide whether a verified payment
    should be marked as fulfilled.
    - Arguments: `($should_mark_fulfilled, $payment, $data, $verification)`

### Actions

- `monopay.payment.created` - Fired after a payment invoice is created.
    - Arguments: `($payment, $options, $api_response, $invoice_data)`
- `monopay.payment.create_failed` - Fired when invoice creation fails.
    - Arguments: `($payment, $options, $api_response, $invoice_data)`
- `monopay.payment.updated` - Fired when a webhook updates an existing payment
    record.
    - Arguments: `($payment, $data, $old_status, $new_status)`
- `monopay.payment.status` - Fired for every webhook status transition.
    - Arguments: `($payment, $data, $new_status, $old_status)`
- `monopay.payment.status.{status}` - Fired for a specific webhook status.
    - Examples: `monopay.payment.status.success`,
        `monopay.payment.status.failure`, `monopay.payment.status.hold`
    - Arguments: `($payment, $data, $old_status)`
- `monopay.payment.verification_failed` - Fired when Monobank verification of
    a successful payment fails.
    - Arguments: `($payment, $data, $verification)`
- `monopay.payment.verified` - Fired after a successful payment is verified.
    - Arguments: `($payment, $data, $verification)`
- `monopay.payment.success` - Fired after a verified successful payment.
    - Arguments: `($payment, $data, $verification)`
- `monopay.payment.failed` - Fired when the webhook reports a failed payment.
    - Arguments: `($payment, $data)`
- `monopay.payment.pending` - Fired when the webhook reports a created or
    processing payment.
    - Arguments: `($payment, $data)`
- `monopay.payment.reversed` - Fired when the webhook reports a reversed
    payment.
    - Arguments: `($payment, $data)`
- `monopay.payment.hold` - Fired when the webhook reports a hold payment.
    - Arguments: `($payment, $data)`



## Error Handling

All methods return arrays with `ok`, `data`, and `error` keys:

```php
$result = monopay_create_payment(...);

if (!$result['ok']) {
    // Handle error
    $errorMessage = $result['error'];
    $statusCode = $result['status'] ?? null;
    $errorCode = $result['errorCode'] ?? null;
    
    Log::error('Payment creation failed', [
        'error' => $errorMessage,
        'code' => $errorCode
    ]);
    
    // Show user-friendly message
    notify_error('Payment processing failed. Please try again.');
}
```

## Testing

### Test Environment

Monopay can use Monobank test tokens while keeping the same API host. Set a Monobank sandbox/test token when configuring the plugin.

### Test Card Numbers

```
4242424242424242 (Visa)
5555555555554444 (Mastercard)
```

### Example Test Flow

```php
// Configure using a Monobank test token
$monopay->configure(
    token: 'test_token_from_api.monobank.ua'
);

// Create test payment
$result = monopay_create_payment(
    amount: 10.00,
    destination: 'Test payment'
);

// Payment URL can be used for testing
// Customer will see test environment notification
```

## Security Considerations

1. **Never commit API tokens** to version control
2. **Use environment variables** for sensitive configuration
3. **Verify webhook signatures** (handled automatically)
4. **Use HTTPS** for webhook and redirect URLs
5. **Validate amounts** on both client and server side
6. **Log all transactions** for audit trail
7. **Handle errors gracefully** without exposing sensitive data

## Troubleshooting

### Webhook not receiving callbacks

1. Check webhook URL is publicly accessible
2. Verify HTTPS is enabled
3. Ensure endpoint returns HTTP 200
4. Check logs for signature verification errors
5. Verify webhook URL is configured correctly

### Payment creation fails

1. Check API token is valid
2. Verify test/production mode matches token
3. Check amount is positive and in correct format
4. Ensure all required fields are provided
5. Review API response error message

### Signature verification fails

1. Public key may have changed - clear cached key
2. Ensure raw body is used for verification
3. Check X-Sign header is present
4. Verify OpenSSL extension is enabled

## API Reference

### Helper Functions

- `monopay()` - Get plugin instance
- `monopay_get_order()` - Get the plugin order manager
- `monopay_create_payment($amount, $destination, $options)` - Create payment invoice
- `monopay_get_status($invoiceId)` - Get payment status
- `monopay_is_paid($invoiceId)` - Check if paid
- `monopay_cancel($invoiceId, $amount, $options)` - Cancel/refund payment

### Plugin Methods

- `configure($token, $options)` - Configure API token and settings
- `create_payment($amount, $destination, $options)` - Create payment invoice
- `handle_webhook($x_sign, $raw_body)` - Process webhook from Monobank
- `get_api()` - Get API client instance
- `get_order()` - Get Order manager instance

### Order Methods

- `create($amount, $destination, $options)` - Create invoice
- `get_status($invoiceId)` - Get invoice status
- `is_paid($invoiceId)` - Check if paid
- `is_pending($invoiceId)` - Check if pending
- `is_failed($invoiceId)` - Check if failed
- `is_hold($invoiceId)` - Check if on hold
- `cancel($invoiceId, $amount, $options)` - Cancel/refund invoice
- `invalidate($invoiceId)` - Invalidate invoice
- `finalize_hold($invoiceId, $amount, $items)` - Finalize hold (capture)
- `parse_webhook($webhook_data)` - Parse webhook data

### API Methods

- `create_invoice($amount, $options)` - Create invoice via API
- `get_invoice_status($invoice_id)` - Get invoice status via API
- `cancel_invoice($invoice_id, $options)` - Cancel invoice via API
- `remove_invoice($invoice_id)` - Remove invoice via API
- `finalize_hold($invoice_id, $amount, $items)` - Finalize hold via API
- `get_public_key()` - Get public key for webhook verification
- `verify_signature($public_key, $x_sign, $body)` - Verify webhook signature

## Support

- **Monobank API Documentation**: https://api.monobank.ua/docs/acquiring.html
- **Test Environment**: https://api.monobank.ua/
- **Production Cabinet**: https://web.monobank.ua/

## License

This plugin follows the same license as Atomic Framework (MIT License).
