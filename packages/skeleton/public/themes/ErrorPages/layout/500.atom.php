<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;
use Engine\Atomic\Core\App;
get_header();
?>
<div class="error-header">
    <div class="error-code">500</div>
    <div class="error-title"><?php _e('error500.title'); ?></div>
    <div class="error-subtitle"><?php _e('error500.subtitle'); ?></div>
</div>
<div class="error-body">
    <img src="<?php echo PUBLIC_URL;?>/assets/img/apple-touch-icon.png" alt="Atomic Framework" class="error-icon">
    <p class="error-description">
        <?php _e('error500.description'); ?>
    </p>
    <div class="error-actions">
        <a href="/" class="btn btn-primary"><?php _e('errorPage.home'); ?></a>
        <a href="javascript:history.back()" class="btn btn-secondary"><?php _e('errorPage.back'); ?></a>
    </div>
    
    <?php 
    $trace = App::instance()->get('ERROR.formatted_trace');
    if ($trace && is_string($trace)): 
    ?>
    <div class="trace-container">
        <div class="trace-header">Stack Trace</div>
        <div class="trace-content">
            <pre id="trace-output"><?php echo htmlspecialchars($trace, ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>
    </div>
    <?php endif; ?>
</div>