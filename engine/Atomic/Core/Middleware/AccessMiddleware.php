<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Auth\ConfigUserProvider;
use Engine\Atomic\Auth\LoginResult;
use Engine\Atomic\Auth\LoginStatus;
use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\Request;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Session\Session;

final class AccessMiddleware implements MiddlewareInterface
{
    public function __construct(private ?string $guard = null) {}

    public function handle(\Base $atomic): bool
    {
        $guard = $this->guard();
        $auth = Auth::instance();
        // Access guards authenticate against their own config-user store.
        // The application may already have registered its database provider,
        // which must not be reused for access:<guard> credentials.
        $auth->set_user_provider(new ConfigUserProvider($guard));

        if (Session::is_started() && Guard::is_authenticated()) {
            return true;
        }

        if (strtoupper((string)$atomic->get('VERB')) === 'POST') {
            $username = trim((string)$atomic->get('POST.username'));
            $secret = (string)($atomic->get('POST.key') ?: $atomic->get('POST.password') ?: $atomic->get('POST.secret'));

            $result = $this->attempt_login($auth, $guard, $username, $secret);
            if ($result?->status === LoginStatus::Success) {
                Response::instance()->redirect($this->safe_redirect($atomic), Response::STATUS_SEE_OTHER, false);
                return false;
            }

            return $this->deny($atomic, $this->failure($result));
        }

        return $this->deny($atomic, $this->failure(null, false));
    }

    private function attempt_login(Auth $auth, string $guard, string $username, string $secret): ?LoginResult
    {
        if ($username === '' || $secret === '') {
            return null;
        }

        return $auth->login_with_secret([
            'username' => $username,
            'guard'    => $guard,
        ], $secret);
    }

    /**
     * @return array{status: int, message: string, json: array<string, mixed>, headers: array<string, string>}
     */
    private function failure(?LoginResult $result, bool $attempted = true): array
    {
        if ($result?->status === LoginStatus::LockedOut) {
            $seconds = $result->retry_after_seconds;
            return [
                'status'  => Response::STATUS_TOO_MANY_REQUESTS,
                'message' => 'Too many login attempts. Try again in ' . $this->retry_delay($seconds) . '.',
                'json'    => ['error' => 'Too many login attempts', 'retry_after_seconds' => $seconds],
                'headers' => ['Retry-After' => (string)$seconds],
            ];
        }

        return [
            'status'  => Response::STATUS_UNAUTHORIZED,
            'message' => $attempted ? 'Invalid username or key.' : '',
            'json'    => ['error' => 'Unauthorized'],
            'headers' => [],
        ];
    }

    private function retry_delay(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ($seconds === 1 ? ' second' : ' seconds');
        }

        $minutes = (int)ceil($seconds / 60);
        return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
    }

    private function guard(): string
    {
        $guard = trim((string)$this->guard);
        return $guard !== '' ? $guard : 'dashboard';
    }

    /**
     * @param array{status: int, message: string, json: array<string, mixed>, headers: array<string, string>} $failure
     */
    private function deny(\Base $atomic, array $failure): bool
    {
        $request = Request::instance();
        $response = Response::instance();
        if (!headers_sent()) {
            foreach ($failure['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($request->wants_json($atomic)) {
            $response->json_response($failure['json'], $failure['status'], false);
            return false;
        }

        $title = ucfirst($this->guard()) . ' Access';
        $redirect = $this->current_url($atomic);
        $response->send_html($this->form($title, $redirect, $failure['message']), $failure['status'], false);
        return false;
    }

    private function safe_redirect(\Base $atomic): string
    {
        return $this->local_redirect((string)$atomic->get('POST.redirect'), $this->current_url($atomic));
    }

    private function local_redirect(string $redirect, string $fallback): string
    {
        foreach ([$redirect, $fallback] as $target) {
            if (str_starts_with($target, '/') && !str_starts_with($target, '//')
                && !str_contains($target, '\\') && !preg_match('/[\x00-\x1F\x7F]/', $target)) {
                return $target;
            }
        }

        return '/';
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

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        $guard = $this->guard();
        $auth = Auth::instance();
        $auth->set_user_provider(new ConfigUserProvider($guard));

        if (Session::is_started() && Guard::is_authenticated()) {
            return $next($request);
        }

        $title = ucfirst($guard) . ' Access';
        $redirect = $this->local_redirect('', $request instanceof \Engine\Atomic\Http\Request ? $request->path() : '/');
        $failure = $this->failure(null, false);
        if ($request instanceof \Engine\Atomic\Http\Request && $request->method() === 'POST') {
            $username = trim((string)($request->input('username') ?? ''));
            $secret = (string)($request->input('key') ?? $request->input('password') ?? $request->input('secret') ?? '');
            $result = $this->attempt_login($auth, $guard, $username, $secret);
            if ($result?->status === LoginStatus::Success) {
                $redirect = $this->local_redirect((string)$request->input('redirect', ''), $redirect);
                return \Engine\Atomic\Http\Response::redirect($redirect, 303);
            }
            $failure = $this->failure($result);
        }

        if ($request instanceof \Engine\Atomic\Http\Request && $request->expectsJson()) {
            return \Engine\Atomic\Http\Response::json($failure['json'], $failure['status'], $failure['headers']);
        }

        return \Engine\Atomic\Http\Response::html($this->form($title, $redirect, $failure['message']), $failure['status'], $failure['headers']);
    }
}
