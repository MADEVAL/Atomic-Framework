<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

get_header();
?>
<div class="error-header">
    <div class="error-code">429</div>
    <div class="error-title"><?php _e('error429.title'); ?></div>
    <div class="error-subtitle"><?php _e('error429.subtitle'); ?></div>
</div>
<div class="error-body">
    <img src="<?php echo PUBLIC_URL;?>/assets/img/apple-touch-icon.png" alt="Atomic Framework" class="error-icon">
    <p class="error-description">
        <?php _e('error429.description'); ?>
    </p>
    <div class="error-actions">
        <a href="/" class="btn btn-primary"><?php _e('errorPage.home'); ?></a>
        <a href="javascript:history.back()" class="btn btn-secondary"><?php _e('errorPage.back'); ?></a>
    </div>
</div>
<?php
get_footer();
?>