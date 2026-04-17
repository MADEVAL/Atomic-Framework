# Mailer

SMTP wrapper for sending emails.

## Basic Usage

```php
// Send text email
mail_to('user@example.com', 'John Doe')
    ->set_text('Hello, this is a plain text message.')
    ->send('Welcome Message');

// Send HTML email
mail_to('user@example.com')
    ->set_html('<h1>Welcome!</h1><p>Thank you for joining us.</p>')
    ->send('Welcome aboard');

// Quick send
mail_send('user@example.com', 'Subject', '<h1>Hello</h1>');
mail_send('user@example.com', 'Subject', 'Plain text', false);
```

## Recipients

```php
mail_to('primary@example.com', 'Primary User')
    ->add_cc('manager@example.com')
    ->add_bcc('archive@example.com')
    ->send('Report');

// Using helpers
mail_to('user@example.com')
    ->mail_cc('boss@example.com')
    ->mail_bcc('archive@example.com')
    ->send('Report');
```

## Attachments

```php
mail_to('client@example.com')
    ->attach('/path/to/document.pdf', 'Report.pdf')
    ->attach('/path/to/image.jpg', null, 'logo123') // inline: <img src="cid:logo123">
    ->send('Documents');

// Using helper
mail_to('client@example.com')
    ->mail_attach('/path/to/document.pdf', 'Report.pdf')
    ->send('Documents');
```

## Headers

```php
mail_to('user@example.com')
    ->add_header('X-Custom-Header', 'value')
    ->add_header('List-Unsubscribe', '<mailto:unsubscribe@example.com>')
    ->send('Newsletter');

// Using helper
mail_to('user@example.com')
    ->mail_header('X-Priority', '1')
    ->mail_header('List-Unsubscribe', '<mailto:unsub@example.com>')
    ->send('Newsletter');
```

## Sender

```php
mail_from('custom@example.com', 'Custom Sender')
    ->mail_reply('support@example.com', 'Support Team')
    ->mail_to('user@example.com')
    ->send('Custom Message');
```

## Reset State

```php
// First email
mail_to('user1@example.com')->send('Message 1');

// Second email (clear recipients)
mail_reset()->add_to('user2@example.com')->send('Message 2');
```

## Examples

**Welcome email:**

```php
mail_to($user->email, $user->name)
    ->set_html("
        <h1>Welcome, {$user->name}!</h1>
        <p>Thank you for registering.</p>
        <p><a href='" . lang_url('/activate/' . $user->token) . "'>Activate Account</a></p>
    ")
    ->send('Welcome to ' . get_option('site_name'));
```

**Admin notification:**

```php
mail_to(get_option('admin_email'))
    ->set_text("New user registered: {$user->email}")
    ->send('New Registration');
```

**Mass mailing:**

```php
$recipients = ['user1@example.com', 'user2@example.com', 'user3@example.com'];
$html = '<h1>Newsletter</h1><p>Monthly updates...</p>';

foreach ($recipients as $email) {
    mail_send($email, 'Monthly Newsletter', $html);
}
```

**Complex email with all features:**

```php
mail_from('noreply@example.com', 'Company Name')
    ->mail_reply('support@example.com')
    ->mail_to('client@example.com', 'Client Name')
    ->mail_cc('manager@example.com')
    ->mail_bcc('archive@example.com')
    ->mail_header('X-Priority', '1')
    ->mail_header('X-Campaign-ID', 'newsletter-2024')
    ->mail_attach('/path/to/invoice.pdf', 'Invoice-2024.pdf')
    ->mail_attach('/path/to/logo.png', null, 'company-logo')
    ->set_html('
        <img src="cid:company-logo" alt="Logo">
        <h1>Invoice</h1>
        <p>Please find the invoice attached.</p>
    ')
    ->send('Your Invoice #2024');
```

**Using text content:**

```php
mail_to('user@example.com')
    ->mail_text('This is a plain text message.')
    ->send('Text Email');
```

**Using HTML content:**

```php
mail_to('user@example.com')
    ->mail_html('<h1>HTML Email</h1><p>This is HTML.</p>')
    ->send('HTML Email');
```

## Deliverability Analysis

Check DNS records and get recommendations:

```php
// Check SPF
$spf = mail_check_spf('example.com');
if (!$spf['exists']) {
    echo "SPF record missing!";
}

// Check DKIM
$dkim = mail_check_dkim('example.com', 'mail');
if ($dkim['valid']) {
    echo "DKIM configured correctly";
}

// Check DMARC
$dmarc = mail_check_dmarc('example.com');
echo "DMARC policy: " . $dmarc['policy'];

// Full analysis
$analysis = mail_analyze('example.com', 'mail');
echo "Deliverability score: " . $analysis['score'] . "/100\n";

foreach ($analysis['recommendations'] as $tip) {
    echo "- $tip\n";
}
```