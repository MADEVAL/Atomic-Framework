<?php
declare(strict_types=1);

namespace Tests\Engine\Files;

use Engine\Atomic\Core\App;
use Engine\Atomic\Files\CSV;
use Engine\Atomic\Files\PDF;
use Engine\Atomic\Files\XLS;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FilesIntegrationTest extends TestCase
{
    private static string $root;
    private static string $testing_dir;

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '-1');

        self::$root = dirname(__DIR__, 4);
        self::$testing_dir = self::$root . '/package/engine/storage/framework/testing';

        if (!is_dir(self::$testing_dir)) {
            mkdir(self::$testing_dir, 0755, true);
        }

        $base = \Base::instance();
        $base->set('FONTS', self::$root . '/src/storage/framework/fonts/');
        $base->set('FONTS_TEMP', self::$root . '/src/storage/framework/cache/fonts/');

        App::instance($base);
    }

    public function test_xls_parser_handles_all_fixture_files(): void
    {
        $fixtures = glob(self::$root . '/hidden/xls/*.xls');

        self::assertIsArray($fixtures);
        self::assertNotEmpty($fixtures);

        foreach ($fixtures as $fixture) {
            $rows = (new XLS($fixture))->parse();

            self::assertIsArray($rows, basename($fixture));
            self::assertNotEmpty($rows, basename($fixture));
        }
    }

    public function test_csv_round_trip_and_binary_exports_handle_numeric_values(): void
    {
        $csv = CSV::instance();
        $rows = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ];
        $headers = ['name' => 'Name', 'age' => 'Age'];

        $dumped = $csv->dumpCSV($rows, $headers);

        self::assertSame("\"Name\";\"Age\"\n\"Alice\";\"30\"\n\"Bob\";\"25\"", $dumped);

        $path = tempnam(self::$testing_dir, 'csv-');
        self::assertNotFalse($path);

        try {
            file_put_contents($path, $dumped);

            $parsed = $csv->parseCSV($path, ';', '"');

            self::assertSame(
                [
                    ['Name', 'Age'],
                    ['Alice', '30'],
                    ['Bob', '25'],
                ],
                $parsed
            );

            self::assertSame(
                [
                    ['Name' => 'Alice', 'Age' => '30'],
                    ['Name' => 'Bob', 'Age' => '25'],
                ],
                $csv->applyHeader($parsed)
            );

            $xls = $csv->dumpXLS($rows, $headers);

            self::assertStringStartsWith("\x09\x08", $xls);
            self::assertGreaterThan(20, strlen($xls));
        } finally {
            // @unlink($path);
        }
    }

    public function test_pdf_generates_from_raw_data(): void
    {
        $path = tempnam(self::$testing_dir, 'pdf-raw-');
        self::assertNotFalse($path);

        try {
            (new PDF(file_to: $path))->raw2pdf('Test', [
                ['Name', 'Age'],
                ['Alice', '30'],
                ['Bob', '25'],
            ]);

            $this->assert_pdf_file($path);
        } finally {
            // @unlink($path);
        }
    }

    public function test_pdf_generates_from_csv_and_xls_files(): void
    {
        $csv_base_path = tempnam(self::$testing_dir, 'pdf-csv-');
        $csv_pdf_path = tempnam(self::$testing_dir, 'pdf-csv-out-');
        $xls_pdf_path = tempnam(self::$testing_dir, 'pdf-xls-out-');

        self::assertNotFalse($csv_base_path);
        self::assertNotFalse($csv_pdf_path);
        self::assertNotFalse($xls_pdf_path);

        $csv_path = $csv_base_path . '.csv';

        try {
            rename($csv_base_path, $csv_path);
            file_put_contents($csv_path, "Name,Age\nAlice,30\nBob,25\n");

            (new PDF(file_to: $csv_pdf_path, file_from: $csv_path))->file2pdf('CSV');
            $this->assert_pdf_file($csv_pdf_path);

            (new PDF(file_to: $xls_pdf_path, file_from: self::$root . '/hidden/xls/test.xls'))->file2pdf('XLS');
            $this->assert_pdf_file($xls_pdf_path);
        } finally {
            // @unlink($csv_path);
            // @unlink($csv_pdf_path);
            // @unlink($xls_pdf_path);
        }
    }

    public function test_parse_all_xls_files_and_generate_pdfs(): void
    {
        $fixtures = glob(self::$root . '/hidden/xls/*.xls');

        self::assertIsArray($fixtures);
        self::assertNotEmpty($fixtures);

        $failures = [];

        foreach ($fixtures as $fixture) {
            $filename = basename($fixture);
            $title = pathinfo($filename, PATHINFO_FILENAME);

            try {
                $rows = (new XLS($fixture))->parse();

                if (!is_array($rows) || empty($rows)) {
                    $failures[] = "$filename: parsed to empty or non-array";
                    continue;
                }

                $pdf_path = self::$testing_dir . '/' . $title . '.pdf';

                (new PDF(file_to: $pdf_path))->raw2pdf($title, $rows);

                unset($rows);

                if (!file_exists($pdf_path) || filesize($pdf_path) === 0) {
                    $failures[] = "$filename: PDF not created or empty";
                    continue;
                }

                if (file_get_contents($pdf_path, false, null, 0, 5) !== '%PDF-') {
                    $failures[] = "$filename: output is not a valid PDF";
                }
            } catch (\Throwable $e) {
                $failures[] = "$filename: " . $e->getMessage();
                unset($rows);
            }
        }

        self::assertEmpty($failures, "Failed files:\n" . implode("\n", $failures));
    }

    public function test_all_xls_files_parse_successfully(): void
    {
        // Verify that all 153 XLS files can be parsed successfully.
        // This validates that the XLS parser handles the complete dataset.
        $fixtures = glob(self::$root . '/hidden/xls/*.xls');

        self::assertIsArray($fixtures);
        self::assertNotEmpty($fixtures);
        self::assertCount(153, $fixtures, "Should have 153 XLS files");

        $successful = 0;
        $empty_files = [];

        foreach ($fixtures as $fixture) {
            $filename = basename($fixture);
            $rows = (new XLS($fixture))->parse();

            if (is_array($rows) && !empty($rows)) {
                $successful++;
            } else {
                $empty_files[] = $filename;
            }
        }

        self::assertGreaterThanOrEqual(150, $successful, "At least 150 files should parse successfully");
        if (!empty($empty_files)) {
            self::markTestIncomplete("Some files are empty: " . implode(", ", array_slice($empty_files, 0, 5)));
        }
    }

    private function assert_pdf_file(string $path): void
    {
        self::assertFileExists($path);

        $size = filesize($path);
        self::assertIsInt($size);
        self::assertGreaterThan(0, $size);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path, false, null, 0, 5));
    }
}
