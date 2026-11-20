<?php
declare(strict_types=1);

namespace Tests\Integration\Http;

use Engine\Atomic\App\Telemetry;
use PHPUnit\Framework\TestCase;

/**
 * The Telemetry dashboard ships as the builtin Telemetry theme, rendered on
 * the server: a typed template per tab (views/), one small script that swaps
 * tabs and filters rows, and every JSON endpoint inside the telemetry access
 * middleware block.
 */
final class TelemetryThemeContractTest extends TestCase
{
    private const THEME_DIR = '/engine/Atomic/Theme/Builtin/Telemetry/';
    private const ROUTES_FILE = '/engine/Atomic/Core/Routes/telemetry.php';

    private function repo_path(string $path): string
    {
        return dirname(__DIR__, 3) . $path;
    }

    private function file(string $path): string
    {
        return (string)file_get_contents($this->repo_path($path));
    }

    public function test_shell_ships_all_thirteen_tabs_and_each_has_a_template(): void
    {
        $shell = $this->file(self::THEME_DIR . 'views/shell.php');

        self::assertCount(13, Telemetry::TABS);
        foreach (Telemetry::TABS as $tab) {
            self::assertStringContainsString("['{$tab}', ", $shell, "missing sidebar tab {$tab}");
            self::assertFileExists($this->repo_path(self::THEME_DIR . "views/{$tab}.php"), "missing template for {$tab}");
        }
        self::assertStringContainsString('get_head()', $shell);
        self::assertStringContainsString('get_footer()', $shell);
    }

    public function test_the_single_page_app_is_gone(): void
    {
        foreach (['assets/js/core.js', 'assets/js/ui.js', 'assets/js/telemetry.js', 'assets/js/tabs', 'layout/telemetry-queue.atom.php', 'views/pending.php'] as $old) {
            self::assertFileDoesNotExist($this->repo_path(self::THEME_DIR . $old));
        }
        self::assertStringNotContainsString('TELEMETRY_JOBS', $this->file('/engine/Atomic/App/Telemetry.php'));
        self::assertStringNotContainsString('ui=ssr', $this->file('/engine/Atomic/App/Telemetry.php'));
    }

    public function test_theme_enqueues_the_dependency_free_assets(): void
    {
        $functions = $this->file(self::THEME_DIR . 'functions.atom.php');

        foreach (['tokens', 'base', 'layout', 'components', 'helpers', 'pages', 'server'] as $css) {
            self::assertStringContainsString("~assets/css/{$css}.css", $functions);
            self::assertFileExists($this->repo_path(self::THEME_DIR . "assets/css/{$css}.css"));
        }
        // The controller localizes its config onto the 'telemetry' handle.
        self::assertStringContainsString("enqueue_script('telemetry', '~assets/js/server.js'", $functions);
        self::assertFileExists($this->repo_path(self::THEME_DIR . 'assets/js/server.js'));
        self::assertStringNotContainsString('w3.css', $functions);
    }

