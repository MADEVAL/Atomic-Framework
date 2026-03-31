# Notifier

Flash messages and temporary data storage between requests.

## Basic Usage

```php
// Add messages
notify('Operation completed', 'success');
notify_success('Profile updated!');
notify_info('New feature available');
notify_warning('Password will expire soon');
notify_error('Failed to save changes');

// Fluent interface
notify_success('Account created')
    ->info('Check your email')
    ->warning('Please verify your account');
```

## Message Types

Available types: `success`, `info`, `warning`, `danger`, `error`

```php
// Using generic notify()
notify('Custom message', 'info');

// Using specific methods
notify_success('Success message');
notify_info('Info message');
notify_warning('Warning message');
notify_error('Error message');
Notifier::instance()->danger('Danger message');
```

## Additional Data

```php
notify_success('File uploaded', [
    'filename' => 'document.pdf',
    'size' => '2.5MB',
    'icon' => 'fa-check-circle'
]);

notify_error('Validation failed', [
    'field' => 'email',
    'rule' => 'required'
]);
```

## Retrieving Messages

```php
// Get all messages (and clear them)
$messages = get_notifications();

// Get all messages (keep them)
$messages = get_notifications(null, false);

// Get specific type
$errors = get_notifications('error');
$successes = get_notifications('success');

// Check if has messages
if (has_notifications()) {
    // Has any messages
}

if (has_notifications('error')) {
    // Has error messages
}

// Count messages
$total = Notifier::instance()->count();
$errorCount = Notifier::instance()->count('error');
```

## Display in Templates

```php
// In your controller
public function index($atomic) {
    notify_success('Welcome back!');
    notify_info('You have 3 unread messages');
}

// In your view (partials/notifications.atom.php)
<?php
$notifications = get_notifications();
foreach ($notifications as $msg): ?>
    <div class="alert alert-<?php echo $msg['type']; ?>">
        <?php echo htmlspecialchars($msg['text']); ?>
        <?php if (isset($msg['icon'])): ?>
            <i class="<?php echo htmlspecialchars($msg['icon']); ?>"></i>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
```

## Flash Data

Temporary data that persists for specified number of requests:

```php
// Set flash data (default: 1 request)
set_flash('form_data', $_POST);
set_flash('highlight', 'new-user');

// Set flash for multiple requests
set_flash('welcome_banner', true, 3); // Show for 3 requests

// Get flash (consumes it)
$formData = get_flash('form_data');

// Peek without consuming
$banner = peek_flash('welcome_banner');

// Check if exists
if (has_flash('form_data')) {
    $data = get_flash('form_data');
}
```

## Clear Messages

```php
// Clear all messages
clear_notifications();

// Clear specific type
clear_notifications('error');

// Reset everything (messages + flash)
Notifier::instance()->reset();
```

## Examples

**Form validation:**

```php
public function saveProfile($atomic) {
    $errors = $this->validate($_POST);
    
    if ($errors) {
        foreach ($errors as $field => $error) {
            notify_error($error, ['field' => $field]);
        }
        set_flash('form_data', $_POST);
        $atomic->reroute('/profile/edit');
        return;
    }
    
    $this->updateProfile($_POST);
    notify_success('Profile updated successfully!');
    $atomic->reroute('/profile');
}

// In edit template
<?php
$formData = get_flash('form_data', []);
$errors = get_notifications('error', false);
?>
<form method="post">
    <input name="email" value="<?php echo $formData['email'] ?? ''; ?>">
    <?php foreach ($errors as $error): ?>
        <?php if ($error['field'] === 'email'): ?>
            <span class="error"><?php echo htmlspecialchars($error['text']); ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</form>
```

**Multi-step wizard:**

```php
// Step 1
public function step1($atomic) {
    set_flash('wizard_step1', $_POST, 5);
    notify_info('Step 1 completed');
    $atomic->reroute('/wizard/step2');
}

// Step 2
public function step2($atomic) {
    $step1Data = peek_flash('wizard_step1');
    set_flash('wizard_step2', $_POST, 5);
    notify_info('Step 2 completed');
    $atomic->reroute('/wizard/step3');
}

// Final step
public function complete($atomic) {
    $step1 = get_flash('wizard_step1');
    $step2 = get_flash('wizard_step2');
    $step3 = $_POST;
    
    $this->processWizard($step1, $step2, $step3);
    notify_success('Wizard completed!');
    $atomic->reroute('/dashboard');
}
```

**Highlight new content:**

```php
// After creating item
public function create($atomic) {
    $item = $this->createItem($_POST);
    set_flash('highlight_item', $item->id, 2);
    notify_success('Item created!');
    $atomic->reroute('/items');
}

// In list view
<?php
$highlightId = peek_flash('highlight_item');
foreach ($items as $item): ?>
    <div class="item <?php echo $item->id == $highlightId ? 'highlight' : ''; ?>">
        <?php echo htmlspecialchars($item->title); ?>
    </div>
<?php endforeach; ?>
```

**Session timeout warning:**

```php
// In beforeroute
public function beforeroute($atomic) {
    $lastActivity = $atomic->get('SESSION.last_activity');
    $timeout = 1800; // 30 minutes
    
    if ($lastActivity && (time() - $lastActivity) > ($timeout - 300)) {
        if (!has_flash('timeout_warning')) {
            notify_warning('Your session will expire in 5 minutes');
            set_flash('timeout_warning', true, 1);
        }
    }
    
    $atomic->set('SESSION.last_activity', time());
}
```