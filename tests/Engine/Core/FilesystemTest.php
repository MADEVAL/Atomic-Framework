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
        $this->assertTrue($this->fs->make_dir($dir));
        $this->assertTrue(is_dir($dir));
        $this->assertTrue($this->fs->remove_dir($dir));
        $this->assertFalse(is_dir($dir));
    }

    public function test_removeDir_recursive(): void
    {
        $dir = self::$tmpDir . 'recdir' . DIRECTORY_SEPARATOR;
        mkdir($dir, 0755, true);
        file_put_contents($dir . 'file.txt', 'x');
        mkdir($dir . 'sub', 0755, true);
        file_put_contents($dir . 'sub' . DIRECTORY_SEPARATOR . 'nested.txt', 'y');
        $this->assertTrue($this->fs->remove_dir($dir, true));
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
        $result = $this->fs->list_files($dir);
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(2, count($result));
    }

    public function test_listFiles_empty_folder_returns_false(): void
    {
        $this->assertFalse($this->fs->list_files(''));
    }

    public function test_normalizePath(): void
    {
        $result = $this->fs->normalize_path('/a/b/../c/./d');
        $this->assertStringNotContainsString('..', $result);
        $this->assertStringNotContainsString('./', $result);
    }

    public function test_joinPaths(): void
    {
        $result = $this->fs->join_paths('a', 'b', 'c');
        $this->assertStringContainsString('a', $result);
        $this->assertStringContainsString('c', $result);
    }

    public function test_isAbsolutePath(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertTrue($this->fs->is_absolute_path('C:\\Users'));
            $this->assertFalse($this->fs->is_absolute_path('relative/path'));
        } else {
            $this->assertTrue($this->fs->is_absolute_path('/home/user'));
            $this->assertFalse($this->fs->is_absolute_path('relative/path'));
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

        $zip_file = self::$tmpDir . 'test.zip';
        $this->assertTrue($this->fs->zip_files([$srcDir . 'file1.txt', $srcDir . 'file2.txt'], $zip_file, $srcDir));
        $this->assertFileExists($zip_file);

        $extractDir = self::$tmpDir . 'extracted' . DIRECTORY_SEPARATOR;
        $this->assertTrue($this->fs->unzip_file($zip_file, $extractDir));
        $this->assertFileExists($extractDir . 'file1.txt');
        $this->assertSame('content1', file_get_contents($extractDir . 'file1.txt'));
    }

    public function test_zip_empty_files_returns_false(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive not available');
        }
        $zip_file = self::$tmpDir . 'empty.zip';
        $this->assertFalse($this->fs->zip_files([], $zip_file));
    }

    // -------------------------------------------------------------------------
    // count_lines
    // -------------------------------------------------------------------------

    public function test_count_lines_empty_file(): void
    {
        $file = self::$tmpDir . 'cl_empty.log';
        file_put_contents($file, '');
        $this->assertSame(0, $this->fs->count_lines($file));
    }

    public function test_count_lines_single_line_no_newline(): void
    {
        $file = self::$tmpDir . 'cl_single_no_nl.log';
        file_put_contents($file, 'only line');
        $this->assertSame(1, $this->fs->count_lines($file));
    }

    public function test_count_lines_single_line_with_newline(): void
    {
        $file = self::$tmpDir . 'cl_single_nl.log';
        file_put_contents($file, "only line\n");
        $this->assertSame(1, $this->fs->count_lines($file));
    }

    public function test_count_lines_multiple_lines(): void
    {
        $file = self::$tmpDir . 'cl_multi.log';
        file_put_contents($file, "line1\nline2\nline3\n");
        $this->assertSame(3, $this->fs->count_lines($file));
    }

    public function test_count_lines_multiple_lines_no_trailing_newline(): void
    {
        $file = self::$tmpDir . 'cl_multi_no_trail.log';
        file_put_contents($file, "line1\nline2\nline3");
        $this->assertSame(3, $this->fs->count_lines($file));
    }

    public function test_count_lines_crlf_endings(): void
    {
        $file = self::$tmpDir . 'cl_crlf.log';
        file_put_contents($file, "line1\r\nline2\r\nline3\r\n");
        $this->assertSame(3, $this->fs->count_lines($file));
    }

    public function test_count_lines_skips_blank_lines(): void
    {
        $file = self::$tmpDir . 'cl_blank.log';
        file_put_contents($file, "line1\n\nline2\n\n\nline3\n");
        $this->assertSame(3, $this->fs->count_lines($file));
    }

    public function test_count_lines_only_newlines_returns_zero(): void
    {
        $file = self::$tmpDir . 'cl_only_nl.log';
        file_put_contents($file, "\n\n\n\n\n");
        $this->assertSame(0, $this->fs->count_lines($file));
    }

    public function test_count_lines_only_crlf_returns_zero(): void
    {
        $file = self::$tmpDir . 'cl_only_crlf.log';
        file_put_contents($file, "\r\n\r\n\r\n");
        $this->assertSame(0, $this->fs->count_lines($file));
    }

    public function test_count_lines_whitespace_only_lines_are_counted(): void
    {
        // The method only strips \r, so lines with spaces/tabs are non-empty.
        $file = self::$tmpDir . 'cl_whitespace.log';
        file_put_contents($file, "  \n\t\n   \t\n");
        $this->assertSame(3, $this->fs->count_lines($file));
    }

    public function test_count_lines_tiny_chunk_size(): void
    {
        // Force many chunk boundaries with a very small chunk size.
        $file = self::$tmpDir . 'cl_tinychunk.log';
        file_put_contents($file, "alpha\nbeta\ngamma\ndelta\n");
        $this->assertSame(4, $this->fs->count_lines($file, 3));
    }

    public function test_count_lines_chunk_size_one(): void
    {
        $file = self::$tmpDir . 'cl_chunk1.log';
        file_put_contents($file, "ab\ncd\nef\n");
        $this->assertSame(3, $this->fs->count_lines($file, 1));
    }

    public function test_count_lines_larger_than_chunk(): void
    {
        // Write enough lines to exceed the default 65536-byte chunk.
        $file = self::$tmpDir . 'cl_large.log';
        $lines = 2000;
        $content = '';
        for ($i = 1; $i <= $lines; $i++) {
            $content .= str_pad("log entry $i", 50) . "\n";
        }
        file_put_contents($file, $content);
        $this->assertSame($lines, $this->fs->count_lines($file));
    }

    public function test_count_lines_nonexistent_file_returns_false(): void
    {
        $this->assertFalse($this->fs->count_lines(self::$tmpDir . 'no_such_file.log'));
    }

    // -------------------------------------------------------------------------
    // read_lines_from_end
    // -------------------------------------------------------------------------

    private function makeLogFile(string $name, int $line_count, int $line_length = 40): string
    {
        $file = self::$tmpDir . $name;
        $content = '';
        for ($i = 1; $i <= $line_count; $i++) {
            $content .= str_pad("line $i", $line_length) . "\n";
        }
        file_put_contents($file, $content);
        return $file;
    }

    public function test_read_lines_from_end_empty_file(): void
    {
        $file = self::$tmpDir . 'rle_empty_file.log';
        file_put_contents($file, '');

        $result = $this->fs->read_lines_from_end($file, 0, 10);

        $this->assertSame([], $result);
    }

    public function test_read_lines_from_end_only_blank_lines(): void
    {
        $file = self::$tmpDir . 'rle_blanks_only.log';
        file_put_contents($file, "\n\n\n\n\n");

        $result = $this->fs->read_lines_from_end($file, 0, 10);

        $this->assertSame([], $result);
    }

    public function test_read_lines_from_end_limit_zero_returns_empty(): void
    {
        $file = self::$tmpDir . 'rle_limit0.log';
        file_put_contents($file, "line1\nline2\n");

        $result = $this->fs->read_lines_from_end($file, 0, 0);

        $this->assertSame([], $result);
    }

    public function test_read_lines_from_end_basic_reverse_order(): void
    {
        $file = self::$tmpDir . 'rle_basic.log';
        file_put_contents($file, "line1\nline2\nline3\n");

        $result = $this->fs->read_lines_from_end($file, 0, 3);

        $this->assertSame(['line3', 'line2', 'line1'], $result);
    }

    public function test_read_lines_from_end_limit_caps_result(): void
    {
        $file = self::$tmpDir . 'rle_limit.log';
        file_put_contents($file, "line1\nline2\nline3\nline4\nline5\n");

        $result = $this->fs->read_lines_from_end($file, 0, 2);

        $this->assertCount(2, $result);
        $this->assertSame(['line5', 'line4'], $result);
    }

    public function test_read_lines_from_end_limit_larger_than_file(): void
    {
        $file = self::$tmpDir . 'rle_limit_big.log';
        file_put_contents($file, "line1\nline2\nline3\n");

        $result = $this->fs->read_lines_from_end($file, 0, 100);

        $this->assertSame(['line3', 'line2', 'line1'], $result);
    }

    public function test_read_lines_from_end_offset_skips_from_end(): void
    {
        $file = self::$tmpDir . 'rle_offset.log';
        file_put_contents($file, "line1\nline2\nline3\nline4\nline5\n");

        // offset=2 skips line5 and line4, starts collecting from line3
        $result = $this->fs->read_lines_from_end($file, 2, 3);

        $this->assertSame(['line3', 'line2', 'line1'], $result);
    }

    public function test_read_lines_from_end_offset_equals_line_count(): void
    {
        $file = self::$tmpDir . 'rle_off_eq.log';
        file_put_contents($file, "line1\nline2\nline3\n");

        // 3 lines, offset=3 → skip all → empty
        $result = $this->fs->read_lines_from_end($file, 3, 10);

        $this->assertSame([], $result);
    }

    public function test_read_lines_from_end_offset_gets_first_line_only(): void
    {
        $file = self::$tmpDir . 'rle_first_only.log';
        file_put_contents($file, "line1\nline2\nline3\nline4\nline5\n");

        // offset=4 skips line5..line2, limit=1 → only line1
        $result = $this->fs->read_lines_from_end($file, 4, 1);

        $this->assertSame(['line1'], $result);
    }

    public function test_read_lines_from_end_skips_empty_lines(): void
    {
        $file = self::$tmpDir . 'rle_empty.log';
        file_put_contents($file, "line1\n\nline2\n\nline3\n");

        $result = $this->fs->read_lines_from_end($file, 0, 10);

        $this->assertSame(['line3', 'line2', 'line1'], $result);
    }

    public function test_read_lines_from_end_offset_counts_non_blank_only(): void
    {
        // Blank lines should not count toward the offset.
        $file = self::$tmpDir . 'rle_off_blank.log';
        file_put_contents($file, "line1\n\n\nline2\n\nline3\n");

        // offset=1 skips line3, collect from line2
        $result = $this->fs->read_lines_from_end($file, 1, 10);

        $this->assertSame(['line2', 'line1'], $result);
    }

    public function test_read_lines_from_end_crlf_endings(): void
    {
        $file = self::$tmpDir . 'rle_crlf.log';
        file_put_contents($file, "line1\r\nline2\r\nline3\r\n");

        $result = $this->fs->read_lines_from_end($file, 0, 3);

        // \r must be stripped
        $this->assertSame(['line3', 'line2', 'line1'], $result);
        foreach ($result as $line) {
            $this->assertStringNotContainsString("\r", $line);
        }
    }

    public function test_read_lines_from_end_crlf_no_trailing_newline(): void
    {
        $file = self::$tmpDir . 'rle_crlf_notrail.log';
        file_put_contents($file, "line1\r\nline2\r\nline3");

        $result = $this->fs->read_lines_from_end($file, 0, 3);

        $this->assertSame(['line3', 'line2', 'line1'], $result);
        foreach ($result as $line) {
            $this->assertStringNotContainsString("\r", $line);
        }
    }

    public function test_read_lines_from_end_single_line_no_newline(): void
    {
        $file = self::$tmpDir . 'rle_one.log';
        file_put_contents($file, 'only line');

        $result = $this->fs->read_lines_from_end($file, 0, 5);

        $this->assertSame(['only line'], $result);
    }

    public function test_read_lines_from_end_single_line_with_newline(): void
    {
        $file = self::$tmpDir . 'rle_one_nl.log';
        file_put_contents($file, "only line\n");

        $result = $this->fs->read_lines_from_end($file, 0, 5);

        $this->assertSame(['only line'], $result);
    }

    public function test_read_lines_from_end_multiple_lines_no_trailing_newline(): void
    {
        $file = self::$tmpDir . 'rle_no_trail.log';
        file_put_contents($file, "line1\nline2\nline3");

        $result = $this->fs->read_lines_from_end($file, 0, 3);

        $this->assertSame(['line3', 'line2', 'line1'], $result);
    }

    public function test_read_lines_from_end_offset_beyond_file_returns_empty(): void
    {
        $file = self::$tmpDir . 'rle_beyond.log';
        file_put_contents($file, "line1\nline2\n");

        $result = $this->fs->read_lines_from_end($file, 100, 5);

        $this->assertSame([], $result);
    }

    public function test_read_lines_from_end_nonexistent_file_returns_false(): void
    {
        $this->assertFalse($this->fs->read_lines_from_end(self::$tmpDir . 'nope.log', 0, 10));
    }

    public function test_read_lines_from_end_file_smaller_than_chunk(): void
    {
        // 10 lines at ~20 bytes each - well under 8192-byte default chunk
        $file = self::$tmpDir . 'rle_small.log';
        file_put_contents($file, implode("\n", range(1, 10)) . "\n");

        $result = $this->fs->read_lines_from_end($file, 0, 10);

        $this->assertCount(10, $result);
        $this->assertSame('10', $result[0]);
        $this->assertSame('1', $result[9]);
    }

    public function test_read_lines_from_end_chunk_size_one(): void
    {
        // Extreme: every byte is its own chunk.
        $file = self::$tmpDir . 'rle_chunk1.log';
        file_put_contents($file, "aa\nbb\ncc\n");

        $result = $this->fs->read_lines_from_end($file, 0, 3, 1);

        $this->assertSame(['cc', 'bb', 'aa'], $result);
    }

    public function test_read_lines_from_end_chunk_size_one_with_offset(): void
    {
        $file = self::$tmpDir . 'rle_chunk1_off.log';
        file_put_contents($file, "aa\nbb\ncc\ndd\n");

        $result = $this->fs->read_lines_from_end($file, 1, 2, 1);

        $this->assertSame(['cc', 'bb'], $result);
    }

    public function test_read_lines_from_end_line_spanning_chunk_boundary(): void
    {
        // Force a chunk boundary inside a line by using chunk_size=20 and lines longer than 20 bytes.
        $file = self::$tmpDir . 'rle_boundary.log';
        $lines = ['short', str_repeat('A', 30), 'end'];
        file_put_contents($file, implode("\n", $lines) . "\n");

        $result = $this->fs->read_lines_from_end($file, 0, 3, 20);

        $this->assertSame(['end', str_repeat('A', 30), 'short'], $result);
    }

    public function test_read_lines_from_end_larger_than_chunk_correct_order(): void
    {
        // 300 lines at ~40 bytes each → ~12KB, crosses default 8192-byte chunk boundary.
        $file = $this->makeLogFile('rle_crosschunk.log', 300);

        $result = $this->fs->read_lines_from_end($file, 0, 5);

        $this->assertCount(5, $result);
        // Newest lines first: line300 … line296
        $this->assertStringContainsString('300', $result[0]);
        $this->assertStringContainsString('296', $result[4]);
    }

    public function test_read_lines_from_end_fast_path_skip_correctness(): void
    {
        // 500 lines, request page 2 (offset=10, limit=10).
        // The fast-path kicks in for chunks that are entirely in the skip window.
        $file = $this->makeLogFile('rle_fastpath.log', 500);

        $result = $this->fs->read_lines_from_end($file, 10, 10);

        $this->assertCount(10, $result);
        // offset=10 skips line500..line491, so first collected = line490
        $this->assertStringContainsString('490', $result[0]);
        $this->assertStringContainsString('481', $result[9]);
    }

    public function test_read_lines_from_end_fast_path_offset_lands_inside_chunk(): void
    {
        // 100 lines with small chunks so the fast-path skips some chunks
        // but the offset boundary falls mid-chunk, exercising the transition.
        $file = $this->makeLogFile('rle_fp_mid.log', 100, 20);

        // offset=7 with chunk_size=50: some chunks fully skipped, one partially
        $result = $this->fs->read_lines_from_end($file, 7, 5, 50);

        $this->assertCount(5, $result);
        // offset=7 skips line100..line94, first collected = line93
        $this->assertStringContainsString('93', $result[0]);
        $this->assertStringContainsString('89', $result[4]);
    }

    public function test_read_lines_from_end_deep_page_fast_path(): void
    {
        // 1000 lines, request a deep page to exercise many fast-path iterations.
        $file = $this->makeLogFile('rle_deep.log', 1000, 60);

        $result = $this->fs->read_lines_from_end($file, 900, 10);

        $this->assertCount(10, $result);
        // offset=900 skips line1000..line101, first collected = line100
        $this->assertStringContainsString('100', $result[0]);
        $this->assertStringContainsString('91', $result[9]);
    }

    public function test_read_lines_from_end_all_pages_cover_entire_file(): void
    {
        // Paginate through a file page by page and verify every line is seen exactly once.
        $line_count = 25;
        $pageSize = 7;
        $file = self::$tmpDir . 'rle_allpages.log';
        $content = '';
        for ($i = 1; $i <= $line_count; $i++) {
            $content .= "L$i\n";
        }
        file_put_contents($file, $content);

        $all = [];
        $offset = 0;
        while (true) {
            $page = $this->fs->read_lines_from_end($file, $offset, $pageSize);
            if (empty($page)) break;
            foreach ($page as $line) {
                $all[] = $line;
            }
            $offset += count($page);
        }

        $this->assertCount($line_count, $all);
        // First element should be last line, last element should be first line.
        $this->assertSame('L25', $all[0]);
        $this->assertSame('L1', $all[$line_count - 1]);
        // No duplicates.
        $this->assertCount($line_count, array_unique($all));
    }

    public function test_read_lines_from_end_all_pages_with_blank_lines(): void
    {
        // Same pagination-completeness test but with interspersed blank lines.
        $file = self::$tmpDir . 'rle_allpages_blank.log';
        $content = '';
        for ($i = 1; $i <= 15; $i++) {
            $content .= "L$i\n\n"; // blank line after each
        }
        file_put_contents($file, $content);

        $all = [];
        $offset = 0;
        $pageSize = 4;
        while (true) {
            $page = $this->fs->read_lines_from_end($file, $offset, $pageSize);
            if (empty($page)) break;
            foreach ($page as $line) {
                $all[] = $line;
            }
            $offset += count($page);
        }

        $this->assertCount(15, $all);
        $this->assertSame('L15', $all[0]);
        $this->assertSame('L1', $all[14]);
    }
}
