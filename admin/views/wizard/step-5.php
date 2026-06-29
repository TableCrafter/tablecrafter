<?php
/**
 * Wizard Step 5 — Review & Create (#1983)
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="gt-wizard-step-inner">
    <h3 class="gt-wizard-step-title">
        <span class="dashicons dashicons-visibility gt-wizard-step-icon"></span>
        <?php esc_html_e( 'Review & Create', 'tc-data-tables' ); ?>
    </h3>
    <p class="gt-wizard-step-desc">
        <?php esc_html_e( 'Check everything looks right, then create your table.', 'tc-data-tables' ); ?>
    </p>

    <!-- Summary panel (populated by JS) -->
    <div class="gt-wizard-summary">
        <div class="gt-wizard-summary-row">
            <span class="gt-wizard-summary-label"><?php esc_html_e( 'Data source', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-summary-value" data-summary="source"></span>
        </div>
        <div class="gt-wizard-summary-row">
            <span class="gt-wizard-summary-label"><?php esc_html_e( 'Table name', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-summary-value" data-summary="title"></span>
        </div>
        <div class="gt-wizard-summary-row">
            <span class="gt-wizard-summary-label"><?php esc_html_e( 'Connected to', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-summary-value" data-summary="connection"></span>
        </div>
        <div class="gt-wizard-summary-row">
            <span class="gt-wizard-summary-label"><?php esc_html_e( 'Columns', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-summary-value" data-summary="columns"></span>
        </div>
        <div class="gt-wizard-summary-row">
            <span class="gt-wizard-summary-label"><?php esc_html_e( 'Rows per page', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-summary-value" data-summary="per_page"></span>
        </div>
        <div class="gt-wizard-summary-row">
            <span class="gt-wizard-summary-label"><?php esc_html_e( 'Search bar', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-summary-value" data-summary="search"></span>
        </div>
        <div class="gt-wizard-summary-row">
            <span class="gt-wizard-summary-label"><?php esc_html_e( 'Mobile card view', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-summary-value" data-summary="mobile"></span>
        </div>
    </div>

    <!-- Error state -->
    <div class="gt-wizard-create-error" style="display:none">
        <span class="dashicons dashicons-warning"></span>
        <span class="gt-wizard-create-error-msg"></span>
    </div>

    <!-- Success state (shown after create) -->
    <div class="gt-wizard-success" style="display:none">
        <div class="gt-wizard-success-banner">
            <span class="dashicons dashicons-yes-alt"></span>
            <strong><?php esc_html_e( 'Table created!', 'tc-data-tables' ); ?></strong>
        </div>

        <div class="gt-wizard-shortcode-box">
            <p class="gt-wizard-shortcode-label">
                <?php esc_html_e( 'Paste this shortcode wherever you want the table to appear:', 'tc-data-tables' ); ?>
            </p>
            <div class="gt-wizard-shortcode-row">
                <code class="gt-wizard-shortcode-code"></code>
                <button type="button" class="button gt-wizard-copy-shortcode">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php esc_html_e( 'Copy', 'tc-data-tables' ); ?>
                </button>
            </div>
        </div>

        <div class="gt-wizard-success-actions">
            <a href="#" class="button button-primary gt-wizard-go-builder">
                <?php esc_html_e( 'Customize in builder', 'tc-data-tables' ); ?> →
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables' ) ); ?>" class="button">
                <?php esc_html_e( 'View all tables', 'tc-data-tables' ); ?>
            </a>
        </div>
    </div>

    <p class="gt-wizard-validation-msg" style="display:none">
        <?php esc_html_e( 'Something went wrong. Please go back and check your settings.', 'tc-data-tables' ); ?>
    </p>
</div>
