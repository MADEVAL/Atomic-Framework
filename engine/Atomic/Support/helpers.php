<?php
declare(strict_types=1);

if (!defined('ATOMIC_START')) exit;

// Global functions for Atomic 

use Engine\Atomic\App\Models\Meta;
use Engine\Atomic\App\Models\Options;
use Engine\Atomic\App\PluginManager as PM;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ErrorHandler as EH;
use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Request as HTTP;
use Engine\Atomic\Core\Response as AR;
use Engine\Atomic\Event\Event as AE;
use Engine\Atomic\Event\System as SysEvents;
use Engine\Atomic\Hook\Hook as AH;
use Engine\Atomic\Hook\Shortcode as SC;
use Engine\Atomic\Hook\System as SysHooks;
use Engine\Atomic\Lang\I18n as AI18n;
use Engine\Atomic\Mail\Mailer;
use Engine\Atomic\Mail\MailerUtils as MU;
use Engine\Atomic\Mail\Notifier;
use Engine\Atomic\Tools\Nonce as AN;
use Engine\Atomic\Tools\Transient;
use Engine\Atomic\Tools\Telegram as TG;
use Engine\Atomic\Theme\Assets;
use Engine\Atomic\Theme\Head;
use Engine\Atomic\Theme\OpenGraph as OG;
use Engine\Atomic\Theme\Theme as AT;

use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Enums\Role;

SysEvents::instance()->init();
SysHooks::instance()->init();

// Hook functions
function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool { return AH::instance()->add_action($tag, $callback, $priority, $accepted_args);}
function has_action(string $tag, ?callable $callback = null): bool {return AH::instance()->has_action($tag, $callback);}
function do_action(string $tag, mixed ...$args): void { AH::instance()->do_action($tag, ...$args);}
function remove_action(string $tag, ?callable $callback = null, int $priority = 10): bool {return AH::instance()->remove_action($tag, $callback, $priority);}

// Filter functions
function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool {return AH::instance()->add_filter($tag, $callback, $priority, $accepted_args);}
function has_filter(string $tag, ?callable $callback = null): bool {return AH::instance()->has_filter($tag, $callback);}
function apply_filters(string $tag, mixed $value, mixed ...$args): mixed {return AH::instance()->apply_filters($tag, $value, ...$args);}
function remove_filter(string $tag, ?callable $callback = null, int $priority = 10): bool {return AH::instance()->remove_filter($tag, $callback, $priority);}

// Template functions
function get_header(string $name = 'header', ?array $data = null): void { AT::get_header($name, $data); }
function get_head(?array $data = null): void { AT::get_head($data);}
function get_custom_head(?array $data = null): void { AT::get_customHead($data);}
function get_sidebar(string $name = 'sidebar', ?array $data = null): void { AT::get_sidebar($name, $data);} 
function get_section(string $name, ?array $data = null): void { AT::get_section($name, $data);} 
function get_footer(string $name = 'footer', ?array $data = null): void { AT::get_footer($name, $data);} 
function get_color(): string { return AT::instance()->getThemeColor(); }
function set_color(string $color = '#ffffff'): string { return AT::instance()->setThemeColor($color); }
function get_theme_uri(): string { return AT::instance()->getThemeUrl(); }
function get_theme_dir(): string { return AT::instance()->getThemeDir(); }
function get_public_uri(): string { return AT::instance()->getPublicUrl(); }
function get_public_dir(): string { return AT::instance()->getPublicDir(); }

// Head functions
function get_favicon(): void { Head::instance()->favicon(); }
function get_title(string $delimiter = ' | '): void { Head::instance()->title($delimiter); }
function get_iconset(string $path = ''): void { Head::instance()->iconset($path); }
function get_manifest(): void { Head::instance()->manifest(); }
function get_canonical_link(): void { Head::instance()->canonical(); }
function get_preconnect(?string $preset = null): void { Head::instance()->preconnect($preset); }
function get_preload_links(): void { Head::instance()->preload(); }
function get_analytics(string $system, string $key): void { Head::instance()->analytics($system, $key); }
function get_schema(string $type, array $data = []): void { Head::instance()->schema($type, $data); }
function add_preconnect(string $origin, bool $crossorigin = false): Head { return Head::instance()->addPreconnect($origin, $crossorigin); }
function add_preload(string $href, string $as, ?string $type = null, bool $crossorigin = false): Head { return Head::instance()->addPreload($href, $as, $type, $crossorigin); }

