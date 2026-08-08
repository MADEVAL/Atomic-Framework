<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Config;

use Engine\Atomic\Core\Config\ConfigLoader;
use Engine\Atomic\Core\Config\V2\ConfigLoader as V2ConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * The legacy ConfigLoader::parse_env and V2\ConfigLoader::parseEnvFile must
 * produce identical results on the same .env file (П-20).
 */
final class ConfigParserParityTest extends TestCase
{
    public function test_parse_env_and_v2_parse_env_file_are_identical_on_fixture_env(): void
    {
        $envFile = ATOMIC_DIR . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . '.env';
        $this->assertFileExists($envFile);

        $legacyMethod = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
        $legacyResult = $legacyMethod->invoke(new ConfigLoader(\Base::instance()), $envFile);

        $v2Result = V2ConfigLoader::parseEnvFile($envFile);

        $this->assertSame(
            $legacyResult,
            $v2Result,
            'Legacy parse_env() and V2 parseEnvFile() must produce identical key/value pairs.'
        );
        $this->assertNotSame([], $v2Result, 'The fixture .env must contain parseable entries.');
    }

    public function test_both_parsers_handle_quotes_comments_and_blank_lines(): void
    {
        $envFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_parity_' . uniqid() . '.env';
        file_put_contents($envFile, implode("\n", [
            '# comment',
            '',
            'PLAIN=value',
            'QUOTED="double value"',
            'SINGLE=\'single value\'',
            'INLINE=keep # trailing comment',
            'SPACES =  spaced  ',
        ]));

        try {
            $legacyMethod = new \ReflectionMethod(ConfigLoader::class, 'parse_env');
            $legacyResult = $legacyMethod->invoke(new ConfigLoader(\Base::instance()), $envFile);

            $v2Result = V2ConfigLoader::parseEnvFile($envFile);

            $this->assertSame($legacyResult, $v2Result);
            $this->assertSame('double value', $v2Result['QUOTED'] ?? null);
            $this->assertSame('keep', $v2Result['INLINE'] ?? null);
            $this->assertSame('spaced', $v2Result['SPACES'] ?? null);
        } finally {
            @unlink($envFile);
        }
    }
}
