<?php
declare(strict_types=1);

namespace Tests\Integration\Http;

use PHPUnit\Framework\TestCase;

final class DefaultThemeContentTest extends TestCase
{
    private function skeleton_path(string $path = ''): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton'
            . ($path === '' ? '' : DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    }

    public function test_default_theme_uses_utf8_feature_icons_and_a_local_brand_asset(): void
    {
        $home = (string)file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'engine/Atomic/Theme/Builtin/default/layout/home.atom.php');

        foreach (['⚡', '🎯', '🔌', '🗄️', '⚙️', '🎨', '🛡️', '🔧', '🔍', '📧', '🤖', '📄'] as $icon) {
            self::assertStringContainsString($icon, $home);
        }

        self::assertStringNotContainsString('вљЎ', $home);
        self::assertStringNotContainsString('рџ', $home);
        self::assertStringContainsString("get_framework_asset_uri('img/apple-touch-icon.png')", $home);
        self::assertFileExists(dirname(__DIR__, 3) . '/engine/Atomic/Theme/Builtin/Shared/assets/img/apple-touch-icon.png');
        self::assertFileDoesNotExist($this->skeleton_path('public/assets/img/apple-touch-icon.png'));

        $footer = (string)file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'engine/Atomic/Theme/Builtin/default/partials/footer.atom.php');
        self::assertStringContainsString('© 2025 Atomic Framework', $footer);
        self::assertStringNotContainsString('В©', $footer);
    }

    public function test_framework_manifest_and_brand_assets_live_in_core(): void
    {
        $manifestPath = dirname(__DIR__, 3) . '/engine/Atomic/Theme/Builtin/Shared/assets/site.webmanifest';
        self::assertFileExists($manifestPath);
        self::assertFileDoesNotExist($this->skeleton_path('public/site.webmanifest'));

        $manifest = (string)file_get_contents($manifestPath);
        self::assertStringContainsString('img/android-chrome-192x192.png', $manifest);
        self::assertStringContainsString('img/apple-touch-icon.png', $manifest);
    }

    public function test_error_theme_has_a_default_color_palette_for_direct_missing_urls(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'engine/Atomic/Theme/Builtin/ErrorPages/assets/css/atomic-errors.css');

        self::assertStringContainsString('--primary-color: #2196f3;', $css);
        self::assertStringContainsString('--secondary-color: #1976d2;', $css);
    }
}