// OpenGraph functions
function get_opengraph(array $data = []): void { OG::instance()->generate($data)->render(); }
function get_twitter_card(array $data = []): void { OG::instance()->generate($data)->renderTwitter(); }

// Assets functions
function enqueue_style(string $handle, string $src, array $deps = [], ?string $version = null, string $media = 'all'): void { Assets::instance()->enqueueStyle($handle, $src, $deps, $version, $media);}
function enqueue_script(string $handle, string $src, array $deps = [], ?string $version = null, bool $inFooter = true, array $attrs = []): void { Assets::instance()->enqueueScript($handle, $src, $deps, $version, $inFooter, $attrs);}
function set_script_attrs(string $handle, array $attrs): void {Assets::instance()->setScriptAttrs($handle, $attrs);}
function localize_script(string $handle, array $data, ?string $varName = null): void {Assets::instance()->localizeScript($handle, $data, $varName);}
function print_styles(): void { Assets::instance()->printStyles(); }
function print_scripts(string $position = 'header'): void { $inFooter = ($position === 'footer'); Assets::instance()->printScripts($inFooter);}
function dequeue_style(string $handle): void { Assets::instance()->dequeueStyle($handle); }
function dequeue_script(string $handle): void { Assets::instance()->dequeueScript($handle); }
function add_inline_style(string $handle, string $css): void { Assets::instance()->addInlineStyle($handle, $css); }
function add_inline_script(string $handle, string $js, string $position = 'footer'): void { Assets::instance()->addInlineScript($handle, $js, $position); }
// Preset asset functions
function enqueue_jquery(): void { Assets::instance()->enqueuePreset('jquery'); }
function enqueue_bootstrap(): void { Assets::instance()->enqueuePreset('bootstrap'); }
function enqueue_w3(): void { Assets::instance()->enqueuePreset('w3'); }
function enqueue_fa(): void { Assets::instance()->enqueuePreset('fa'); }
function enqueue_modernizr(): void { Assets::instance()->enqueuePreset('modernizr'); }
function enqueue_font(string $font): void { Assets::instance()->enqueueFont($font); }

// Atomic functions
function current_path(bool $stripLang = true): string { return AM::instance()->get_currentPath($stripLang); }
function url_segments(bool $stripLang = true): array { return AM::instance()->segments($stripLang); }
function get_segment(int $index, ?string $default = null, bool $stripLang = true): ?string { return AM::instance()->segment($index, $default, $stripLang); }
function is_home(): bool { return AM::instance()->is_home(); }
function is_page(string|array $patterns, bool $stripLang = true): bool { return AM::instance()->is_page($patterns, $stripLang); }
function is_section(string $prefix, bool $stripLang = true): bool { return AM::instance()->is_section($prefix, $stripLang); }
function is_ssl(): bool { return AM::instance()->get_isSecure(); }
function is_ajax(): bool { return AM::instance()->get_isAjax(); }
function is_mobile(): bool { return AM::instance()->is_mobile();}
function is_404(): bool { return AM::instance()->is_404(); }
function is_telegram(): bool { return AM::instance()->is_telegram(); }
function is_botblocker(): bool { return AM::instance()->is_botblocker();}
function is_gs(): bool { return AM::instance()->is_gs(); }
function get_encoding(): string { return AM::instance()->get_encoding(); }
function get_year(): string { return date('Y'); }
function get_copyright_years(int $startYear): string { return $startYear . ' - ' . date('Y'); }
function get_date(string $format = 'Y-m-d'): string { return date($format); }
function get_copy(): string { return '©'; }
function get_error_trace(): string { return AM::instance()->get_formatErrorTrace(); }

// Nonce functions
function create_nonce(string $action = '', int $ttl = 3600): string { return AN::instance()->create_nonce($action, $ttl);}
function verify_nonce(string $token, string $action = ''): bool { return AN::instance()->verify_nonce($token, $action); }  

