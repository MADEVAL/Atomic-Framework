<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\CLI\CLI as AtomicCLI;
use Engine\Atomic\Lang\I18n;

class Methods {

    protected App $atomic;
    private static ?self $instance = null;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }   

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function get_public_url(): string 
    {
        return $this->atomic->get('DOMAIN');
    }

    public function get_user_language(): string 
    {
        return $this->atomic->get('LANGUAGE');
    }
 
    public function get_user_agent(): string 
    {
        return $this->atomic->get('AGENT');
    }
    
    public function get_user_ip(): string 
    {
        return $this->atomic->get('IP');
    }

    public function get_current_url(): string 
    {
        return $this->atomic->get('REALM');
    }

    public function get_current_route(): string 
    {
        return $this->atomic->get('PATTERN');
    }

    public function get_current_method(): string 
    {
        return $this->atomic->get('VERB');
    }

    public function get_is_ajax(): bool 
    {
        return (bool)$this->atomic->get('AJAX');
    }

    public function get_is_secure(): bool 
    {
        return $this->atomic->get('SCHEME') === 'https';
    }

    public function get_is_debug(): bool 
    {
        $debug = $this->atomic->get('DEBUG');
        return $debug > 0;
    }

    public function get_public_dir(): string 
    {
        return $this->atomic->get('ROOT');
    }

    public function get_format_error_trace(): string 
    {
        return $this->atomic->get('ERROR.formatted_trace');
    }

    public function get_encoding(): string 
    {
        return $this->atomic->get('ENCODING') ?? 'UTF-8';
    }

    public function is_mobile(): bool 
    {
        $device = $this->get_user_device();
        return str_contains($device, 'phone') || str_contains($device, 'tab');
    }

    public function is_404(): bool 
    {
        return $this->atomic->get('ERROR.code') === 404;
    }

    public function is_telegram(): bool 
    {
        $ua = $this->get_user_agent();
        return str_contains($ua, 'TelegramBot');
    }

    public function is_botblocker(): bool 
    {
        $ua = $this->get_user_agent();
        return str_contains($ua, 'BotBlocker/Crawler');
    }

    public function is_gs(): bool 
    {
        $ua = $this->get_user_agent();
        return str_contains($ua, 'GLOBUS.studio/Crawler');
    }

    public function get_user_device(bool $accuracy = false): string
    {
        if (AtomicCLI::is_cli()) return 'pc';
        $ua = (string)($this->get_user_agent() ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $srv = $_SERVER ?? [];
        if (class_exists('\Detection\MobileDetect')) {
            try {
                $detect = new \Detection\MobileDetect();
                if ($ua !== '') {
                    if (method_exists($detect, 'setUserAgent')) $detect->setUserAgent($ua);
                }
                if (method_exists($detect, 'is') && ($detect->is('TV') || $detect->is('SmartTV'))) return 'tv';
                if ($detect->isTablet()) return 'tab';
                if ($detect->isMobile()) return 'phone';
                return 'pc';
            } catch (\Throwable $e) {
                return ($accuracy) ? $this->device_from_ua($ua, $srv) . '_maybe' : $this->device_from_ua($ua, $srv);
            }
        }
        return ($accuracy) ? $this->device_from_ua($ua, $srv) . '_maybe' : $this->device_from_ua($ua, $srv);
    }

    private function device_from_ua(string $ua, array $srv): string
    {
        $ua = strtolower($ua);
        if ($ua === '') return 'pc';

        if (preg_match('/smarttv|googletv|appletv|tizen|web0s|webos|hbbtv|netcast|viera|aquos|bravia|roku|crkey|chromecast|aft\w+|firetv|mitv|dtv|ce-html|inettv/i', $ua)) {
            return 'tv';
        }

        if (preg_match('/ipad|tablet|playbook|silk|kindle(?!.*fire)|nexus\s*(7|9|10)|sm\-t|gt\-p|galaxy\s?tab|xoom|lenovo\s?tab|mi\s?pad|tab(?!let pc)/i', $ua)) {
            return 'tab';
        }

        if (!empty($srv['HTTP_X_WAP_PROFILE']) || !empty($srv['HTTP_PROFILE'])) {
            return 'phone';
        }
        if (!empty($srv['HTTP_ACCEPT']) && preg_match('/wap|vnd\.wap|wml/i', $srv['HTTP_ACCEPT'])) {
            return 'phone';
        }

        if (preg_match('/mobi|iphone|ipod|blackberry|iemobile|windows phone|opera mini|opera mobi|android.*mobile|palmos|fennec|maemo|symbian|nokia|htc|zte|huawei|oneplus|pixel/i', $ua)) {
            return 'phone';
        }

        return 'pc';
    } 
    
    public function list_routes(): array
    {
        $routes = $this->atomic->get('ROUTES');
        $base = rtrim($this->get_public_url() ?? '', '/');
        $groups = [
            'WEB/CLI'   => [],
            'WEB ERROR' => [],
            'API'       => []
        ];

        if (is_array($routes)) {
            foreach ($routes as $pattern => $routeList) {
                $patternStr = (string)$pattern;

                if (preg_match('/\s+(\/\S*)$/', $patternStr, $m)) {
                    $path = $m[1];
                } else {
                    $path = $patternStr;
                }

                $href = $base . '/' . ltrim($path, '/');
                $routeData = [
                    'pattern' => $patternStr,
                    'href' => $href
                ];

                if (stripos($patternStr, '/error/') !== false) {
                    $groups['WEB ERROR'][] = $routeData;
                } elseif (stripos($patternStr, '/api/') !== false) {
                    $groups['API'][] = $routeData;
                } else {
                    $groups['WEB/CLI'][] = $routeData;
                }
            }
        }

        return $groups;
    }

    public function get_current_path(bool $strip_lang = true): string
    {
        $path = (string)($this->atomic->get('PATH') ?? '/');
        $path = '/'.ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) $path = rtrim($path, '/');
        return $strip_lang ? I18n::instance()->strip_path_lang_prefix($path) : $path;
    }

    public function is_home(): bool
    {
        return $this->get_current_path(true) === '/';
    }

    public function is_page(string|array $patterns, bool $strip_lang = true): bool
    {
        $current = $this->get_current_path($strip_lang);
        foreach ((array)$patterns as $pat) {
            if ($this->match_path($current, (string)$pat)) return true;
        }
        return false;
    }

    public function is_section(string $prefix, bool $strip_lang = true): bool
    {
        $prefix = '/'.trim($prefix, '/');
        $cur = $this->get_current_path($strip_lang);
        return $cur === $prefix || str_starts_with($cur, $prefix.'/');
    }

    public function segments(bool $strip_lang = true): array 
    {
        $p = trim($this->get_current_path($strip_lang), '/');
        return $p === '' ? [] : explode('/', $p);
    }

    public function segment(int $index, ?string $default = null, bool $strip_lang = true): ?string
    {
        $segments = $this->segments($strip_lang);
        return $segments[$index] ?? $default;
    }

    private function match_path(string $current, string $pattern): bool
    {
        $current = $current === '' ? '/' : $current;
        if ($pattern === '' || $pattern === null) return false;
        if ($pattern[0] !== '/') $pattern = '/'.ltrim($pattern, '/');
        if ($pattern === '*' || $pattern === '/*') return true;
        if (str_ends_with($pattern, '/*')) {
            $prefix = rtrim(substr($pattern, 0, -2), '/');
            return $current === $prefix || str_starts_with($current, $prefix.'/');
        }
        if (str_contains($pattern, '*')) {
            $rx = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#u';
            return (bool)preg_match($rx, $current);
        }
        return rtrim($current, '/') === rtrim($pattern, '/');
    }    
    
}