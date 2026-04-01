<?php
declare(strict_types=1);

namespace Tests\Engine\Theme;

use Engine\Atomic\Theme\Schema;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Schema::class);
        $prop = $ref->getProperty('instance');        $prop->setValue(null, null);

        $this->schema = Schema::instance();
    }

    public function test_singleton(): void
    {
        $this->assertSame($this->schema, Schema::instance());
    }

    public function test_generate_unknown_type(): void
    {
        $this->assertNull($this->schema->generate('unknown_type'));
    }

    public function test_generate_product(): void
    {
        $result = $this->schema->generate('product', [
            'name' => 'Test Product',
            'description' => 'A test product',
            'price' => '29.99',
            'currency' => 'USD',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('https://schema.org', $result['@context']);
        $this->assertSame('Product', $result['@type']);
        $this->assertSame('Test Product', $result['name']);
        $this->assertSame('A test product', $result['description']);
        $this->assertSame('29.99', $result['offers']['price']);
        $this->assertSame('USD', $result['offers']['priceCurrency']);
    }

    public function test_generate_article(): void
    {
        $result = $this->schema->generate('article', [
            'title' => 'Test Article',
            'author' => 'John Doe',
            'published' => '2024-01-01',
            'description' => 'Article description',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Article', $result['@type']);
        $this->assertSame('Test Article', $result['headline']);
        $this->assertSame('John Doe', $result['author']['name']);
    }

    public function test_generate_organization(): void
    {
        $result = $this->schema->generate('organization', [
            'name' => 'Test Corp',
            'url' => 'https://example.com',
            'email' => 'info@example.com',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Organization', $result['@type']);
        $this->assertSame('Test Corp', $result['name']);
    }

    public function test_removes_empty_placeholders(): void
    {
        $result = $this->schema->generate('product', [
            'name' => 'Partial',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Partial', $result['name']);
        // Unpopulated placeholders should be removed
        $this->assertArrayNotHasKey('sku', $result);
    }

    public function test_generate_breadcrumb(): void
    {
        $result = $this->schema->generate('breadcrumb', []);

        $this->assertIsArray($result);
        $this->assertSame('BreadcrumbList', $result['@type']);
    }

    public function test_case_insensitive_type(): void
    {
        $upper = $this->schema->generate('PRODUCT', ['name' => 'Test']);
        $lower = $this->schema->generate('product', ['name' => 'Test']);

        $this->assertEquals($upper, $lower);
    }
}