// Response functions
function send_json(mixed $data, int $status = 200, bool $terminate = true): void{AR::instance()->send_json($data, $status, $terminate);}
function send_json_error(string $msg, int $status = 400, array $extra = [], bool $terminate = true): void{ AR::instance()->send_json_error($msg, $status, $extra, $terminate);}
function send_json_success(array $data = [], int $status = 200, bool $terminate = true): void{ AR::instance()->send_json_success($data, $status, $terminate);}
function json_response(mixed $data, int $status = 200, bool $terminate = true): void{ AR::instance()->jsonResponse($data, $status, $terminate);}
function atomic_json_encode(mixed $data, int $flags = 0, int $depth = 512): string{return AR::instance()->atomic_json_encode($data, $flags, $depth);}

// Shortcode functions
function add_shortcode(string $tag, callable $callback): void { SC::instance()->add_shortcode($tag, $callback); }
function do_shortcode(string $text): string { return SC::instance()->do_shortcode($text); }
function remove_shortcode(string $tag): void { SC::instance()->remove_shortcode($tag); }

// Transient functions
function set_transient(string $name, mixed $value, int $expiration = 0, ?string $driver = null): mixed { return Transient::set($name, $value, $expiration, $driver);}
function get_transient(string $name, ?string $driver = null): mixed { return Transient::get($name, $driver); }
function delete_transient(string $name, ?string $driver = null): bool { return Transient::delete($name, $driver);}
function delete_all_transients(?string $driver = null): bool { return Transient::delete_all($driver);}

// Languages functions
// UI locale functions if UI language was detected
function __(string $key, array $vars = [], ?string $domain = null, ?string $lang = null): string {return AI18n::instance()->t($key, $vars, $domain, $lang); }
function _e(string $key, array $vars = [], ?string $domain = null, ?string $lang = null): void { echo AI18n::instance()->t($key, $vars, $domain, $lang); }
function _x(string $key, string $context, array $vars = [], ?string $domain = null, ?string $lang = null): string {return AI18n::instance()->tx($key, $context, $vars, $domain, $lang);}
function _n(string $singular, string $plural, int $count, array $vars = [], ?string $domain = null, ?string $lang = null): string { return AI18n::instance()->tn($singular, $plural, $count, $vars, $domain, $lang); }
// Content locale functions if set_locale() used
function __c(string $key, array $vars = [], ?string $domain = null, ?string $lang = null): string {return AI18n::instance()->t($key, $vars, $domain, $lang ?? content_locale());}
function _ec(string $key, array $vars = [], ?string $domain = null, ?string $lang = null): void {echo __c($key, $vars, $domain, $lang);}
function _xc(string $key, string $context, array $vars = [], ?string $domain = null, ?string $lang = null): string {return AI18n::instance()->tx($key, $context, $vars, $domain, $lang ?? content_locale());}
function _nc(string $singular, string $plural, int $count, array $vars = [], ?string $domain = null, ?string $lang = null): string {return AI18n::instance()->tn($singular, $plural, $count, $vars, $domain, $lang ?? content_locale());}
// Locale management functions
function set_locale(string $lang): void { AI18n::instance()->set($lang); }
function get_locale(): string { return AI18n::instance()->get(); }
function content_locale(?string $lang = null): string {if ($lang !== null) AI18n::instance()->setContent($lang); return AI18n::instance()->getContent();}
function get_languages(): array { return AI18n::instance()->languages(); }
// URL functions
function lang_url(string $path = '/', ?string $lang = null): string { return AI18n::instance()->url($path, $lang);}
function hreflang_links(string $path = '/'): string { return AI18n::instance()->hreflang($path);}

// HTTP functions
function remote_get(string $url, array $args = []): array  { return HTTP::instance()->remote_get($url, $args); }
function remote_head(string $url, array $args = []): array { return HTTP::instance()->remote_head($url, $args); }
function remote_post(string $url, mixed $data = null, array $args = []): array { return HTTP::instance()->remote_post($url, $data, $args); }
function remote_put(string $url, mixed $data = null, array $args = []): array  { return HTTP::instance()->remote_put($url, $data, $args); }

// Telegram functions
function telegram(?string $token = null, ?string $chatId = null): TG { return TG::instance($token, $chatId); }
function telegram_send(string $text, ?string $chatId = null, array $opts = []): array { return TG::instance()->send($text, $chatId, $opts); }

// Options functions
function add_option(string $key, string $value): bool { return Options::set_option($key, $value); }
function update_option(string $key, string $value): bool { return Options::set_option($key, $value); }
function get_option(string $key, mixed $default = null): mixed { return Options::get_option($key, $default); }
function delete_option(string $key): bool { return Options::delete_option($key); }

