<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Seeder;
use PHPUnit\Framework\TestCase;
use Tests\Support\StreamCapture;
use Tests\Support\TempPath;

class SeederTest extends TestCase
{
    private string $tmp_dir;

    protected function setUp(): void
    {
        $this->tmp_dir = TempPath::make_dir('atomic_seeder_');
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->tmp_dir);
    }

    private function make_output_with_stderr_capture(): array
    {
        return StreamCapture::output_with_stderr();
    }

    private function read_stream(mixed $stream): string
    {
        return StreamCapture::read($stream);
    }

    public function test_run_valid_seed(): void
    {
        $seedFile = $this->tmp_dir . 'test_seed.php';
        file_put_contents($seedFile, '<?php return ["run" => function() { file_put_contents("' . addslashes($this->tmp_dir) . 'seed_ran.txt", "done"); }];');

        Seeder::run($seedFile);
        $this->assertFileExists($this->tmp_dir . 'seed_ran.txt');
        $this->assertSame('done', file_get_contents($this->tmp_dir . 'seed_ran.txt'));
    }

    public function test_run_nonexistent_file(): void
    {
        [$out, $stream] = $this->make_output_with_stderr_capture();
        Seeder::run('/nonexistent/seed.php', $out);
        $this->assertSame("Source file not found or not readable: /nonexistent/seed.php\n", $this->read_stream($stream));
    }

    public function test_run_invalid_seed_without_run_key(): void
    {
        $seedFile = $this->tmp_dir . 'bad_seed.php';
        file_put_contents($seedFile, '<?php return ["setup" => function() {}];');

        [$out, $stream] = $this->make_output_with_stderr_capture();
        Seeder::run($seedFile, $out);
        $this->assertSame("Invalid seed file: 'run' function not found.\n", $this->read_stream($stream));
    }

    public function test_run_seed_with_exception(): void
    {
        $seedFile = $this->tmp_dir . 'error_seed.php';
        file_put_contents($seedFile, '<?php return ["run" => function() { throw new \Exception("seed error"); }];');

        [$out, $stream] = $this->make_output_with_stderr_capture();
        Seeder::run($seedFile, $out);
        $this->assertMatchesRegularExpression('/Error executing seed.*seed error/', $this->read_stream($stream));
    }
}
