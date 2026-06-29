<?php
/**
 * Wizard Step 4 — Display options (#1982)
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="gt-wizard-step-inner">
    <h3 class="gt-wizard-step-title">
        <span class="dashicons dashicons-admin-appearance gt-wizard-step-icon"></span>
        <?php esc_html_e( 'How should it look?', 'tc-data-tables' ); ?>
    </h3>
    <p class="gt-wizard-step-desc">
        <?php esc_html_e( 'Three quick settings — you can fine-tune everything else in the full builder later.', 'tc-data-tables' ); ?>
    </p>

    <div class="gt-wizard-display-options">

        <!-- Rows per page -->
        <div class="gt-wizard-display-row">
            <span class="gt-wizard-display-icon dashicons dashicons-screenoptions"></span>
            <div class="gt-wizard-display-info">
                <strong><?php esc_html_e( 'Rows per page', 'tc-data-tables' ); ?></strong>
                <span><?php esc_html_e( 'How many rows to show before pagination kicks in.', 'tc-data-tables' ); ?></span>
            </div>
            <select name="wizard_per_page" id="gt-wizard-per-page" class="gt-wizard-display-control">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>

        <!-- Search bar -->
        <div class="gt-wizard-display-row">
            <span class="gt-wizard-display-icon dashicons dashicons-search"></span>
            <div class="gt-wizard-display-info">
                <strong><?php esc_html_e( 'Search bar', 'tc-data-tables' ); ?></strong>
                <span><?php esc_html_e( 'Let visitors search across all columns instantly.', 'tc-data-tables' ); ?></span>
            </div>
            <label class="gt-wizard-toggle">
                <input type="checkbox" name="wizard_search" id="gt-wizard-search" checked>
                <span class="gt-wizard-toggle-track"></span>
            </label>
        </div>

        <!-- Mobile card view -->
        <div class="gt-wizard-display-row">
            <span class="gt-wizard-display-icon dashicons dashicons-smartphone"></span>
            <div class="gt-wizard-display-info">
                <strong><?php esc_html_e( 'Mobile card view', 'tc-data-tables' ); ?></strong>
                <span><?php esc_html_e( 'On small screens each row becomes a stacked card for easy reading.', 'tc-data-tables' ); ?></span>
            </div>
            <label class="gt-wizard-toggle">
                <input type="checkbox" name="wizard_mobile_card" id="gt-wizard-mobile-card" checked>
                <span class="gt-wizard-toggle-track"></span>
            </label>
        </div>

    </div>
</div>
