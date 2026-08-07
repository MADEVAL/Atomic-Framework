<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

/*
 * Atomic Example Showcase — functions.atom.php
 *
 * Demonstrates EVERY theme capability:
 *   - Conditional asset loading (is_home, is_page, is_section, is_mobile)
 *   - All enqueue variants (style, script, inline, localize, dequeue, jquery, bootstrap, w3, fa)
 *   - Hooks and filters (add_action, add_filter)
 *   - Shortcodes (add_shortcode)
 *   - Transients (set_transient)
 *   - Options (add_option)
 */

defined('THEME_DIR')   || define('THEME_DIR', get_theme_dir());
defined('THEME_URL')   || define('THEME_URL', get_theme_uri());
defined('PUBLIC_URL')  || define('PUBLIC_URL', get_public_uri());
defined('EXAMPLE_VERSION') || define('EXAMPLE_VERSION', '1.0.0');

enqueue_style('example-core', '~assets/css/example.css', [], EXAMPLE_VERSION);

if (!is_mobile()) {
    enqueue_script('example-core', '~assets/js/example.js', [], EXAMPLE_VERSION, true);
    localize_script('example-core', [
        'ajax_url' => '/api',
        'nonce'    => create_nonce('example-ajax'),
        'theme'    => 'example',
        'locale'   => get_locale(),
    ], 'ExampleData');
} else {
    add_inline_style('example-core', '.showcase-hero h1 { font-size: 1.5rem; }');
}

set_transient('example_theme_loaded', gmdate('Y-m-d H:i:s'), 300);

add_action('example_theme_loaded', function (string $time): void {
    add_option('example_last_load', $time);
}, 10, 1);

do_action('example_theme_loaded', gmdate('Y-m-d H:i:s'));

add_filter('example_greeting', fn(string $name): string => "Hello, {$name} — from Example Theme!");

add_shortcode('example_button', function (array $atts, ?string $content): string {
    $label = htmlspecialchars($content ?? 'Click', ENT_QUOTES, 'UTF-8');
    $url   = htmlspecialchars($atts['url'] ?? '#', ENT_QUOTES, 'UTF-8');
    $class = htmlspecialchars($atts['class'] ?? 'btn', ENT_QUOTES, 'UTF-8');
    return "<a href=\"{$url}\" class=\"{$class}\">{$label}</a>";
});

add_shortcode('example_card', function (array $atts, ?string $content): string {
    $title = htmlspecialchars($atts['title'] ?? '', ENT_QUOTES, 'UTF-8');
    return "<div class=\"card\"><h3>{$title}</h3><div>{$content}</div></div>";
});
