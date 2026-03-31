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

    public function enqueuePreset(string $name): void
    {
        $name = strtolower($name);
        if (!isset(self::PRESETS[$name])) return;

        $preset = self::PRESETS[$name];
        $deps = $preset['deps'] ?? [];

        foreach ($deps as $dep) {
            $this->enqueuePreset($dep);
        }

        if (isset($preset['css'])) {
            $this->enqueueStyle($name, $preset['css']);
        }

        if (isset($preset['js'])) {
            $inFooter = $preset['footer'] ?? true;
            $this->enqueueScript($name, $preset['js'], $deps, null, $inFooter);
        }
    }

    public function enqueueFont(string $font): void
    {
        $handle = 'google-font-' . sanitize_key($font);
        $family = str_replace(' ', '+', $font);
        $url = "https://fonts.googleapis.com/css2?family={$family}:wght@300;400;500;600;700&display=swap";
        $this->enqueueStyle($handle, $url);
    }

    public function enqueueStyle(
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

    public function enqueueScript(
        string $handle,
        string $src,
        array $deps = [],
        ?string $version = null,
        bool $inFooter = true,
        array $attrs = []         // ['defer'=>true,'type'=>'module']
    ): void {
        if (in_array($handle, $this->loaded['scripts'], true)) return;
        $this->scripts[$handle] = [
            'src'      => $src,
            'deps'     => array_values(array_unique($deps)),
            'version'  => $version,
            'inFooter' => $inFooter,
            'attrs'    => $attrs,
        ];
    }

    public function setScriptAttrs(string $handle, array $attrs): void
    {
        if (!isset($this->scripts[$handle])) return;
        $this->scripts[$handle]['attrs'] = array_merge($this->scripts[$handle]['attrs'] ?? [], $attrs);
    }

    public function localizeScript(string $handle, array $data, ?string $varName = null): void
    {
        $var = $this->normalizeVarName($varName ?: ($handle.'Data'));
        $this->localize[$handle] = ['var' => $var, 'data' => $data];
    }

    public function addInlineStyle(string $handle, string $css): void
    {
        $this->inlineStyles[$handle][] = $css;
    }

    public function addInlineScript(string $handle, string $js, string $position = 'footer'): void
    {
        $pos = ($position === 'header') ? 'header' : 'footer';
        $this->inlineScripts[$pos][$handle][] = $js;
    }

    public function dequeueStyle(string $handle): void
    {
        unset($this->styles[$handle]);
        $this->loaded['styles'] = array_values(array_filter($this->loaded['styles'], fn($h) => $h !== $handle));
        unset($this->inlineStyles[$handle]);
    }

    public function dequeueScript(string $handle): void
    {
        unset($this->scripts[$handle]);
        $this->loaded['scripts'] = array_values(array_filter($this->loaded['scripts'], fn($h) => $h !== $handle));
        unset($this->localize[$handle]);
        unset($this->inlineScripts['header'][$handle], $this->inlineScripts['footer'][$handle]);
    }

    public function printStyles(): void
    {
        $visited = [];
        foreach (array_keys($this->styles) as $handle) {
            $this->printStyleRecursive($handle, $visited);
        }
    }

    public function printScripts(bool $inFooter = true): void
    {
        $visited = [];
        foreach ($this->scripts as $handle => $_) {
            $this->printScriptRecursive($handle, $inFooter, $visited);
        }
    }

    private function printStyleRecursive(string $handle, array &$visited): void
    {
        if (!isset($this->styles[$handle])) return;
        if (in_array($handle, $this->loaded['styles'], true)) return;

        if (isset($visited[$handle])) return; 
        $visited[$handle] = true;

        foreach ($this->styles[$handle]['deps'] as $dep) {
            $this->printStyleRecursive($dep, $visited);
        }

        $style = $this->styles[$handle];
        $href  = $this->resolveSrc($style['src']);
        $ver   = $this->buildVersionParam($style['version'], $href);
        $media = htmlspecialchars($style['media'], ENT_QUOTES);

        echo '<link rel="stylesheet" href="' . htmlspecialchars($href . $ver, ENT_QUOTES) . '" media="' . $media . '">' . PHP_EOL;

        if (!empty($this->inlineStyles[$handle])) {
            echo "<style>\n" . implode("\n", $this->inlineStyles[$handle]) . "\n</style>\n";
        }

        $this->loaded['styles'][] = $handle;
    }

    private function printScriptRecursive(string $handle, bool $inFooter, array &$visited): void
    {
        if (!isset($this->scripts[$handle])) return;
        if (in_array($handle, $this->loaded['scripts'], true)) return;
        if ($this->scripts[$handle]['inFooter'] !== $inFooter) return;

        if (isset($visited[$handle])) return; 
        $visited[$handle] = true;

        foreach ($this->scripts[$handle]['deps'] as $dep) {
            $this->printScriptRecursive($dep, $inFooter, $visited);
        }
        $script = $this->scripts[$handle];
        $src    = $this->resolveSrc($script['src']);
        $ver    = $this->buildVersionParam($script['version'], $src);

        if (isset($this->localize[$handle])) {
            $payload = json_encode($this->localize[$handle]['data'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $varName = $this->localize[$handle]['var'];
            if ($payload !== false) {
                echo '<script>(function(w){w['.json_encode($varName).']='.$payload.';})(window);</script>'."\n";
            }
        }

        if ($inFooter === false && !empty($this->inlineScripts['header'][$handle])) {
            echo "<script>\n" . implode("\n", $this->inlineScripts['header'][$handle]) . "\n</script>\n";
        }
        $attrsHtml = $this->buildScriptAttrsHtml($script['attrs'] ?? []);
        echo '<script src="' . htmlspecialchars($src . $ver, ENT_QUOTES) . '"' . $attrsHtml . '></script>' . PHP_EOL;
        if ($inFooter === true && !empty($this->inlineScripts['footer'][$handle])) {
            echo "<script>\n" . implode("\n", $this->inlineScripts['footer'][$handle]) . "\n</script>\n";
        }

        $this->loaded['scripts'][] = $handle;
    }

    private function resolveSrc(string $src): string
    {
        if (preg_match('~^(?:https?:)?//~i', $src)) return $src;
        if ($src !== '' && $src[0] === '~') {
            $base = Theme::instance()->getThemeUrl();  
            return rtrim($base, '/') . '/' . ltrim(substr($src, 1), '/');
        }
        if ($src !== '' && $src[0] === '/') {
            $base = rtrim((string)$this->atomic->get('BASE'), '/');
            return $base . $src;
        }
        $base = Theme::instance()->getThemeUrl();
        return rtrim($base, '/') . '/' . ltrim($src, '/');
    }

    private function buildVersionParam(?string $version, string $resolvedUrl): string
    {
        if ($version !== null && $version !== '') {
            return '?ver=' . rawurlencode($version);
        }
        $path = parse_url($resolvedUrl, PHP_URL_PATH) ?: '';
        $root = rtrim((string)$this->atomic->get('ROOT'), '/');
        $full = $root . '/' . ltrim($path, '/');
        if (is_file($full)) {
            $mtime = @filemtime($full);
            if ($mtime) return '?ver=' . $mtime;
        }
        return '';
    }

    private function buildScriptAttrsHtml(array $attrs): string
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

    private function normalizeVarName(string $name): string
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
