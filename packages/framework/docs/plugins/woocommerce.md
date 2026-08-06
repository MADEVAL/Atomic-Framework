# WooCommerce Plugin

Integrate WooCommerce REST API for products, orders, and customers.

## Connection

```php
wc_connect(
    'https://shop.example.com',
    'ck_consumer_key',
    'cs_consumer_secret'
);
```

## Products

```php
// Get products (paginated)
$result = wc_get_products(1, 10);

// Get all products
$result = wc_get_all_products();

// Get single product
$product = wc_get_product(123);

// Parse product
$parsed = wc_parse_product($product['data']);
```

## Categories

```php
$categories = wc_get_categories();
$allCategories = wc_get_all_categories();
```

## Orders

```php
// Create order
$order = wc_create_order([
    'payment_method' => 'bacs',
    'billing' => [...],
    'line_items' => [
        ['product_id' => 123, 'quantity' => 2]
    ]
]);

// Get order
$order = wc_get_order(456);

// Update order status
wc_update_order_status(456, 'processing');

// Apply coupon
wc_apply_coupon(456, 'SAVE10');
```

## Customers

```php
// Get customer
$customer = wc_get_customer(789);

// Get by email
$customer = wc_get_customer_by_email('user@example.com');

// Register customer
wc_register_customer('user@example.com', 'username', 'John', 'Doe', '+1234567890');

// Register from Telegram
wc_register_customer_from_telegram(12345678, 'john_doe', 'John', 'Doe');

// Update address
wc_update_customer_address(789, 
    ['address_1' => '123 Main St', 'city' => 'NY'],
    ['address_1' => '456 Second St', 'city' => 'LA']
);
```

## Sync & Cache

```php
// Sync products
wc_sync_products();
$products = wc_cached_products();

// Sync categories
wc_sync_categories();
$cats = wc_cached_categories();
```

## Examples

```php
// Display products
wc_connect('https://shop.example.com', 'ck_***', 'cs_***');
$result = wc_sync_products();

foreach (wc_cached_products() as $product) {
    echo '<div>';
    echo '<h3>' . $product['name'] . '</h3>';
    echo '<p>Price: $' . $product['price'] . '</p>';
    echo '</div>';
}
```
