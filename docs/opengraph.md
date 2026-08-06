## OpenGraph ##

```php
// Simple usage
get_opengraph([
    'title' => 'Page Title',
    'description' => 'Page description',
    'image' => '/path/to/image.jpg'
]);

// With Twitter Card
get_twitter_card([
    'title' => 'Page Title',
    'description' => 'Description',
    'image' => '/path/to/image.jpg'
]);

// Article
set_og_title('Article Title')
    ->set_og_description('Article description')
    ->set_og_image('/path/to/image.jpg')
    ->set_og_article([
        'published_time' => '2025-01-01T12:00:00Z',
        'author' => 'Author Name',
        'section' => 'Technology',
        'tag' => ['PHP', 'Framework', 'Web Development']
    ]);
render_opengraph();
render_twitter();

// Product
set_og_product([
    'price:amount' => '99.99',
    'price:currency' => 'USD',
    'availability' => 'in stock',
    'brand' => 'Brand Name'
]);
```