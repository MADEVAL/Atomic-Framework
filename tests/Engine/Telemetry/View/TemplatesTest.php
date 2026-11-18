<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic-templates-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/hello.php', '<?php return static function (\Engine\Atomic\Telemetry\View\Ui $ui, string $name): void { ?><b><?= $ui->e($name) ?></b><?php };');
        file_put_contents($this->dir . '/broken.php', '<?php return "not a closure";');
        file_put_contents($this->dir . '/throws.php', '<?php return static function (): void { echo "partial"; throw new \RuntimeException("boom"); };');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_renders_the_closure_with_its_arguments(): void
    {
        self::assertSame('<b>&lt;Ann&gt;</b>', (string)(new Templates($this->dir))->render('hello', new Ui(), '<Ann>'));
    }

    public function test_a_wrong_argument_is_a_type_error(): void
    {
        $this->expectException(\TypeError::class);
        (new Templates($this->dir))->render('hello', new Ui(), ['not a string']);
    }

    public function test_a_template_must_return_a_closure(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new Templates($this->dir))->render('broken');
    }

    public function test_names_cannot_leave_the_directory(): void
    {
        self::assertFalse((new Templates($this->dir))->exists('../hello'));
        $this->expectException(\InvalidArgumentException::class);
        (new Templates($this->dir))->render('../hello');
    }

    public function test_a_failing_template_discards_its_partial_output(): void
    {
        $level = ob_get_level();
        try {
            (new Templates($this->dir))->render('throws');
            self::fail('expected the template to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($level, ob_get_level());
    }
}
