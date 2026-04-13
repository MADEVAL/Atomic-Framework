<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Traits\Singleton;

class ErrorHandler
{
    use Singleton;

    protected App $atomic;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function formatTrace(int $code, string $text, string $trace): string
    {
        try {
            $output = $text . "\n\n";
            $output .= str_repeat('-', 80) . "\n\n";

            $lines = explode("\n", $trace);
            foreach ($lines as $line) {
                if (preg_match('/\[(.*?):(\d+)\]/', $line, $match)) {
                    $file = $match[1];
                    $lineNum = (int)$match[2];
                    
                    $output .= "File: " . $file . "\n";
                    $output .= "Line: " . $lineNum . "\n\n";
                    
                    if (is_file($file) && is_readable($file)) {
                        $rows = @file($file);
                        if ($rows) {
                            $start = max(0, $lineNum - 7);
                            $end = min(count($rows), $lineNum + 5);
                            
                            for ($i = $start; $i < $end; $i++) {
                                $num = str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT);
                                if ($i == $lineNum - 1) {
                                    $output .= ">>> " . $num . ' | ' . $rows[$i];
                                } else {
                                    $output .= "    " . $num . ' | ' . $rows[$i];
                                }
                            }
                            $output .= "\n";
                        }
                    }
                    $output .= str_repeat('-', 80) . "\n\n";
                }
            }

            return $output;
        } catch (\Throwable $e) {
            $detail = ((int)App::atomic()->get('DEBUG')) > 0
                ? ' (' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . ')'
                : '';
            return "Error formatting trace{$detail}\nOriginal error: [{$code}] {$text}";
        }
    }
}
