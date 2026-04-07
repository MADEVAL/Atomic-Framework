<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Config\PathResolutionTrait;
use Engine\Atomic\Core\Filesystem;
use PHPUnit\Framework\TestCase;

class PathNormalizationTest extends TestCase
{
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = Filesystem::instance();
    }

    // ── Filesystem::normalizePath ──────────────────────────────────────────────

    public function test_linux_absolute_path_unchanged(): void
    {
        $this->assertSame(
            '/var/www/app/storage',
            $this->fs->normalizePath('/var/www/app/storage')
        );
    }

    public function test_relative_traversal_resolved(): void
    {
        $this->assertSame(
            'public/uploads',
            $this->fs->normalizePath('storage/../public/uploads')
        );
    }

    public function test_windows_drive_path_preserved(): void
    {
        $this->assertSame(
            'C:/work/app/storage',
            $this->fs->normalizePath('C:\\work\\app\\storage')
        );
    }

    public function test_unc_path_preserved(): void
    {
        $this->assertSame(
            '//server/share/app/storage',
            $this->fs->normalizePath('\\\\server\\share\\app\\storage')
        );
    }

    public function test_wsl_unc_path_preserved(): void
    {
        $this->assertSame(
            '//wsl.localhost/Ubuntu/home/user/project',
            $this->fs->normalizePath('\\\\wsl.localhost\\Ubuntu\\home\\user\\project')
        );
    }

    public function test_dot_segments_collapsed(): void
    {
        $this->assertSame('/a/c/d', $this->fs->normalizePath('/a/b/../c/./d'));
    }

    public function test_drive_with_traversal_resolved(): void
    {
        $this->assertSame('C:/work/app', $this->fs->normalizePath('C:/work/app/storage/..'));
    }

    public function test_unc_with_traversal_resolved(): void
    {
        $this->assertSame('//server/share/app', $this->fs->normalizePath('//server/share/app/logs/..'));
    }

    public function test_duplicate_slashes_collapsed(): void
    {
        $this->assertSame('/var/www/app', $this->fs->normalizePath('/var///www//app'));
    }

    // ── Filesystem::isAbsolutePath ─────────────────────────────────────────────

    public function test_linux_path_is_absolute(): void
    {
        $this->assertTrue($this->fs->isAbsolutePath('/var/www/app'));
    }

    public function test_windows_drive_backslash_is_absolute(): void
    {
        $this->assertTrue($this->fs->isAbsolutePath('C:\\work\\app'));
    }

    public function test_windows_drive_slash_is_absolute(): void
    {
        $this->assertTrue($this->fs->isAbsolutePath('C:/work/app'));
    }

    public function test_unc_backslash_is_absolute(): void
    {
        $this->assertTrue($this->fs->isAbsolutePath('\\\\server\\share'));
    }

    public function test_unc_slash_is_absolute(): void
    {
        $this->assertTrue($this->fs->isAbsolutePath('//server/share'));
    }

    public function test_relative_path_is_not_absolute(): void
    {
        $this->assertFalse($this->fs->isAbsolutePath('storage/logs'));
        $this->assertFalse($this->fs->isAbsolutePath('relative/path'));
        $this->assertFalse($this->fs->isAbsolutePath(''));
    }

    // ── PathResolutionTrait::normalize_separators ──────────────────────────────

    /** @return object{normalize: callable} */
    private function traitSubject(): object
    {
        return new class {
            use PathResolutionTrait;
            public function normalize(string $p): string { return $this->normalize_separators($p); }
        };
    }

    public function test_trait_unc_double_slash_preserved(): void
    {
        $s = $this->traitSubject();
        $this->assertSame('//server/share/app', $s->normalize('\\\\server\\share\\app'));
    }

    public function test_trait_wsl_unc_double_slash_preserved(): void
    {
        $s = $this->traitSubject();
        $this->assertSame('//wsl.localhost/Ubuntu/home', $s->normalize('\\\\wsl.localhost\\Ubuntu\\home'));
    }

    public function test_trait_linux_path_unchanged(): void
    {
        $s = $this->traitSubject();
        $this->assertSame('/var/www/app', $s->normalize('/var/www/app'));
    }

    public function test_trait_windows_drive_backslash_converted(): void
    {
        $s = $this->traitSubject();
        $this->assertSame('C:/work/app/storage', $s->normalize('C:\\work\\app\\storage'));
    }

    public function test_trait_duplicate_slashes_collapsed_for_non_unc(): void
    {
        $s = $this->traitSubject();
        $this->assertSame('/var/www/app', $s->normalize('/var///www//app'));
    }
}
