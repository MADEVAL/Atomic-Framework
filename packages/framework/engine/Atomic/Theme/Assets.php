<?php
declare(strict_types=1);
namespace Engine\Atomic\Theme;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Filesystem as FS;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Theme\Theme;

final class Assets
{
    use Singleton;

    protected App $atomic;

    protected array $styles  = [];        // handle => [src,deps,version,media]
    protected array $scripts = [];        // handle => [src,deps,version,inFooter,attrs]
    protected array $localize = [];       // handle => ['var' => string, 'data' => array]
    protected array $inlineStyles  = [];  // handle => [css,...]
    protected array $inlineScripts = [    // 'header'|'footer' => handle => [js,...]
        'header' => [],
        'footer' => [],
    ];
    protected array $loaded = [
        'styles'  => [],
        'scripts' => [],
    ];

    private const PRESETS = [
        'jquery' => [
            'js' => 'https://code.jquery.com/jquery-3.7.1.min.js',
        ],
        'bootstrap' => [
            'css' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
            'js' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
            'deps' => ['jquery'],
        ],
        'w3' => [
            'css' => 'https://www.w3schools.com/w3css/4/w3.css',
        ],
        'fa' => [
            'css' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
        ],
        'modernizr' => [
            'js' => 'https://cdnjs.cloudflare.com/ajax/libs/modernizr/2.8.3/modernizr.min.js',
            'footer' => false,
        ],
    ];

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function enqueue_preset(string $name): void
    {
        $name = strtolower($name);
        if (!isset(self::PRESETS[$name])) return;

        $preset = self::PRESETS[$name];
        $deps = $preset['deps'] ?? [];

        foreach ($deps as $dep) {
            $this->enqueue_preset($dep);
        }

        if (isset($preset['css'])) {
            $this->enqueue_style($name, $preset['css']);
        }

        if (isset($preset['js'])) {
            $in_footer = $preset['footer'] ?? true;
            $this->enqueue_script($name, $preset['js'], $deps, null, $in_footer);
        }
    }

    public function enqueue_font(string $font): void
    {
        $handle = 'google-font-' . sanitize_key($font);
        $family = str_replace(' ', '+', $font);
        $url = "https://fonts.googleapis.com/css2?family={$family}:wght@300;400;500;600;700&display=swap";
        $this->enqueue_style($handle, $url);
    }

    public function enqueue_style(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        string $media = 'all'
    ): void {
        if (in_array($handle, $this->loaded['styles'], true)) return;
        $this->styles[$handle] = [
            'src'     => $src,
            'deps'    => array_values(array_unique($deps)),
            'version' => $version,
            'media'   => $media,
        ];
    }

    public function enqueue_script(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        bool $in_footer = true,
        array $attrs = []         // ['defer'=>true,'type'=>'module']
    ): void {
        if (in_array($handle, $this->loaded['scripts'], true)) return;
        $this->scripts[$handle] = [
            'src'      => $src,
            'deps'     => array_values(array_unique($deps)),
            'version'  => $version,
            'inFooter' => $in_footer,
            'attrs'    => $attrs,
        ];
    }

    public function set_script_attrs(string $handle, array $attrs): void
    {
        if (!isset($this->scripts[$handle])) return;
        $this->scripts[$handle]['attrs'] = array_merge($this->scripts[$handle]['attrs'] ?? [], $attrs);
    }

    public function localize_script(string $handle, array $data, ?string $var_name = null): void
    {
        $var = $this->normalize_var_name($var_name ?: ($handle.'Data'));
        $this->localize[$handle] = ['var' => $var, 'data' => $data];
    }

    public function add_inline_style(string $handle, string $css): void
    {
        $this->inlineStyles[$handle][] = $css;
    }

    public function add_inline_script(string $handle, string $js, string $position = 'footer'): void
    {
        $pos = ($position === 'header') ? 'header' : 'footer';
        $this->inlineScripts[$pos][$handle][] = $js;
    }

    public function dequeue_style(string $handle): void
    {
        unset($this->styles[$handle]);
        $this->loaded['styles'] = array_values(array_filter($this->loaded['styles'], fn($h) => $h !== $handle));
        unset($this->inlineStyles[$handle]);
    }

    public function dequeue_script(string $handle): void
    {
        unset($this->scripts[$handle]);
        $this->loaded['scripts'] = array_values(array_filter($this->loaded['scripts'], fn($h) => $h !== $handle));
        unset($this->localize[$handle]);
        unset($this->inlineScripts['header'][$handle], $this->inlineScripts['footer'][$handle]);
    }

    public function print_styles(): void
    {
        $visited = [];
        foreach (array_keys($this->styles) as $handle) {
            $this->print_style_recursive($handle, $visited);
        }
    }

