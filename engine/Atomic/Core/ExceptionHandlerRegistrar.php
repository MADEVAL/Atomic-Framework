<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Theme\Assets;
use Engine\Atomic\App\Error as ErrorController;

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
                    die('Fatal error: too many error handler recursions');
                }
                $atomic->set('ERROR.recursion_counter', $recursionCounter + 1);

                $status = (string)$atomic->get('ERROR.status');
                $code   = (int)$atomic->get('ERROR.code');
                $text   = (string)$atomic->get('ERROR.text');
                $trace  = $atomic->get('ERROR.trace');
                $level  = (string)$atomic->get('ERROR.level');

                try {
                    $textTrace = ErrorHandler::instance()->formatTrace($code, $text, $trace);
                    $atomic->set('ERROR.formatted_trace', $textTrace);
                } catch (\Throwable $e) {
                    $atomic->set('ERROR.formatted_trace', 'Error formatting trace: ' . $e->getMessage());
                }

                $dumpPath = Log::dumpHive();
                $dumpId   = $dumpPath ? basename($dumpPath, '.json') : null;
                if ($dumpId) {
                    $atomic->set('ERROR.dump_id', $dumpId);
                    $atomic->set('ERROR.dump_path', $dumpPath);
                }

                $msg = '[ONERROR][' . $code . '][' . $level . '][' . $status . '][' . $text . ']';
                if ($dumpId) $msg .= '[dump_id:' . $dumpId . ']';
                Log::debug($msg);

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
                    if ($code === 500) {
                        $ctrl = new ErrorController();
                        Assets::instance()->addInlineStyle('atomic-error', ':root { --primary-color: #f44336; --secondary-color: #d32f2f;}');
                        $ctrl->error500($atomic);
                        if ($prevDebug >= 3) $atomic->set('DEBUG', $prevDebug);
                        return;
                    } elseif (in_array($code, $errorRoutes, true)) {
                        $atomic->reroute('/error/' . $code);
                        return;
                    }
                } else {
                    echo $msg . PHP_EOL;
                    return;
                }

                if ($prevDebug >= 3) $atomic->set('DEBUG', $prevDebug);
                echo $text;
            } catch (\Throwable $e) {
                http_response_code(500);
                die('Critical error in exception handler: ' . $e->getMessage());
            }
        });
    }
}
