<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;
get_head();
?>
<header class="showcase-hero">
    <div class="showcase-hero-content">
        <img src="<?php echo PUBLIC_URL; ?>assets/img/apple-touch-icon.png"
             alt="Atomic" class="showcase-logo" width="64" height="64">
        <h1><?php _e('example.title'); ?></h1>
        <p><?php _e('example.subtitle'); ?></p>
        <div class="showcase-badges">
            <span class="badge">v1.0.0</span>
            <span class="badge"><?php echo count(get_languages()); ?> langs</span>
            <span class="badge">~100 helpers</span>
        </div>
    </div>
</header>
<nav class="showcase-nav" id="showcase-nav">
    <?php
    $sections = [
        'hooks', 'shortcode', 'head', 'routes', 'i18n',
        'security', 'cache', 'auth', 'notify', 'assets', 'utils', 'remote',
    ];
    foreach ($sections as $s): ?>
        <a href="#section-<?php echo $s; ?>"><?php _e("example.nav.{$s}"); ?></a>
    <?php endforeach; ?>
</nav>
