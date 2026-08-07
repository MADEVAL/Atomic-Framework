<?php

declare(strict_types=1);
if (!defined( 'ATOMIC_START' ) ) exit;
?>
<!-- Comprehensive Filter Panel -->
<div id="filter-panel" class="w3-hide filter-panel">
    <div class="w3-row-padding">
        <!-- Basic Filters Row -->
        <div class="w3-quarter filter-section">
            <label class="filter-label">Driver</label>
            <select id="filter-driver" class="filter-select">
                <option value="">All Drivers</option>
                <option value="db">Database</option>
                <option value="redis">Redis</option>
            </select>
        </div>
        
        <div class="w3-quarter filter-section">
            <label class="filter-label">Queue</label>
            <input type="text" id="filter-queue" class="filter-input" placeholder="Queue name...">
        </div>
        
        <div class="w3-quarter filter-section">
            <label class="filter-label">State</label>
            <select id="filter-state" class="filter-select">
                <option value="">All States</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="cancel_requested">Cancel Requested</option>
                <option value="cancelled">Canceled</option>
                <option value="running">Running</option>
                <option value="pending">Pending</option>
            </select>
        </div>

        <div class="w3-quarter filter-section">
            <label class="filter-label">UUID Search</label>
            <input type="text" id="filter-uuid" class="filter-input" placeholder="Job UUID...">
        </div>
    </div>
    
    <div class="w3-row-padding">
        <!-- Time Range & Advanced Filters Row -->
        <div class="w3-quarter filter-section">
            <label class="filter-label">Date From</label>
            <input type="datetime-local" id="filter-date-from" class="filter-input">
        </div>
        
        <div class="w3-quarter filter-section">
            <label class="filter-label">Date To</label>
            <input type="datetime-local" id="filter-date-to" class="filter-input">
        </div>
        
        <div class="w3-quarter filter-section">
            <!-- Empty section for layout balance -->
        </div>
    </div>
    
    <!-- Quick Filters & Actions -->
    <div class="w3-container w3-padding">
        <div class="w3-row">
            <div class="w3-half">
                <label class="filter-label">Quick Filters</label>
                <button onclick="applyQuickFilter('last_hour')" class="w3-button w3-small w3-round w3-border filter-quick-btn">Last Hour</button>
                <button onclick="applyQuickFilter('today')" class="w3-button w3-small w3-round w3-border filter-quick-btn">Today</button>
                <button onclick="applyQuickFilter('failed_only')" class="w3-button w3-small w3-round w3-border filter-quick-btn">Failed Only</button>
            </div>
            <div class="w3-half w3-right-align">
                <button onclick="clearAllFilters()" class="w3-button w3-round w3-border w3-margin-right">
                    <i class="fa fa-times w3-margin-right"></i>Clear All
                </button>
                <button onclick="applyFilters()" class="w3-button w3-round w3-blue">
                    <i class="fa fa-search w3-margin-right"></i>Apply Filters
                </button>
            </div>
        </div>
    </div>
</div>