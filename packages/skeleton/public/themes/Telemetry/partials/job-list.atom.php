<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

$queue_pagination = is_array($pagination ?? null) ? $pagination : [];
$queue_page_raw = (int)($queue_pagination['page'] ?? 1);
$queue_page = max(1, $queue_page_raw);
$queue_per_page = max(1, (int)($queue_pagination['per_page'] ?? 50));
$queue_total = max(0, (int)($queue_pagination['total'] ?? (is_array($jobs ?? null) ? count($jobs) : 0)));
$queue_last_page = max(1, (int)($queue_pagination['last_page'] ?? ($queue_per_page > 0 ? ceil($queue_total / $queue_per_page) : 1)));
$queue_active_page = min($queue_page, $queue_last_page);
$queue_from = $queue_total > 0 ? (($queue_active_page - 1) * $queue_per_page) + 1 : 0;
$queue_to = $queue_total > 0 ? min($queue_total, $queue_active_page * $queue_per_page) : 0;

$build_queue_url = static function (array $query): string {
    $qs = http_build_query($query);
    return '/telemetry' . ($qs !== '' ? ('?' . $qs) : '');
};

$build_queue_page_url = static function (int $target_page) use ($build_queue_url, $queue_per_page): string {
    $query = $_GET;
    $query['page'] = max(1, $target_page);
    $query['per_page'] = $queue_per_page;
    return $build_queue_url($query);
};

