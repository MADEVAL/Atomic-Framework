<?php
declare(strict_types=1);

namespace Tests\Engine\Theme;

use Engine\Atomic\Core\App;
use Engine\Atomic\Theme\AssetController;
use PHPUnit\Framework\TestCase;

final class AssetControllerTest extends TestCase
{
    public function test_builtin_css_asset_is_served_from_internal_theme_directory(): void
    {
        $atomic = \Base::instance();
        $atomic->set('PARAMS', [
            'theme' => 'default',
            '*' => 'assets/css/atomic_core.css',
        ]);

        ob_start();
        (new AssetController())->serve($atomic);
        $content = (string)ob_get_clean();

        $this->assertStringContainsString('--atomic-primary', $content);
    }

    public function test_builtin_asset_is_served_through_f3_route(): void
    {
        $atomic = \Base::instance();
        $routes = $atomic->get('ROUTES');
        $response = $atomic->get('RESPONSE');

        try {
            $atomic->clear('ROUTES');
            $atomic->clear('RESPONSE');
            App::instance()->route(
                'GET /' . \Engine\Atomic\Theme\Theme::INTERNAL_URL_PREFIX . '/themes/@theme/*',
                'Engine\\Atomic\\Theme\\AssetController->serve'
            );

            $atomic->mock('GET /__atomic/themes/default/assets/css/atomic_core.css');

            $this->assertStringContainsString('--atomic-primary', (string)$atomic->get('RESPONSE'));
        } finally {
            $atomic->set('ROUTES', $routes);
            $atomic->set('RESPONSE', $response);
        }
    }

    public function test_asset_path_cannot_escape_builtin_theme_directory(): void
    {
        $atomic = \Base::instance();
        $atomic->set('PARAMS', [
            'theme' => 'default',
            '*' => '../theme.json',
        ]);

        ob_start();
        (new AssetController())->serve($atomic);
        $content = (string)ob_get_clean();

        $this->assertSame('Not Found', $content);
    }

    public function test_theme_source_files_are_not_served_by_the_asset_endpoint(): void
    {
        $atomic = \Base::instance();
        $atomic->set('PARAMS', [
            'theme' => 'default',
            '*' => 'theme.json',
        ]);

        ob_start();
        (new AssetController())->serve($atomic);
        $content = (string)ob_get_clean();

        $this->assertSame('Not Found', $content);
    }

    public function test_asset_with_unapproved_extension_is_not_served(): void
    {
        $file = ATOMIC_ENGINE . 'Atomic/Theme/Builtin/Shared/assets/private.txt';
        file_put_contents($file, 'private');

        try {
            $atomic = \Base::instance();
            $atomic->set('PARAMS', [
                'theme' => 'Shared',
                '*' => 'assets/private.txt',
            ]);

            ob_start();
            (new AssetController())->serve($atomic);
            $content = (string)ob_get_clean();

            $this->assertSame('Not Found', $content);
        } finally {
            @unlink($file);
        }
    }
}
