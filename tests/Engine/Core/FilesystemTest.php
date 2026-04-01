<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Filesystem;
use PHPUnit\Framework\TestCase;

class FilesystemTest extends TestCase
{
    private static string $tmpDir;
    private Filesystem $fs;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_fs_test_' . uniqid() . DIRECTORY_SEPARATOR;
        mkdir(self::$tmpDir, 0755, true);
    }

    protected function setUp(): void
    {
        $this->fs = Filesystem::instance();
    }

    public static function tearDownAfterClass(): void
    {
        self::rmdirRecursive(self::$tmpDir);
    }

    private static function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . $item;
            is_dir($path) ? self::rmdirRecursive($path . DIRECTORY_SEPARATOR) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_write_and_read(): void
    {
        $file = self::$tmpDir . 'test.txt';
        $this->fs->write($file, 'Hello', false);
        $this->assertSame('Hello', $this->fs->read($file));
    }

    public function test_write_append(): void
    {
        $file = self::$tmpDir . 'append.txt';
        $this->fs->write($file, 'A', false);
        $this->fs->write($file, 'B', true);
        $this->assertSame('AB', $this->fs->read($file));
    }

    public function test_exists(): void
    {
        $file = self::$tmpDir . 'exists.txt';
        $this->assertFalse($this->fs->exists($file));
        file_put_contents($file, 'x');
        $this->assertTrue($this->fs->exists($file));
    }

    public function test_delete(): void
    {
        $file = self::$tmpDir . 'delete.txt';
        file_put_contents($file, 'bye');
        $this->assertTrue($this->fs->delete($file));
        $this->assertFalse(file_exists($file));
    }

    public function test_delete_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->fs->delete(self::$tmpDir . 'nonexistent'));
    }

    public function test_rename(): void
    {
        $old = self::$tmpDir . 'old.txt';
        $new = self::$tmpDir . 'new.txt';
        file_put_contents($old, 'data');
        $this->assertTrue($this->fs->rename($old, $new));
        $this->assertFalse(file_exists($old));
        $this->assertTrue(file_exists($new));
    }

    public function test_rename_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->fs->rename(self::$tmpDir . 'nope', self::$tmpDir . 'nope2'));
    }

    public function test_makeDir_and_removeDir(): void
    {
        $dir = self::$tmpDir . 'subdir' . DIRECTORY_SEPARATOR;
        $this->assertTrue($this->fs->makeDir($dir));
        $this->assertTrue(is_dir($dir));
        $this->assertTrue($this->fs->removeDir($dir));
        $this->assertFalse(is_dir($dir));
    }

    public function test_removeDir_recursive(): void
    {
        $dir = self::$tmpDir . 'recdir' . DIRECTORY_SEPARATOR;
        mkdir($dir, 0755, true);
        file_put_contents($dir . 'file.txt', 'x');
        mkdir($dir . 'sub', 0755, true);
        file_put_contents($dir . 'sub' . DIRECTORY_SEPARATOR . 'nested.txt', 'y');
        $this->assertTrue($this->fs->removeDir($dir, true));
        $this->assertFalse(is_dir($dir));
    }

    public function test_copy_dir(): void
    {
        $src = self::$tmpDir . 'copysrc' . DIRECTORY_SEPARATOR;
        $dst = self::$tmpDir . 'copydst' . DIRECTORY_SEPARATOR;
        mkdir($src, 0755, true);
        file_put_contents($src . 'a.txt', 'AAA');
        mkdir($src . 'inner', 0755, true);
        file_put_contents($src . 'inner' . DIRECTORY_SEPARATOR . 'b.txt', 'BBB');

        $this->assertTrue($this->fs->copy_dir($src, $dst));
        $this->assertFileExists($dst . 'a.txt');
        $this->assertSame('AAA', file_get_contents($dst . 'a.txt'));
        $this->assertFileExists($dst . 'inner' . DIRECTORY_SEPARATOR . 'b.txt');
    }

    public function test_copy_dir_nonexistent_source(): void
    {
        $this->assertFalse($this->fs->copy_dir(self::$tmpDir . 'nosrc', self::$tmpDir . 'nodst'));
    }

    public function test_listFiles(): void
    {
        $dir = self::$tmpDir . 'listdir' . DIRECTORY_SEPARATOR;
        mkdir($dir, 0755, true);
        file_put_contents($dir . 'x.txt', 'x');
        file_put_contents($dir . 'y.txt', 'y');
        $result = $this->fs->listFiles($dir);
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(2, count($result));
    }

    public function test_listFiles_empty_folder_returns_false(): void
    {
        $this->assertFalse($this->fs->listFiles(''));
    }

    public function test_normalizePath(): void
    {
        $result = $this->fs->normalizePath('/a/b/../c/./d');
        $this->assertStringNotContainsString('..', $result);
        $this->assertStringNotContainsString('./', $result);
    }

    public function test_joinPaths(): void
    {
        $result = $this->fs->joinPaths('a', 'b', 'c');
        $this->assertStringContainsString('a', $result);
        $this->assertStringContainsString('c', $result);
    }

    public function test_isAbsolutePath(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertTrue($this->fs->isAbsolutePath('C:\\Users'));
            $this->assertFalse($this->fs->isAbsolutePath('relative/path'));
        } else {
            $this->assertTrue($this->fs->isAbsolutePath('/home/user'));
            $this->assertFalse($this->fs->isAbsolutePath('relative/path'));
        }
    }

    public function test_zip_and_unzip(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $srcDir = self::$tmpDir . 'ziptest' . DIRECTORY_SEPARATOR;
        mkdir($srcDir, 0755, true);
        file_put_contents($srcDir . 'file1.txt', 'content1');
        file_put_contents($srcDir . 'file2.txt', 'content2');

        $zipFile = self::$tmpDir . 'test.zip';
        $this->assertTrue($this->fs->zip_files([$srcDir . 'file1.txt', $srcDir . 'file2.txt'], $zipFile, $srcDir));
        $this->assertFileExists($zipFile);

        $extractDir = self::$tmpDir . 'extracted' . DIRECTORY_SEPARATOR;
        $this->assertTrue($this->fs->unzip_file($zipFile, $extractDir));
        $this->assertFileExists($extractDir . 'file1.txt');
        $this->assertSame('content1', file_get_contents($extractDir . 'file1.txt'));
    }

    public function test_zip_empty_files_returns_false(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive not available');
        }
        $zipFile = self::$tmpDir . 'empty.zip';
        $this->assertFalse($this->fs->zip_files([], $zipFile));
    }
}
