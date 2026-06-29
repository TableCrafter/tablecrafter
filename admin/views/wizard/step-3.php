<?php
/**
 * Wizard Step 3 — Column picker (#1981)
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="gt-wizard-step-inner">
    <h3 class="gt-wizard-step-title">
        <span class="dashicons dashicons-editor-table gt-wizard-step-icon"></span>
        <?php esc_html_e( 'Which columns do you want?', 'tc-data-tables' ); ?>
    </h3>
    <p class="gt-wizard-step-desc">
        <?php esc_html_e( 'Choose the fields to show as columns. You can reorder and configure them later in the full builder.', 'tc-data-tables' ); ?>
    </p>

    <!-- Column toolbar -->
    <div class="gt-wizard-col-toolbar">
        <button type="button" class="button button-small gt-wizard-select-all">
            <?php esc_html_e( 'Select all', 'tc-data-tables' ); ?>
        </button>
        <button type="button" class="button button-small gt-wizard-deselect-all">
            <?php esc_html_e( 'Deselect all', 'tc-data-tables' ); ?>
        </button>
        <span class="gt-wizard-col-count"></span>
    </div>

    <!-- Loading state -->
    <div class="gt-wizard-cols-loading">
        <span class="gt-wizard-spinner"></span>
        <?php esc_html_e( 'Loading fields…', 'tc-data-tables' ); ?>
    </div>

    <!-- Column list (populated by JS) -->
    <div class="gt-wizard-cols-list" style="display:none"></div>

    <!-- Error state -->
    <div class="gt-wizard-cols-error" style="display:none">
        <span class="dashicons dashicons-warning"></span>
        <span class="gt-wizard-cols-error-msg"></span>
    </div>

    <p class="gt-wizard-validation-msg" style="display:none">
        <?php esc_html_e( 'Please select at least one column.', 'tc-data-tables' ); ?>
    </p>
</div>
