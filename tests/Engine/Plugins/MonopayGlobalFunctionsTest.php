<?php
declare(strict_types=1);

namespace Tests\Engine\Plugins;

use PHPUnit\Framework\TestCase;

final class MonopayGlobalFunctionsTest extends TestCase
{
    public function test_plugin_registration_does_not_fatal_when_loading_global_helpers(): void
    {
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'atomic_monopay_register_' . bin2hex(random_bytes(6)) . '.php';
        $contents = sprintf(
            <<<'PHP'
<?php
declare(strict_types=1);
define('ATOMIC_START', microtime(true));
require %s;
$plugin = new \Engine\Atomic\Plugins\Monopay\Monopay();
$plugin->register();
echo 'REGISTERED';
PHP,
            var_export(ATOMIC_VENDOR . 'autoload.php', true)
        );

        $written = file_put_contents($script, $contents);
        if ($written === false) {
            $this->fail('Could not create the Monopay registration probe.');
        }

        $output = [];
        $exit_code = -1;
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exit_code);
        } finally {
            @unlink($script);
        }

        $output_text = implode(PHP_EOL, $output);
        $this->assertSame(0, $exit_code, 'Monopay registration probe failed: ' . $output_text);
        $this->assertStringContainsString('REGISTERED', $output_text);
    }
}
