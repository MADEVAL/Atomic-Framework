<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

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
        $this->expectOutputString("Source file not found or not readable: /nonexistent/seed.php\n");
        Seeder::run('/nonexistent/seed.php');
    }

    public function test_run_invalid_seed_without_run_key(): void
    {
        $seedFile = $this->tmpDir . 'bad_seed.php';
        file_put_contents($seedFile, '<?php return ["setup" => function() {}];');

        $this->expectOutputString("Invalid seed file: 'run' function not found.\n");
        Seeder::run($seedFile);
    }

    public function test_run_seed_with_exception(): void
    {
        $seedFile = $this->tmpDir . 'error_seed.php';
        file_put_contents($seedFile, '<?php return ["run" => function() { throw new \Exception("seed error"); }];');

        $this->expectOutputRegex('/Error executing seed.*seed error/');
        Seeder::run($seedFile);
    }
}
