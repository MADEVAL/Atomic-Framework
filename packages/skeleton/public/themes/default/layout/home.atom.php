<?php

declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

get_header();
?>
<section class="atomic-hero">
	<div class="atomic-container">
		<div class="atomic-hero-content">
			<img src="<?php echo PUBLIC_URL;?>/assets/img/apple-touch-icon.png" alt="Atomic Framework Logo" class="atomic-logo">
			<h1 class="atomic-title">Atomic Framework</h1>
			<p class="atomic-subtitle">Fast, Lightweight & Scalable PHP Framework</p>
			<div class="atomic-cta">
				<a href="#features" class="atomic-btn atomic-btn-primary">Explore Features</a>
				<a href="https://github.com/atomic-framework" class="atomic-btn atomic-btn-secondary">View on GitHub</a>
			</div>
		</div>
	</div>
</section>

<section class="atomic-content" id="features">
	<div class="atomic-container">
		<h2 class="atomic-section-title">Built for Modern Development</h2>
		<p class="atomic-section-subtitle">Everything you need to build fast and reliable web applications</p>

		<div class="atomic-description">
Atomic is a lightweight yet powerful PHP framework built on the Fat-Free core, designed for developers who value speed, simplicity, and scalability. It lets you build modern web applications without the overhead - combining an elegant architecture with production-ready features out of the box.
Enjoy a clean plugin system, expressive ORM with validation, and an event-driven core with hooks and queues. Atomic handles caching through Redis, Memcached, databases, or files - giving you performance that scales effortlessly. Configuration is flexible and familiar: use PHP files or .env variables to set up your app in seconds.
Atomic also includes a full theme and resource manager, middleware stack, multilingual support, and CLI tools to accelerate your workflow. Built-in telemetry, debugging, and logging keep your development smooth and transparent.
With support for SQL and MongoDB, database migrations, transient storage, nonces, secure authentication, and CSRF protection - Atomic puts clean architecture and security first. You'll also find ready-to-use systems for emails, notifications, Telegram bots, AI integrations, file operations, and even PDF, XLS, and CSV generation.
Focus on your features - Atomic takes care of the complexity.
		</div>

		<div class="atomic-features">
			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">вљЎ</div>
				<h3 class="atomic-feature-title">Lightning Fast Performance</h3>
				<p class="atomic-feature-text">Built on Fat-Free core with advanced caching supporting Memcached, Redis, databases, and file storage. Optimized for peak performance in production environments.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџЋЇ</div>
				<h3 class="atomic-feature-title">Event-Driven Architecture</h3>
				<p class="atomic-feature-text">Powerful event system with hooks and queues for building reactive applications. Decouple components and build scalable, maintainable solutions.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ”Њ</div>
				<h3 class="atomic-feature-title">Plugin & Middleware System</h3>
				<p class="atomic-feature-text">Extensible plugin architecture and full-featured middleware pipeline. Add functionality without complexity and control request flow with precision.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ—„пёЏ</div>
				<h3 class="atomic-feature-title">Universal Database Support</h3>
				<p class="atomic-feature-text">Built-in ORM with data validation supporting SQL databases and MongoDB. Includes migration system for seamless database version control.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">вљ™пёЏ</div>
				<h3 class="atomic-feature-title">Flexible Configuration</h3>
				<p class="atomic-feature-text">Configure via PHP files or .env variables. Environment-specific settings with support for transients and nonces for secure data handling.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџЋЁ</div>
				<h3 class="atomic-feature-title">Theme System & Resources</h3>
				<p class="atomic-feature-text">Comprehensive theme support with built-in resource manager. Multi-language capabilities for building globally accessible applications.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ›ЎпёЏ</div>
				<h3 class="atomic-feature-title">Security First</h3>
				<p class="atomic-feature-text">CSRF protection, robust authentication, nonces, and security best practices built-in. Your applications are secure from the ground up.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ”§</div>
				<h3 class="atomic-feature-title">Powerful CLI Tools</h3>
				<p class="atomic-feature-text">Command-line interface for rapid development tasks. Generate boilerplate, run migrations, and manage your application from the terminal.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ”Ќ</div>
				<h3 class="atomic-feature-title">Telemetry & Debugging</h3>
				<p class="atomic-feature-text">Built-in Telemetry debugger with comprehensive logging and dump utilities. Identify and resolve issues quickly during development.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ“§</div>
				<h3 class="atomic-feature-title">Communication Tools</h3>
				<p class="atomic-feature-text">Email and notification systems with Telegram integration. Keep users informed across multiple channels effortlessly.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ¤–</div>
				<h3 class="atomic-feature-title">AI Integration</h3>
				<p class="atomic-feature-text">Base connectors for popular AI services. Integrate machine learning and AI capabilities into your applications with ease.</p>
			</div>

			<div class="atomic-feature-card">
				<div class="atomic-feature-icon">рџ“„</div>
				<h3 class="atomic-feature-title">File Generation & Processing</h3>
				<p class="atomic-feature-text">Generate PDF, Excel, and CSV files. Full filesystem operations support for complete file management capabilities.</p>
			</div>
		</div>

		<h2 class="atomic-section-title">Complete Technology Stack</h2>
		<div class="atomic-tech-stack">
			<span class="atomic-tech-badge">PHP 8+</span>
			<span class="atomic-tech-badge">Fat-Free Framework</span>
			<span class="atomic-tech-badge">ORM & Migrations</span>
			<span class="atomic-tech-badge">SQL & MongoDB</span>
			<span class="atomic-tech-badge">Memcached & Redis</span>
			<span class="atomic-tech-badge">Event System</span>
			<span class="atomic-tech-badge">Hooks & Queues</span>
			<span class="atomic-tech-badge">Middleware</span>
			<span class="atomic-tech-badge">Plugin System</span>
			<span class="atomic-tech-badge">Theme Manager</span>
			<span class="atomic-tech-badge">Multi-Language</span>
			<span class="atomic-tech-badge">CLI Tools</span>
			<span class="atomic-tech-badge">Telemetry Debug</span>
			<span class="atomic-tech-badge">Authentication</span>
			<span class="atomic-tech-badge">CSRF Protection</span>
			<span class="atomic-tech-badge">Transients</span>
			<span class="atomic-tech-badge">Email System</span>
			<span class="atomic-tech-badge">Telegram API</span>
			<span class="atomic-tech-badge">AI Connectors</span>
			<span class="atomic-tech-badge">PDF Generation</span>
			<span class="atomic-tech-badge">Excel & CSV</span>
			<span class="atomic-tech-badge">Filesystem API</span>
			<span class="atomic-tech-badge">Secure Nonces</span>
			<span class="atomic-tech-badge">Notification System</span>
			<span class="atomic-tech-badge">.env</span>
			<span class="atomic-tech-badge">Logging & Debugging</span>
			<span class="atomic-tech-badge">Shortcodes</span>
			<span class="atomic-tech-badge">Validation</span>
		</div>
	</div>
</section>
<?php
get_footer();
?>