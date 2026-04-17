<?php
declare(strict_types=1);
namespace Engine\Atomic\Theme;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Traits\Singleton;

final class Schema
{
    use Singleton;

    protected App $atomic;

    private const TEMPLATES = [
        'organization' => [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => '{name}',
            'url' => '{url}',
            'logo' => '{logo}',
            'description' => '{description}',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '{address.street}',
                'addressLocality' => '{address.city}',
                'addressRegion' => '{address.region}',
                'postalCode' => '{address.postal}',
                'addressCountry' => '{address.country}'
            ],
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => '{phone}',
                'contactType' => 'customer service',
                'email' => '{email}'
            ]
        ],
        'product' => [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => '{name}',
            'image' => '{image}',
            'description' => '{description}',
            'sku' => '{sku}',
            'brand' => [
                '@type' => 'Brand',
                'name' => '{brand}'
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => '{url}',
                'priceCurrency' => '{currency}',
                'price' => '{price}',
                'availability' => '{availability}',
                'priceValidUntil' => '{priceValidUntil}'
            ]
        ],
        'article' => [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => '{title}',
            'image' => '{image}',
            'datePublished' => '{published}',
            'dateModified' => '{modified}',
            'author' => [
                '@type' => 'Person',
                'name' => '{author}'
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => '{publisher}',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => '{publisher.logo}'
                ]
            ],
            'description' => '{description}'
        ],
        'breadcrumb' => [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => []
        ],
        'website' => [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => '{name}',
            'url' => '{url}',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => '{searchUrl}?q={search_term_string}'
                ],
                'query-input' => 'required name=search_term_string'
            ]
        ]
    ];

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function generate(string $type, array $data = []): ?array
    {
        $type = strtolower($type);
        
        if (!isset(self::TEMPLATES[$type])) {
            return null;
        }

        $template = self::TEMPLATES[$type];
        
        if (empty($data)) {
            return $this->get_defaults($type);
        }

        return $this->populate($template, $data);
    }

    private function populate(array $template, array $data): array
    {
        $json = json_encode($template);
        
        foreach ($data as $key => $value) {
            $placeholder = '{' . $key . '}';
            
            if (is_scalar($value)) {
                $json = str_replace($placeholder, addslashes((string)$value), $json);
            }
        }

        $result = json_decode($json, true);
        
        return $this->remove_empty_placeholders($result);
    }

    private function remove_empty_placeholders(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->remove_empty_placeholders($value);
                if (empty($data[$key])) {
                    unset($data[$key]);
                }
            } elseif (is_string($value) && preg_match('/^\{.*\}$/', $value)) {
                unset($data[$key]);
            }
        }
        
        return $data;
    }

    private function get_defaults(string $type): array
    {
        $base = rtrim(AM::instance()->get_public_url(), '/');
        
        $defaults = [
            'organization' => [
                'name' => $this->atomic->get('APP_NAME') ?? 'Organization',
                'url' => $base,
                'logo' => $base . '/assets/img/logo.png',
                'description' => '',
            ],
            'website' => [
                'name' => $this->atomic->get('APP_NAME') ?? 'Website',
                'url' => $base,
                'searchUrl' => $base . '/search',
            ],
        ];

        return isset($defaults[$type]) 
            ? $this->populate(self::TEMPLATES[$type], $defaults[$type])
            : self::TEMPLATES[$type];
    }

    private function __clone() {}
}