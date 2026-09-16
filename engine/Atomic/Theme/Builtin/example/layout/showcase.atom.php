<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Hash;

$atomic = App::instance();

/*
 * ─── SECTION MACRO ───────────────────────────────────────────
 * Each section card follows this pattern for DRY demonstration.
 */
$section = static function (string $id, string $title_key, string $desc_key, string $content): string {
    $title = __($title_key);
    $desc  = __($desc_key);
    return <<<HTML
    <section id="section-{$id}" class="showcase-section">
        <div class="section-header"><h2>{$title}</h2><p>{$desc}</p></div>
        <div class="section-body">{$content}</div>
    </section>
    HTML;
};

$kv = static function (string $label, string $value, string $extra = ''): string {
    $v = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    return "<div class=\"kv\"><span class=\"kv-key\">{$label}</span><span class=\"kv-val\" {$extra}>{$v}</span></div>";
};

$badge = static function (string $text, string $color = ''): string {
    $s = $color ? " style=\"background:{$color}\"" : '';
    return "<span class=\"badge\"{$s}>{$text}</span>";
};

get_header();

/*
 * ═══════════════════════════════════════════════════════════════
 * 1. HOOKS & FILTERS
 * ═══════════════════════════════════════════════════════════════
 */
$action_output = '';
$action_called = false;
add_action('example_showcase_action', function (string $msg) use (&$action_output, &$action_called): void {
    $action_output = $msg;
    $action_called = true;
});
do_action('example_showcase_action', 'Fired from showcase layout!');

$filtered = apply_filters('example_greeting', 'World');
$has_hook  = has_action('example_showcase_action') ? 'true' : 'false';
$has_filter = has_filter('example_greeting') ? 'true' : 'false';

