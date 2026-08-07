<?php

declare(strict_types=1);

namespace Tests\Engine\View;

use Engine\Atomic\View\ViewRenderer;
use Engine\Atomic\Http\Response;
use PHPUnit\Framework\TestCase;

final class ViewRendererTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/atomic_view_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_render_returns_string(): void
    {
        file_put_contents($this->tmpDir . '/home.atom.php', '<h1>Hello</h1>');

        $renderer = new ViewRenderer($this->tmpDir);
        $result = $renderer->render('home');

        $this->assertStringContainsString('<h1>Hello</h1>', $result);
    }

    public function test_render_passes_data_to_template(): void
    {
        file_put_contents($this->tmpDir . '/user.atom.php', '<?=$name?>');

        $renderer = new ViewRenderer($this->tmpDir);
        $result = $renderer->render('user', ['name' => 'John']);

        $this->assertSame('John', $result);
    }

    public function test_render_to_response_returns_response(): void
    {
        file_put_contents($this->tmpDir . '/page.atom.php', 'Page');

        $renderer = new ViewRenderer($this->tmpDir);
        $response = $renderer->renderToResponse('page', [], 200);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->status());
        $this->assertSame('Page', $response->body());
    }

    public function test_make_returns_view_object(): void
    {
        file_put_contents($this->tmpDir . '/test.atom.php', 'data');

        $renderer = new ViewRenderer($this->tmpDir);
        $view = $renderer->make('test', ['key' => 'val']);

        $this->assertStringContainsString('data', $view->render());
    }

    public function test_view_with_adds_data(): void
    {
        file_put_contents($this->tmpDir . '/test.atom.php', '<?=$x?><?=$y?>');

        $renderer = new ViewRenderer($this->tmpDir);
        $view = $renderer->make('test', ['x' => 'a'])->with('y', 'b');

        $this->assertSame('ab', $view->render());
    }

    public function test_view_to_response(): void
    {
        file_put_contents($this->tmpDir . '/test.atom.php', 'ok');

        $renderer = new ViewRenderer($this->tmpDir);
        $response = $renderer->make('test')->toResponse(201);

        $this->assertSame(201, $response->status());
        $this->assertSame('ok', $response->body());
    }

    private function removeDir(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
