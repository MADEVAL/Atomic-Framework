<?php
declare(strict_types=1);
namespace Engine\Atomic\Lang;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Cache\Interfaces\CacheStoreInterface;
use Engine\Atomic\Core\CacheManager;
use Engine\Atomic\Core\Filesystem;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Theme\Theme;

final class I18n
{
    use Singleton;

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
    /** @var array<string, list<string>> form name per plural-rule index */
    private array $pluralForms = [
        'en' => ['one', 'other'],
        'ru' => ['one', 'few', 'many'],
        'uk' => ['one', 'few', 'many'],
    ];
    private ?CacheStoreInterface $cache = null;

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

        // East Slavic rule: 1/21 one, 2-4/22-24 few, everything else many.
        $eastSlavic = function(int $n): int {
            $n = abs($n) % 100; $n1 = $n % 10;
            if ($n1 == 1 && $n != 11) return 0;
            if ($n1 >= 2 && $n1 <= 4 && ($n < 10 || $n >= 20)) return 1;
            return 2;
        };
        $this->pluralRules = [
            'en' => fn(int $n): int => $n == 1 ? 0 : 1,
            'ru' => $eastSlavic,
            'uk' => $eastSlavic,
        ];
    }

    public function set(string $lang): void
    {
        if (!in_array($lang, $this->supported, true)) $lang = $this->default;
        $this->current = $lang;
        $this->content = $lang;
        $this->app->language($lang);
        if (session_status() === \PHP_SESSION_ACTIVE) $this->app->set($this->session, $lang); //TODO: save session in \SESSION
        setcookie($this->cookie, $lang, time()+31536000, '/', '', $this->app->get('JAR.secure'), true);
    }

    public function get(): string
    {
        return $this->current;
    }

    public function set_content(string $lang): void
    {
        $this->content = in_array($lang, $this->supported, true) ? $lang : $this->default;
    }

    public function get_content(): string
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
        $val  = $this->get_key($dict, $key);
        if ($val === null && $lang !== $this->default) {
            $dict = $this->domain($domain, $this->default);
            $val  = $this->get_key($dict, $key);
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

    /**
     * Plural translation.
     *
     * The language's plural rule returns a form index. Index 0 uses $singular.
     * Any other index looks up "<plural>.<form>" (e.g. "items.few" for ru), where
     * <form> is the CLDR-style name from $pluralForms, and falls back to $plural.
     * Languages whose only forms are one/other use $plural directly.
     */
    public function tn(string $singular, string $plural, int $count, array $vars = [], ?string $domain = null, ?string $lang = null): string
    {
        $lang = $lang ?: $this->current;
        $ruleLang = isset($this->pluralRules[$lang]) ? $lang : $this->default;
        $rule = $this->pluralRules[$ruleLang] ?? fn(int $n): int => $n == 1 ? 0 : 1;
        $idx  = $rule($count);
        $vars = $vars + ['count' => $count];

        if ($idx === 0) {
            return $this->t($singular, $vars, $domain, $lang);
        }

        $form = $this->pluralForms[$ruleLang][$idx] ?? 'other';
        if ($form !== 'other') {
            $formKey = $plural.'.'.$form;
            $txt = $this->t($formKey, $vars, $domain, $lang);
            if ($txt !== $formKey) return $txt;
        }
        return $this->t($plural, $vars, $domain, $lang);
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

    /**
     * Build the dictionary for a domain/language pair.
     *
     * For each base path (LOCALES, app/Lang, theme languages; later bases win),
     * files are merged in this order, each overriding the previous:
     *   1. Group files <base>/<lang>/<group>.php|json, sorted by filename, each
     *      merged under the key <group> (en/auth.php -> "auth.*"). Basenames must
     *      match [a-z0-9_]+; others and non-array files are skipped. The file named
     *      after the requested domain is not a group (see 2).
     *   2. Domain file <base>/<lang>/<domain>.php|json, merged at the root.
     *   3. Single file <base>/<lang>.php|json, merged at the root.
     * Finally $dict[$domain], when present, is lifted to the root.
     */
    private function domain(string $domain, string $lang): array
    {
        $k = $domain.'|'.$lang;
        if (isset($this->domains[$k])) {
            return $this->domains[$k];
        }

        $theme = Theme::instance()->get_theme_name();
        $hash  = $this->app->hash('i18n|'.$theme.'|'.$domain.'|'.$lang);

        // Only resolve the store when a TTL is configured.
        $cache = $this->ttl > 0 ? $this->cache() : null;
        if ($cache !== null && $cache->exists($hash, $lex)) {
            return $this->domains[$k] = (array)$lex;
        }

        $current_theme  = Theme::instance()->get_theme_dir();

        $paths = array_values(array_unique(array_filter([
            $this->app->get('LOCALES'),
            ATOMIC_ROOT.'/app/Lang',
            $current_theme.'/languages',
        ])));
        $dict = [];
        foreach ($paths as $base) {
            $base = rtrim((string)$base, '/');
            foreach ($this->group_files($base.'/'.$lang, $domain) as [$group, $file]) {
                $arr = $this->load_file($file);
                if ($arr !== null) $dict = array_replace_recursive($dict, [$group => $arr]);
            }
            foreach ($this->candidate_files($base, $domain, $lang) as $file) {
                $resolvedFile = realpath($file);
                if ($resolvedFile === false || !is_file($resolvedFile) || !is_readable($resolvedFile)) continue;
                $arr = $this->load_file($resolvedFile);
                if ($arr !== null) $dict = array_replace_recursive($dict, $arr);
            }
        }

        if (isset($dict[$domain]) && is_array($dict[$domain])) {
            $dict = array_replace_recursive($dict, $dict[$domain]);
        }

        if ($this->ttl && $cache !== null) $cache->set($hash, $dict, $this->ttl);
        return $this->domains[$k] = $dict;
    }

    private function cache(): ?CacheStoreInterface
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            return $this->cache = CacheManager::instance()->store();
        } catch (\Throwable $e) {
            Log::warning('I18n cache unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Group files in a language folder, in filename order.
     *
     * @return list<array{0: string, 1: string}> [group, resolved path]
     */
    private function group_files(string $dir, string $domain): array
    {
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) return [];
        $names = scandir($realDir);
        if ($names === false) return [];
        sort($names, SORT_STRING);

        $out = [];
        foreach ($names as $name) {
            if (!preg_match('/^([a-z0-9_]+)\.(php|json)$/D', $name, $m)) continue;
            if ($m[1] === $domain) continue;
            $file = realpath($realDir.DIRECTORY_SEPARATOR.$name);
            if ($file === false || !is_file($file) || !is_readable($file)) continue;
            if (dirname($file) !== $realDir) continue;
            $out[] = [$m[1], $file];
        }
        return $out;
    }

    private function load_file(string $file): ?array
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            $arr = require $file;
        } elseif ($ext === 'json') {
            $arr = json_decode((string)Filesystem::instance()->read($file), true);
        } else {
            return null;
        }
        return is_array($arr) ? $arr : null;
    }

    private function candidate_files(string $base, string $domain, string $lang): array
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

    private function get_key(array $dict, string $key): mixed
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
    
    public function url_mode(): string
    {
        return $this->mode;
    }

    public function strip_path_lang_prefix(string $path): string
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

    function decode_field(string $raw, string $content_lang, bool $fallback = true): string
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
