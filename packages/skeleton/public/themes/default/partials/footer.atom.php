<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;
?>

<!-- Footer -->
<footer class="atomic-footer">
	<?php if (!is_page('/login') && !is_page('/register')): ?>
	<div class="atomic-container">
		<div class="atomic-footer-brand">Atomic Framework</div>
		<div class="atomic-footer-tagline">Power in minimalism</div>
		
		<div class="atomic-footer-links">
			<a href="#features" class="atomic-footer-link">Features</a>
			<a href="https://github.com/atomic-framework" class="atomic-footer-link">GitHub</a>
			<a href="/docs" class="atomic-footer-link">Documentation</a>
			<a href="/community" class="atomic-footer-link">Community</a>
		</div>
		
		<div class="atomic-footer-divider"></div>
		
		<div class="atomic-footer-copy">
			В© 2025 Atomic Framework. All rights reserved.
		</div>
		<div class="atomic-footer-developer">
			Developed by <a href="https://globus.studio" target="_blank">GLOBUS.studio</a>
		</div>
	</div>
	<?php endif; ?>
</footer>
<!-- Footer -->

<?php
    print_scripts('footer');
?>
</body>
</html>