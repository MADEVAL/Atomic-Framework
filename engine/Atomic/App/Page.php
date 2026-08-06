<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

use DB\Cortex\Schema\Schema;
use Engine\Atomic\Enums\Rule;

if (!defined('ATOMIC_START')) exit;

abstract class Page extends Model
{
    protected $db = 'DB';

    protected $fieldConf = [
        'route' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'template' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'title' => [
            'type' => Schema::DT_VARCHAR256,
            'required' => true,
            'rules' => [Rule::STR_MIN],
            'min' => 3,
        ],
        'slug' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => false,
            'required' => true,
            'unique' => true,
        ],
        'subtitle' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'excerpt' => [
            'type' => Schema::DT_TEXT,
            'nullable' => true,
        ],
        'content' => [
            'type' => Schema::DT_LONGTEXT,
            'nullable' => true,
        ],
        'content_type' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'default' => 'page',
        ],
        'category' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
        ],
        'main_image' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'thumbnail' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'gallery' => [
            'type' => Schema::DT_LONGTEXT,
            'nullable' => true,
        ],
        'attributes' => [
            'type' => Schema::DT_LONGTEXT,
            'nullable' => true,
        ],
        'custom_fields' => [
            'type' => Schema::DT_LONGTEXT,
            'nullable' => true,
        ],
        'seo_title' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'seo_description' => [
            'type' => Schema::DT_VARCHAR512,
            'nullable' => true,
        ],
        'meta_keywords' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'og_title' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'og_description' => [
            'type' => Schema::DT_VARCHAR512,
            'nullable' => true,
        ],
        'og_image' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'is_published' => [
            'type' => Schema::DT_BOOL,
            'nullable' => false,
            'default' => 0,
        ],
        'publish_at' => [
            'type' => Schema::DT_DATETIME,
            'nullable' => true,
        ],
        'expires_at' => [
            'type' => Schema::DT_DATETIME,
            'nullable' => true,
        ],
        'author' => [
            'type' => Schema::DT_INT,
        ],
        'views' => [
            'type' => Schema::DT_INT,
            'nullable' => false,
            'default' => 0,
        ],
        'rating' => [
            'type' => Schema::DT_FLOAT,
            'nullable' => true,
            'default' => 0,
        ],
        'sort_order' => [
            'type' => Schema::DT_INT,
            'nullable' => true,
            'default' => 0,
        ],
        'tags' => [
            'type' => Schema::DT_VARCHAR512,
            'nullable' => true,
        ],
        'language' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => true,
            'default' => 'en',
        ],
        'status' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => true,
            'default' => 'draft',
        ],
        'uuid' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => true,
            'unique' => true,
        ],
        'created_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'updated_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
    ];
}