// User functions
function atomic_get_current_user(): ?AuthenticatableInterface { return Auth::instance()->get_current_user(); }
function is_authenticated(): bool { return Guard::is_authenticated(); }
function is_guest(): bool { return Guard::is_guest(); }
function has_role(string|\BackedEnum $role): bool { return Guard::has_role($role); }
function has_any_role(array $roles): bool { return Guard::has_any_role($roles); }
function is_admin(): bool { return Guard::has_role(Role::ADMIN); }
function is_seller(): bool { return Guard::has_role(Role::SELLER); }
function is_buyer(): bool { return Guard::has_role(Role::BUYER); }
function is_moterator(): bool { return Guard::has_role(Role::MODERATOR); }
function is_support(): bool { return Guard::has_role(Role::SUPPORT); }

// Impersonation functions
function is_impersonating(): bool { return Auth::instance()->is_impersonating(); }
function get_real_admin(): ?AuthenticatableInterface { return Auth::instance()->get_real_admin(); }

// Meta functions
function add_meta(string $uuid, string $key, string $value): bool { return Meta::set_meta($uuid, $key, $value); }
function update_meta(string $uuid, string $key, string $value): bool { return Meta::set_meta($uuid, $key, $value); }
function get_meta(string $uuid, string $key, mixed $default = null): mixed { return Meta::get_meta($uuid, $key, $default); }
function delete_meta(string $uuid, string $key): bool { return Meta::delete_meta($uuid, $key); }

// Database functions

// Misc functions

// Error handling functions
function format_error_trace(int $code, string $text, string $trace): string {return EH::instance()->formatTrace($code, $text, $trace);}

// Plugin functions
function plugin_manager(): PM { return PM::instance(); }
function get_plugin(string $name): mixed { return PM::instance()->get($name); }
function has_plugin(string $name): bool { return PM::instance()->has($name); }
function enable_plugin(string $name): bool { return PM::instance()->enable($name); }
function disable_plugin(string $name): bool { return PM::instance()->disable($name); }

// Notifier functions
function notify(string $text, string $type = 'info', array $data = []): Notifier { return Notifier::instance()->add($text, $type, $data); }
function notify_success(string $text, array $data = []): Notifier { return Notifier::instance()->success($text, $data); }
function notify_info(string $text, array $data = []): Notifier { return Notifier::instance()->info($text, $data); }
function notify_warning(string $text, array $data = []): Notifier { return Notifier::instance()->warning($text, $data); }
function notify_error(string $text, array $data = []): Notifier { return Notifier::instance()->error($text, $data); }
function get_notifications(?string $type = null, bool $clear = true): array { return Notifier::instance()->get($type, $clear); }
function has_notifications(?string $type = null): bool { return Notifier::instance()->has($type); }
function clear_notifications(?string $type = null): Notifier { return Notifier::instance()->clear($type); }
function set_flash(string $key, mixed $value, int $lifetime = 1): Notifier { return Notifier::instance()->setFlash($key, $value, $lifetime); }
function get_flash(string $key, mixed $default = null): mixed { return Notifier::instance()->getFlash($key, $default); }
function peek_flash(string $key, mixed $default = null): mixed { return Notifier::instance()->peekFlash($key, $default); }
function has_flash(string $key): bool { return Notifier::instance()->hasFlash($key); }

// Mail functions
function mail_to(string $email, ?string $name = null): Mailer { return Mailer::instance()->reset()->addTo($email, $name); }
function mail_send(string $to, string $subject, string $message, bool $html = true): bool { 
    return $html 
        ? Mailer::instance()->reset()->addTo($to)->setHTML($message)->send($subject)
        : Mailer::instance()->reset()->addTo($to)->setText($message)->send($subject);
}
function mail_check_spf(string $domain): array { return MU::instance()->checkSPF($domain); }
function mail_check_dkim(string $domain, string $selector): array { return MU::instance()->checkDKIM($domain, $selector); }
function mail_check_dmarc(string $domain): array { return MU::instance()->checkDMARC($domain); }
function mail_analyze(string $domain, string $selector = ''): array { return MU::instance()->analyzeDeliverability($domain, $selector); }