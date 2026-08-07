<?php

declare(strict_types=1);
if (!defined('ATOMIC_START')) exit;

get_header();
?>
<div style="display:flex;min-height:calc(100vh - 120px);align-items:center;justify-content:center;padding:2rem;">
    <div style="width:100%;max-width:420px;padding:2rem;border:1px solid var(--atomic-border, #e0e0e0);border-radius:12px;background:var(--atomic-bg-card, #fff);">
        <h2 style="text-align:center;margin-bottom:1.5rem;">Welcome Back</h2>

        <div id="alert" style="display:none;padding:0.75rem 1rem;border-radius:8px;background:#fee;color:#c33;margin-bottom:1rem;font-size:0.9rem;"></div>

        <form id="auth-form" method="post" action="/login">
            <input type="hidden" name="atomic_nonce" value="<?php echo create_nonce('login'); ?>">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:0.35rem;">Email</label>
                <input type="email" name="email" required autocomplete="email"
                       style="width:100%;padding:0.65rem 0.75rem;border:1px solid var(--atomic-border, #ddd);border-radius:8px;font-size:0.95rem;box-sizing:border-box;">
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:0.35rem;">Password</label>
                <input type="password" name="password" required autocomplete="current-password"
                       style="width:100%;padding:0.65rem 0.75rem;border:1px solid var(--atomic-border, #ddd);border-radius:8px;font-size:0.95rem;box-sizing:border-box;">
            </div>

            <button type="submit"
                    style="width:100%;padding:0.75rem;background:#4f9cf7;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;">
                Sign In
            </button>
        </form>

        <p style="text-align:center;margin-top:1.25rem;color:var(--atomic-text-secondary, #888);font-size:0.9rem;">
            Don't have an account? <a href="/register" style="color:#4f9cf7;text-decoration:none;">Sign up</a>
        </p>
    </div>
</div>
<?php
get_footer();
?>