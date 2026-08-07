<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;
?>
<!-- Header -->
<header class="w3-container atomic-header">
    <?php
    $current_query = $GET ?? [];
    $build_queue_url = static function (array $query): string {
        $qs = http_build_query($query);
        return '/telemetry' . ($qs !== '' ? ('?' . $qs) : '');
    };
    $state_counts = is_array($state_counts ?? null) ? $state_counts : [];
    $header_title = (string)($title ?? get_title());
    $build_state_url = static function (?string $state = null) use ($current_query, $build_queue_url): string {
        $query = $current_query;
        $query['tab'] = 'queues';
        $query['page'] = 1;
        if ($state === null || $state === '') {
            unset($query['state']);
        } else {
            $query['state'] = $state;
        }
        return $build_queue_url($query);
    };
    $has_state_filter = isset($filters['state']) && in_array($filters['state'], ['failed', 'completed', 'pending', 'running', 'cancel_requested', 'cancelled'], true);
    ?>
    <div class="atomic-header-flex">
        <div class="atomic-header-left">
            <h1 class="atomic-header-title">
                <img src="/favicon.ico" alt="Atomic" class="atomic-header-favicon">
                <?php echo htmlspecialchars($header_title); ?>
            </h1>
        </div>
        <div class="atomic-header-right">
            <div id="queue-header-counters" class="atomic-header-counters">
                <?php if (($state_counts['total'] ?? 0) > 0): ?>
                    <a href="<?php echo htmlspecialchars($build_state_url('failed')); ?>" class="atomic-chip-link <?php echo (($filters['state'] ?? '') === 'failed') ? 'atomic-chip-active' : ''; ?>">
                        <span class="atomic-chip atomic-chip-failed">
                            <i class="fa fa-times"></i><span><?php echo htmlspecialchars((string)($state_counts['failed'] ?? 0)); ?> failed</span>
                        </span>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_state_url('completed')); ?>" class="atomic-chip-link <?php echo (($filters['state'] ?? '') === 'completed') ? 'atomic-chip-active' : ''; ?>">
                        <span class="atomic-chip atomic-chip-success">
                            <i class="fa fa-check"></i><span><?php echo htmlspecialchars((string)($state_counts['completed'] ?? 0)); ?> completed</span>
                        </span>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_state_url('pending')); ?>" class="atomic-chip-link <?php echo (($filters['state'] ?? '') === 'pending') ? 'atomic-chip-active' : ''; ?>">
                        <span class="atomic-chip atomic-chip-queued">
                            <i class="fa fa-clock-o"></i><span><?php echo htmlspecialchars((string)($state_counts['pending'] ?? 0)); ?> waiting</span>
                        </span>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_state_url('cancel_requested')); ?>" class="atomic-chip-link <?php echo (($filters['state'] ?? '') === 'cancel_requested') ? 'atomic-chip-active' : ''; ?>">
                        <span class="atomic-chip atomic-chip-running">
                            <i class="fa fa-exclamation-circle"></i><span><?php echo htmlspecialchars((string)($state_counts['cancel_requested'] ?? 0)); ?> cancel requested</span>
                        </span>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_state_url('cancelled')); ?>" class="atomic-chip-link <?php echo (($filters['state'] ?? '') === 'cancelled') ? 'atomic-chip-active' : ''; ?>">
                        <span class="atomic-chip atomic-chip-canceled">
                            <i class="fa fa-ban"></i><span><?php echo htmlspecialchars((string)($state_counts['cancelled'] ?? 0)); ?> canceled</span>
                        </span>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_state_url()); ?>" class="atomic-chip-link <?php echo !$has_state_filter ? 'atomic-chip-active' : ''; ?>">
                        <span class="atomic-chip atomic-chip-neutral">
                            <i class="fa fa-database"></i><span><?php echo htmlspecialchars((string)($state_counts['total'] ?? 0)); ?> all jobs</span>
                        </span>
                    </a>
                <?php else: ?>
                    <span class="atomic-chip atomic-chip-empty">
                        <i class="fa fa-check"></i><span>Queue empty</span>
                    </span>
                <?php endif; ?>
            </div>
            <div id="queue-header-actions" class="atomic-header-actions">
                <form method="get" action="/telemetry" class="atomic-search-form">
                    <input type="hidden" name="tab" value="queues">
                    <?php if (($filters['state'] ?? '') !== ''): ?>
                        <input type="hidden" name="state" value="<?php echo htmlspecialchars((string)$filters['state']); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="page" value="1">
                    <input type="text" name="uuid" value="<?php echo htmlspecialchars((string)($filters['uuid'] ?? '')); ?>" class="filter-input atomic-search-input" placeholder="Search UUID...">
                    <button type="submit" class="w3-button w3-round w3-small w3-border">
                        <i class="fa fa-search"></i><span>Search</span>
                    </button>
                </form>
                <button id="queue-header-refresh" type="button" class="w3-button w3-round w3-small w3-border">
                    <i class="fa fa-refresh"></i><span>Refresh</span>
                </button>
                <button id="logs-header-refresh" type="button" class="w3-button w3-round w3-small w3-border" style="display:none;">
                    <i class="fa fa-refresh"></i><span>Refresh</span>
                </button>
            </div>
        </div>
    </div>
</header>

<!-- Navigation Tabs -->
<nav class="w3-container atomic-nav">
    <div class="w3-padding-small" style="display:flex; gap:12px; flex-wrap:wrap;">
        <a href="#" class="w3-button atomic-nav-tab atomic-active" data-tab="dashboard"><i class="fa fa-dashboard"></i> Dashboard</a>
        <a href="#" class="w3-button atomic-nav-tab" data-tab="queues"><i class="fa fa-tasks"></i> Queues</a>
        <a href="#" class="w3-button atomic-nav-tab" data-tab="hive"><i class="fa fa-database"></i> Hive</a>
        <a href="#" class="w3-button atomic-nav-tab" data-tab="logs"><i class="fa fa-file-text-o"></i> Logs</a>
    </div>
</nav>