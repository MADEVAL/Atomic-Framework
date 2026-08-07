<?php

declare(strict_types=1);

namespace Engine\Atomic\View;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Http\Response;

final class ViewRenderer
{
    public function __construct(
        private readonly string $templatePath,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templatePath . '/' . $template . '.atom.php';

        if (!file_exists($file)) {
            return '';
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }

    /** @param array<string, mixed> $data */
    public function renderToResponse(string $template, array $data = [], int $status = 200): Response
    {
        $body = $this->render($template, $data);
        return Response::html($body, $status);
    }

    /** @param array<string, mixed> $data */
    public function make(string $template, array $data = []): View
    {
        return new View($this, $template, $data);
    }
}

final class View
{
    private array $data;

    public function __construct(
        private readonly ViewRenderer $renderer,
        private readonly string $template,
        array $data = [],
    ) {
        $this->data = $data;
    }

    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function render(): string
    {
        return $this->renderer->render($this->template, $this->data);
    }

    public function toResponse(int $status = 200): Response
    {
        return $this->renderer->renderToResponse($this->template, $this->data, $status);
    }
}