    public function test_styles_take_raw_colors_only_from_tokens(): void
    {
        foreach (['base', 'layout', 'components', 'helpers', 'pages', 'server'] as $css) {
            $source = $this->file(self::THEME_DIR . "assets/css/{$css}.css");
            self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|rgba?\(/', $source, "{$css}.css has a raw color; add a token");
        }
    }

    public function test_script_builds_no_html_from_strings(): void
    {
        $js = $this->file(self::THEME_DIR . 'assets/js/server.js');

        self::assertStringContainsString('window.TELEMETRY_CONFIG', $js);
        // Markup only ever comes from the server; the script never concatenates HTML.
        self::assertDoesNotMatchRegularExpression('/innerHTML\s*=\s*[\'"`]|insertAdjacentHTML|document\.write/', $js);
    }

    public function test_all_endpoints_are_registered_behind_telemetry_access(): void
    {
        $routes = $this->file(self::ROUTES_FILE);

        foreach ([
            '/telemetry/view/@tab' => '->fragment',
            '/telemetry/part/@part' => '->part',
            '/telemetry/jobs' => '->jobs',
            '/telemetry/summary' => '->summary',
            '/telemetry/schedule' => '->schedule',
            '/telemetry/migrations' => '->migrations',
            '/telemetry/migration-source' => '->migration_source',
            '/telemetry/plugins' => '->plugins',
            '/telemetry/mutex' => '->mutex',
            '/telemetry/database' => '->database',
            '/telemetry/rate-limits' => '->rate_limits',
            '/telemetry/quota' => '->quota',
            '/telemetry/exceptions' => '->exceptions',
            '/telemetry/security' => '->security',
        ] as $path => $action) {
            self::assertStringContainsString("{$path}', 'Engine\\Atomic\\App\\Telemetry{$action}'", $routes);
        }

        // Every route line carries the shared access middleware variable.
        foreach (preg_split('/\r?\n/', $routes) ?: [] as $line) {
            if (str_contains($line, '/telemetry')) {
                self::assertStringContainsString('$telemetry_access', $line);
            }
        }
    }

    public function test_controller_injects_config_and_masks_every_printed_text(): void
    {
        $controller = $this->file('/engine/Atomic/App/Telemetry.php');

        self::assertStringContainsString("localize_script('telemetry', \$config + ['history' => self::HISTORY_TABS], 'TELEMETRY_CONFIG')", $controller);
        self::assertStringContainsString('Html::filtered(self::mask(...)', $controller);
        // Operator views keep IPs, users and keys visible; only secrets are masked.
        self::assertDoesNotMatchRegularExpression('/Redactor::(redact|redact_string)\(/', $controller);
    }

    public function test_client_config_base_follows_app_base_path(): void
    {
        $atomic = \Base::instance();
        $original = $atomic->get('BASE');
        try {
            $atomic->set('BASE', '');
            self::assertSame('/telemetry', Telemetry::client_config($atomic)['base']);
            $atomic->set('BASE', '/shop/');
            self::assertSame('/shop/telemetry', Telemetry::client_config($atomic)['base']);
        } finally {
            $atomic->set('BASE', $original);
        }
    }

    public function test_client_config_flags_unprotected_access_mode(): void
    {
        $atomic = \Base::instance();
        $original = $atomic->get('TELEMETRY_ACCESS_MODE');
        try {
            foreach (['none' => true, '' => true, 'bogus' => true, ' NONE ' => true, 'auth' => false, 'config' => false] as $mode => $public) {
                $atomic->set('TELEMETRY_ACCESS_MODE', $mode);
                self::assertSame($public, Telemetry::client_config($atomic)['publicAccess'], "mode '{$mode}'");
            }
        } finally {
            $atomic->set('TELEMETRY_ACCESS_MODE', $original);
        }
    }

    public function test_shell_renders_public_access_banner(): void
    {
        $shell = $this->file(self::THEME_DIR . 'views/shell.php');

        self::assertStringContainsString('$shell->public_access', $shell);
        self::assertStringContainsString('TELEMETRY_ACCESS_MODE=auth', $shell);
    }

    public function test_shell_renders_missing_stats_tables_notice(): void
    {
        $shell = $this->file(self::THEME_DIR . 'views/shell.php');
        $controller = $this->file('/engine/Atomic/App/Telemetry.php');

        self::assertStringContainsString('$shell->stats_missing', $shell);
        self::assertStringContainsString('migrations/migrate', $shell);
        self::assertStringContainsString('StatsConnection::needs_migration()', $controller);
    }

    public function test_shell_states_the_configured_retention(): void
    {
        $shell = $this->file(self::THEME_DIR . 'views/shell.php');
        $controller = $this->file('/engine/Atomic/App/Telemetry.php');

        self::assertStringContainsString('$shell->retention_days', $shell);
        self::assertStringNotContainsString('kept for 30 days', $shell);
        self::assertStringContainsString('StatsConnection::retention_days()', $controller);
    }
}