    public function print_scripts(bool $in_footer = true): void
    {
        $visited = [];
        foreach ($this->scripts as $handle => $_) {
            $this->print_script_recursive($handle, $in_footer, $visited);
        }
    }

    private function print_style_recursive(string $handle, array &$visited): void
    {
        if (!isset($this->styles[$handle])) return;
        if (in_array($handle, $this->loaded['styles'], true)) return;

        if (isset($visited[$handle])) return; 
        $visited[$handle] = true;

        foreach ($this->styles[$handle]['deps'] as $dep) {
            $this->print_style_recursive($dep, $visited);
        }

        $style = $this->styles[$handle];
        $href  = $this->resolve_src($style['src']);
        $ver   = $this->build_version_param($style['version'], $href);
        $media = htmlspecialchars($style['media'], ENT_QUOTES);

        echo '<link rel="stylesheet" href="' . htmlspecialchars($href . $ver, ENT_QUOTES) . '" media="' . $media . '">' . PHP_EOL;

        if (!empty($this->inlineStyles[$handle])) {
            echo "<style>\n" . implode("\n", $this->inlineStyles[$handle]) . "\n</style>\n";
        }

        $this->loaded['styles'][] = $handle;
    }

    private function print_script_recursive(string $handle, bool $in_footer, array &$visited): void
    {
        if (!isset($this->scripts[$handle])) return;
        if (in_array($handle, $this->loaded['scripts'], true)) return;
        if ($this->scripts[$handle]['inFooter'] !== $in_footer) return;

        if (isset($visited[$handle])) return; 
        $visited[$handle] = true;

        foreach ($this->scripts[$handle]['deps'] as $dep) {
            $this->print_script_recursive($dep, $in_footer, $visited);
        }
        $script = $this->scripts[$handle];
        $src    = $this->resolve_src($script['src']);
        $ver    = $this->build_version_param($script['version'], $src);

        if (isset($this->localize[$handle])) {
            $payload = json_encode($this->localize[$handle]['data'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $var_name = $this->localize[$handle]['var'];
            if ($payload !== false) {
                echo '<script>(function(w){w['.json_encode($var_name).']='.$payload.';})(window);</script>'."\n";
            }
        }

        if ($in_footer === false && !empty($this->inlineScripts['header'][$handle])) {
            echo "<script>\n" . implode("\n", $this->inlineScripts['header'][$handle]) . "\n</script>\n";
        }
        $attrsHtml = $this->build_script_attrs_html($script['attrs'] ?? []);
        echo '<script src="' . htmlspecialchars($src . $ver, ENT_QUOTES) . '"' . $attrsHtml . '></script>' . PHP_EOL;
        if ($in_footer === true && !empty($this->inlineScripts['footer'][$handle])) {
            echo "<script>\n" . implode("\n", $this->inlineScripts['footer'][$handle]) . "\n</script>\n";
        }

        $this->loaded['scripts'][] = $handle;
    }

    private function resolve_src(string $src): string
    {
        if (preg_match('~^(?:https?:)?//~i', $src)) return $src;
        if ($src !== '' && $src[0] === '~') {
            $base = Theme::instance()->get_theme_url();  
            return rtrim($base, '/') . '/' . ltrim(substr($src, 1), '/');
        }
        if ($src !== '' && $src[0] === '/') {
            $base = rtrim((string)$this->atomic->get('BASE'), '/');
            return $base . $src;
        }
        $base = Theme::instance()->get_theme_url();
        return rtrim($base, '/') . '/' . ltrim($src, '/');
    }

    private function build_version_param(?string $version, string $resolved_url): string
    {
        if ($version !== null && $version !== '') {
            return '?ver=' . rawurlencode($version);
        }
        $path = parse_url($resolved_url, PHP_URL_PATH) ?: '';
        $root = rtrim((string)$this->atomic->get('ROOT'), '/');
        $full = $root . '/' . ltrim($path, '/');
        if (is_file($full)) {
            $mtime = @filemtime($full);
            if ($mtime) return '?ver=' . $mtime;
        }
        return '';
    }

    private function build_script_attrs_html(array $attrs): string
    {
        if (!$attrs) return '';
        $out = '';
        foreach ($attrs as $name => $value) {
            if (is_int($name)) { 
                $name = (string)$value;
                $value = true;
            }
            $name = strtolower(trim($name));
            if ($value === true) {
                $out .= ' ' . htmlspecialchars($name, ENT_QUOTES);
            } elseif ($value !== false && $value !== null && $value !== '') {
                $out .= ' ' . htmlspecialchars($name, ENT_QUOTES) . '="' . htmlspecialchars((string)$value, ENT_QUOTES) . '"';
            }
        }
        return $out;
    }

    private function normalize_var_name(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        if (!preg_match('/^[A-Za-z_]/', $name)) $name = '_'.$name;
        return $name;
    }

    private function __clone() {}
}

function sanitize_key(string $key): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '-', $key));
}