echo $section('hooks', 'example.hooks.title', 'example.hooks.desc', ''
    . $kv(__('example.hooks.action'), $action_called ? '✓ ' . $action_output : '✗ Not fired')
    . $kv(__('example.hooks.filter'), '✓ ' . htmlspecialchars((string)$filtered))
    . $kv(__('example.hooks.greeting'), apply_filters('example_greeting', 'Atomic'))
    . $kv('has_action(...)', $has_hook)
    . $kv('has_filter(...)', $has_filter)
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 2. SHORTCODES
 * ═══════════════════════════════════════════════════════════════
 */
$sc_raw  = '[example_button url="https://github.com" class="btn btn-primary"]Visit GitHub[/example_button]';
$sc_card = '[example_card title="Card Title"]This content was passed between shortcode tags and rendered via do_shortcode().[/example_card]';

$sc_rendered  = do_shortcode($sc_raw);
$sc_card_rendered = do_shortcode($sc_card);

echo $section('shortcode', 'example.shortcode.title', 'example.shortcode.desc', ''
    . $kv(__('example.shortcode.raw'), htmlspecialchars($sc_raw))
    . "<div class=\"kv\"><span class=\"kv-key\">" . __('example.shortcode.rendered') . "</span><span class=\"kv-val\">{$sc_rendered} {$sc_card_rendered}</span></div>"
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 3. HEAD & SEO (already demonstrated in head.atom.php above)
 * ═══════════════════════════════════════════════════════════════
 */
echo $section('head', 'example.head.title', 'example.head.desc', ''
    . $kv('get_opengraph()', '✓ Injected in &lt;head&gt; — view page source')
    . $kv('get_twitter_card()', '✓ Injected in &lt;head&gt;')
    . $kv('get_schema(Organization)', '✓ JSON-LD injected')
    . $kv('get_analytics(gtag, ...)', '✓ GA script injected')
    . $kv('get_preconnect()', '✓ fonts.googleapis.com + fonts.gstatic.com')
    . $kv('hreflang_links()', '✓ Auto-generated for en + ru')
    . $kv('get_canonical_link()', '✓ Canonical URL set')
    . $kv('get_manifest()', '✓ Web app manifest linked')
    . $kv('get_favicon()', '✓ Favicon injected')
    . $kv('get_iconset()', '✓ Apple/mobile icons injected')
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 4. ROUTES & DEVICE DETECTION
 * ═══════════════════════════════════════════════════════════════
 */
$segments = url_segments();
echo $section('routes', 'example.routes.title', 'example.routes.desc', ''
    . $kv(__('example.routes.path'), current_path())
    . $kv(__('example.routes.segments'), implode(' / ', $segments))
    . $kv('get_segment(0)', (string)(get_segment(0) ?? '—'))
    . "<div class=\"kv\"><span class=\"kv-key\">" . __('example.routes.checks') . "</span>"
    . "<span class=\"kv-val\">"
    . $badge('is_ssl: ' . (is_ssl() ? '✓' : '✗'), is_ssl() ? '#00b894' : '#636e72')
    . ' ' . $badge('is_ajax: ' . (is_ajax() ? '✓' : '✗'), is_ajax() ? '#00b894' : '#636e72')
    . ' ' . $badge('is_mobile: ' . (is_mobile() ? '✓' : '✗'), is_mobile() ? '#00b894' : '#636e72')
    . ' ' . $badge('is_botblocker: ' . (is_botblocker() ? '✓' : '✗'), is_botblocker() ? '#e17055' : '#636e72')
    . ' ' . $badge('is_telegram: ' . (is_telegram() ? '✓' : '✗'), is_telegram() ? '#0984e3' : '#636e72')
    . ' ' . $badge('is_gs: ' . (is_gs() ? '✓' : '✗'), is_gs() ? '#6c5ce7' : '#636e72')
    . ' ' . $badge('is_404: ' . (is_404() ? '✓' : '✗'), is_404() ? '#d63031' : '#636e72')
    . ' ' . $badge('is_home: ' . (is_home() ? '✓' : '✗'), is_home() ? '#00b894' : '#636e72')
    . "</span></div>"
    . $kv('is_page("example")', is_page('example') ? 'true' : 'false')
    . $kv('is_section("example")', is_section('example') ? 'true' : 'false')
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 5. I18N
 * ═══════════════════════════════════════════════════════════════
 */
$current_locale = get_locale();
$langs = get_languages();
$langs_str = implode(', ', array_map(fn($l) => is_array($l) ? ($l['code'] ?? '?') : (string)$l, $langs));

$plural_count = 3;
$plural_singular = '1 item';
$plural_form = '%d items';

echo $section('i18n', 'example.i18n.title', 'example.i18n.desc', ''
    . $kv('get_locale()', $current_locale)
    . $kv('get_languages()', $langs_str)
    . $kv(__('example.i18n.simple'), __('example.title'))
    . $kv('_e() echo', '') . '<em>' . _e('example.title') . '</em>'
    . $kv(__('example.i18n.context'), _x('example.title', 'showcase'))
    . $kv(__('example.i18n.plural'), _n($plural_singular, $plural_form, $plural_count, ['%d' => $plural_count]))
    . $kv(__('example.i18n.lang_url'), lang_url('/example', 'ru'))
    . $kv('set_locale("ru")', '') . set_locale('ru') . ' ' . $badge('Switched to ru', '#e17055')
    . $kv('content_locale()', (string)content_locale())
    . $kv('__(transient)', __('example.cache.transient'))
    . $kv('_c() cached', __c('example.cache.transient'))
);

// restore
set_locale('en');

/*
 * ═══════════════════════════════════════════════════════════════
 * 6. SECURITY & NONCES
 * ═══════════════════════════════════════════════════════════════
 */
$demo_nonce = create_nonce('example-demo');
$verify_ok  = verify_nonce($demo_nonce, 'example-demo') ? '✓ Valid' : '✗ Invalid';
$verify_bad = verify_nonce('00000000000000000000000000000000', 'example-demo') ? 'Valid' : '✗ Invalid (expected)';

echo $section('security', 'example.security.title', 'example.security.desc', ''
    . $kv(__('example.security.nonce'), $demo_nonce)
    . $kv(__('example.security.verify'), $verify_ok)
    . $kv('verify_nonce(bad token)', $verify_bad)
    . $kv('Hash algo', PASSWORD_DEFAULT)
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 7. CACHE & TRANSIENTS
 * ═══════════════════════════════════════════════════════════════
 */
$transient_val = get_transient('example_theme_loaded');
$transient_val_str = $transient_val !== null ? (string)$transient_val : '(expired or no cache backend)';

$option_val = get_option('example_last_load', '—');
$option_val_str = $option_val !== null ? (string)$option_val : '—';

echo $section('cache', 'example.cache.title', 'example.cache.desc', ''
    . $kv(__('example.cache.transient'), $transient_val_str)
    . $kv(__('example.cache.option'), $option_val_str)
    . $kv('add_option / update_option', '✓ Persisted in DB options table')
    . $kv('delete_option / delete_transient', '✓ Both clear their respective stores')
    . $kv('add_meta / get_meta / update_meta / delete_meta', '✓ Entity metadata API bound to UUID')
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 8. AUTH & ROLES
 * ═══════════════════════════════════════════════════════════════
 */
if (is_authenticated()) {
    $user = atomic_get_current_user();
    $auth_block = ''
        . $kv('is_authenticated()', '✓ true')
        . $kv('is_guest()', is_guest() ? 'true' : 'false')
        . $kv('atomic_get_current_user()', $user ? '✓ ' . get_class($user) : '✗ null')
        . $kv('has_role(admin)', has_role('admin') ? 'true' : 'false')
        . $kv('has_role(seller)', has_role('seller') ? 'true' : 'false')
        . $kv('has_any_role([admin,moderator])', has_any_role(['admin', 'moderator']) ? 'true' : 'false')
        . $kv('is_admin()', is_admin() ? 'true' : 'false')
        . $kv('is_seller()', is_seller() ? 'true' : 'false')
        . $kv('is_buyer()', is_buyer() ? 'true' : 'false')
        . $kv('is_moderator()', is_moderator() ? 'true' : 'false')
        . $kv('is_support()', is_support() ? 'true' : 'false')
        . $kv('is_impersonating()', is_impersonating() ? 'true' : 'false');
} else {
    $auth_block = ''
        . $kv('is_authenticated()', '✗ false')
        . $kv('is_guest()', '✓ true')
        . '<div class="auth-notice">'
        . $badge(__('example.auth.guest'), '#e17055')
        . ' <a href="/login" class="btn btn-sm">' . __('example.auth.login_link') . '</a>'
        . '</div>';
}

echo $section('auth', 'example.auth.title', 'example.auth.desc', $auth_block);

/*
 * ═══════════════════════════════════════════════════════════════
 * 9. FLASH NOTIFICATIONS
 * ═══════════════════════════════════════════════════════════════
 */
$existing = get_notifications(null, false);
$notify_block = '';

if (!empty($existing)) {
    foreach ($existing as $n) {
        $type = is_array($n) ? ($n['type'] ?? 'info') : 'info';
        $text = is_array($n) ? ($n['text'] ?? '') : (string)$n;
        $notify_block .= "<div class=\"flash flash-{$type}\">" . htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8') . "</div>";
    }
}

if (empty($existing)) {
    notify_success('✓ Example success notification');
    notify_info('ℹ Example info notification');
    notify_warning('⚠ Example warning notification');
    notify_error('✗ Example error notification');
    set_flash('example_demo', 'This flash value persists for 1 request.', 1);
    $notify_block .= '<p><em>' . __('example.notify.create') . '</em></p>';
}

$flash_val = get_flash('example_demo', '—');

echo $section('notify', 'example.notify.title', 'example.notify.desc', ''
    . $notify_block
    . $kv('set_flash / get_flash', (string)$flash_val)
    . $kv('has_notifications()', has_notifications() ? 'true' : 'false')
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 10. ASSET MANAGEMENT
 * ═══════════════════════════════════════════════════════════════
 */
echo $section('assets', 'example.assets.title', 'example.assets.desc', ''
    . $kv('enqueue_style("example-core", "~assets/css/example.css")', '✓ Loaded in head')
    . $kv('enqueue_script("example-core", "~assets/js/example.js")', is_mobile() ? '✗ Skipped (mobile)' : '✓ Loaded in footer')
    . $kv(__('example.assets.localized'), '✓ window.ExampleData in console')
    . $kv('localize_script("example-core", {...})', '✓ Passed data to script')
    . $kv(__('example.assets.inline'), is_mobile() ? '✓ Mobile responsive fix' : '✗ Not needed (desktop)')
    . $kv('enqueue_jquery()', '✓ Built-in jQuery preset (not loaded in demo)')
    . $kv('enqueue_bootstrap()', '✓ Built-in Bootstrap preset (not loaded in demo)')
    . $kv('enqueue_w3()', '✓ Built-in W3.CSS preset (not loaded in demo)')
    . $kv('enqueue_fa()', '✓ Built-in Font Awesome 6.5.1 preset (not loaded in demo)')
    . $kv('enqueue_modernizr()', '✓ Built-in Modernizr preset (not loaded in demo)')
    . $kv('enqueue_font("Inter")', '✓ Google Font loader (not loaded in demo)')
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 11. DATE & UTILITY FUNCTIONS
 * ═══════════════════════════════════════════════════════════════
 */
echo $section('utils', 'example.utils.title', 'example.utils.desc', ''
    . $kv(__('example.utils.year'), get_year())
    . $kv(__('example.utils.copyright'), get_copyright_years(2020))
    . $kv(__('example.utils.date'), get_date('Y-m-d H:i:s'))
    . $kv(__('example.utils.encoding'), get_encoding())
    . $kv('get_copy()', get_copy())
    . $kv('atomic_json_encode(...)', htmlspecialchars(atomic_json_encode(['framework' => 'Atomic', 'version' => '0.2.0'], JSON_PRETTY_PRINT)))
);

/*
 * ═══════════════════════════════════════════════════════════════
 * 12. REMOTE & INTEGRATIONS
 * ═══════════════════════════════════════════════════════════════
 */
echo $section('remote', 'example.remote.title', 'example.remote.desc', ''
    . $kv('remote_get($url, $args)', '⚠ Makes real HTTP requests — not called in demo.')
    . $kv('remote_post($url, $data, $args)', '⚠ POST variant with body support.')
    . $kv('remote_head($url, $args)', '⚠ HEAD request variant.')
    . $kv('remote_put($url, $data, $args)', '⚠ PUT request variant.')
    . $kv('telegram($token, $chat_id)', '⚠ Bot API — not called in demo.')
    . $kv('telegram_send($text, ...)', '⚠ Sends message via bot.')
    . $kv('mail_to($to, $subject, $body, $opts)', '⚠ Sends email via configured mailer.')
    . $kv('mail_send($message)', '⚠ Raw mail send.')
    . $kv('mail_check_spf / dkim / dmarc', '⚠ DNS record checker for deliverability.')
    . $kv('ai_connector($key, $provider)', '⚠ OpenAI / Groq / OpenRouter / Globus.')
);

/*
 * ─── FINAL: print all collected notifications ─────────────────
 */
$notifications = get_notifications(null, true);
foreach ($notifications as $n) {
    $type = is_array($n) ? ($n['type'] ?? 'info') : 'info';
    $text = is_array($n) ? ($n['text'] ?? '') : (string)$n;
    echo "<div class=\"flash flash-{$type}\">" . htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8') . "</div>";
}

get_footer();
