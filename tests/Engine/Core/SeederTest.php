<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\Seeder;
use PHPUnit\Framework\TestCase;

class SeederTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_seeder_' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '*');
        if ($files) {
            foreach ($files as $f) @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function make_output_with_stderr_capture(): array
    {
        $stream = fopen('php://memory', 'r+');
        $out = new Output(null, $stream);
        return [$out, $stream];
    }

    private function read_stream(mixed $stream): string
    {
        rewind($stream);
        return stream_get_contents($stream);
    }

    public function test_run_valid_seed(): void
    {
        $seedFile = $this->tmpDir . 'test_seed.php';
        file_put_contents($seedFile, '<?php return ["run" => function() { file_put_contents("' . addslashes($this->tmpDir) . 'seed_ran.txt", "done"); }];');

        Seeder::run($seedFile);
        $this->assertFileExists($this->tmpDir . 'seed_ran.txt');
        $this->assertSame('done', file_get_contents($this->tmpDir . 'seed_ran.txt'));
    }

    public function test_run_nonexistent_file(): void
    {
        [$out, $stream] = $this->make_output_with_stderr_capture();
        Seeder::run('/nonexistent/seed.php', $out);
        $this->assertSame("Source file not found or not readable: /nonexistent/seed.php\n", $this->read_stream($stream));
    }

    public function test_run_invalid_seed_without_run_key(): void
    {
        $seedFile = $this->tmpDir . 'bad_seed.php';
        file_put_contents($seedFile, '<?php return ["setup" => function() {}];');

        [$out, $stream] = $this->make_output_with_stderr_capture();
        Seeder::run($seedFile, $out);
        $this->assertSame("Invalid seed file: 'run' function not found.\n", $this->read_stream($stream));
    }

    public function test_run_seed_with_exception(): void
    {
        $seedFile = $this->tmpDir . 'error_seed.php';
        file_put_contents($seedFile, '<?php return ["run" => function() { throw new \Exception("seed error"); }];');

        [$out, $stream] = $this->make_output_with_stderr_capture();
        Seeder::run($seedFile, $out);
        $this->assertMatchesRegularExpression('/Error executing seed.*seed error/', $this->read_stream($stream));
    }
}
