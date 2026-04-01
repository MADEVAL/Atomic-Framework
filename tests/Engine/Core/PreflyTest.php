<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Prefly;
use PHPUnit\Framework\TestCase;

class PreflyTest extends TestCase
{
    private Prefly $prefly;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Prefly::class);
        $prop = $ref->getProperty('instance');        $prop->setValue(null, null);

        $this->prefly = Prefly::instance();
    }

    public function test_singleton(): void
    {
        $this->assertSame($this->prefly, Prefly::instance());
    }

    public function test_php_version_compatible_current(): void
    {
        $this->assertTrue($this->prefly->is_php_version_compatible('8.0.0'));
    }

    public function test_php_version_compatible_future(): void
    {
        $this->assertFalse($this->prefly->is_php_version_compatible('99.0.0'));
    }

    public function test_php_version_compatible_empty(): void
    {
        $this->assertTrue($this->prefly->is_php_version_compatible(''));
    }

    public function test_extension_loaded_json(): void
    {
        $this->assertTrue($this->prefly->is_extension_loaded('json'));
    }

    public function test_extension_loaded_nonexistent(): void
    {
        $this->assertFalse($this->prefly->is_extension_loaded('nonexistent_extension_xyz'));
    }

    public function test_extension_loaded_empty(): void
    {
        $this->assertTrue($this->prefly->is_extension_loaded(''));
    }

    public function test_function_available(): void
    {
        $this->assertTrue($this->prefly->is_function_available('strlen'));
    }

    public function test_function_not_available(): void
    {
        $this->assertFalse($this->prefly->is_function_available('nonexistent_func_xyz'));
    }

    public function test_function_available_empty(): void
    {
        $this->assertTrue($this->prefly->is_function_available(''));
    }

    public function test_class_available(): void
    {
        $this->assertTrue($this->prefly->is_class_available(\stdClass::class));
    }

    public function test_class_not_available(): void
    {
        $this->assertFalse($this->prefly->is_class_available('NonExistentClass_XYZ'));
    }

    public function test_class_available_empty(): void
    {
        $this->assertTrue($this->prefly->is_class_available(''));
    }

    public function test_check_environment_structure(): void
    {
        $result = $this->prefly->check_environment();

        $this->assertArrayHasKey('php_version', $result);
        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('required', $result['php_version']);
        $this->assertArrayHasKey('current', $result['php_version']);
        $this->assertArrayHasKey('status', $result['php_version']);
        $this->assertSame(PHP_VERSION, $result['php_version']['current']);
    }

    public function test_check_environment_extensions(): void
    {
        $result = $this->prefly->check_environment();
        $required = ['json', 'session', 'mbstring', 'fileinfo', 'pdo', 'curl'];

        foreach ($required as $ext) {
            $this->assertArrayHasKey($ext, $result['extensions']);
        }
    }

    public function test_all_checks_passed(): void
    {
        $this->assertTrue($this->prefly->all_checks_passed());
    }
}
