<?php

declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

get_header();
?>
<div style="max-width:700px;margin:2rem auto;padding:0 1rem;">
    <h1 style="margin-bottom:1.5rem;">Dashboard</h1>
    <div style="padding:2rem;border:1px solid var(--atomic-border, #e0e0e0);border-radius:12px;background:var(--atomic-bg-card, #fff);">
        <h2>Welcome back<?php echo !empty($user->name) ? ', ' . htmlspecialchars($user->name) : ''; ?>!</h2>
        <p style="color:var(--atomic-text-secondary, #888);margin-top:0.5rem;">
            You are logged in as <strong><?php echo htmlspecialchars($user->email); ?></strong>
        </p>

        <div style="margin-top:1.5rem;padding:1rem;background:var(--atomic-bg-secondary, #f8f9fa);border-radius:8px;border:1px solid var(--atomic-border, #e0e0e0);">
            <p style="font-size:0.85rem;color:var(--atomic-text-secondary, #888);margin-bottom:0.5rem;">Quick Info</p>
            <p>UUID: <code style="color:#4f9cf7;"><?php echo htmlspecialchars($user->uuid); ?></code></p>
            <p>Member since: <?php echo htmlspecialchars($user->created_at); ?></p>
        </div>

        <div style="margin-top:1.5rem;">
            <a href="/logout" style="display:inline-block;padding:0.5rem 1.25rem;border:1px solid #e74c3c;color:#e74c3c;border-radius:8px;text-decoration:none;font-weight:500;">Logout</a>
        </div>
    </div>
</div>
<?php
get_footer();
?>