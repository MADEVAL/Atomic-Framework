<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\View;

if (!defined('ATOMIC_START')) exit;

/**
 * A template returns a closure typed with what it needs, so a wrong argument is a
 * TypeError instead of an empty page. A theme can override any template.
 */
final class Templates
{
    public function __construct(private readonly string $directory) {}

    public function exists(string $name): bool
    {
        return self::valid_name($name) && is_file($this->path($name));
    }

    public function render(string $name, mixed ...$args): Html
    {
        if (!self::valid_name($name)) {
            throw new \InvalidArgumentException('Invalid template name: ' . $name);
        }
        $template = (static fn (string $file): mixed => require $file)($this->path($name));
        if (!$template instanceof \Closure) {
            throw new \UnexpectedValueException('Template ' . $name . ' must return a closure.');
        }

        ob_start();
        try {
            $template(...$args);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return Html::raw((string)ob_get_clean());
    }

    private function path(string $name): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $name . '.php';
    }

    private static function valid_name(string $name): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9-]*$/', $name);
    }
}
