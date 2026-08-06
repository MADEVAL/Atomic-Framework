# Telegram

Send messages via Telegram Bot API.

## Configuration

In `config.ini` or `.env`:

```ini
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_CHAT_ID=your_default_chat_id
```

## Basic Usage

```php
// Send to default chat
telegram_send('Hello from Atomic!');

// Send to specific chat
telegram_send('Message text', '123456789');

// With options
telegram_send('**Bold text**', null, [
    'parse_mode' => 'Markdown'
]);
```

## Multiple Bots

```php
// Bot 1 (default)
telegram_send('Message from bot 1');

// Bot 2 (custom)
$bot2 = telegram('bot2_token', 'bot2_chat_id');
$bot2->send('Message from bot 2');

// Bot 3 (notifications)
$notifyBot = telegram('notify_token');
$notifyBot->set_chat_id('notify_chat_id');
$notifyBot->send('Notification message');
```

## Parse Modes

```php
// Markdown
telegram_send('**Bold** _italic_ `code`', null, [
    'parse_mode' => 'Markdown'
]);

// HTML
telegram_send('<b>Bold</b> <i>italic</i> <code>code</code>', null, [
    'parse_mode' => 'HTML'
]);
```

## Advanced Options

```php
telegram_send('Message', null, [
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
    'disable_notification' => true,
]);
```

## Examples

**Error notification:**

```php
try {
    // Some code
} catch (\Throwable $e) {
    telegram_send('❌ Error: ' . $e->getMessage());
}
```

**User registration:**

```php
public function register($atomic) {
    $user = $this->createUser($_POST);
    
    telegram_send(
        "✅ New registration\nEmail: {$user->email}\nName: {$user->name}"
    );
    
    notify_success('Account created!');
    $atomic->reroute('/dashboard');
}
```

**Order notification:**

```php
$message = "🛒 New Order #{$order->id}\n\n";
$message .= "Customer: {$order->customer_name}\n";
$message .= "Total: \${$order->total}\n";
$message .= "Items: {$order->items_count}";

telegram_send($message, null, ['parse_mode' => 'Markdown']);
```

**Multi-bot setup:**

```php
// Errors to admin bot
$adminBot = telegram(
    get_option('admin_bot_token'),
    get_option('admin_chat_id')
);
$adminBot->send('Critical error occurred!');

// Orders to sales bot
$salesBot = telegram(
    get_option('sales_bot_token'),
    get_option('sales_chat_id')
);
$salesBot->send("New order: \${$order->total}");
```

**With custom instance:**

```php
$bot = telegram()
    ->set_token('custom_token')
    ->set_chat_id('custom_chat_id');

$result = $bot->send('Custom message');

if ($result['ok']) {
    echo "Message sent!";
}
```
