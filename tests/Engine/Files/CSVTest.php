<?php
declare(strict_types=1);

namespace Tests\Engine\Files;

use Engine\Atomic\Files\CSV;
use PHPUnit\Framework\TestCase;

class CSVTest extends TestCase
{
    private CSV $csv;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->csv = CSV::instance();
        $this->tmpDir = sys_get_temp_dir() . '/atomic_csv_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->tmpDir);
        }
    }

    public function test_instance(): void
    {
        $this->assertInstanceOf(CSV::class, $this->csv);
    }

    public function test_parse_csv_file_not_found(): void
    {
        $result = @$this->csv->parseCSV('/nonexistent/file.csv');
        $this->assertFalse($result);
    }

    public function test_parse_csv_basic(): void
    {
        $file = $this->tmpDir . '/test.csv';
        // Each line MUST have a trailing delimiter for the framework's regex to match
        file_put_contents($file, "name;age;\nJohn;30;\nJane;25;\n");

        $result = @$this->csv->parseCSV($file, ';');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_apply_header(): void
    {
        $rows = [
            ['name', 'age', 'city'],
            ['John', '30', 'NYC'],
            ['Jane', '25', 'LA'],
        ];

        $result = $this->csv->applyHeader($rows);
        $this->assertCount(2, $result);
        $this->assertSame('John', $result[0]['name']);
        $this->assertSame('30', $result[0]['age']);
        $this->assertSame('NYC', $result[0]['city']);
        $this->assertSame('Jane', $result[1]['name']);
    }

    public function test_apply_header_custom(): void
    {
        $rows = [
            ['John', '30'],
            ['Jane', '25'],
        ];

        $result = $this->csv->applyHeader($rows, ['name', 'age']);
        $this->assertCount(2, $result);
        $this->assertSame('John', $result[0]['name']);
        $this->assertSame('30', $result[0]['age']);
    }

    public function test_dump_xls(): void
    {
        $rows = [
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25],
        ];
        $headers = ['name' => 'Name', 'age' => 'Age'];

        $result = $this->csv->dumpXLS($rows, $headers);
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
        // XLS BOF marker
        $this->assertStringStartsWith(pack("ssssss", 0x809, 0x8, 0x0, 0x10, 0x0, 0x0), $result);
    }

    public function test_dump_xls_auto_headers(): void
    {
        $rows = [
            ['col1' => 'val1', 'col2' => 'val2'],
        ];
        // Numeric-keyed headers get auto-ucfirst
        $headers = ['col1', 'col2'];

        $result = $this->csv->dumpXLS($rows, $headers);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_dump_csv(): void
    {
        $rows = [
            ['name' => 'John', 'city' => 'NYC'],
            ['name' => 'Jane', 'city' => 'LA'],
        ];
        $headers = ['name' => 'Name', 'city' => 'City'];

        $result = $this->csv->dumpCSV($rows, $headers);
        $this->assertIsString($result);
        $this->assertStringContainsString('Name', $result);
        $this->assertStringContainsString('John', $result);
    }
}
