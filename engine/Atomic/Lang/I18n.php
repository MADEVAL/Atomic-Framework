<?php
declare(strict_types=1);
namespace Engine\Atomic\Lang;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\I18nDomain;
use Engine\Atomic\Theme\Theme;

final class I18n
{
    private static ?self $instance = null;

    private App $app;
    private array $supported;
    private string $default;
    private string $mode;
    private int $ttl;
    private string $cookie;
    private string $session;
    private string $current;
    private string $content;

    private array $domains = [];
    private array $pluralRules = [];

    private function __construct()
    {
        $this->app = App::instance();
        $cfg = (array)$this->app->get('i18n') ?: [];

        $this->supported = array_values($cfg['languages'] ?? ['en','ru']);
        $this->default   = (string)($cfg['default'] ?? $this->supported[0]);
        $this->mode      = (string)($cfg['url_mode'] ?? 'prefix'); // prefix|param|none
        $this->ttl       = (int)($cfg['ttl'] ?? 0);
        $this->cookie    = (string)($cfg['cookie'] ?? 'lang');
        $this->session   = (string)($cfg['session'] ?? 'lang');

        $this->current   = $this->detect();
        $this->content   = $this->current;

        $this->pluralRules = [
            'en' => fn(int $n): int => $n == 1 ? 0 : 1,
            'ru' => function(int $n): int {
                $n = abs($n) % 100; $n1 = $n % 10;
                if ($n1 == 1 && $n != 11) return 0;
                if ($n1 >= 2 && $n1 <= 4 && ($n < 10 || $n >= 20)) return 1;
                return 2;
            },
        ];
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function set(string $lang): void
    {
        if (!in_array($lang, $this->supported, true)) $lang = $this->default;
        $this->current = $lang;
        $this->content = $lang;
        $this->app->language($lang);
        if (session_status() === \PHP_SESSION_ACTIVE) $this->app->set($this->session, $lang); //TODO: save session in \SESSION
        setcookie($this->cookie, $lang, time()+31536000, '/', '', false, true);
    }

    public function get(): string
    {
        return $this->current;
    }

    public function setContent(string $lang): void
    {
        $this->content = in_array($lang, $this->supported, true) ? $lang : $this->default;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function languages(): array
    {
        return $this->supported;
    }

    public function t(string $key, array $vars = [], ?string $domain = null, ?string $lang = null): string
    {
        $domain = $domain ?: 'default';
        $lang   = $lang   ?: $this->current;
        $dict = $this->domain($domain, $lang);
        $val  = $this->getKey($dict, $key);
        if ($val === null && $lang !== $this->default) {
            $dict = $this->domain($domain, $this->default);
            $val  = $this->getKey($dict, $key);
        }
        $val = $val ?? $key;

        if ($vars) {
            $pairs = [];
            foreach ($vars as $k=>$v) $pairs['{'.$k.'}'] = (string)$v;
            $val = strtr($val, $pairs);
        }
        return $val;
    }

    public function tx(string $key, string $context, array $vars = [], ?string $domain = null, ?string $lang = null): string
    {
        $ctxKey = $context.'|'.$key;
        $v = $this->t($ctxKey, $vars, $domain, $lang);
        if ($v === $ctxKey) $v = $this->t($key, $vars, $domain, $lang);
        return $v;
    }

    public function tn(string $singular, string $plural, int $count, array $vars = [], ?string $domain = null, ?string $lang = null): string
    {
        $lang = $lang ?: $this->current;
        $rule = $this->pluralRules[$lang] ?? $this->pluralRules[$this->default] ?? fn(int $n)=> $n==1?0:1;
        $idx  = $rule($count);

        $keyS = $singular;
        $keyP = $plural;

        if ($idx === 0) {
            $txt = $this->t($keyS, $vars + ['count'=>$count], $domain, $lang);
        } elseif ($lang === 'ru' && $idx === 1) {
            $txt = $this->t($keyP.'.few', $vars + ['count'=>$count], $domain, $lang);
            if ($txt === $keyP.'.few') $txt = $this->t($keyP, $vars + ['count'=>$count], $domain, $lang);
        } else {
            $txt = $this->t($keyP, $vars + ['count'=>$count], $domain, $lang);
        }
        return $txt;
    }

    public function url(string $path = '/', ?string $lang = null): string
    {
        $lang = $lang ?: $this->current;
        $base = rtrim((string)$this->app->get('BASE'), '/');
        $path = '/'.ltrim($path, '/');
        if ($this->mode === 'prefix') {
            return $base.'/'.rawurlencode($lang).$path;
        }
        if ($this->mode === 'param') {
            $sep = strpos($path,'?')===false ? '?' : '&';
            return $base.$path.$sep.'lang='.rawurlencode($lang);
        }
        return $base.$path;
    }

    public function hreflang(string $path = '/'): string
    {
        $out = [];
        foreach ($this->supported as $lang) {
            $href = $this->url($path, $lang);
            $out[] = '<link rel="alternate" hreflang="'.htmlspecialchars($lang,ENT_QUOTES).'" href="'.htmlspecialchars($href,ENT_QUOTES).'">';
        }
        return implode("\n", $out);
    }

    private function detect(): string
    {
        $lang = null;

        if ($this->mode === 'prefix') {
            $path = (string)$this->app->get('PATH');
            $seg  = trim($path,'/');
            $seg  = $seg !== '' ? explode('/',$seg)[0] : '';
            if ($seg && in_array($seg, $this->supported, true)) $lang = $seg;
        } elseif ($this->mode === 'param') {
            $q = (array)$this->app->get('GET');
            if (!empty($q['lang'])) $lang = (string)$q['lang'];
        }

        if (!$lang && isset($_COOKIE[$this->cookie]) && in_array($_COOKIE[$this->cookie], $this->supported, true)) {
            $lang = (string)$_COOKIE[$this->cookie];
        }

        if (!$lang && session_status() === \PHP_SESSION_ACTIVE) {
            $sess = $this->app->get($this->session);
            if ($sess && in_array($sess, $this->supported, true)) $lang = (string)$sess;
        }

        if (!$lang) {
            $al = (string)($this->app->get('HEADERS.Accept-Language') ?? '');
            if ($al) {
                $this->app->language($al);
                $langs = explode(',', (string)$this->app->get('LANGUAGE'));
                foreach ($langs as $c) {
                    $c = strtolower(substr($c,0,2));
                    if (in_array($c, $this->supported, true)) { $lang = $c; break; }
                }
            }
        }

        $lang = $lang ?: $this->default;
        $this->app->language($lang);
        return $lang;
    }

    private function domain(string $domain, string $lang): array
    {
        $k = $domain.'|'.$lang;
        if (isset($this->domains[$k])) {
            return $this->domains[$k];
        }

        $cache = \Cache::instance();
        $theme = Theme::instance()->getThemeName(); 
        $hash  = $this->app->hash('i18n|'.$theme.'|'.$domain.'|'.$lang);

        if (($this->ttl > 0) && $cache->exists($hash, $lex)) {
            return $this->domains[$k] = (array)$lex;
        }

        $current_theme  = Theme::instance()->getThemeDir();

        $paths = array_values(array_unique(array_filter([
            $this->app->get('LOCALES'),
            ATOMIC_ROOT.'/app/Lang',
            $current_theme.'/languages',
        ])));
        $dict = [];
        foreach ($paths as $base) {
            $candidates = $this->candidateFiles($base, $domain, $lang);
            foreach ($candidates as $file) {
                if (is_file($file)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if ($ext === 'php') {
                        $arr = require $file;
                        if (is_array($arr)) {
                            $dict = array_replace_recursive($dict, $arr);
                        }
                    } elseif ($ext === 'json') {
                        $arr = json_decode((string)@file_get_contents($file), true);
                        if (is_array($arr)) $dict = array_replace_recursive($dict, $arr);
                    }
                }
            }
        }

        if (isset($dict[$domain]) && is_array($dict[$domain])) {
            $dict = array_replace_recursive($dict, $dict[$domain]);
        }

        if ($this->ttl) $cache->set($hash, $dict, $this->ttl);
        return $this->domains[$k] = $dict;
    }

    private function candidateFiles(string $base, string $domain, string $lang): array
    {
        $base = rtrim($base, '/');
        $files = [];

        $files = array_merge($files, [
            $base.'/'.$lang.'/'.$domain.'.php',
            $base.'/'.$lang.'/'.$domain.'.json',
            $base.'/'.$lang.'.php',
            $base.'/'.$lang.'.json',            
        ]);
        
        return $files;
    }

    private function getKey(array $dict, string $key): mixed
    {
        if (array_key_exists($key, $dict)) return $dict[$key];
        if (strpos($key, '.') === false) return null;
        $parts = explode('.', $key);
        $cur = $dict;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
            $cur = $cur[$p];
        }
        return $cur;
    }
    
    public function urlMode(): string
    {
        return $this->mode;
    }

    public function stripPathLangPrefix(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        if ($this->mode !== 'prefix') return $path;
        $seg = trim($path, '/');
        if ($seg === '') return '/';
        $parts = explode('/', $seg);
        $first = $parts[0] ?? '';
        if ($first && in_array($first, $this->languages(), true)) {
            array_shift($parts);
            $path = '/'.implode('/', $parts);
            if ($path === '') $path = '/';
        }
        return $path;
    }

    function decodeField(string $raw, string $content_lang, bool $fallback = true): string
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $value = $decoded[$content_lang] ?? '';
            if (!$fallback && $value === '') return '';
            if ($value === '') {
                foreach ($decoded as $item) {
                    if ($item !== '') {
                        $value = $item;
                        break;
                    }
                }
            }
            return (string)$value;
        }
        return $raw;
    }
}