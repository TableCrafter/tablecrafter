<?php
/**
 * Wizard Step 2 — Table name + connect to source (#1980)
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="gt-wizard-step-inner">
    <h3 class="gt-wizard-step-title">
        <span class="dashicons dashicons-admin-links gt-wizard-step-icon"></span>
        <span class="gt-wizard-step2-title"><?php esc_html_e( 'Name your table & connect', 'tc-data-tables' ); ?></span>
    </h3>
    <p class="gt-wizard-step-desc gt-wizard-step2-desc">
        <?php esc_html_e( 'Give your table a name and tell us which data to pull in.', 'tc-data-tables' ); ?>
    </p>

    <!-- Table title (all sources) -->
    <div class="gt-wizard-field">
        <label for="gt-wizard-table-title">
            <?php esc_html_e( 'Table Name', 'tc-data-tables' ); ?>
            <span class="gt-wizard-required">*</span>
        </label>
        <input type="text" id="gt-wizard-table-title" name="table_title"
               placeholder="<?php esc_attr_e( 'e.g. Load Submissions, Product Catalog…', 'tc-data-tables' ); ?>"
               autocomplete="off" maxlength="120">
        <p class="description"><?php esc_html_e( 'Internal label for your reference — not shown to visitors.', 'tc-data-tables' ); ?></p>
    </div>

    <!-- === Gravity Forms branch === -->
    <div class="gt-wizard-branch" data-branch="gravity_forms">
        <div class="gt-wizard-field">
            <label for="gt-wizard-form-id">
                <?php esc_html_e( 'Select Form', 'tc-data-tables' ); ?>
                <span class="gt-wizard-required">*</span>
            </label>
            <select id="gt-wizard-form-id" name="form_id">
                <option value=""><?php esc_html_e( '— choose a form —', 'tc-data-tables' ); ?></option>
                <!-- populated by admin-wizard.js from gtWizardData.forms -->
            </select>
            <p class="description"><?php esc_html_e( 'We\'ll pull entries from this form.', 'tc-data-tables' ); ?></p>
        </div>
    </div>

    <!-- === JSON / REST API branch === -->
    <div class="gt-wizard-branch" data-branch="json" style="display:none">
        <div class="gt-wizard-field">
            <label for="gt-wizard-json-url">
                <?php esc_html_e( 'JSON URL', 'tc-data-tables' ); ?>
                <span class="gt-wizard-required">*</span>
            </label>
            <input type="url" id="gt-wizard-json-url" name="json_url"
                   placeholder="https://api.example.com/data.json"
                   autocomplete="off">
            <p class="description"><?php esc_html_e( 'Public HTTPS endpoint returning a JSON array of objects.', 'tc-data-tables' ); ?></p>
        </div>
        <button type="button" class="button gt-wizard-test-json">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( 'Test Connection', 'tc-data-tables' ); ?>
        </button>
        <div class="gt-wizard-test-result" style="display:none"></div>
    </div>

    <!-- === CSV branch (#2039) === -->
    <div class="gt-wizard-branch" data-branch="csv" style="display:none">
        <div class="gt-wizard-field">
            <label for="gt-wizard-csv-url">
                <?php esc_html_e( 'CSV URL', 'tc-data-tables' ); ?>
                <span class="gt-wizard-required">*</span>
            </label>
            <input type="url" id="gt-wizard-csv-url" name="csv_url"
                   placeholder="https://example.com/data.csv" autocomplete="off">
            <p class="description"><?php esc_html_e( 'Public URL to a .csv file (first row = column headers).', 'tc-data-tables' ); ?></p>
        </div>
        <button type="button" class="button gt-wizard-test-remote" data-source-type="csv" data-url-field="csv_url">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( 'Test Connection', 'tc-data-tables' ); ?>
        </button>
        <div class="gt-wizard-test-result" style="display:none"></div>
    </div>

    <!-- === Google Sheets branch (#2039) === -->
    <div class="gt-wizard-branch" data-branch="google_sheets" style="display:none">
        <div class="gt-wizard-field">
            <label for="gt-wizard-sheets-url">
                <?php esc_html_e( 'Google Sheet URL', 'tc-data-tables' ); ?>
                <span class="gt-wizard-required">*</span>
            </label>
            <input type="url" id="gt-wizard-sheets-url" name="google_sheets_url"
                   placeholder="https://docs.google.com/spreadsheets/d/…/pub?output=csv" autocomplete="off">
            <p class="description"><?php esc_html_e( 'Publish the sheet to the web (File → Share → Publish to web) and paste the link.', 'tc-data-tables' ); ?></p>
        </div>
        <button type="button" class="button gt-wizard-test-remote" data-source-type="google_sheets" data-url-field="google_sheets_url">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( 'Test Connection', 'tc-data-tables' ); ?>
        </button>
        <div class="gt-wizard-test-result" style="display:none"></div>
    </div>

    <!-- === WooCommerce branch === -->
    <div class="gt-wizard-branch" data-branch="woocommerce_products" style="display:none">
        <div class="gt-wizard-info-box">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php esc_html_e( 'WooCommerce detected. We\'ll automatically pull your product catalog. Click Next to choose which columns to show.', 'tc-data-tables' ); ?>
        </div>
    </div>

    <p class="gt-wizard-validation-msg" style="display:none">
        <?php esc_html_e( 'Please fill in all required fields before continuing.', 'tc-data-tables' ); ?>
    </p>
</div>
