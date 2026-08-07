<?php
declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;
?>
<footer class="showcase-footer">
    <div class="showcase-footer-inner">
        <div class="showcase-footer-left">
            <strong>Atomic Framework</strong>
            <span>&copy; <?php echo get_copyright_years(2020); ?> <?php echo get_copy(); ?></span>
        </div>
        <div class="showcase-footer-right">
            <span><?php _e('example.footer.tagline'); ?></span>
            <span class="showcase-footer-dot">·</span>
            <span><?php _e('errorPage.developer'); ?> <a href="https://globus.studio" target="_blank" rel="noopener">GLOBUS.studio</a></span>
        </div>
    </div>
</footer>
<?php print_scripts('footer'); ?>
</body>
</html>
