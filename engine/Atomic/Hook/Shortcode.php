<?php
declare(strict_types=1);
namespace Engine\Atomic\Hook;

if (!defined('ATOMIC_START')) exit;

final class Shortcode
{
    private static ?self $instance = null;
    private array $handlers = []; 

    public static function instance(): self 
    {
        return self::$instance ??= new self();
    }

    public function add_shortcode(string $tag, callable $callback): void 
    {
        $this->handlers[$tag] = $callback; 
    }

    public function remove_shortcode(string $tag): void 
    {
        unset($this->handlers[$tag]); 
    }

    public function do_shortcode(string $text): string 
    {
        if (!$this->handlers || $text === '') return $text; 
        $tagList = implode('|', array_map('preg_quote', array_keys($this->handlers))); 
        $pattern = '/\[(?:(' . $tagList . '))(?:\s+([^\]]*?))?\](?:([\s\S]*?)\[\/\1\])?/u'; //TODO test
        return preg_replace_callback($pattern, function($match) {
            $full = $match[0];
            $tag = $match[1];
            $atts = $this->parseAtts($match[2] ?? '');
            $content = $match[3] ?? null;
            $callback = $this->handlers[$tag] ?? null; 
            if (!$callback) return $full;
            return (string)($callback)($atts, $content);
        }, $text);
    }

    private function parseAtts(string $attrString): array 
    {
        $out = [];
        if ($attrString === '') return $out;
        $attrPattern = '/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))|(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/u'; // TODO test
        if (preg_match_all($attrPattern, $attrString, $matches, PREG_SET_ORDER)) { 
            foreach ($matches as $match) { 
                $attrName           = $match[1];

                $quot_double        = $match[2];
                $quot_single        = $match[3];
                $quot_no            = $match[4];

                $quot_double_alt    = $match[5];
                $quot_single_alt    = $match[6];
                $quot_no_alt        = $match[7];                

                if ($attrName !== '') {
                    $out[$attrName] = $quot_double !== '' ? $quot_double : ($quot_single !== '' ? $quot_single : $quot_no);
                } else {
                    $out[] = $quot_double_alt !== '' ? $quot_double_alt : ($quot_single_alt !== '' ? $quot_single_alt : $quot_no_alt);
                }
            }
        }
        return $out;
    }
}
