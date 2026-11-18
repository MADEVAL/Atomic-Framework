<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Theme\Assets;
use Engine\Atomic\App\Error as ErrorController;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Enums\LogChannel;
use Engine\Atomic\Telemetry\Stats\ExceptionRecorder;

class ExceptionHandlerRegistrar
{
    public static function register(\Base $atomic): void
    {
        $atomic->set('ONERROR', function (\Base $atomic) {
            try {
                $prevDebug = (int)$atomic->get('DEBUG');
                if ($prevDebug >= 3) $atomic->set('DEBUG', 2);

                $recursionCounter = (int)$atomic->get('ERROR.recursion_counter');
                if ($recursionCounter > 2) {
                    http_response_code(500);
                    $msg = '<h1>500 Internal Server Error</h1><p>A fatal error occurred. Please try again later.</p>';
                    if ((int)$atomic->get('DEBUG') > 0) {
                        $msg .= '<p><small>Recursion limit reached in error handler.</small></p>';
                    }
                    echo $msg;
                    return;
                }
                $atomic->set('ERROR.recursion_counter', $recursionCounter + 1);

                $status = (string)$atomic->get('ERROR.status');
                $code   = (int)$atomic->get('ERROR.code');
                $text   = (string)$atomic->get('ERROR.text');
                $trace  = $atomic->get('ERROR.trace');
                $level  = (string)$atomic->get('ERROR.level');

                $textTrace = '';
                try {
                    $textTrace = ErrorHandler::instance()->format_trace($code, $text, $trace);
                    $atomic->set('ERROR.formatted_trace', $textTrace);
                } catch (\Throwable $e) {
                    $detail = $prevDebug > 0
                        ? $e->getMessage() . ' | trace type: ' . gettype($trace)
                        : $e->getMessage();
                    $textTrace = 'Error formatting trace: ' . $detail;
                    $atomic->set('ERROR.formatted_trace', $textTrace);
                }

                $dumpPath = Log::dump_hive();
                $dumpId   = $dumpPath ? basename($dumpPath, '.json') : null;
                if ($dumpId) {
                    $atomic->set('ERROR.dump_id', $dumpId);
                    $atomic->set('ERROR.dump_path', $dumpPath);
                }

                $msg = '[ONERROR][' . $code . '][' . $level . '][' . $status . '][' . $text . ']';
                if ($dumpId) $msg .= '[dump_id:' . $dumpId . ']';
                Log::channel(LogChannel::ERROR)->error($msg);

                self::record_exception_stats($atomic, [
                    'code'    => $code,
                    'text'    => $text,
                    'level'   => $level,
                    'path'    => (string)$atomic->get('PATH'),
                    'dump_id' => $dumpId,
                ]);

                $currentPath = (string)$atomic->get('PATH');
                if (preg_match('#^/error/\d+#', $currentPath)) {
                    if ($prevDebug >= 3) $atomic->set('DEBUG', $prevDebug);
                    return;
                }

                $errorRoutes = [];
                $routes = $atomic->get('ROUTES');
                if (is_array($routes)) {
                    foreach ($routes as $pattern => $data) {
                        if (preg_match('#/error/(\d+)#', $pattern, $m)) {
                            $errorRoutes[] = (int)$m[1];
                        }
                    }
                }

                if (empty($atomic->CLI)) {
                    $isApiOrAjax = (
                        $atomic->get('AJAX') ||
                        str_starts_with((string)$atomic->get('PATH'), '/api/') ||
                        str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
                    );

                    if ($isApiOrAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code($code);
                        echo json_encode([
                            'error' => [
                                'status' => $status,
                                'code'   => $code,
                                'text'   => $prevDebug > 0 ? $text : 'Internal Server Error',
                                'trace'  => $prevDebug > 0 ? explode("\n", $textTrace) : null
                            ]
                        ]);
                        if ($prevDebug >= 3) $atomic->set('DEBUG', $prevDebug);
                        return;
                    }

                    if ($code === 500) {
                        $ctrl = new ErrorController();
                        Assets::instance()->add_inline_style('atomic-error', ':root { --primary-color: #f44336; --secondary-color: #d32f2f;}');
                        $ctrl->error500($atomic);
                        if ($prevDebug >= 3) $atomic->set('DEBUG', $prevDebug);
                        return;
                    } elseif (in_array($code, $errorRoutes, true)) {
                        $ctrl   = new ErrorController();
                        $method = 'error' . $code;
                        if (method_exists($ctrl, $method)) {
                            $ctrl->{$method}($atomic);
                        }
                        if ($prevDebug >= 3) $atomic->set('DEBUG', $prevDebug);
                        return;
                    }
                } else {
                    (new Output())->err($msg);
                    return;
                }

                if ($prevDebug >= 3) $atomic->set('DEBUG', $prevDebug);
                $atomic->clear('ERROR.recursion_counter');
                echo $text;
            } catch (\Throwable $e) {
                Log::channel(LogChannel::ERROR)->critical('Critical error in exception handler: ' . $e->getMessage());
                http_response_code(500);
                echo '<h1>500 Internal Server Error</h1>';
                return;
            }
        });
    }

    /**
     * A broken stats store must never affect error handling.
     *
     * @param array{code:int, text:string, level:string, path:string, dump_id:?string} $error
     */
    private static function record_exception_stats(\Base $atomic, array $error): void
    {
        try {
            $throwable = $atomic->get('EXCEPTION');
            if (!$throwable instanceof \Throwable) {
                $throwable = null;
            }

            $file = null;
            $line = null;
            if ($throwable !== null) {
                $file = (string)$throwable->getFile();
                $line = (int)$throwable->getLine();
            } else {
                $trace = $atomic->get('ERROR.trace');
                if (is_array($trace) && isset($trace[0])) {
                    $frame = is_array($trace[0]) ? $trace[0] : [];
                    $file = isset($frame['file']) ? (string)$frame['file'] : null;
                    $line = isset($frame['line']) ? (int)$frame['line'] : null;
                }
            }

            ExceptionRecorder::capture([
                'class'   => $throwable !== null ? get_class($throwable) : 'PHP Error',
                'message' => $throwable !== null ? $throwable->getMessage() : $error['text'],
                'file'    => $file,
                'line'    => $line,
                'code'    => $error['code'],
                'level'   => strtolower($error['level'] !== '' ? $error['level'] : 'error'),
                'path'    => $error['path'],
                'dump_id' => $error['dump_id'],
            ]);
        } catch (\Throwable $e) {
            Log::debug('[Stats] exception stats recording failed: ' . $e->getMessage());
        }
    }
}