$build_queue_per_page_url = static function (int $target_per_page) use ($build_queue_url): string {
    $query = $_GET;
    $query['page'] = 1;
    $query['per_page'] = max(1, $target_per_page);
    return $build_queue_url($query);
};
?>
<!-- Job List -->
<?php if (!empty($jobs)): ?>
    <div class="w3-card-4 w3-round atomic-job-list">
        <?php $i = 0; foreach ($jobs as $uuid => $job): $i++; $job_state = (string)($job['state'] ?? ''); ?>
                <!-- Job Item with filter data attributes -->
                <div class="atomic-job-item" 
                     data-driver="<?php echo htmlspecialchars($job['driver']); ?>"
                     data-queue="<?php echo htmlspecialchars($job['queue']); ?>"
                     data-state="<?php echo htmlspecialchars($job_state); ?>"
                     data-created-at="<?php echo htmlspecialchars($job['created_at']); ?>"
                     data-created-at-formatted="<?php echo htmlspecialchars($job['created_at_formatted']); ?>"
                     >
                    
                    <!-- Job Separator (except for first job) -->
                    <?php if ($i > 1): ?>
                        <div class=" at-border-dark-grey" style="border-width: 2px !important;"></div>
                    <?php endif; ?>
                
                <!-- Job Header (Clickable) -->
                <div class="atomic-job-header w3-hover-light-grey" onclick="toggleJobDetails(this)" data-driver="<?php echo htmlspecialchars($job['driver']); ?>">
                    <div class="atomic-job-row">
                        <div class="atomic-job-main">
                            <div class="atomic-job-icon-wrapper">
                                <?php if ($job_state == 'running'): ?>
                                    <div class="atomic-job-icon atomic-icon-running"><i class="fa fa-cogs w3-spin"></i></div>
                                <?php elseif ($job_state == 'cancel_requested'): ?>
                                    <div class="atomic-job-icon atomic-icon-cancel-requested"><i class="fa fa-exclamation-circle"></i></div>
                                <?php elseif (in_array($job_state, ['canceled', 'cancelled'], true)): ?>
                                    <div class="atomic-job-icon atomic-icon-canceled"><i class="fa fa-ban"></i></div>
                                <?php elseif ($job_state == 'completed'): ?>
                                    <div class="atomic-job-icon atomic-icon-success"><i class="fa fa-check"></i></div>
                                <?php elseif ($job_state == 'failed'): ?>
                                    <div class="atomic-job-icon atomic-icon-failed"><i class="fa fa-times"></i></div>
                                <?php else: ?>
                                    <div class="atomic-job-icon atomic-icon-queued"><i class="fa fa-clock-o"></i></div>
                                <?php endif; ?>
                            </div>
                            <div class="atomic-job-text">
                                <div class="atomic-job-title">
                                    <span class="atomic-job-uuid"><?php echo htmlspecialchars($uuid); ?></span>
                                    <button class="atomic-copy-btn" title="Copy UUID" onclick="event.stopPropagation(); copyToClipboard('<?php echo htmlspecialchars($uuid); ?>', this)"><i class="fa fa-clone"></i></button>
                                </div>
                                <div class="atomic-job-meta">
                                    <span><strong>Created:</strong> <?php echo htmlspecialchars($job['created_at_formatted']); ?></span>
                                    <?php if ($job_state == 'running' && !empty($job['process_start_ticks'])): ?>
                                        <span class="meta-sep">вЂў</span><span><strong>Started:</strong> <?php echo htmlspecialchars($job['process_start_ticks']); ?></span>
                                    <?php endif; ?>
                                    <span class="meta-sep">вЂў</span>
                                    <span>
                                        <?php if ($job['driver'] == 'redis'): ?>
                                            <i class="fa fa-server w3-text-blue"></i> Redis
                                        <?php else: ?>
                                            <i class="fa fa-database w3-text-green"></i> Database
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="atomic-job-expand-icon"><i class="fa fa-chevron-right"></i></div>
                    </div>
                </div>
                
                <!-- Job Details Panel (Separate from clickable header) -->
                <div class="w3-hide atomic-job-details-panel">
                    <!-- Job Info Section -->
                    <div class="w3-container w3-padding">
                        <div class="w3-text-grey w3-medium atomic-info-header">
                            <i class="fa fa-info-circle w3-margin-right"></i>Job Information
                        </div>
                        
                        <!-- Vertical Layout for Job Information -->
                        <div class="w3-margin-top-off">
                            <!-- UUID Row with Copy Button -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12 m12">
                                    <div class="w3-text-grey w3-small">UUID:</div>
                                    <div class="w3-margin-top-off" style="display: flex; justify-content: space-between; align-items: center;">
                                        <div class="at-text-coral w3-small"><?php echo htmlspecialchars($uuid); ?></div>
                                        <button class="atomic-copy-btn" 
                                                onclick="copyToClipboard('<?php echo htmlspecialchars($uuid); ?>', this)"
                                                title="Copy UUID to clipboard">
                                            <i class="fa fa-clone"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Queue Row -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12">
                                    <div class="w3-text-grey w3-small">Queue:</div>
                                    <div class="at-text-coral w3-small w3-margin-top-off"><?php echo htmlspecialchars($job['queue']); ?></div>
                                </div>
                            </div>

                            <!-- Driver Row -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12">
                                    <div class="w3-text-grey w3-small">Driver:</div>
                                    <div class="at-text-coral w3-small w3-margin-top-off">
                                        <?php if ($job['driver'] == 'redis'): ?>
                                            <i class="fa fa-server w3-margin-right"></i>Redis
                                        <?php else: ?>
                                            <i class="fa fa-database w3-margin-right"></i>Database
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Max Attempts Row -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12">
                                    <div class="w3-text-grey w3-small">Max Attempts:</div>
                                    <div class="at-text-coral w3-small w3-margin-top-off"><?php echo htmlspecialchars($job['max_attempts']); ?></div>
                                </div>
                            </div>
                            
                            <!-- Attempts Row -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12">
                                    <div class="w3-text-grey w3-small">Attempts:</div>
                                    <div class="at-text-coral w3-small w3-margin-top-off"><?php echo htmlspecialchars($job['attempts']); ?></div>
                                </div>
                            </div>

                            <!-- Timeout Row -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12">    
                                    <div class="w3-text-grey w3-small">Timeout (seconds):</div>
                                    <div class="at-text-coral w3-small w3-margin-top-off"><?php echo htmlspecialchars($job['timeout']); ?></div>
                                </div>
                            </div>

                            <!-- Retry Delay Row -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12">    
                                    <div class="w3-text-grey w3-small">Retry Delay (seconds):</div>
                                    <div class="at-text-coral w3-small w3-margin-top-off"><?php echo htmlspecialchars($job['retry_delay']); ?></div>
                                </div>
                            </div>

                            <!-- Created At Row with Copy Button -->
                            <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                <div class="w3-col s12 m12">    
                                    <div class="w3-text-grey w3-small">Created At:</div>
                                    <div class="w3-margin-top-off" style="display: flex; justify-content: space-between; align-items: center;">
                                        <div class="at-text-coral w3-small"><?php echo htmlspecialchars($job['created_at_formatted']); ?></div>
                                        <div style="display: flex; gap: 8px;">
                                            <button class="atomic-copy-btn" 
                                                    onclick="copyToClipboard('<?php echo htmlspecialchars($job['created_at_formatted']); ?>', this)"
                                                    title="Copy formatted timestamp">
                                                <i class="fa fa-calendar"></i>
                                            </button>
                                            <button class="atomic-copy-btn" 
                                                    onclick="copyToClipboard('<?php echo htmlspecialchars($job['created_at']); ?>', this)"
                                                    title="Copy Unix timestamp">
                                                <i class="fa fa-clock-o"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php
                                $cancel_requested_at_raw = $job['cancel_requested_at'] ?? '';
                                $cancelled_at_raw = $job['cancelled_at'] ?? '';
                                $cancel_reason = trim((string)($job['reason'] ?? ''));
                                $cancel_requested_at = (string)($job['cancel_requested_at_formatted'] ?? '');
                                $cancelled_at = (string)($job['cancelled_at_formatted'] ?? '');
                                $has_cancel_details = $cancel_requested_at !== '' || $cancelled_at !== '' || $cancel_reason !== '';
                            ?>
                            <?php if ($has_cancel_details): ?>
                                <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                    <div class="w3-col s12">
                                        <div class="w3-text-grey w3-medium atomic-info-header w3-margin-bottom">
                                            <i class="fa fa-ban w3-margin-right"></i>Cancellation
                                        </div>
                                    </div>
                                </div>

                                <?php if ($cancel_requested_at !== ''): ?>
                                    <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                        <div class="w3-col s12 m12">
                                            <div class="w3-text-grey w3-small">Cancel Requested At:</div>
                                            <div class="w3-margin-top-off" style="display: flex; justify-content: space-between; align-items: center;">
                                                <div class="at-text-coral w3-small"><?php echo htmlspecialchars($cancel_requested_at); ?></div>
                                                <button class="atomic-copy-btn"
                                                        onclick="copyToClipboard('<?php echo htmlspecialchars((string)$cancel_requested_at_raw); ?>', this)"
                                                        title="Copy raw timestamp">
                                                    <i class="fa fa-clock-o"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($cancelled_at !== ''): ?>
                                    <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                        <div class="w3-col s12 m12">
                                            <div class="w3-text-grey w3-small">Cancelled At:</div>
                                            <div class="w3-margin-top-off" style="display: flex; justify-content: space-between; align-items: center;">
                                                <div class="at-text-coral w3-small"><?php echo htmlspecialchars($cancelled_at); ?></div>
                                                <button class="atomic-copy-btn"
                                                        onclick="copyToClipboard('<?php echo htmlspecialchars((string)$cancelled_at_raw); ?>', this)"
                                                        title="Copy raw timestamp">
                                                    <i class="fa fa-clock-o"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($cancel_reason !== ''): ?>
                                    <div class="w3-row w3-padding-small w3-border-bottom at-border-grey">
                                        <div class="w3-col s12">
                                            <div class="w3-text-grey w3-small">Cancellation Reason:</div>
                                            <div class="at-text-coral w3-small w3-margin-top-off"><?php echo htmlspecialchars($cancel_reason); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <!-- Error Row (if applicable) -->
                            <?php if ($job_state == 'failed' && !empty($job['exception'])): ?>
                                <div class="w3-row w3-padding-small at-border-grey">
                                    <div class="w3-col s12">
                                        <div class="w3-text-grey w3-medium atomic-info-header w3-margin-bottom">
                                            <i class="fa fa-exclamation-triangle w3-text-red w3-margin-right"></i>Exception
                                        </div>

                                        <!-- Summary (message, file:line) gets rendered by JS if possible; fallback shows raw JSON -->
                                        <div id="exception-<?php echo htmlspecialchars($uuid); ?>" class="w3-margin-top-off">
                                            <!-- Raw JSON if available; JS will try to parse -->
                                            <pre class="w3-code w3-round atomic-payload-code w3-hide"><code class="json-exception"><?php echo json_encode($job['exception']); ?></code></pre>

                                            <!-- Fallback discrete fields for JS to assemble nicely -->
                                            <span class="exception-message w3-hide"><?php echo htmlspecialchars($job['exception']['message'] ?? ''); ?></span>
                                            <span class="exception-file w3-hide"><?php echo htmlspecialchars($job['exception']['file'] ?? ''); ?></span>
                                            <span class="exception-line w3-hide"><?php echo htmlspecialchars($job['exception']['line'] ?? ''); ?></span>
                                            <pre class="exception-trace w3-hide"><code><?php echo htmlspecialchars($job['exception']['trace_string'] ?? ''); ?></code></pre>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Payload Section -->
                    <div class="w3-container w3-padding  at-border-grey">
                        <div class="w3-row">
                            <div class="w3-col m8 l8">
                                <div class="w3-text-grey w3-medium">
                                    <i class="fa fa-code w3-margin-right"></i>Payload
                                </div>
                            </div>
                            <div class="w3-col m4 l4 w3-right-align">
                                <button class="w3-button w3-round w3-small w3-border" onclick="togglePayload(this, '<?php echo htmlspecialchars($uuid); ?>')">
                                    <i class="fa fa-chevron-down w3-margin-right"></i>Collapse
                                </button>
                            </div>
                        </div>
                        <div id="payload-<?php echo htmlspecialchars($uuid); ?>" class="w3-margin-top-off">
                            <pre class="w3-code w3-round atomic-payload-code"><code class="json-payload"><?php echo $job['payload']; ?></code></pre>
                        </div>
                    </div>
                    
                    <!-- Telemetry Events Section -->
                    <div class="w3-container w3-padding  at-border-grey" id="telemetry-<?php echo htmlspecialchars($uuid); ?>">
                        <div class="w3-text-grey w3-medium atomic-telemetry-header">
                            <i class="fa fa-bar-chart w3-margin-right"></i>Telemetry Events
                        </div>
                        
                        <div class="w3-center w3-margin-top-off atomic-loading-spinner">
                            <i class="fa fa-spinner fa-spin w3-text-grey"></i>
                            <span class="w3-text-grey w3-margin-left">Loading telemetry events...</span>
                        </div>
                    </div>
                </div>
                
                </div> <!-- Close atomic-job-item -->
        <?php endforeach; ?>
    </div>

    <div class="atomic-pagination-bar w3-margin-top">
        <div class="atomic-pagination-summary w3-small w3-text-grey">
            Showing <?php echo htmlspecialchars((string)$queue_from); ?>-<?php echo htmlspecialchars((string)$queue_to); ?> of <?php echo htmlspecialchars((string)$queue_total); ?> jobs
        </div>
        <div class="atomic-pagination-actions">
            <label for="queue-per-page" class="atomic-pagination-label">Rows:</label>
            <select
                id="queue-per-page"
                class="filter-select atomic-pagination-select"
                onchange="window.location.href=this.value"
            >
                <?php foreach ([25, 50, 100, 200] as $option_per_page): ?>
                    <option
                        value="<?php echo htmlspecialchars($build_queue_per_page_url($option_per_page)); ?>"
                        <?php echo $queue_per_page === $option_per_page ? 'selected' : ''; ?>
                    >
                        <?php echo htmlspecialchars((string)$option_per_page); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <a
                href="<?php echo htmlspecialchars($build_queue_page_url(1)); ?>"
                class="w3-button w3-round w3-small w3-border <?php echo $queue_active_page <= 1 ? 'atomic-btn-disabled' : ''; ?>"
                aria-disabled="<?php echo $queue_active_page <= 1 ? 'true' : 'false'; ?>"
            >
                <i class="fa fa-angle-double-left"></i>
            </a>
            <a
                href="<?php echo htmlspecialchars($build_queue_page_url(max(1, $queue_active_page - 1))); ?>"
                class="w3-button w3-round w3-small w3-border <?php echo $queue_active_page <= 1 ? 'atomic-btn-disabled' : ''; ?>"
                aria-disabled="<?php echo $queue_active_page <= 1 ? 'true' : 'false'; ?>"
            >
                <i class="fa fa-angle-left"></i>
            </a>

            <span class="atomic-pagination-current w3-small">
                Page <?php echo htmlspecialchars((string)$queue_active_page); ?> / <?php echo htmlspecialchars((string)$queue_last_page); ?>
            </span>

            <a
                href="<?php echo htmlspecialchars($build_queue_page_url(min($queue_last_page, $queue_active_page + 1))); ?>"
                class="w3-button w3-round w3-small w3-border <?php echo $queue_active_page >= $queue_last_page ? 'atomic-btn-disabled' : ''; ?>"
                aria-disabled="<?php echo $queue_active_page >= $queue_last_page ? 'true' : 'false'; ?>"
            >
                <i class="fa fa-angle-right"></i>
            </a>
            <a
                href="<?php echo htmlspecialchars($build_queue_page_url($queue_last_page)); ?>"
                class="w3-button w3-round w3-small w3-border <?php echo $queue_active_page >= $queue_last_page ? 'atomic-btn-disabled' : ''; ?>"
                aria-disabled="<?php echo $queue_active_page >= $queue_last_page ? 'true' : 'false'; ?>"
            >
                <i class="fa fa-angle-double-right"></i>
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="w3-card-4 w3-center w3-padding-64 w3-round atomic-empty-state">
        <div class="w3-text-grey w3-xxxlarge">
            <i class="fa fa-inbox"></i>
        </div>
        <h3 class="w3-text-grey">No jobs in queue</h3>
        <p class="w3-text-grey">All jobs have been processed or no jobs have been submitted yet.</p>
        <button type="button" class="w3-button w3-green w3-round" onclick="refreshQueueFromInlineButton(this)">
            <i class="fa fa-refresh w3-margin-right"></i>Refresh Queue
        </button>
    </div>
<?php endif; ?>