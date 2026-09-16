<?php
declare(strict_types=1);
namespace Engine\Atomic\Theme;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Filesystem as FS;
use Engine\Atomic\Theme\Assets;

class Theme
{
    public const INTERNAL_URL_PREFIX = '__atomic';

    private const BUILTIN_THEME_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'Builtin';

    protected App $atomic;
    private static ?self $instance = null;

    protected string $themeUI;
    protected string $themeDir;
    protected string $themesBaseUrl;
    protected string $theme_name;
    protected string $themeCore;
    protected string $themeData;
    protected string $publicUrl;
    protected string $publicDir;
    protected bool $builtin = false;
    protected ?array $themeMeta = null;

    private function __construct(?string $theme_name = null)
    {
        $this->atomic    = App::instance();
        // Read UI from config
        // Theme name from param or config
        $this->theme_name = $theme_name ?? (string)$this->atomic->get('THEME.envname', 'default');
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $this->theme_name)) {
            throw new \InvalidArgumentException('Invalid theme name: ' . $this->theme_name);
        }

        $this->themeUI  = rtrim((string)$this->atomic->get('ENQ_UI_FIX'), '/\\');
        // Initialize public paths before resolving the theme URL.
        $this->publicUrl = AM::instance()->get_public_url();
        $this->publicDir = AM::instance()->get_public_dir();
        $this->themesBaseUrl = $this->custom_theme_base_url($this->themeUI);

        $customDir = $this->themeUI . DIRECTORY_SEPARATOR . $this->theme_name . DIRECTORY_SEPARATOR;
        $builtinDir = self::builtin_theme_dir($this->theme_name);

        // A custom theme always wins over a bundled theme with the same name.
        if (is_dir($customDir) && is_file($customDir . 'theme.json')) {
            $this->themeDir = $customDir;
        } elseif ($builtinDir !== null) {
            $this->builtin = true;
            $this->themeDir = $builtinDir . DIRECTORY_SEPARATOR;
        } else {
            $this->themeDir = $customDir;
        }

        // Keep F3's UI hive pointed at the resolved theme directory.
        $this->atomic->set('UI', $this->themeDir);
        if (!is_dir($this->themeDir)) {
            Log::error('Theme directory not found: ' . $this->themeDir);
        }
        // Core functions file path
        $this->themeCore = $this->themeDir . 'functions.atom.php';
        // Theme data file path
        $this->themeData = $this->themeDir . 'theme.json';
    }

    public static function builtin_theme_dir(string $theme_name): ?string
    {
        if ($theme_name === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $theme_name)) {
            return null;
        }

        $directory = self::BUILTIN_THEME_DIR . DIRECTORY_SEPARATOR . $theme_name;
        return is_dir($directory) ? $directory : null;
    }

    public static function internal_url(string $path = ''): string
    {
        $base = rtrim(AM::instance()->get_public_url(), '/');
        return $base . '/' . self::INTERNAL_URL_PREFIX
            . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    private function custom_theme_base_url(string $customRoot): string
    {
        $publicDir = realpath($this->publicDir);
        $customDir = realpath($customRoot) ?: $customRoot;
        $relative = '';

        if ($publicDir !== false) {
            $prefix = rtrim($publicDir, '/\\') . DIRECTORY_SEPARATOR;
            if (str_starts_with($customDir, $prefix)) {
                $relative = str_replace('\\', '/', substr($customDir, strlen($prefix)));
            }
        }

        if ($relative === '') {
            $relative = 'themes';
        }

        return rtrim($this->publicUrl, '/') . '/' . trim($relative, '/') . '/';
    }

    public static function instance(?string $theme_name = null): self
    {
        if ($theme_name !== null) {
            self::$instance = new self($theme_name);
            self::$instance->run();
            return self::$instance;
        }
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->run();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function include(string $file): bool
    {
        $file = (string)$file;
        $fullPath = FS::instance()->is_absolute_path($file) ? $file : rtrim($this->themeDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($file, '/\\');

        $realFile = realpath($fullPath);
        if ($realFile === false) {
            return false;
        }

        $realBase = realpath($this->themeDir);
        if ($realBase !== false && !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR) && $realFile !== $realBase) {
            Log::warning('Theme::include() path escapes theme directory: ' . $file);
            return false;
        }

        if (is_file($realFile) && is_readable($realFile)) {
            include_once $realFile;
            return true;
        }
        return false;
    }

    public function run(): void
    {
        $included = $this->include($this->themeCore);
        if (!$included) {
            Log::warning('Theme functions file not found: ' . $this->themeCore);
        }
        $this->parse();
    }

    public function parse() : void
    {
        $file = $this->themeData;
        $meta = [];

        if ($file && is_file($file) && is_readable($file)) {
            $json = FS::instance()->read($file);
            if ($json !== false && $json !== '') {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $meta = $data;
                } else {
                    Log::warning('Invalid JSON in theme data file: ' . $file);
                }
            }
        } else {
            Log::warning('Theme data file not found or unreadable: ' . $file);
        }

        $meta['_file']  = $file;
        $meta['_dir']   = $this->themeDir ?? null;
        $meta['_url']   = $this->themesBaseUrl ?? null;
        $meta['_theme'] = $this->theme_name ?? null;

        $this->themeMeta = $meta;

        $map = [
            'THEME._file'        => $meta['_file'],
            'THEME._dir'         => $meta['_dir'],
            'THEME._url'         => $meta['_url'],
            'THEME._theme'       => $meta['_theme'],
            'THEME._url_public'  => $this->publicUrl,
            'THEME._dir_public'  => $this->publicDir,
        ];
        foreach ($meta as $k => $v) {
            if (in_array($k, ['_file', '_dir', '_url', '_theme'], true)) continue;
            $map['THEME.' . $k] = $v;
        }

        $this->atomic->mset($map);
    }

    public static function get_header(string $name = 'header', ?array $vars = null): void {
        echo \View::instance()->render('partials/' . $name . '.atom.php', 'text/html', $vars);
    }

    public static function get_footer(string $name = 'footer', ?array $vars = null): void {
        echo \View::instance()->render('partials/' . $name . '.atom.php', 'text/html', $vars);
    }

    public static function get_sidebar(string $name = 'sidebar', ?array $vars = null): void {
        echo \View::instance()->render('partials/' . $name . '.atom.php', 'text/html', $vars);
    }

    public static function get_section(string $name, ?array $vars = null): void {
        echo \View::instance()->render('partials/' . $name . '.atom.php', 'text/html', $vars);
    }

    public static function get_head(?array $vars = null): void {
        echo \View::instance()->render('partials/head.atom.php', 'text/html', $vars);
    }

    public static function get_custom_head(?array $vars = null): void {
        $path = 'partials/head.custom.atom.php';
        $fullPath = App::instance()->get('UI') . $path;
        if (!is_file($fullPath)) {
            return;
        }
        echo \View::instance()->render($path, 'text/html', $vars);
    }
   
    public function get_theme_meta(): array
    {
        return $this->themeMeta ?? [];
    }

    public function get_theme_dir(): string
    {
        return $this->themeDir;
    }

    public function get_theme_url(): string
    {
        $baseUrl = $this->builtin
            ? self::internal_url('themes/')
            : $this->themesBaseUrl;

        return rtrim($baseUrl, '/') . '/' . rawurlencode($this->theme_name);
    }

    public function is_builtin(): bool
    {
        return $this->builtin;
    }

    public function get_theme_name(): string
    {
        return $this->theme_name;
    }

    public function get_public_url(): string
    {
        return $this->publicUrl;
    }

    public function get_public_dir(): string
    {
        return $this->publicDir;
    }

    public function get_theme_color(): string
    {
        if (isset($this->themeMeta) && is_array($this->themeMeta) && array_key_exists('color', $this->themeMeta)) {
            return (string)$this->themeMeta['color'];
        } else {
            return '#ffffff';
        }
    }

    public function set_theme_color(string $color = '#ffffff'): string
    {
        $pageColor = $this->atomic->get('PAGE.color');
        if (!empty($pageColor)) {
            return (string)$pageColor;
        } else {
            return $color;
        }
    }
}
