<?php
declare(strict_types=1);
namespace Engine\Atomic\Theme;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Lang\I18n;

class Head
{
    use Singleton;

    protected App $atomic;
    
    private array $preconnects = [];
    private array $preloads = [];

    private const ANALYTICS = [
        'google' => [
            'async' => true,
            'script' => "https://www.googletagmanager.com/gtag/js?id={KEY}",
            'inline' => "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{KEY}');"
        ],
        'yandex' => [
            'async' => true,
            'inline' => "(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,'script','https://mc.yandex.ru/metrika/tag.js','ym');ym({KEY},'init',{clickmap:true,trackLinks:true,accurateTrackBounce:true});"
        ],
        'ga4' => [
            'async' => true,
            'script' => "https://www.googletagmanager.com/gtag/js?id={KEY}",
            'inline' => "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{KEY}');"
        ],
    ];

    private const PRECONNECTS_COMMON = [
        'cloudflare' => 'https://cdnjs.cloudflare.com',
        'google-fonts' => 'https://fonts.googleapis.com',
        'google-fonts-static' => 'https://fonts.gstatic.com',
        'jquery' => 'https://code.jquery.com',
        'bootstrap' => 'https://cdn.jsdelivr.net',
    ];

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function favicon(): void
    {
        $base = rtrim(AM::instance()->get_publicUrl(), '/');
        $favicon = $this->atomic->get('FAVICON') ?? '/favicon.ico';
        $favicon = ltrim($favicon, '/');
        echo '<link rel="icon" href="' . $base . '/' . $favicon . '">' . PHP_EOL;
    }

    public function title(string $delimiter = ' | '): void
    {
        $appname = $this->atomic->get('APP_NAME') ?? 'Atomic';
        $title = $this->atomic->get('PAGE.title');
        
        if (empty($title)) {
            $title = $appname;
        } else {
            $title .= $delimiter . $appname;
        }
        
        $encoding = $this->atomic->get('ENCODING') ?? 'UTF-8';
        echo htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, $encoding);
    }

    public function iconset(string $path = ''): void
    {
        $base = rtrim(AM::instance()->get_publicUrl(), '/');
        
        if ($path !== '') {
            $base .= '/' . ltrim($path, '/');
        } else {
            $base .= '/assets/img/';
        }

        $icons = [
            ['size' => '16x16', 'file' => 'favicon-16x16.png'],
            ['size' => '32x32', 'file' => 'favicon-32x32.png'],
            ['size' => '192x192', 'file' => 'android-chrome-192x192.png'],
            ['size' => '512x512', 'file' => 'android-chrome-512x512.png'],
        ];

        foreach ($icons as $icon) {
            echo '<link rel="icon" type="image/png" sizes="' . $icon['size'] . '" href="' . $base . $icon['file'] . '">' . PHP_EOL;
        }

        echo '<link rel="apple-touch-icon" href="' . $base . 'apple-touch-icon.png">' . PHP_EOL;
    }

    public function manifest(): void
    {
        $base = rtrim(AM::instance()->get_publicUrl(), '/');
        $manifest = ltrim('/site.webmanifest', '/');
        echo '<link rel="manifest" href="' . $base . '/' . $manifest . '">' . PHP_EOL;
    }

    public function canonical(): void
    {
        $scheme = $this->atomic->get('SCHEME') ?? 'https';
        $host = $this->atomic->get('HOST');
        $base = rtrim($this->atomic->get('BASE') ?? '', '/');
        $path = AM::instance()->get_currentPath(false);
        
        $url = $scheme . '://' . $host . $base . $path;
        
        echo '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . PHP_EOL;
    }

    public function addPreconnect(string $origin, bool $crossorigin = false): self
    {
        $this->preconnects[$origin] = $crossorigin;
        return $this;
    }

    public function addPreload(string $href, string $as, ?string $type = null, bool $crossorigin = false): self
    {
        $this->preloads[] = compact('href', 'as', 'type', 'crossorigin');
        return $this;
    }

    public function preconnect(?string $preset = null): void
    {
        if ($preset && isset(self::PRECONNECTS_COMMON[$preset])) {
            $this->addPreconnect(self::PRECONNECTS_COMMON[$preset], $preset === 'google-fonts-static');
        }
        
        if (empty($this->preconnects)) {
            return;
        }

        foreach ($this->preconnects as $origin => $crossorigin) {
            $attrs = 'rel="preconnect" href="' . htmlspecialchars($origin, ENT_QUOTES) . '"';
            if ($crossorigin) {
                $attrs .= ' crossorigin';
            }
            echo '<link ' . $attrs . '>' . PHP_EOL;
        }
        
        $this->preconnects = [];
    }

    public function preload(): void
    {
        foreach ($this->preloads as $item) {
            $attrs = 'rel="preload" href="' . htmlspecialchars($item['href'], ENT_QUOTES) . '" as="' . htmlspecialchars($item['as'], ENT_QUOTES) . '"';
            
            if ($item['type']) {
                $attrs .= ' type="' . htmlspecialchars($item['type'], ENT_QUOTES) . '"';
            }
            
            if ($item['crossorigin']) {
                $attrs .= ' crossorigin';
            }
            
            echo '<link ' . $attrs . '>' . PHP_EOL;
        }
    }

    public function analytics(string $system, string $key): void
    {
        $system = strtolower($system);
        
        if (!isset(self::ANALYTICS[$system])) {
            return;
        }

        $config = self::ANALYTICS[$system];
        
        if (isset($config['script'])) {
            $src = str_replace('{KEY}', $key, $config['script']);
            $async = $config['async'] ? ' async' : '';
            echo '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '"' . $async . '></script>' . PHP_EOL;
        }

        if (isset($config['inline'])) {
            $code = str_replace('{KEY}', $key, $config['inline']);
            echo '<script>' . $code . '</script>' . PHP_EOL;
        }
    }

    public function schema(string $type, array $data = []): void
    {
        $schema = Schema::instance()->generate($type, $data);
        
        if ($schema) {
            echo '<script type="application/ld+json">' . PHP_EOL;
            echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            echo PHP_EOL . '</script>' . PHP_EOL;
        }
    }

    private function __clone() {}
}