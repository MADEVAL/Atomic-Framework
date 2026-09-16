<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;

get_head();
?>
<div class="w3-content atomic-main-container">
    <div class="w3-card-4 w3-round atomic-card">

        <?php get_header(); ?>

        <main>
            <!-- Dashboard Tab Content -->
            <div id="tab-dashboard" class="tab-pane w3-container w3-padding">
                <div class="tab-body">
                    <div class="w3-center w3-margin-top-off">
                        <i class="fa fa-info-circle w3-text-grey"></i>
                        <span class="w3-text-grey w3-margin-left">Open tab</span>
                    </div>
                </div>
            </div>

            <!-- Queues Tab Content -->
            <div id="tab-queues" class="tab-pane w3-container w3-padding w3-hide">
                <div class="tab-body">
                    <!-- TODO: bring back advanced filter panel when queue filtering/indexing is redesigned -->
                    <?php get_section('job-list'); ?>
                </div>
            </div>

            <!-- Hive Tab Content -->
            <div id="tab-hive" class="tab-pane w3-container w3-padding w3-hide">
                <div class="tab-body">
                    <div class="w3-center w3-margin-top-off">
                        <i class="fa fa-info-circle w3-text-grey"></i>
                        <span class="w3-text-grey w3-margin-left">Hive</span>
                    </div>
                </div>
            </div>

            <!-- Logs Tab Content -->
            <div id="tab-logs" class="tab-pane w3-container w3-padding w3-hide">
                <div class="tab-body">
                    <div class="w3-center w3-margin-top-off">
                        <i class="fa fa-info-circle w3-text-grey"></i>
                        <span class="w3-text-grey w3-margin-left">Logs</span>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php get_footer(); ?>