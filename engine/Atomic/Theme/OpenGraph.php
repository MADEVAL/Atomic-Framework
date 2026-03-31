<?php
declare(strict_types=1);
namespace Engine\Atomic\Theme;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Lang\I18n;

final class OpenGraph
{
    use Singleton;

    protected App $atomic;

    private array $meta = [];

    private const DEFAULTS = [
        'type' => 'website',
        'locale' => 'en_US',
        'site_name' => '',
    ];

    private const LOCALES = [
        'en' => 'en_US',
        'ru' => 'ru_RU',
        'de' => 'de_DE',
        'fr' => 'fr_FR',
        'es' => 'es_ES',
        'it' => 'it_IT',
        'pt' => 'pt_BR',
        'zh' => 'zh_CN',
        'ja' => 'ja_JP',
        'ko' => 'ko_KR',
    ];

    private function __construct()
    {
        $this->atomic = App::instance();
        $this->loadDefaults();
    }

    private function loadDefaults(): void
    {
        $scheme = $this->atomic->get('SCHEME') ?? 'https';
        $host = $this->atomic->get('HOST');
        $base = rtrim($this->atomic->get('BASE') ?? '', '/');
        $path = AM::instance()->get_currentPath(false);
        
        $lang = I18n::instance()->get();
        $locale = self::LOCALES[$lang] ?? self::LOCALES['en'];

        $this->meta = [
            'url' => $scheme . '://' . $host . $base . $path,
            'type' => self::DEFAULTS['type'],
            'locale' => $locale,
            'site_name' => $this->atomic->get('APP_NAME') ?? 'Website',
        ];
    }

    public function set(string $property, string $content): self
    {
        $this->meta[$property] = $content;
        return $this;
    }

    public function setTitle(string $title): self
    {
        $this->meta['title'] = $title;
        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->meta['description'] = $description;
        return $this;
    }

    public function setImage(string $image): self
    {
        if (!preg_match('~^https?://~i', $image)) {
            $base = rtrim(AM::instance()->get_publicUrl(), '/');
            $image = $base . '/' . ltrim($image, '/');
        }
        $this->meta['image'] = $image;
        return $this;
    }

    public function setUrl(?string $url = null): self
    {
        if ($url === null) {
            $scheme = $this->atomic->get('SCHEME') ?? 'https';
            $host = $this->atomic->get('HOST');
            $base = rtrim($this->atomic->get('BASE') ?? '', '/');
            $path = AM::instance()->get_currentPath(false);
            $url = $scheme . '://' . $host . $base . $path;
        }
        $this->meta['url'] = $url;
        return $this;
    }

    public function setType(string $type): self
    {
        $this->meta['type'] = $type;
        return $this;
    }

    public function setLocale(string $locale): self
    {
        $this->meta['locale'] = $locale;
        return $this;
    }

    public function setSiteName(string $siteName): self
    {
        $this->meta['site_name'] = $siteName;
        return $this;
    }

    public function setArticle(array $data): self
    {
        $this->setType('article');
        
        if (isset($data['published_time'])) {
            $this->meta['article:published_time'] = $data['published_time'];
        }
        if (isset($data['modified_time'])) {
            $this->meta['article:modified_time'] = $data['modified_time'];
        }
        if (isset($data['author'])) {
            $this->meta['article:author'] = $data['author'];
        }
        if (isset($data['section'])) {
            $this->meta['article:section'] = $data['section'];
        }
        if (isset($data['tag'])) {
            $tags = is_array($data['tag']) ? $data['tag'] : [$data['tag']];
            foreach ($tags as $tag) {
                $this->meta['article:tag'][] = $tag;
            }
        }
        
        return $this;
    }

    public function setProduct(array $data): self
    {
        $this->setType('product');
        
        if (isset($data['price:amount'])) {
            $this->meta['product:price:amount'] = $data['price:amount'];
        }
        if (isset($data['price:currency'])) {
            $this->meta['product:price:currency'] = $data['price:currency'];
        }
        if (isset($data['availability'])) {
            $this->meta['product:availability'] = $data['availability'];
        }
        if (isset($data['brand'])) {
            $this->meta['product:brand'] = $data['brand'];
        }
        
        return $this;
    }

    public function render(): void
    {
        foreach ($this->meta as $property => $content) {
            if (is_array($content)) {
                foreach ($content as $item) {
                    $this->printTag($property, $item);
                }
            } else {
                $this->printTag($property, $content);
            }
        }
    }

    public function renderTwitter(): void
    {
        $twitter = [
            'twitter:card' => 'summary_large_image',
            'twitter:title' => $this->meta['title'] ?? '',
            'twitter:description' => $this->meta['description'] ?? '',
            'twitter:image' => $this->meta['image'] ?? '',
        ];

        foreach ($twitter as $name => $content) {
            if ($content) {
                echo '<meta name="' . htmlspecialchars($name, ENT_QUOTES) . '" content="' . htmlspecialchars($content, ENT_QUOTES) . '">' . PHP_EOL;
            }
        }
    }

    public function generate(array $data = []): self
    {
        if (isset($data['title'])) {
            $this->setTitle($data['title']);
        }
        if (isset($data['description'])) {
            $this->setDescription($data['description']);
        }
        if (isset($data['image'])) {
            $this->setImage($data['image']);
        }
        if (isset($data['url'])) {
            $this->setUrl($data['url']);
        }
        if (isset($data['type'])) {
            $this->setType($data['type']);
        }
        if (isset($data['site_name'])) {
            $this->setSiteName($data['site_name']);
        }

        return $this;
    }

    private function printTag(string $property, string $content): void
    {
        if (empty($content)) return;
        
        $property = htmlspecialchars($property, ENT_QUOTES);
        $content = htmlspecialchars($content, ENT_QUOTES);
        
        echo '<meta property="og:' . $property . '" content="' . $content . '">' . PHP_EOL;
    }

    private function __clone() {}
}