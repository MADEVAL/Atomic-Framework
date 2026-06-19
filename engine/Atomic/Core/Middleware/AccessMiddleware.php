<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\ConfigUserProvider;
use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\Request;
use Engine\Atomic\Core\Response;

final class AccessMiddleware implements MiddlewareInterface
{
    public function __construct(private ?string $guard = null) {}

    public function handle(\Base $atomic): bool
    {
        $guard = $this->guard();
        $auth = Auth::instance();
        if (!$auth->has_user_provider()) {
            $auth->set_user_provider(new ConfigUserProvider($guard));
        }

        if (Guard::is_authenticated()) {
            return true;
        }

        if (strtoupper((string)$atomic->get('VERB')) === 'POST') {
            $username = trim((string)$atomic->get('POST.username'));
            $secret = (string)($atomic->get('POST.key') ?: $atomic->get('POST.password') ?: $atomic->get('POST.secret'));

            if ($username !== '' && $secret !== '') {
                $user = $auth->login_with_secret([
                    'username' => $username,
                    'guard'    => $guard,
                ], $secret);

                if ($user !== null) {
                    $response = Response::instance();
                    $response->redirect($this->safe_redirect($atomic), Response::STATUS_SEE_OTHER, false);
                    return false;
                }
            }

            return $this->deny($atomic, 'Invalid username or key.');
        }

        return $this->deny($atomic);
    }

    private function guard(): string
    {
        $guard = trim((string)$this->guard);
        return $guard !== '' ? $guard : 'dashboard';
    }

    private function deny(\Base $atomic, string $error = ''): bool
    {
        $request = Request::instance();
        $response = Response::instance();
        if ($request->wants_json($atomic)) {
            $response->send_json_error('Unauthorized', Response::STATUS_UNAUTHORIZED, [], false);
            return false;
        }

        $title = ucfirst($this->guard()) . ' Access';
        $redirect = $this->current_url($atomic);
        $html = $this->form($title, $redirect, $error);
        $response->send_html($html, Response::STATUS_UNAUTHORIZED, false);
        return false;
    }

    private function safe_redirect(\Base $atomic): string
    {
        $redirect = (string)$atomic->get('POST.redirect');
        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            return $redirect;
        }

        return $this->current_url($atomic);
    }

    private function current_url(\Base $atomic): string
    {
        $path = (string)($atomic->get('PATH') ?: $atomic->get('URI') ?: '/');
        $query = (string)$atomic->get('QUERY');
        return $path . ($query !== '' ? '?' . $query : '');
    }

    private function form(string $title, string $redirect, string $error): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $redirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
        $error_html = $error !== ''
            ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f7f9;color:#1f2937;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
form{width:min(100% - 32px,360px);background:#fff;border:1px solid #d9dee7;border-radius:8px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.08)}
h1{font-size:20px;line-height:1.2;margin:0 0 20px}
label{display:block;font-size:13px;font-weight:600;margin:14px 0 6px}
input{box-sizing:border-box;width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:10px 12px;font:inherit}
button{width:100%;margin-top:18px;border:0;border-radius:6px;background:#111827;color:#fff;padding:10px 12px;font:inherit;font-weight:600;cursor:pointer}
.error{border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:6px;padding:10px 12px;font-size:14px;margin-bottom:14px}
</style>
</head>
<body>
<form method="post">
<h1>{$title}</h1>
{$error_html}
<input type="hidden" name="redirect" value="{$redirect}">
<label for="access-username">Username</label>
<input id="access-username" name="username" autocomplete="username" required autofocus>
<label for="access-key">Key</label>
<input id="access-key" name="key" type="password" autocomplete="current-password" required>
<button type="submit">Sign in</button>
</form>
</body>
</html>
HTML;
    }
}
