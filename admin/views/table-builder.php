<?php
/**
 * Table Builder View
 *
 * @package GravityTables
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get available Gravity Forms
$forms = array();
if (class_exists('GFAPI')) {
    $forms = GFAPI::get_forms();
}

// Check if editing existing table
$table_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$table_data = null;
$table_settings = array();

if ($table_id) {
    $admin = TC_Admin::get_instance();
    $table_data = $admin->get_table($table_id);
    if ($table_data) {
        $decoded = json_decode($table_data->settings, true);
        $table_settings = is_array($decoded) ? $decoded : array();
    }
}
?>

<div class="wrap gt-responsive-builder-wrap">
    <h1 class="wp-heading-inline">
        <?php 
        if ($table_id && $table_data) {
            echo sprintf(__('Edit Table: %s', 'tc-data-tables'), esc_html($table_data->title));
        } else {
            echo __('Create New Table', 'tc-data-tables');
        }
        ?>
    </h1>
    
    <?php if ($table_id && $table_data): ?>
        <a href="<?php echo admin_url('admin.php?page=gravity-tables-new'); ?>" class="page-title-action">
            <?php _e('Add New', 'tc-data-tables'); ?>
        </a>

        <div class="gt-breadcrumb" style="margin: 10px 0;">
            <a href="<?php echo admin_url('admin.php?page=gravity-tables'); ?>"><?php _e('← Back to All Tables', 'tc-data-tables'); ?></a>
        </div>
    <?php else: ?>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gravity-tables-import')); ?>" class="page-title-action">
            <?php _e('Import from CSV instead', 'tc-data-tables'); ?>
        </a>
    <?php endif; ?>

    <hr class="wp-header-end">

    <!-- Top Save Bar -->
    <div class="gt-top-save-bar">
        <div class="gt-save-bar-content">
            <div class="gt-save-bar-info">
                <span class="gt-save-status" id="gt-save-status">
                    <?php if ($table_id): ?>
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Last saved: ', 'tc-data-tables'); ?>
                        <span id="last-saved-time"><?php echo date_i18n(get_option('time_format'), strtotime($table_data->updated_at ?? 'now')); ?></span>
                    <?php else: ?>
                        <span class="dashicons dashicons-edit"></span>
                        <?php _e('New table - not saved yet', 'tc-data-tables'); ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="gt-save-bar-actions">
                <?php if ($table_id): ?>
                <button type="button" class="button gt-transpose-btn" id="gt-transpose-btn" data-table-id="<?php echo esc_attr($table_id); ?>">
                    <?php _e('Transpose', 'tc-data-tables'); ?>
                </button>
                <?php endif; ?>
                <button type="button" class="button-primary gt-save-button" data-save-location="top">
                    <?php echo $table_id ? __('Update Table', 'tc-data-tables') : __('Save Table', 'tc-data-tables'); ?>
                </button>
            </div>
        </div>
    </div>

    <div id="gt-table-builder" class="gt-admin-container">

        <!-- Error state: shown by JS when panel data cannot be loaded -->
        <div id="gt-load-error" class="gt-panel-error notice notice-error" style="display:none;">
            <p>
                <strong><?php esc_html_e('Could not load table data.', 'tc-data-tables'); ?></strong>
                <?php esc_html_e('There was a problem fetching the table configuration. This can happen due to a caching plugin, a JavaScript conflict, or a network timeout.', 'tc-data-tables'); ?>
            </p>
            <p>
                <button type="button" id="gt-retry-load" class="button button-secondary">
                    <?php esc_html_e('Retry', 'tc-data-tables'); ?>
                </button>
            </p>
        </div>

        <!-- Step 1: Basic Settings -->
        <div class="gt-builder-section" id="basic-settings">
            <div class="gt-section-header gt-collapsible" data-target="basic-settings-content">
                <div class="gt-section-title">
                    <h2><?php _e('1. Basic Settings', 'tc-data-tables'); ?></h2>
                    <span class="gt-toggle-icon" title="Click to toggle section">▼</span>
                </div>
                <p><?php _e('Configure the basic settings for your table.', 'tc-data-tables'); ?></p>
            </div>
            <div class="gt-section-content" id="basic-settings-content">

                <div class="gt-basic-settings-container">
                    <div class="gt-form-row">
                        <label for="table-title"><?php _e('Table Title', 'tc-data-tables'); ?></label>
                        <input type="text" id="table-title" name="table_title"
                               value="<?php echo $table_data ? esc_attr($table_data->title) : ''; ?>"
                               placeholder="<?php _e('Enter table title', 'tc-data-tables'); ?>" required>
                        <p class="description"><?php _e('This title is for your reference in the admin area.', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row">
                        <label><?php _e('Table Template', 'tc-data-tables'); ?></label>
                        <select name="template_type">
                            <option value="standard" <?php selected(isset($table_settings['template_type']) ? $table_settings['template_type'] : 'standard', 'standard'); ?>>
                                <?php _e('Standard data table', 'tc-data-tables'); ?>
                            </option>
                            <option value="comparison" <?php selected(isset($table_settings['template_type']) ? $table_settings['template_type'] : 'standard', 'comparison'); ?>>
                                <?php _e('Comparison / Pricing table', 'tc-data-tables'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('Comparison/Pricing tables show feature rows, a pricing row with billing-period toggle, and per-column CTA buttons.', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row">
                        <label><?php _e('Data Source', 'tc-data-tables'); ?></label>
                        <select name="data_source_type" id="gt-data-source-type">
                            <?php
                            // #2009 — render options from the single source-of-truth registry
                            // instead of a hardcoded list. New sources register once via the
                            // gravity_tables_source_types filter and appear here automatically.
                            $gt_current_source = isset($table_settings['data_source_type']) ? $table_settings['data_source_type'] : 'gravity_forms';
                            foreach (TC_Source_Registry::for_builder() as $gt_src_key => $gt_src_def) :
                                ?>
                                <option value="<?php echo esc_attr($gt_src_key); ?>" <?php selected($gt_current_source, $gt_src_key); ?>>
                                    <?php echo esc_html($gt_src_def['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Choose the data source for this table. WooCommerce Products queries your product catalog live; JSON URL fetches from a remote endpoint with optional auth headers.', 'tc-data-tables'); ?></p>
                    </div>

                    <!-- #2002 — Google Sheets data source fields (convergence #2006). -->
                    <!-- Shown only when data_source_type = google_sheets. Toggle wired in admin/bind-events.js. -->
                    <div class="gt-form-row gt-google-sheets-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'google_sheets') ? '' : 'display: none;'; ?>">
                        <label for="gt-google-sheets-url"><?php _e('Google Sheet URL', 'tc-data-tables'); ?></label>
                        <input type="url"
                               id="gt-google-sheets-url"
                               name="google_sheets_url"
                               value="<?php echo esc_attr($table_settings['google_sheets_url'] ?? ''); ?>"
                               placeholder="https://docs.google.com/spreadsheets/d/.../edit"
                               style="width: 100%; max-width: 520px;" />
                        <p class="description"><?php _e('Paste a Google Sheet share URL. The sheet must be published to the web (File &rarr; Share &rarr; Publish to web). Private subnets are blocked server-side for security.', 'tc-data-tables'); ?></p>
                    </div>
                    <div class="gt-form-row gt-google-sheets-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'google_sheets') ? '' : 'display: none;'; ?>">
                        <button type="button" class="button gt-remote-source-preview" data-source-type="google_sheets" data-url-field="google_sheets_url"><?php _e('Preview source', 'tc-data-tables'); ?></button>
                        <span class="gt-remote-source-preview-result" data-source-type="google_sheets" style="margin-left:8px;"></span>
                    </div>

                    <!-- #2004 — XML data source fields (convergence #2006). -->
                    <!-- Shown only when data_source_type = xml. Toggle wired in admin/bind-events.js. -->
                    <div class="gt-form-row gt-xml-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'xml') ? '' : 'display: none;'; ?>">
                        <label for="gt-xml-url"><?php _e('XML URL', 'tc-data-tables'); ?></label>
                        <input type="url"
                               id="gt-xml-url"
                               name="xml_url"
                               value="<?php echo esc_attr($table_settings['xml_url'] ?? ''); ?>"
                               placeholder="https://example.com/feed.xml"
                               style="width: 100%; max-width: 520px;" />
                        <p class="description"><?php _e('Public HTTP(S) URL of an XML feed or file. Private subnets are blocked server-side for security.', 'tc-data-tables'); ?></p>
                    </div>
                    <div class="gt-form-row gt-xml-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'xml') ? '' : 'display: none;'; ?>">
                        <label for="gt-xml-row-path"><?php _e('Row element path (optional)', 'tc-data-tables'); ?></label>
                        <input type="text"
                               id="gt-xml-row-path"
                               name="xml_row_path"
                               value="<?php echo esc_attr($table_settings['xml_row_path'] ?? ''); ?>"
                               placeholder="channel/item"
                               style="width: 100%; max-width: 520px;" />
                        <p class="description"><?php _e('Path to the repeating element (e.g. channel/item). Leave blank to auto-detect.', 'tc-data-tables'); ?></p>
                    </div>
                    <div class="gt-form-row gt-xml-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'xml') ? '' : 'display: none;'; ?>">
                        <button type="button" class="button gt-remote-source-preview" data-source-type="xml" data-url-field="xml_url" data-path-field="xml_row_path"><?php _e('Preview source', 'tc-data-tables'); ?></button>
                        <span class="gt-remote-source-preview-result" data-source-type="xml" style="margin-left:8px;"></span>
                    </div>

                    <!-- #2010 — live CSV URL data source fields (convergence #2006). -->
                    <!-- Shown only when data_source_type = csv. Toggle wired in admin/bind-events.js. -->
                    <div class="gt-form-row gt-csv-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'csv') ? '' : 'display: none;'; ?>">
                        <label for="gt-csv-url"><?php _e('CSV URL', 'tc-data-tables'); ?></label>
                        <input type="url"
                               id="gt-csv-url"
                               name="csv_url"
                               value="<?php echo esc_attr($table_settings['csv_url'] ?? ''); ?>"
                               placeholder="https://example.com/data.csv"
                               style="width: 100%; max-width: 520px;" />
                        <p class="description"><?php _e('Public HTTP(S) URL of a CSV file. The first row is treated as the header. Private subnets are blocked server-side for security.', 'tc-data-tables'); ?></p>
                        <p>
                            <button type="button" class="button gt-remote-source-preview" data-source-type="csv" data-url-field="csv_url"><?php _e('Preview source', 'tc-data-tables'); ?></button>
                            <span class="gt-remote-source-preview-result" data-source-type="csv" style="margin-left:8px;"></span>
                        </p>
                    </div>

                    <!-- #1998 — Excel (.xlsx) source -->
                    <div class="gt-form-row gt-xlsx-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'xlsx') ? '' : 'display: none;'; ?>">
                        <label for="gt-xlsx-url"><?php _e('Excel (.xlsx) URL', 'tc-data-tables'); ?></label>
                        <input type="url"
                               id="gt-xlsx-url"
                               name="xlsx_url"
                               value="<?php echo esc_attr($table_settings['xlsx_url'] ?? ''); ?>"
                               placeholder="https://example.com/data.xlsx"
                               style="width: 100%; max-width: 520px;" />
                        <p class="description"><?php _e('Public HTTP(S) URL of an .xlsx workbook. The first sheet\'s first row is treated as the header. Private subnets are blocked server-side for security.', 'tc-data-tables'); ?></p>
                        <p>
                            <button type="button" class="button gt-remote-source-preview" data-source-type="xlsx" data-url-field="xlsx_url"><?php _e('Preview source', 'tc-data-tables'); ?></button>
                            <span class="gt-remote-source-preview-result" data-source-type="xlsx" style="margin-left:8px;"></span>
                        </p>
                    </div>

                    <!-- #2003 — External database data source fields (convergence #2006). -->
                    <!-- Shown only when data_source_type = external_db. Toggle wired in admin/bind-events.js. -->
                    <?php
                    $gt_db_connections = class_exists('TC_External_DB') ? TC_External_DB::get_instance()->get_connections() : array();
                    $gt_db_selected    = isset($table_settings['external_db_connection']) ? (string) $table_settings['external_db_connection'] : '';
                    ?>
                    <div class="gt-form-row gt-external-db-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'external_db') ? '' : 'display: none;'; ?>">
                        <label for="gt-external-db-connection"><?php _e('Database connection', 'tc-data-tables'); ?></label>
                        <select id="gt-external-db-connection" name="external_db_connection" style="width: 100%; max-width: 520px;">
                            <option value=""><?php _e('— Select a connection —', 'tc-data-tables'); ?></option>
                            <?php foreach ($gt_db_connections as $gt_db_i => $gt_db_conn) :
                                $gt_db_label = !empty($gt_db_conn['label'])
                                    ? $gt_db_conn['label']
                                    : (($gt_db_conn['username'] ?? '') . '@' . ($gt_db_conn['host'] ?? '') . '/' . ($gt_db_conn['dbname'] ?? ''));
                                ?>
                                <option value="<?php echo esc_attr((string) $gt_db_i); ?>" <?php selected($gt_db_selected, (string) $gt_db_i); ?>>
                                    <?php echo esc_html($gt_db_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($gt_db_connections)) : ?>
                        <p class="description"><?php printf(
                            /* translators: %s: opening anchor to the Database Connections admin page, %s: closing anchor. */
                            esc_html__('No database connections are configured yet. Add one on the %1$sDatabase Connections%2$s screen, then select it here.', 'tc-data-tables'),
                            '<a href="' . esc_url(admin_url('admin.php?page=gravity-tables-db-connections')) . '">',
                            '</a>'
                        ); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="gt-form-row gt-external-db-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'external_db') ? '' : 'display: none;'; ?>">
                        <label for="gt-external-db-query"><?php _e('Read-only SELECT query', 'tc-data-tables'); ?></label>
                        <textarea id="gt-external-db-query"
                                  name="external_db_query"
                                  rows="4"
                                  placeholder="SELECT id, name, created_at FROM customers ORDER BY created_at DESC LIMIT 100"
                                  style="width: 100%; max-width: 520px; font-family: monospace;"><?php echo esc_textarea($table_settings['external_db_query'] ?? ''); ?></textarea>
                        <p class="description"><?php _e('Only SELECT statements run (enforced server-side in read-only mode).', 'tc-data-tables'); ?></p>
                    </div>

                    <!-- #985 v4.168.0 — JSON data source fields (slice 3b-2 of #512). -->
                    <!-- Shown only when data_source_type = json. The toggle is wired in admin/bind-events.js. -->
                    <div class="gt-form-row gt-json-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'json') ? '' : 'display: none;'; ?>">
                        <label for="gt-json-url"><?php _e('JSON URL', 'tc-data-tables'); ?></label>
                        <input type="url"
                               id="gt-json-url"
                               name="json_url"
                               value="<?php echo esc_attr($table_settings['json_url'] ?? ''); ?>"
                               placeholder="https://api.example.com/data.json"
                               style="width: 100%; max-width: 520px;" />
                        <p class="description"><?php _e('Public HTTP(S) URL. Localhost and private subnets are blocked server-side for security.', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row gt-json-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'json') ? '' : 'display: none;'; ?>">
                        <label for="gt-json-headers"><?php _e('Request Headers (optional)', 'tc-data-tables'); ?></label>
                        <textarea id="gt-json-headers"
                                  name="json_headers_raw"
                                  rows="3"
                                  placeholder="<?php echo esc_attr(__('Authorization: Bearer your-token-here', 'tc-data-tables')); ?>&#10;<?php echo esc_attr(__('X-API-Key: your-api-key', 'tc-data-tables')); ?>"
                                  style="width: 100%; max-width: 520px; font-family: monospace;"><?php
                            // Render saved headers back as Key: Value lines.
                            if (!empty($table_settings['json_headers']) && is_array($table_settings['json_headers'])) {
                                $lines = array();
                                foreach ($table_settings['json_headers'] as $k => $v) {
                                    $lines[] = $k . ': ' . $v;
                                }
                                echo esc_textarea(implode("\n", $lines));
                            }
                            ?></textarea>
                        <p class="description"><?php _e('One header per line in "Key: Value" format. Used for API keys, Bearer tokens, Basic Auth, etc.', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row gt-json-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'json') ? '' : 'display: none;'; ?>">
                        <label for="gt-json-dot-path"><?php _e('JSON Path (optional)', 'tc-data-tables'); ?></label>
                        <input type="text"
                               id="gt-json-dot-path"
                               name="json_dot_path"
                               value="<?php echo esc_attr($table_settings['json_dot_path'] ?? ''); ?>"
                               placeholder="data.results"
                               style="width: 100%; max-width: 360px;" />
                        <p class="description"><?php _e('Dot-path to the array of rows inside the response. Leave empty when the document is a flat top-level array.', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row gt-json-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'json') ? '' : 'display: none;'; ?>">
                        <label for="gt-json-refresh-minutes"><?php _e('Refresh interval (minutes)', 'tc-data-tables'); ?></label>
                        <input type="number"
                               id="gt-json-refresh-minutes"
                               name="json_refresh_minutes"
                               value="<?php echo esc_attr($table_settings['json_refresh_minutes'] ?? 30); ?>"
                               min="5"
                               max="1440"
                               step="5"
                               style="width: 120px;" />
                        <p class="description"><?php _e('How often to re-fetch the URL. Clamped to 5–1440 minutes (5 min minimum to avoid hammering the remote endpoint).', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row gt-json-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'json') ? '' : 'display: none;'; ?>">
                        <button type="button" class="button button-secondary" id="gt-json-test-connection">
                            <?php _e('Test connection', 'tc-data-tables'); ?>
                        </button>
                        <span id="gt-json-test-result" style="margin-left: 12px;"></span>
                        <p class="description"><?php _e('Fetches the URL with your headers + dot-path and shows a preview of the inferred columns + first rows.', 'tc-data-tables'); ?></p>
                    </div>

                    <!-- #990 v4.171.0 — Airtable picker preview (phase A of #517). -->
                    <!-- #992 v4.172.0 — Connection wizard fields (phase B of #517). -->
                    <div class="gt-form-row gt-airtable-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'airtable') ? '' : 'display: none;'; ?>">
                        <label for="gt-airtable-pat"><?php _e('Personal Access Token', 'tc-data-tables'); ?></label>
                        <input type="password"
                               id="gt-airtable-pat"
                               name="airtable_pat"
                               value=""
                               placeholder="<?php echo !empty($table_settings['airtable_pat_set']) ? esc_attr__('•••••••• (saved — leave blank to keep)', 'tc-data-tables') : 'pat...'; ?>"
                               autocomplete="new-password"
                               style="width: 100%; max-width: 420px; font-family: monospace;" />
                        <p class="description">
                            <?php _e('Airtable PAT (Personal Access Token). Stored encrypted at rest. Generate one at', 'tc-data-tables'); ?>
                            <a href="https://airtable.com/create/tokens" target="_blank" rel="noopener">airtable.com/create/tokens</a>.
                        </p>
                    </div>

                    <div class="gt-form-row gt-airtable-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'airtable') ? '' : 'display: none;'; ?>">
                        <label for="gt-airtable-base-id"><?php _e('Base ID', 'tc-data-tables'); ?></label>
                        <input type="text"
                               id="gt-airtable-base-id"
                               name="airtable_base_id"
                               value="<?php echo esc_attr($table_settings['airtable_base_id'] ?? ''); ?>"
                               placeholder="appXXXXXXXXXXXXXX"
                               style="width: 100%; max-width: 320px; font-family: monospace;" />
                        <p class="description"><?php _e('The Airtable base ID (starts with "app"). Find it in the URL: airtable.com/<strong>appXXX</strong>/...', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row gt-airtable-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'airtable') ? '' : 'display: none;'; ?>">
                        <label for="gt-airtable-table-id"><?php _e('Table ID or name', 'tc-data-tables'); ?></label>
                        <input type="text"
                               id="gt-airtable-table-id"
                               name="airtable_table_id"
                               value="<?php echo esc_attr($table_settings['airtable_table_id'] ?? ''); ?>"
                               placeholder="tblXXXXXXXXXXXXXX"
                               style="width: 100%; max-width: 320px; font-family: monospace;" />
                        <p class="description"><?php _e('The table ID (starts with "tbl") or the table name. IDs are stable across renames; names are easier to read.', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row gt-airtable-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'airtable') ? '' : 'display: none;'; ?>">
                        <label for="gt-airtable-view"><?php _e('View (optional)', 'tc-data-tables'); ?></label>
                        <input type="text"
                               id="gt-airtable-view"
                               name="airtable_view"
                               value="<?php echo esc_attr($table_settings['airtable_view'] ?? ''); ?>"
                               placeholder="Grid view"
                               style="width: 100%; max-width: 320px;" />
                        <p class="description"><?php _e('Specific view to filter/sort by (leave empty to use the default view).', 'tc-data-tables'); ?></p>
                    </div>

                    <!-- #998 v4.175.0 — Notion connection foundation (phase 1 of #592). -->
                    <div class="gt-form-row gt-notion-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'notion') ? '' : 'display: none;'; ?>">
                        <label for="gt-notion-token"><?php _e('Notion API Token', 'tc-data-tables'); ?></label>
                        <input type="password"
                               id="gt-notion-token"
                               name="notion_token"
                               value=""
                               placeholder="<?php echo !empty($table_settings['notion_token_set']) ? esc_attr__('•••••••• (saved — leave blank to keep)', 'tc-data-tables') : 'secret_...'; ?>"
                               autocomplete="new-password"
                               style="width: 100%; max-width: 420px; font-family: monospace;" />
                        <p class="description">
                            <?php _e('Internal-integration token. Stored encrypted at rest. Create one at', 'tc-data-tables'); ?>
                            <a href="https://www.notion.so/my-integrations" target="_blank" rel="noopener">notion.so/my-integrations</a>.
                            <?php _e('Then share your target database with the integration from the database page.', 'tc-data-tables'); ?>
                        </p>
                    </div>

                    <div class="gt-form-row gt-notion-source-fields"
                         style="<?php echo (isset($table_settings['data_source_type']) && $table_settings['data_source_type'] === 'notion') ? '' : 'display: none;'; ?>">
                        <label for="gt-notion-database-id"><?php _e('Database ID', 'tc-data-tables'); ?></label>
                        <input type="text"
                               id="gt-notion-database-id"
                               name="notion_database_id"
                               value="<?php echo esc_attr($table_settings['notion_database_id'] ?? ''); ?>"
                               placeholder="32-char hex (dashes optional)"
                               style="width: 100%; max-width: 360px; font-family: monospace;" />
                        <p class="description"><?php _e('The Notion database UUID. Open the database in Notion and find it in the URL: notion.so/.../<strong>DATABASE-ID</strong>?v=...', 'tc-data-tables'); ?></p>
                    </div>

                    <?php
                    // #1010 v4.181.0 — sync_direction picker (phase 1 of #613).
                    // #1013 v4.183.0 — Picker unified across all three external sources
                    // (json, airtable, notion) now that the Airtable push consumer
                    // accepts both legacy (pull_only/push_only/bidirectional) and
                    // canonical (pull/push/two_way) naming (see #1011).
                    $gt_ext_source = isset($table_settings['data_source_type']) && in_array($table_settings['data_source_type'], ['json', 'airtable', 'notion'], true);
                    $gt_sync_dir_raw = isset($table_settings['sync_direction']) ? (string) $table_settings['sync_direction'] : 'pull';
                    // Map legacy naming to the new shape so edit-mode shows the right option.
                    $gt_sync_dir = ['pull_only' => 'pull', 'push_only' => 'push', 'bidirectional' => 'two_way'][$gt_sync_dir_raw] ?? $gt_sync_dir_raw;
                    ?>
                    <div class="gt-form-row gt-sync-direction-field"
                         style="<?php echo $gt_ext_source ? '' : 'display: none;'; ?>">
                        <label for="gt-sync-direction"><?php _e('Sync direction', 'tc-data-tables'); ?></label>
                        <?php /* edit-mode reads $table_settings['sync_direction'] via the $gt_sync_dir variable computed above (#1010 phase 1 of #613). */ ?>
                        <select id="gt-sync-direction" name="sync_direction">
                            <option value="pull"    <?php selected($gt_sync_dir, 'pull'); ?>><?php _e('Pull only — read from source (default, safe)', 'tc-data-tables'); ?></option>
                            <option value="push"    <?php selected($gt_sync_dir, 'push'); ?>><?php _e('Push only — write to source (preview, write-back per source pending)', 'tc-data-tables'); ?></option>
                            <option value="two_way" <?php selected($gt_sync_dir, 'two_way'); ?>><?php _e('Two-way — read AND write (preview)', 'tc-data-tables'); ?></option>
                        </select>
                        <p class="description">
                            <?php _e('Pull is the safe default. Push and Two-way are reserved for the upcoming write-back implementation (phase 2+ of #613) — selecting them now records the intent but does not yet push edits.', 'tc-data-tables'); ?>
                        </p>
                    </div>

                    <!-- #2108 — only relevant when the data source is Gravity Forms entries.
                         Toggled in admin/bind-events.js on data-source change. -->
                    <div class="gt-form-row gt-gravity-forms-source-fields"
                         style="<?php echo ($gt_current_source === 'gravity_forms') ? '' : 'display: none;'; ?>">
                        <label for="gravity-form"><?php _e('Gravity Form', 'tc-data-tables'); ?></label>
                        <select id="gravity-form" name="form_id" <?php echo ($gt_current_source === 'gravity_forms') ? 'required' : ''; ?>>
                            <option value=""><?php _e('Select a Gravity Form', 'tc-data-tables'); ?></option>
                            <?php foreach ($forms as $form): ?>
                                <option value="<?php echo esc_attr($form['id']); ?>"
                                        <?php selected($table_data ? $table_data->form_id : 0, $form['id']); ?>>
                                    <?php echo esc_html($form['title']) . ' (ID: ' . $form['id'] . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('Select the Gravity Form whose entries you want to display.', 'tc-data-tables'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Section Save Button -->
            <div class="gt-section-save">
                <button type="button" class="button-primary gt-save-button" data-save-location="basic-settings">
                    <?php _e('Save', 'tc-data-tables'); ?>
                </button>
            </div>
        </div>

        <!-- Step 2: Field Selection -->
        <div class="gt-builder-section" id="field-selection">
            <div class="gt-section-header gt-collapsible" data-target="field-selection-content">
                <div class="gt-section-title">
                    <h2><?php _e('2. Select & Configure Fields', 'tc-data-tables'); ?></h2>
                    <span class="gt-toggle-icon" title="Click to toggle section">▼</span>
                </div>
                <p><?php _e('Choose which fields to display and configure their properties.', 'tc-data-tables'); ?></p>
            </div>
            <div class="gt-section-content" id="field-selection-content">

                <div class="gt-field-selection-container">
                <div class="gt-available-fields">
                    <h3><?php _e('Available Fields', 'tc-data-tables'); ?></h3>
                    <p class="description"><?php _e('Drag fields from here to the table columns area.', 'tc-data-tables'); ?></p>
                    <div id="available-fields-list">
                        <!-- Fields will be populated via JavaScript -->
                    </div>
                </div>

                <div class="gt-selected-fields">
                    <h3><?php _e('Table Columns', 'tc-data-tables'); ?></h3>
                    <p class="description"><?php _e('Drag to reorder columns. Click to configure properties.', 'tc-data-tables'); ?></p>
                    <div id="selected-fields-list">
                        <!-- Selected fields will be displayed here -->
                    </div>
                </div>
            </div>

            <!-- Field Configuration Modal with Tabs -->
            <div id="field-config-modal" class="gt-modal gt-modal-tabbed" style="display: none;">
                <div class="gt-modal-content">
                    <div class="gt-modal-header">
                        <h3><?php _e('Configure Field', 'tc-data-tables'); ?></h3>
                        <span class="gt-modal-close">&times;</span>
                    </div>
                    
                    <!-- Field Name Display -->
                    <div class="gt-field-info">
                        <div id="field-name-display" class="gt-field-name-display">
                            <!-- Field name will be displayed here -->
                        </div>
                    </div>
                    
                    <!-- Tab Navigation -->
                    <div class="gt-field-modal-tabs">
                        <button type="button" class="gt-field-modal-tab active" data-tab="gt-general-tab">
                            <span class="dashicons dashicons-admin-generic"></span>
                            <?php _e('General', 'tc-data-tables'); ?>
                        </button>
                        <button type="button" class="gt-field-modal-tab" data-tab="gt-advanced-tab">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <?php _e('Advanced', 'tc-data-tables'); ?>
                        </button>
                        <button type="button" class="gt-field-modal-tab" data-tab="gt-filtering-tab">
                            <span class="dashicons dashicons-filter"></span>
                            <?php _e('Filtering', 'tc-data-tables'); ?>
                        </button>
                        <button type="button" class="gt-field-modal-tab" data-tab="gt-conditional-tab">
                            <span class="dashicons dashicons-art"></span>
                            <?php _e('Conditional Formatting', 'tc-data-tables'); ?>
                        </button>
                        <button type="button" class="gt-field-modal-tab" data-tab="gt-responsive-tab">
                            <span class="dashicons dashicons-smartphone"></span>
                            <?php _e('Responsive', 'tc-data-tables'); ?>
                        </button>
                    </div>
                    
                    <div class="gt-modal-body">
                        <!-- General Settings Tab -->
                        <div id="gt-general-tab" class="gt-field-modal-tab-content active">
                            <h4><?php _e('Basic Settings', 'tc-data-tables'); ?></h4>
                            <p class="description"><?php _e('Configure basic display properties for this column.', 'tc-data-tables'); ?></p>
                            
                            <div class="gt-form-row">
                                <label><?php _e('Column Label', 'tc-data-tables'); ?></label>
                                <input type="text" id="field-label" placeholder="<?php _e('Custom column label', 'tc-data-tables'); ?>">
                                <p class="description"><?php _e('Leave empty to use the field\'s default label', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row">
                                <label><?php _e('Column Width', 'tc-data-tables'); ?></label>
                                <select id="field-width">
                                    <option value=""><?php _e('Auto (fit content)', 'tc-data-tables'); ?></option>
                                    <optgroup label="<?php _e('Fixed Widths', 'tc-data-tables'); ?>">
                                        <option value="80px"><?php _e('80px (Narrow)', 'tc-data-tables'); ?></option>
                                        <option value="120px"><?php _e('120px (Small)', 'tc-data-tables'); ?></option>
                                        <option value="150px"><?php _e('150px (Medium)', 'tc-data-tables'); ?></option>
                                        <option value="200px"><?php _e('200px (Large)', 'tc-data-tables'); ?></option>
                                        <option value="300px"><?php _e('300px (Extra Large)', 'tc-data-tables'); ?></option>
                                    </optgroup>
                                    <optgroup label="<?php _e('Percentage Widths', 'tc-data-tables'); ?>">
                                        <option value="10%">10%</option>
                                        <option value="15%">15%</option>
                                        <option value="20%">20%</option>
                                        <option value="25%">25%</option>
                                        <option value="30%">30%</option>
                                        <option value="40%">40%</option>
                                        <option value="50%">50%</option>
                                    </optgroup>
                                </select>
                                <p class="description"><?php _e('Set specific width to optimize horizontal space usage. Use percentages for responsive layouts or fixed pixels for consistent sizing.', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row">
                                <label><?php _e('Text Alignment', 'tc-data-tables'); ?></label>
                                <select id="field-alignment">
                                    <option value=""><?php _e('Auto (left for text, right for numbers)', 'tc-data-tables'); ?></option>
                                    <option value="left"><?php _e('Left', 'tc-data-tables'); ?></option>
                                    <option value="center"><?php _e('Center', 'tc-data-tables'); ?></option>
                                    <option value="right"><?php _e('Right', 'tc-data-tables'); ?></option>
                                    <option value="justify"><?php _e('Justify', 'tc-data-tables'); ?></option>
                                </select>
                                <p class="description"><?php _e('Persists in the table\'s column_alignments setting (sanitizer at class-tc-admin.php:661 whitelists left/center/right/justify). Empty = auto.', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row">
                                <label><?php _e('Word-wrap Mode', 'tc-data-tables'); ?></label>
                                <select id="field-wrap-mode">
                                    <option value="default"><?php _e('Default (wrap on whitespace only)', 'tc-data-tables'); ?></option>
                                    <option value="break-word"><?php _e('Break inside long tokens (URLs, IDs, hashes)', 'tc-data-tables'); ?></option>
                                    <option value="hyphenate"><?php _e('Hyphenate (CSS hyphens:auto + lang attribute)', 'tc-data-tables'); ?></option>
                                    <option value="nowrap"><?php _e('No wrap (single line + ellipsis on overflow)', 'tc-data-tables'); ?></option>
                                </select>
                                <p class="description"><?php _e('Controls how cell content wraps. Default lets the browser decide; the other modes apply explicit CSS via TC_Wrap_Mode_Service::css_for_mode. Sanitized server-side against the four-mode whitelist (#520).', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row">
                                <label><?php _e('Vertical Alignment', 'tc-data-tables'); ?></label>
                                <select id="field-vertical-alignment">
                                    <option value=""><?php _e('Default (browser middle)', 'tc-data-tables'); ?></option>
                                    <option value="top"><?php _e('Top', 'tc-data-tables'); ?></option>
                                    <option value="middle"><?php _e('Middle', 'tc-data-tables'); ?></option>
                                    <option value="bottom"><?php _e('Bottom', 'tc-data-tables'); ?></option>
                                </select>
                                <p class="description"><?php _e('Controls cell vertical alignment. Default = browser/CSS default (middle for table cells). Persisted in column_vertical_alignments; sanitizer at class-tc-admin.php:735 whitelists top/middle/bottom (#549).', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row">
                                <label><?php _e('Cell type', 'tc-data-tables'); ?></label>
                                <select id="field-cell-type">
                                    <option value=""><?php _e('Plain text (default)', 'tc-data-tables'); ?></option>
                                    <option value="star_rating"><?php _e('Star rating (numeric → 0–5 stars)', 'tc-data-tables'); ?></option>
                                    <option value="badge"><?php _e('Status badge (text → colored pill)', 'tc-data-tables'); ?></option>
                                </select>
                                <p class="description"><?php _e('Render the cell value through a renderer service. Star rating: numeric → SVG stars. Status badge: map text values to colored pill badges — configure the badge map below (#1741).', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row gt-badge-map-row" style="display:none;">
                                <label><?php _e('Badge map (JSON)', 'tc-data-tables'); ?></label>
                                <textarea id="field-badge-map" rows="4" style="width:100%;font-family:monospace;font-size:12px;" placeholder='{"Pending": {"bg": "#fbbf24", "text": "#000"}, "Active": {"bg": "#10b981", "text": "#fff"}}'></textarea>
                                <p class="description"><?php _e('JSON object mapping cell text values to badge colors. Each key is the exact cell value (case-sensitive); value is {"bg": "#hex", "text": "#hex"}. Non-matching values render as plain text.', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row gt-validation-rules-section">
                                <label><strong><?php _e('Inline-edit validation (Pro)', 'tc-data-tables'); ?></strong></label>
                                <p class="description"><?php _e('Rules checked before saving an inline cell edit. Ignored for non-editable columns.', 'tc-data-tables'); ?></p>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px;">
                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-val-required">
                                        <?php _e('Required', 'tc-data-tables'); ?>
                                    </label>
                                    <span></span>
                                    <div>
                                        <label><?php _e('Min length', 'tc-data-tables'); ?></label>
                                        <input type="number" id="field-val-min-length" min="0" style="width:80px;" placeholder="0">
                                    </div>
                                    <div>
                                        <label><?php _e('Max length', 'tc-data-tables'); ?></label>
                                        <input type="number" id="field-val-max-length" min="0" style="width:80px;" placeholder="0">
                                    </div>
                                    <div>
                                        <label><?php _e('Min value', 'tc-data-tables'); ?></label>
                                        <input type="number" id="field-val-min-value" style="width:80px;" placeholder="—">
                                    </div>
                                    <div>
                                        <label><?php _e('Max value', 'tc-data-tables'); ?></label>
                                        <input type="number" id="field-val-max-value" style="width:80px;" placeholder="—">
                                    </div>
                                    <div style="grid-column:1/-1;">
                                        <label><?php _e('Regex pattern (no delimiters)', 'tc-data-tables'); ?></label>
                                        <input type="text" id="field-val-regex" style="width:100%;" placeholder="^[A-Za-z]+$">
                                    </div>
                                    <div style="grid-column:1/-1;">
                                        <label><?php _e('Regex error message', 'tc-data-tables'); ?></label>
                                        <input type="text" id="field-val-regex-message" style="width:100%;" placeholder="<?php esc_attr_e('Invalid format.', 'tc-data-tables'); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="gt-form-row gt-col-role-visibility-section">
                                <label><strong><?php _e('Visible to roles (Pro)', 'tc-data-tables'); ?></strong></label>
                                <p class="description"><?php _e('Leave blank to show to everyone. Check one or more roles to restrict this column to only those roles.', 'tc-data-tables'); ?></p>
                                <div id="field-role-visibility-checkboxes" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                                    <?php
                                    $gt_all_roles = wp_roles()->get_names();
                                    foreach ( $gt_all_roles as $gt_role_key => $gt_role_name ) :
                                    ?>
                                    <label class="gt-checkbox-label" style="min-width:120px;">
                                        <input type="checkbox" class="field-role-visibility-cb"
                                               data-role="<?php echo esc_attr( $gt_role_key ); ?>">
                                        <?php echo esc_html( $gt_role_name ); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <label class="gt-checkbox-label">
                                <input type="checkbox" id="field-detail-only">
                                <?php _e('Mark as detail-row column (slice 2 of #556)', 'tc-data-tables'); ?>
                                <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Persists the column as a "detail-only" candidate. Slice 2 (this iteration) ships the admin toggle + persistence layer; slice 3 (future) ships the runtime expand/collapse rendering. Configuring this today carries no visible effect — it just stages the per-column flag so when slice 3 lands, the chosen columns automatically move into the detail-row UX. The save shape (column_detail_only flat field_id => true map) is normalized via TC_Detail_Rows_Service::normalize_column.', 'tc-data-tables'); ?>"></span>
                            </label>

                            <label class="gt-checkbox-label">
                                <input type="checkbox" id="field-auto-merge-consecutive">
                                <?php _e('Auto-merge consecutive duplicate values (slice 2 of #518)', 'tc-data-tables'); ?>
                                <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('When this column has consecutive rows with the same value, merge them into a single rowspan group. Slice 1 shipped TC_Rowspan_Merge_Service (the pure-function helper). Slice 2 (this iteration) wires the per-column opt-in. Slice 3 will do the template surgery so the runtime actually emits rowspan-merged cells. Configuring this today persists the flag (column_auto_merge field_id => bool map) but carries no visible effect until slice 3 lands. Strict equality — \'10\' and 10 are NOT merged.', 'tc-data-tables'); ?>"></span>
                            </label>

                            <label class="gt-checkbox-label">
                                <input type="checkbox" id="field-drilldown-enabled">
                                <?php _e('Enable click-to-filter on this column (slice 2 of #568)', 'tc-data-tables'); ?>
                                <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('When enabled, clicking a cell value in this column on the live table filters the table to rows that match that value (drill-down "filter by example" UX). Slice 1 (v4.7.53) shipped TC_Drilldown_Filter_Service (filter-state helper with toggle, AND-apply, and URL serde). Slice 2 (this iteration) wires the per-column admin opt-in (storage shape: flat list of field_id strings in drilldown_columns). Slice 3 will ship the frontend cell click delegation, removable filter chips above the table, and URL persistence. Configuring this today persists the flag but carries no runtime effect until slice 3 lands.', 'tc-data-tables'); ?>"></span>
                            </label>

                            <div class="gt-form-row">
                                <label><?php _e('Totals row aggregation', 'tc-data-tables'); ?></label>
                                <select id="field-aggregation">
                                    <option value=""><?php _e('Auto (Sum for numeric, none otherwise)', 'tc-data-tables'); ?></option>
                                    <option value="SUM"><?php _e('Sum', 'tc-data-tables'); ?></option>
                                    <option value="AVG"><?php _e('Average', 'tc-data-tables'); ?></option>
                                    <option value="MIN"><?php _e('Min', 'tc-data-tables'); ?></option>
                                    <option value="MAX"><?php _e('Max', 'tc-data-tables'); ?></option>
                                    <option value="COUNT"><?php _e('Count', 'tc-data-tables'); ?></option>
                                    <option value="COUNT_DISTINCT"><?php _e('Count distinct', 'tc-data-tables'); ?></option>
                                </select>
                                <p class="description"><?php _e('Aggregation function for this column in the totals row (visible when "Show column totals row" is on under Display Options). Auto = Sum for numeric columns, blank otherwise (legacy behavior). Backed by TC_Formula_Service::SUPPORTED_AGGREGATIONS. Aggregation runs over the currently-loaded rows (per-page when pagination is on). Persisted in column_aggregations.', 'tc-data-tables'); ?></p>
                            </div>

                            <div class="gt-form-row">
                                <label class="gt-checkbox-label">
                                    <input type="checkbox" id="field-data-bar-enabled"<?php echo gt_is_premium() ? '' : ' disabled'; ?>>
                                    <?php _e('Show data bar (Pro)', 'tc-data-tables'); ?>
                                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Render a horizontal fill bar behind each numeric cell in this column, scaled to the current page maximum. The bar sits BEHIND the number (no markup is added inside the cell), so the totals row, CSV/Excel/PDF export, conditional formatting, and inline editing are all unaffected. Numeric columns only (number / quantity / total / calculation); empty and non-numeric cells show no bar. Suppressed automatically in card view, pivot view, server-side-pagination tables, auto-merged columns, and cells that already carry a background color. Persisted in column_data_bars (#1731).', 'tc-data-tables'); ?>"></span>
                                </label>
                                <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;">
                                    <?php _e('Bar color', 'tc-data-tables'); ?>
                                    <input type="color" id="field-data-bar-color" value="#3b82f6"<?php echo gt_is_premium() ? '' : ' disabled'; ?>>
                                </label>
                                <?php if (!gt_is_premium()): ?>
                                    <p class="description"><?php _e('Data Bars are a Pro feature. Upgrade to enable in-cell value bars.', 'tc-data-tables'); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="gt-form-group" style="margin-top: 16px; padding: 12px; background: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px;">
                                <h5 style="margin-top: 0;"><?php _e('Link Behavior', 'tc-data-tables'); ?></h5>
                                <p class="description"><?php _e('Applies when the cell renders as a link (URL fields, email fields, or fields with link transforms). For non-link cells these settings have no effect. Persisted in column_link_settings (#664).', 'tc-data-tables'); ?></p>

                                <div class="gt-form-row">
                                    <label><?php _e('Link Target', 'tc-data-tables'); ?></label>
                                    <select id="field-link-target">
                                        <option value=""><?php _e('Default (same window)', 'tc-data-tables'); ?></option>
                                        <option value="_self"><?php _e('Same window (_self)', 'tc-data-tables'); ?></option>
                                        <option value="_blank"><?php _e('New tab (_blank)', 'tc-data-tables'); ?></option>
                                        <option value="_parent"><?php _e('Parent frame (_parent)', 'tc-data-tables'); ?></option>
                                        <option value="_top"><?php _e('Top frame (_top)', 'tc-data-tables'); ?></option>
                                        <option value="new_tab"><?php _e('new_tab (legacy alias)', 'tc-data-tables'); ?></option>
                                    </select>
                                </div>

                                <div class="gt-form-row">
                                    <label><?php _e('Link Color', 'tc-data-tables'); ?></label>
                                    <input type="text" id="field-link-color" placeholder="<?php esc_attr_e('e.g. #1976d2 or "blue"', 'tc-data-tables'); ?>" style="width: 200px;">
                                    <p class="description"><?php _e('Hex color (#RRGGBB) or named CSS color. Leave empty to inherit theme styling.', 'tc-data-tables'); ?></p>
                                </div>

                                <div class="gt-form-row">
                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-link-underline" checked>
                                        <?php _e('Show underline on link', 'tc-data-tables'); ?>
                                    </label>
                                </div>
                            </div>

                            <div class="gt-form-group">
                                <h4><?php _e('Column Behavior', 'tc-data-tables'); ?></h4>
                                <div class="gt-checkbox-group">
                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-sortable">
                                        <?php _e('Make this column sortable', 'tc-data-tables'); ?>
                                    </label>
                                    
                                    <label class="gt-checkbox-label" title="<?php _e('Check this to make this field read-only (not editable)', 'tc-data-tables'); ?>">
                                        <input type="checkbox" id="field-disabled">
                                        <?php _e('Disable Editing', 'tc-data-tables'); ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Advanced Options Tab -->
                        <div id="gt-advanced-tab" class="gt-field-modal-tab-content">
                            <h4><?php _e('Advanced Options', 'tc-data-tables'); ?></h4>
                            <p class="description"><?php _e('Configure advanced field behaviors and data transformations.', 'tc-data-tables'); ?></p>
                            
                            <div class="gt-form-group">
                                <label class="gt-checkbox-label">
                                    <input type="checkbox" id="field-lookup-enabled">
                                    <?php _e('Enable lookup (for foreign key fields)', 'tc-data-tables'); ?>
                                </label>
                                <p class="description"><?php _e('Use this when the field contains an ID that should be resolved to a display value', 'tc-data-tables'); ?></p>
                            </div>
                            
                            <!-- Lookup Configuration (shown when checkbox is checked) -->
                            <div id="lookup-config" style="display: none;">
                                <div class="gt-form-group">
                                    <h4><?php _e('Lookup Configuration', 'tc-data-tables'); ?></h4>
                                    
                                    <div class="gt-form-row">
                                        <label><?php _e('Lookup Type', 'tc-data-tables'); ?></label>
                                        <select id="field-lookup-type">
                                            <option value=""><?php _e('Select lookup type', 'tc-data-tables'); ?></option>
                                            <option value="user"><?php _e('WordPress User', 'tc-data-tables'); ?></option>
                                            <option value="post"><?php _e('WordPress Post/Page', 'tc-data-tables'); ?></option>
                                            <option value="custom"><?php _e('Custom Table', 'tc-data-tables'); ?></option>
                                        </select>
                                    </div>

                                    <div class="gt-form-row gt-lookup-user-config" style="display: none;">
                                        <label><?php _e('Display Field', 'tc-data-tables'); ?></label>
                                        <select id="field-lookup-user-field">
                                            <option value="display_name"><?php _e('Display Name', 'tc-data-tables'); ?></option>
                                            <option value="user_login"><?php _e('Username', 'tc-data-tables'); ?></option>
                                            <option value="user_email"><?php _e('Email', 'tc-data-tables'); ?></option>
                                            <option value="first_name"><?php _e('First Name', 'tc-data-tables'); ?></option>
                                            <option value="last_name"><?php _e('Last Name', 'tc-data-tables'); ?></option>
                                            <option value="user_nicename"><?php _e('Nice Name', 'tc-data-tables'); ?></option>
                                        </select>
                                        
                                        <label><?php _e('User Roles to Include', 'tc-data-tables'); ?></label>
                                        <select id="field-lookup-user-roles" multiple size="5">
                                            <?php
                                            $roles = wp_roles()->get_names();
                                            foreach ($roles as $role_key => $role_name):
                                            ?>
                                                <option value="<?php echo esc_attr($role_key); ?>">
                                                    <?php echo esc_html($role_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php _e('Select which user roles should appear in the dropdown. Leave empty to show all roles.', 'tc-data-tables'); ?></p>
                                    </div>

                                    <div class="gt-form-row gt-lookup-post-config" style="display: none;">
                                        <label><?php _e('Display Field', 'tc-data-tables'); ?></label>
                                        <select id="field-lookup-post-field">
                                            <option value="post_title"><?php _e('Title', 'tc-data-tables'); ?></option>
                                            <option value="post_excerpt"><?php _e('Excerpt', 'tc-data-tables'); ?></option>
                                            <option value="post_status"><?php _e('Status', 'tc-data-tables'); ?></option>
                                            <option value="post_type"><?php _e('Post Type', 'tc-data-tables'); ?></option>
                                        </select>
                                    </div>

                                    <div class="gt-form-row gt-lookup-custom-config" style="display: none;">
                                        <label><?php _e('Table Name', 'tc-data-tables'); ?></label>
                                        <input type="text" id="field-lookup-table" placeholder="<?php _e('e.g., wp_custom_table', 'tc-data-tables'); ?>">
                                        <p class="description"><?php _e('Enter the database table name (including prefix)', 'tc-data-tables'); ?></p>

                                        <label><?php _e('ID Column', 'tc-data-tables'); ?></label>
                                        <input type="text" id="field-lookup-id-column" placeholder="<?php _e('e.g., id', 'tc-data-tables'); ?>">
                                        <p class="description"><?php _e('Column name that contains the ID to match', 'tc-data-tables'); ?></p>

                                        <label><?php _e('Display Column', 'tc-data-tables'); ?></label>
                                        <input type="text" id="field-lookup-display-column" placeholder="<?php _e('e.g., name', 'tc-data-tables'); ?>">
                                        <p class="description"><?php _e('Column name that contains the value to display', 'tc-data-tables'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filtering Options Tab -->
                        <div id="gt-filtering-tab" class="gt-field-modal-tab-content">
                            <h4><?php _e('Filter Settings', 'tc-data-tables'); ?></h4>
                            <p class="description"><?php _e('Configure how users can filter data in this column.', 'tc-data-tables'); ?></p>
                            
                            <div class="gt-form-group">
                                <label class="gt-checkbox-label">
                                    <input type="checkbox" id="field-filterable" checked>
                                    <?php _e('Allow filtering', 'tc-data-tables'); ?>
                                </label>
                                <p class="description"><?php _e('Enable filtering capabilities for this column', 'tc-data-tables'); ?></p>
                            </div>
                            
                            <div class="gt-form-group" id="filter-options-group">
                                <h4><?php _e('Filter Display', 'tc-data-tables'); ?></h4>
                                <div class="gt-form-row">
                                    <label><?php _e('Filter Type', 'tc-data-tables'); ?></label>
                                    <select id="field-filter-type">
                                        <option value="text"><?php _e('Text Input', 'tc-data-tables'); ?></option>
                                        <option value="dropdown"><?php _e('Dropdown', 'tc-data-tables'); ?></option>
                                        <option value="date"><?php _e('Date Picker', 'tc-data-tables'); ?></option>
                                        <option value="range"><?php _e('Range Slider', 'tc-data-tables'); ?></option>
                                        <option value="checkboxes"><?php _e('Checkboxes', 'tc-data-tables'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Choose how users will filter data in this column', 'tc-data-tables'); ?></p>
                                </div>
                                
                                <div class="gt-form-row">
                                    <label><?php _e('Placeholder Text', 'tc-data-tables'); ?></label>
                                    <input type="text" id="field-filter-placeholder" placeholder="<?php _e('e.g., Filter by...', 'tc-data-tables'); ?>">
                                    <p class="description"><?php _e('Text shown in the filter input when empty', 'tc-data-tables'); ?></p>
                                </div>
                            </div>
                            
                            <!-- Text Filter Options -->
                            <div class="gt-form-group gt-filter-type-options gt-filter-text-options">
                                <h4><?php _e('Text Filter Options', 'tc-data-tables'); ?></h4>
                                <p class="description"><?php _e('Both toggles are wired through to the AJAX filter handler (#650 fixed in v4.8.4). Case-sensitive uses MySQL LIKE BINARY; exact-match swaps the substring LIKE for an exact-equals comparison.', 'tc-data-tables'); ?></p>
                                <div class="gt-checkbox-group">
                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-filter-case-sensitive">
                                        <?php _e('Case sensitive filtering', 'tc-data-tables'); ?>
                                    </label>

                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-filter-exact-match">
                                        <?php _e('Exact match only', 'tc-data-tables'); ?>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Dropdown Filter Options -->
                            <div class="gt-form-group gt-filter-type-options gt-filter-dropdown-options" style="display: none;">
                                <h4><?php _e('Dropdown Filter Options', 'tc-data-tables'); ?></h4>
                                <div class="gt-checkbox-group">
                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-filter-multiple">
                                        <?php _e('Allow multiple selections', 'tc-data-tables'); ?>
                                    </label>
                                </div>
                                <div class="gt-form-row">
                                    <label><?php _e('Option Sorting', 'tc-data-tables'); ?></label>
                                    <select id="field-filter-sort-options">
                                        <option value="alphabetical"><?php _e('Alphabetical', 'tc-data-tables'); ?></option>
                                        <option value="frequency"><?php _e('By Frequency (most common first)', 'tc-data-tables'); ?></option>
                                        <option value="original"><?php _e('Original Order (creation order)', 'tc-data-tables'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('All three options now wire through to render time (#650 closed in v4.8.5). Frequency uses COUNT(*) descending; Original Order uses MIN(entry_id) ascending so values appear in the order they were first seen.', 'tc-data-tables'); ?></p>
                                </div>
                            </div>
                            
                            <!-- Date Filter Options -->
                            <div class="gt-form-group gt-filter-type-options gt-filter-date-options" style="display: none;">
                                <h4><?php _e('Date Filter Options', 'tc-data-tables'); ?></h4>
                                <div class="gt-form-row">
                                    <label><?php _e('Date Range Type', 'tc-data-tables'); ?></label>
                                    <select id="field-filter-date-range">
                                        <option value="single"><?php _e('Single Date', 'tc-data-tables'); ?></option>
                                        <option value="range"><?php _e('Date Range', 'tc-data-tables'); ?></option>
                                    </select>
                                </div>
                                <div class="gt-checkbox-group">
                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-filter-show-presets">
                                        <?php _e('Show preset ranges (Today, This Week, etc.)', 'tc-data-tables'); ?>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Range Filter Options -->
                            <div class="gt-form-group gt-filter-type-options gt-filter-range-options" style="display: none;">
                                <h4><?php _e('Range Filter Options', 'tc-data-tables'); ?></h4>
                                <div class="gt-form-row">
                                    <label><?php _e('Step Size', 'tc-data-tables'); ?></label>
                                    <input type="number" id="field-filter-range-step" min="0.001" step="0.001" value="0.001" placeholder="0.001">
                                    <p class="description"><?php _e('Minimum increment for the range slider', 'tc-data-tables'); ?></p>
                                </div>
                                <div class="gt-form-row">
                                    <label><?php _e('Display Format', 'tc-data-tables'); ?></label>
                                    <select id="field-filter-range-format">
                                        <option value="number"><?php _e('Number', 'tc-data-tables'); ?></option>
                                        <option value="currency"><?php _e('Currency', 'tc-data-tables'); ?></option>
                                        <option value="percentage"><?php _e('Percentage', 'tc-data-tables'); ?></option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Checkboxes Filter Options -->
                            <div class="gt-form-group gt-filter-type-options gt-filter-checkboxes-options" style="display: none;">
                                <h4><?php _e('Checkboxes Filter Options', 'tc-data-tables'); ?></h4>
                                <div class="gt-form-row">
                                    <label><?php _e('Logic Type', 'tc-data-tables'); ?></label>
                                    <select id="field-filter-checkboxes-logic">
                                        <option value="or"><?php _e('OR (show if any selected)', 'tc-data-tables'); ?></option>
                                        <option value="and"><?php _e('AND (show only if all selected)', 'tc-data-tables'); ?></option>
                                    </select>
                                </div>
                                <div class="gt-checkbox-group">
                                    <label class="gt-checkbox-label">
                                        <input type="checkbox" id="field-filter-show-select-all">
                                        <?php _e('Show "Select All" option', 'tc-data-tables'); ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Conditional Formatting Tab -->
                        <div id="gt-conditional-tab" class="gt-field-modal-tab-content">
                            <h4><?php _e('Conditional Formatting Rules', 'tc-data-tables'); ?></h4>
                            <p class="description"><?php _e('Apply visual formatting rules based on cell values. You can add multiple rules per field.', 'tc-data-tables'); ?></p>

                            <div id="gt-conditional-formatting-rules-container">
                                <!-- Conditional formatting rules will be added here -->
                            </div>

                            <div class="gt-form-row">
                                <button type="button" class="button" id="gt-add-conditional-rule">
                                    <span class="dashicons dashicons-plus-alt"></span>
                                    <?php _e('Add Formatting Rule', 'tc-data-tables'); ?>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Responsive Settings Tab -->
                        <div id="gt-responsive-tab" class="gt-field-modal-tab-content">
                            <h4><?php _e('Mobile & Tablet Display Settings', 'tc-data-tables'); ?></h4>
                            <p class="description"><?php _e('Configure how this field appears on different device types. These settings only apply when Enhanced Responsive Mode is enabled in Table Settings.', 'tc-data-tables'); ?></p>
                            
                            <div class="gt-responsive-settings">
                                <div class="gt-form-group">
                                    <h5><?php _e('Mobile Devices (≤480px)', 'tc-data-tables'); ?></h5>
                                    
                                    <div class="gt-form-row">
                                        <label class="gt-checkbox-label">
                                            <input type="checkbox" id="field-mobile-visible" checked>
                                            <?php _e('Show on mobile devices', 'tc-data-tables'); ?>
                                        </label>
                                        <p class="description"><?php _e('Uncheck to hide this field completely on mobile', 'tc-data-tables'); ?></p>
                                    </div>
                                    
                                    <div class="gt-form-row" id="mobile-label-row">
                                        <label><?php _e('Mobile Label Override', 'tc-data-tables'); ?></label>
                                        <input type="text" id="field-mobile-label" placeholder="<?php _e('Leave blank to use default label', 'tc-data-tables'); ?>">
                                        <p class="description"><?php _e('Use shorter labels for mobile display (e.g., "Name" instead of "Customer Full Name")', 'tc-data-tables'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="gt-form-group">
                                    <h5><?php _e('Tablet Devices (481-768px)', 'tc-data-tables'); ?></h5>
                                    
                                    <div class="gt-form-row">
                                        <label class="gt-checkbox-label">
                                            <input type="checkbox" id="field-tablet-visible" checked>
                                            <?php _e('Show on tablet devices', 'tc-data-tables'); ?>
                                        </label>
                                        <p class="description"><?php _e('Uncheck to hide this field on tablet screens', 'tc-data-tables'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="gt-form-group gt-responsive-info">
                                    <div class="gt-info-box">
                                        <span class="dashicons dashicons-info"></span>
                                        <p><strong><?php _e('Note:', 'tc-data-tables'); ?></strong> 
                                        <?php _e('These responsive settings require Enhanced Responsive Mode to be enabled in your Table Settings. When disabled, all fields will use the standard responsive behavior.', 'tc-data-tables'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="gt-modal-footer">
                        <button type="button" class="button" id="cancel-field-config"><?php _e('Cancel', 'tc-data-tables'); ?></button>
                        <button type="button" class="button-primary" id="save-field-config"><?php _e('Save', 'tc-data-tables'); ?></button>
                    </div>
                </div>
            </div>
            </div>
            
            <!-- Section Save Button -->
            <div class="gt-section-save">
                <button type="button" class="button-primary gt-save-button" data-save-location="field-selection">
                    <?php _e('Save', 'tc-data-tables'); ?>
                </button>
            </div>
        </div>

        <!-- Step 3: Table Features -->
        <div class="gt-builder-section" id="table-features">
            <div class="gt-section-header gt-collapsible" data-target="table-features-content">
                <div class="gt-section-title">
                    <h2><?php _e('3. Table Features', 'tc-data-tables'); ?></h2>
                    <span class="gt-toggle-icon" title="Click to toggle section">▼</span>
                </div>
                <p><?php _e('Configure table functionality and behavior.', 'tc-data-tables'); ?></p>
            </div>
            <div class="gt-section-content" id="table-features-content">

                <div class="gt-features-grid">
                <div class="gt-feature-group">
                    <h4><?php _e('Table Display', 'tc-data-tables'); ?></h4>

<label class="gt-checkbox-label">
                        <input type="checkbox" name="show_search"
                               <?php checked(isset($table_settings['show_search']) ? $table_settings['show_search'] : true); ?>>
                        <?php _e('Show search controls', 'tc-data-tables'); ?>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_pagination"
                               <?php checked(isset($table_settings['show_pagination']) ? $table_settings['show_pagination'] : true); ?>>
                        <?php _e('Show pagination', 'tc-data-tables'); ?>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_entry_info"
                               <?php checked(isset($table_settings['show_entry_info']) ? $table_settings['show_entry_info'] : true); ?>>
                        <?php _e('Show entry information', 'tc-data-tables'); ?>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_add_entry"
                               <?php checked(isset($table_settings['show_add_entry']) ? $table_settings['show_add_entry'] : true); ?>>
                        <?php _e('Show Add New Entry button', 'tc-data-tables'); ?>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_column_totals"
                               <?php checked(isset($table_settings['show_column_totals']) ? $table_settings['show_column_totals'] : false); ?>>
                        <?php _e('Show column totals row', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Displays a summary row at the bottom with totals for numeric columns', 'tc-data-tables'); ?>"></span>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_length_selector"
                               <?php checked(!empty($table_settings['show_length_selector'])); ?>>
                        <?php _e('Show length selector ("Show N entries" dropdown)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Lets visitors pick the page size on the fly. Off by default — existing tables stay locked to the admin-set "Entries per page" value.', 'tc-data-tables'); ?>"></span>
                    </label>
                    <div class="gt-form-row">
                        <label for="gt-length-options"><?php _e('Length selector options', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-length-options" name="length_selector_options"
                               value="<?php echo esc_attr(isset($table_settings['length_selector_options']) ? (string) $table_settings['length_selector_options'] : ''); ?>"
                               placeholder="10,25,50,100,-1"
                               style="width: 100%; max-width: 320px;">
                        <p class="description"><?php _e('Comma-separated list of options. Use -1 for "All". Empty = default "10,25,50,100,-1".', 'tc-data-tables'); ?></p>
                        
                    </div>

                    <div class="gt-form-row">
                        <label><?php _e('Entries per page', 'tc-data-tables'); ?></label>
                        <select name="per_page">
                            <option value="10" <?php selected(isset($table_settings['per_page']) ? $table_settings['per_page'] : 25, 10); ?>>10</option>
                            <option value="25" <?php selected(isset($table_settings['per_page']) ? $table_settings['per_page'] : 25, 25); ?>>25</option>
                            <option value="50" <?php selected(isset($table_settings['per_page']) ? $table_settings['per_page'] : 25, 50); ?>>50</option>
                            <option value="100" <?php selected(isset($table_settings['per_page']) ? $table_settings['per_page'] : 25, 100); ?>>100</option>
                            <option value="-1" <?php selected(isset($table_settings['per_page']) ? $table_settings['per_page'] : 25, -1); ?>><?php _e('All', 'tc-data-tables'); ?></option>
                        </select>
                    </div>

                    <div class="gt-form-row">
                        <label><?php _e('Auto-refresh interval (seconds, 0 = off)', 'tc-data-tables'); ?></label>
                        <input type="number" name="auto_refresh_interval" min="0" step="5" style="width:80px;"
                               value="<?php echo esc_attr(isset($table_settings['auto_refresh_interval']) ? (int) $table_settings['auto_refresh_interval'] : 0); ?>">
                        <p class="description"><?php _e('When set, the table automatically reloads its entries at this interval. Minimum enforced: 5 seconds. Set to 0 to disable. The refresh pauses while a cell is being edited inline. Free feature (#1743).', 'tc-data-tables'); ?></p>
                    </div>

                    <div class="gt-form-row">
                        <label>
                            <input type="checkbox" name="show_column_picker" value="1"
                                   <?php checked( ! empty( $table_settings['show_column_picker'] ) ); ?>>
                            <?php _e( 'Enable column visibility picker', 'tc-data-tables' ); ?>
                        </label>
                        <p class="description"><?php _e( 'Adds a "Columns" button to the table toolbar. Visitors can show/hide individual columns. Their choice is saved per-browser to localStorage. Free feature (#1744).', 'tc-data-tables' ); ?></p>
                    </div>

                                    </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Sorting', 'tc-data-tables'); ?></h4>

<h5>
                        <?php _e('Default sort', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Override the initial sort column / direction shown on first page load. Without this override the table sorts by Entry creation date descending. Visitors can still re-sort by clicking column headers; their session preference takes precedence without modifying the saved table configuration. Backed by TC_Default_Sort_Service.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <?php
                    $gt_ds_form_fields = array();
                    if (!empty($table_data) && !empty($table_data->form_id) && class_exists('GFAPI')) {
                        $gt_ds_form = GFAPI::get_form((int) $table_data->form_id);
                        if ($gt_ds_form && !is_wp_error($gt_ds_form) && !empty($gt_ds_form['fields'])) {
                            foreach ($gt_ds_form['fields'] as $gt_dsf) {
                                $gt_ds_form_fields[(string) $gt_dsf->id] = (string) $gt_dsf->label;
                            }
                        }
                    }
                    $gt_ds_current_col = isset($table_settings['default_sort_column']) ? (string) $table_settings['default_sort_column'] : '';
                    $gt_ds_current_dir = isset($table_settings['default_sort_direction']) ? (string) $table_settings['default_sort_direction'] : 'asc';
                    ?>
                    <div class="gt-form-row">
                        <label for="gt-default-sort-column"><?php _e('Default sort column', 'tc-data-tables'); ?></label>
                        <select id="gt-default-sort-column" name="default_sort_column" style="min-width: 280px;">
                            <option value="" <?php selected($gt_ds_current_col, ''); ?>><?php _e('— Use legacy default (date_created desc) —', 'tc-data-tables'); ?></option>
                            <option value="date_created" <?php selected($gt_ds_current_col, 'date_created'); ?>><?php _e('Entry creation date', 'tc-data-tables'); ?></option>
                            <option value="entry_id" <?php selected($gt_ds_current_col, 'entry_id'); ?>><?php _e('Entry ID', 'tc-data-tables'); ?></option>
                            <?php foreach ($gt_ds_form_fields as $gt_dsf_id => $gt_dsf_label): ?>
                                <option value="<?php echo esc_attr($gt_dsf_id); ?>" <?php selected($gt_ds_current_col, (string) $gt_dsf_id); ?>><?php echo esc_html($gt_dsf_label) . ' (#' . esc_html($gt_dsf_id) . ')'; ?></option>
                            <?php endforeach; ?>
                            <?php if ($gt_ds_current_col !== '' && $gt_ds_current_col !== 'date_created' && $gt_ds_current_col !== 'entry_id' && !isset($gt_ds_form_fields[$gt_ds_current_col])): ?>
                                <option value="<?php echo esc_attr($gt_ds_current_col); ?>" selected><?php echo esc_html($gt_ds_current_col); ?> <?php _e('(saved value — field not in current form)', 'tc-data-tables'); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-default-sort-direction"><?php _e('Default sort direction', 'tc-data-tables'); ?></label>
                        <select id="gt-default-sort-direction" name="default_sort_direction">
                            <option value="asc"  <?php selected($gt_ds_current_dir, 'asc'); ?>><?php _e('Ascending (A → Z, 0 → 9, oldest → newest)', 'tc-data-tables'); ?></option>
                            <option value="desc" <?php selected($gt_ds_current_dir, 'desc'); ?>><?php _e('Descending (Z → A, 9 → 0, newest → oldest)', 'tc-data-tables'); ?></option>
                        </select>
                    </div>

                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

<h5>
                        <?php _e('Multi-column sort (#565)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Shift-click on a column header adds it as a secondary or tertiary tiebreaker (max 3). Numbered badges (1, 2, 3) appear next to sort arrows so customers can see the active sort order. Plain click resets to single-column sort. Slice 1 (v4.9.0) shipped the UX; slice 2 (v4.9.1) refactored the SQL ORDER BY block so secondary tiebreakers actually apply across all rows.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="enable_multi_sort"
                               <?php checked(isset($table_settings['enable_multi_sort']) ? (bool) $table_settings['enable_multi_sort'] : true); ?>>
                        <?php _e('Enable multi-column sort (shift-click)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Default ON. Disable to revert to legacy single-column sort behavior.', 'tc-data-tables'); ?>"></span>
                    </label>

                                    </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Filtering &amp; Data', 'tc-data-tables'); ?></h4>

<h5>
                        <?php _e('Cascading filter dropdown (#599)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Configure a single parent → child filter dependency. When the visitor picks a value in the parent filter dropdown, the child filter dropdown re-populates with only the child values that appear alongside that parent in the table data (Country → State → City pattern). Slice 2 (this release) ships the admin field; slice 3 wires the frontend behavior. Until slice 3 lands, the chain persists but does not yet drive runtime filtering. Empty values disable the chain. Self-referencing pairs (parent === child) are rejected by the sanitizer.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <div class="gt-form-row">
                        <label for="gt-cascading-filter-parent" style="display:block; margin-bottom:4px;">
                            <strong><?php _e('Parent filter field id', 'tc-data-tables'); ?></strong>
                        </label>
                        <input
                            type="text"
                            name="cascading_filter_parent_field"
                            id="gt-cascading-filter-parent"
                            value="<?php echo esc_attr(isset($table_settings['cascading_filter_parent_field']) ? (string) $table_settings['cascading_filter_parent_field'] : ''); ?>"
                            placeholder="<?php esc_attr_e('e.g. 3', 'tc-data-tables'); ?>"
                            style="width: 100%; max-width: 200px;"
                        >
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-cascading-filter-child" style="display:block; margin-bottom:4px;">
                            <strong><?php _e('Child filter field id', 'tc-data-tables'); ?></strong>
                        </label>
                        <input
                            type="text"
                            name="cascading_filter_child_field"
                            id="gt-cascading-filter-child"
                            value="<?php echo esc_attr(isset($table_settings['cascading_filter_child_field']) ? (string) $table_settings['cascading_filter_child_field'] : ''); ?>"
                            placeholder="<?php esc_attr_e('e.g. 5', 'tc-data-tables'); ?>"
                            style="width: 100%; max-width: 200px;"
                        >
                    </div>

                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

<h5>
                        <?php _e('Filter persistence (browser localStorage)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help" title="<?php esc_attr_e('When on, the visitor\'s search box value and per-column filter selections are saved to their browser\'s localStorage (keyed by table id). On their next visit (or when they navigate away and back), those filters are auto-restored before the first AJAX so the table arrives in the same filtered state. Sort state is NOT persisted — it would be surprising to "remember" sort across pages. URL pre-filters (the toggle below) take precedence: when ?gt_col_X=value is in the URL, localStorage is ignored. Off by default to avoid surprising visitors who expect a fresh table on revisit.', 'tc-data-tables'); ?>" style="color: #2271b1; cursor: help; vertical-align: middle; font-size: 16px;"></span>
                    </h5>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="persist_filters_localstorage"
                               <?php checked(!empty($table_settings['persist_filters_localstorage'])); ?>>
                        <?php _e('Persist filters in browser localStorage', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Storage key: gt-filters-{table_id}. Cleared automatically when the visitor uses "Reset filters" in the toolbar.', 'tc-data-tables'); ?>"></span>
                    </label>

                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

<h5>
                        <?php _e('URL pre-filtering', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help" title="<?php esc_attr_e('When enabled, append <code>?gt_col_{column_id}=value</code> query parameters to the page URL to pre-filter the table on load. Useful for shareable / bookmarkable filtered views, "deep links" from email campaigns, and sales pages that link to a category-filtered table. Multiple parameters are AND-combined. Backed by TC_URL_Filter_Service. Off by default — enabling per-table is an explicit admin decision (the URL params reach client-side via $_GET sanitization, then map to the existing per-column filter inputs).', 'tc-data-tables'); ?>" style="color: #2271b1; cursor: help; vertical-align: middle; font-size: 16px;"></span>
                    </h5>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="allow_url_filters"
                               <?php checked(!empty($table_settings['allow_url_filters'])); ?>>
                        <?php _e('Allow URL pre-filtering', 'tc-data-tables'); ?>
                    </label>
                    <p class="description" style="margin: 4px 0 0 24px;"><?php _e('Example: <code>?gt_col_status=Active&amp;gt_col_region=North</code>', 'tc-data-tables'); ?></p>

                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

<h5><?php _e('Filter by Status', 'tc-data-tables'); ?></h5>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_deleted_entries"
                               <?php checked(isset($table_settings['show_deleted_entries']) ? $table_settings['show_deleted_entries'] : false); ?>>
                        <?php _e('Show deleted form records', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Include entries that have been deleted/trashed', 'tc-data-tables'); ?>"></span>
                    </label>

                    <h5><?php _e('Filter by User', 'tc-data-tables'); ?></h5>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="filter_user_entries"
                               <?php checked(isset($table_settings['filter_user_entries']) ? $table_settings['filter_user_entries'] : false); ?>>
                        <?php _e('Show user only their own entries', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('When enabled, logged-in users will only see entries they created', 'tc-data-tables'); ?>"></span>
                    </label>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Appearance', 'tc-data-tables'); ?></h4>

<h5>
                        <?php _e('Collapsible table', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Wrap the entire table in a collapsible container with an accessible expand/collapse toggle button above it. Useful for dashboards with multiple tables — visitors can collapse the ones they\'re not currently using to reduce visual noise. Toggle uses aria-expanded + aria-controls + role-correct semantics for screen readers. Open/closed state persists per-table in localStorage so the collapsed/expanded choice survives page reloads. Backed by TC_Collapsible_Service. Off by default — existing tables render exactly as today.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="collapsible_enabled"
                               <?php checked(!empty($table_settings['collapsible_enabled'])); ?>>
                        <?php _e('Make whole table collapsible', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Renders an expand/collapse toggle button above the table.', 'tc-data-tables'); ?>"></span>
                    </label>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="collapsible_default_collapsed"
                               <?php checked(!empty($table_settings['collapsible_default_collapsed'])); ?>>
                        <?php _e('Default to collapsed on first visit', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Only applies when "Make whole table collapsible" is on. Visitors with a saved localStorage preference override this.', 'tc-data-tables'); ?>"></span>
                    </label>

                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

<h5>
                        <?php _e('Row height', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Constrain row height to a fixed value. Useful for dense data tables, fixed-pixel grid layouts, or matching a strict design spec. Three named presets (compact = 32px, default = 48px, comfortable = 64px) or a custom CSS value (e.g. 56px / 4em / 80vh). Backed by TC_Row_Height_Service. Empty = browser default (auto). Mobile cards mode (≤ 768px) always reverts to auto so card layouts aren\'t broken.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <div class="gt-form-row">
                        <label for="gt-row-height"><?php _e('Body row height', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-row-height" name="row_height"
                               value="<?php echo esc_attr(isset($table_settings['row_height']) ? (string) $table_settings['row_height'] : ''); ?>"
                               placeholder="compact / default / comfortable / 56px / 4em"
                               style="width: 100%; max-width: 280px;">
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-header-height"><?php _e('Header row height', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-header-height" name="header_height"
                               value="<?php echo esc_attr(isset($table_settings['header_height']) ? (string) $table_settings['header_height'] : ''); ?>"
                               placeholder="compact / default / comfortable / 56px / 4em"
                               style="width: 100%; max-width: 280px;">
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-row-overflow"><?php _e('Overflow mode', 'tc-data-tables'); ?></label>
                        <select id="gt-row-overflow" name="row_overflow_mode">
                            <?php $gt_overflow_current = isset($table_settings['row_overflow_mode']) ? (string) $table_settings['row_overflow_mode'] : 'ellipsis'; ?>
                            <option value="ellipsis" <?php selected($gt_overflow_current, 'ellipsis'); ?>><?php _e('Ellipsis (clip overflow with …)', 'tc-data-tables'); ?></option>
                            <option value="expand" <?php selected($gt_overflow_current, 'expand'); ?>><?php _e('Expand (click row to reveal full content)', 'tc-data-tables'); ?></option>
                        </select>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Only applies when row height is set. Ellipsis: long content is clipped with …; rows stay one-line. Expand: rows clamp at the configured height by default; toggling .gt-row-expanded on a <tr> reveals the full content (no JS click handler is wired in slice 1, so this currently behaves as ellipsis until a future iteration adds the click toggle).', 'tc-data-tables'); ?>"></span>
                    </div>

                                        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

<h5>
                        <?php _e('Border style', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Per-table border preset. Classic = horizontal + vertical lines + outer border + accented header bottom (default — matches the existing look). Rows only = horizontal dividers without column dividers. None = borderless. Outer only = single outer border with rounded corners. Backed by TC_Border_Service.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <div class="gt-form-row">
                        <label for="gt-border-preset"><?php _e('Border preset', 'tc-data-tables'); ?></label>
                        <select id="gt-border-preset" name="border_preset">
                            <?php
                            $gt_border_current = isset($table_settings['border_preset']) ? (string) $table_settings['border_preset'] : 'classic';
                            $gt_border_options = array(
                                'classic'    => __('Classic (default)', 'tc-data-tables'),
                                'rows_only'  => __('Rows only — no vertical dividers', 'tc-data-tables'),
                                'none'       => __('None — borderless', 'tc-data-tables'),
                                'outer_only' => __('Outer only — single rounded outer border', 'tc-data-tables'),
                            );
                            foreach ($gt_border_options as $gt_bo_key => $gt_bo_label):
                            ?>
                                <option value="<?php echo esc_attr($gt_bo_key); ?>" <?php selected($gt_border_current, $gt_bo_key); ?>><?php echo esc_html($gt_bo_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                                    </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Security &amp; Advanced', 'tc-data-tables'); ?></h4>

                    <h5>
                        <?php _e('Password protection (#607)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Per-table password gate for the frontend. When set, visitors see a password form instead of the table; on correct password, the table renders and the password is remembered for ~24h via a signed cookie. Useful for: club rosters, member directories, internal pricing tables, sensitive data on otherwise public pages. Distinct from role-based access — works for visitors WITHOUT WordPress accounts. Backed by TC_Table_Password_Service (slice 1, v4.10.1). Slice 2 (this release) wires the admin field + save sanitization. Slice 3 wires the frontend gate; until that lands, this setting persists but does NOT yet block visitors. Stored as a bcrypt hash, never as plaintext.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <?php
                    $gt_pwd_has = !empty($table_settings['table_password_hash']);
                    ?>
                    <div class="gt-field-group">
                        <label for="gt-table-password" style="display:block; margin-bottom:4px;">
                            <strong><?php _e('Password (optional)', 'tc-data-tables'); ?></strong>
                        </label>
                        <input
                            type="password"
                            name="table_password"
                            id="gt-table-password"
                            value=""
                            autocomplete="new-password"
                            placeholder="<?php echo $gt_pwd_has ? esc_attr__('Password currently set — leave blank to keep it', 'tc-data-tables') : esc_attr__('Leave blank to disable password protection', 'tc-data-tables'); ?>"
                            style="width: 100%; max-width: 380px;"
                        >
                        <p class="description" style="margin-top:6px;">
                            <?php
                            if ($gt_pwd_has) {
                                _e('A password is currently set. Leave the field blank to keep it. Type a new password to change it. Type <code>__GT_CLEAR__</code> (literally) to remove the password and disable protection.', 'tc-data-tables');
                            } else {
                                _e('No password set. Type any password to enable protection. Stored as a bcrypt hash; the plaintext is never written to the database.', 'tc-data-tables');
                            }
                            ?>
                        </p>
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

                    <h5>
                        <?php _e('Per-row actions (#618)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Configure the built-in send_email and post_webhook per-row actions.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <p class="description" style="margin: -8px 0 12px 0;"><?php _e('Both actions are gated behind filters (default off). Enable with: <code>add_filter(\'gt_send_email_enabled\', \'__return_true\');</code> and/or <code>add_filter(\'gt_post_webhook_enabled\', \'__return_true\');</code>', 'tc-data-tables'); ?></p>
                    <div class="gt-form-row">
                        <label for="gt-send-email-recipient-field" style="display:block; margin-bottom:4px;">
                            <strong><?php _e('Send-email: recipient field id (optional)', 'tc-data-tables'); ?></strong>
                        </label>
                        <input
                            type="text"
                            name="send_email_recipient_field"
                            id="gt-send-email-recipient-field"
                            value="<?php echo esc_attr(isset($table_settings['send_email_recipient_field']) ? (string) $table_settings['send_email_recipient_field'] : ''); ?>"
                            placeholder="<?php esc_attr_e('e.g. 3 — leave blank to use the first email-shaped value in the row', 'tc-data-tables'); ?>"
                            style="width: 100%; max-width: 380px;"
                        >
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Gravity Forms field id whose value should be used as the email recipient. Leave blank to fall back to auto-detect (first value in the entry that looks like an email address).', 'tc-data-tables'); ?>"></span>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-per-row-webhook-url" style="display:block; margin-bottom:4px;">
                            <strong><?php _e('Post-webhook: destination URL', 'tc-data-tables'); ?></strong>
                        </label>
                        <input
                            type="url"
                            name="per_row_webhook_url"
                            id="gt-per-row-webhook-url"
                            value="<?php echo esc_attr(isset($table_settings['per_row_webhook_url']) ? (string) $table_settings['per_row_webhook_url'] : ''); ?>"
                            placeholder="https://example.com/webhook"
                            style="width: 100%; max-width: 500px;"
                        >
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('JSON payload (event, action, table_id, row_id, entry) is POSTed here when a visitor clicks the "Send to webhook" button. Customize the payload shape via the gt_post_webhook_payload filter. Distinct from the entry-event Webhook URL field above (that one fires on entry CRUD; this one fires on per-row button click).', 'tc-data-tables'); ?>"></span>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Print &amp; Export', 'tc-data-tables'); ?></h4>


<h5>
                        <?php _e('Print settings (#531)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Per-table print customization. The base print stylesheet (assets/css/frontend-print.css) hides chrome, expands rows, repeats thead, and applies grayscale zebra striping. These settings let you override paper size, opt out of the default repeat-header / row-striping behavior, and exclude specific columns from the printed output. Backed by TC_Print_Settings_Service. When the master toggle is off (default) the global print stylesheet alone applies — existing tables see no behavior change.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <?php
                    $gt_print_settings = isset($table_settings['print_settings']) && is_array($table_settings['print_settings']) ? $table_settings['print_settings'] : array();
                    $gt_print_enabled = !empty($gt_print_settings['enabled']);
                    $gt_print_paper_size = isset($gt_print_settings['paper_size']) ? (string) $gt_print_settings['paper_size'] : 'letter';
                    $gt_print_repeat_header = !isset($gt_print_settings['repeat_header']) || !empty($gt_print_settings['repeat_header']);
                    $gt_print_row_striping = !isset($gt_print_settings['row_striping']) || !empty($gt_print_settings['row_striping']);
                    $gt_print_excluded = isset($gt_print_settings['excluded_columns']) && is_array($gt_print_settings['excluded_columns']) ? $gt_print_settings['excluded_columns'] : array();

                    // Driven by form fields when the table has form_id (same pattern as Top-N).
                    $gt_print_form_fields = array();
                    if (!empty($table_data) && !empty($table_data->form_id) && class_exists('GFAPI')) {
                        $gt_print_form = GFAPI::get_form((int) $table_data->form_id);
                        if ($gt_print_form && !is_wp_error($gt_print_form) && !empty($gt_print_form['fields'])) {
                            foreach ($gt_print_form['fields'] as $gt_pf) {
                                $gt_print_form_fields[(string) $gt_pf->id] = (string) $gt_pf->label;
                            }
                        }
                    }
                    ?>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="print_settings[enabled]" value="1"
                               <?php checked($gt_print_enabled); ?>>
                        <?php _e('Enable per-table print overrides', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('When off, only the global print stylesheet applies. When on, the settings below emit an inline &lt;style media="print"&gt; block scoped to this table\'s wrapper.', 'tc-data-tables'); ?>"></span>
                    </label>
                    <div class="gt-form-row">
                        <label for="gt-print-paper-size"><?php _e('Paper size', 'tc-data-tables'); ?></label>
                        <select id="gt-print-paper-size" name="print_settings[paper_size]">
                            <?php foreach (array('letter', 'a4', 'legal', 'a3', 'tabloid') as $gt_ps): ?>
                                <option value="<?php echo esc_attr($gt_ps); ?>" <?php selected($gt_print_paper_size, $gt_ps); ?>><?php echo esc_html(strtoupper($gt_ps)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Emits @page { size: &lt;value&gt; } in the inline print stylesheet.', 'tc-data-tables'); ?>"></span>
                    </div>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="print_settings[repeat_header]" value="1"
                               <?php checked($gt_print_repeat_header); ?>>
                        <?php _e('Repeat header on each printed page', 'tc-data-tables'); ?>
                    </label>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="print_settings[row_striping]" value="1"
                               <?php checked($gt_print_row_striping); ?>>
                        <?php _e('Alternate row backgrounds (zebra striping)', 'tc-data-tables'); ?>
                    </label>
                    <?php if (!empty($gt_print_form_fields)): ?>
                    <div class="gt-form-row">
                        <label><?php _e('Excluded columns', 'tc-data-tables'); ?></label>
                        <select name="print_settings[excluded_columns][]" multiple size="6" style="width: 100%; max-width: 420px;">
                            <?php foreach ($gt_print_form_fields as $gt_fid => $gt_flabel): ?>
                                <option value="<?php echo esc_attr($gt_fid); ?>" <?php selected(in_array((string) $gt_fid, $gt_print_excluded, true), true); ?>><?php echo esc_html($gt_flabel) . ' (#' . esc_html($gt_fid) . ')'; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Selected columns are hidden in the printed output via display:none. Hold Ctrl/Cmd to select multiple.', 'tc-data-tables'); ?>"></span>
                    </div>
                    <?php endif; ?>
                    
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

                    <h5>
                        <?php _e('Export filename pattern (#634)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Override the default export filename for CSV / Excel downloads. Tokens: {table_name}, {table_id}, {YYYY-MM-DD}, {YYYY}, {MM}, {DD}, {HH}, {mm}, {ss}, {HHMMSS}, {timestamp}. Leave empty to use the legacy "gravity_tables_export_<datetime>.csv" pattern.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <div class="gt-form-row">
                        <label for="gt-export-filename-pattern"><?php _e('Filename pattern', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-export-filename-pattern" name="export_filename_pattern"
                               value="<?php echo esc_attr(isset($table_settings['export_filename_pattern']) ? (string) $table_settings['export_filename_pattern'] : ''); ?>"
                               placeholder="{table_name}-{YYYY-MM-DD}.csv"
                               style="width: 100%; max-width: 420px;">
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Data &amp; i18n', 'tc-data-tables'); ?></h4>

                    <h5>
                        <?php _e('Pagination labels (i18n / customization)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Override the five DataTables-style pagination/info labels per table. Empty = use plugin default. Useful for translation, branding, or matching site tone. Tokens: {start}, {end}, {total} (info_text only). Backed by TC_Pagination_Label_Service.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <div class="gt-form-row">
                        <label for="gt-info-text"><?php _e('Info text', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-info-text" name="info_text"
                               value="<?php echo esc_attr(isset($table_settings['info_text']) ? (string) $table_settings['info_text'] : ''); ?>"
                               placeholder="Showing {start} to {end} of {total} entries"
                               style="width: 100%; max-width: 420px;">
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-previous-label"><?php _e('Previous button', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-previous-label" name="previous_label"
                               value="<?php echo esc_attr(isset($table_settings['previous_label']) ? (string) $table_settings['previous_label'] : ''); ?>"
                               placeholder="Previous"
                               style="width: 100%; max-width: 240px;">
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-next-label"><?php _e('Next button', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-next-label" name="next_label"
                               value="<?php echo esc_attr(isset($table_settings['next_label']) ? (string) $table_settings['next_label'] : ''); ?>"
                               placeholder="Next"
                               style="width: 100%; max-width: 240px;">
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-no-results"><?php _e('Empty state', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-no-results" name="no_results"
                               value="<?php echo esc_attr(isset($table_settings['no_results']) ? (string) $table_settings['no_results'] : ''); ?>"
                               placeholder="No matching entries found."
                               style="width: 100%; max-width: 420px;">
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-loading-label"><?php _e('Loading text', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-loading-label" name="loading"
                               value="<?php echo esc_attr(isset($table_settings['loading']) ? (string) $table_settings['loading'] : ''); ?>"
                               placeholder="Loading…"
                               style="width: 100%; max-width: 240px;">
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

                    <h5>
                        <?php _e('Top-N rows (#347)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Show only the top-N rows when sorted by a specific field. Leave count at 0 to disable. The runtime layer (class-tc-ajax.php server_side_entries) sorts and slices the result set; this UI is the configuration surface that was missing until v4.8.8.', 'tc-data-tables'); ?>"></span>
                    </h5>
                    <div class="gt-form-row">
                        <label><?php _e('Top-N count', 'tc-data-tables'); ?></label>
                        <input type="number" name="top_n_count" min="0" max="100000" step="1"
                               value="<?php echo esc_attr(isset($table_settings['top_n_count']) ? (int) $table_settings['top_n_count'] : 0); ?>"
                               style="width: 100px;">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('0 = disabled. Positive = keep that many rows after sorting.', 'tc-data-tables'); ?>"></span>
                    </div>
                    <div class="gt-form-row">
                        <label><?php _e('Top-N column', 'tc-data-tables'); ?></label>
                        <?php
                        $top_n_form_fields = array();
                        if (!empty($table_data) && !empty($table_data->form_id) && class_exists('GFAPI')) {
                            $top_n_form = GFAPI::get_form((int) $table_data->form_id);
                            if ($top_n_form && !is_wp_error($top_n_form) && !empty($top_n_form['fields'])) {
                                foreach ($top_n_form['fields'] as $top_n_f) {
                                    $top_n_form_fields[(string) $top_n_f->id] = (string) $top_n_f->label;
                                }
                            }
                        }
                        $top_n_current = isset($table_settings['top_n_column']) ? (string) $table_settings['top_n_column'] : '';
                        ?>
                        <select name="top_n_column" style="min-width: 280px;">
                            <option value=""<?php selected($top_n_current, ''); ?>>
                                <?php esc_html_e('— Top-N disabled —', 'tc-data-tables'); ?>
                            </option>
                            <option value="date_created"<?php selected($top_n_current, 'date_created'); ?>>
                                <?php esc_html_e('Entry creation date (date_created)', 'tc-data-tables'); ?>
                            </option>
                            <?php foreach ($top_n_form_fields as $tn_id => $tn_label): ?>
                                <option value="<?php echo esc_attr($tn_id); ?>"<?php selected($top_n_current, (string) $tn_id); ?>>
                                    <?php echo esc_html(sprintf('%s (#%s)', $tn_label, $tn_id)); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($top_n_current !== '' && $top_n_current !== 'date_created' && !isset($top_n_form_fields[$top_n_current])) : ?>
                                <option value="<?php echo esc_attr($top_n_current); ?>" selected>
                                    <?php echo esc_html(sprintf(__('%s (saved value — field not in current form)', 'tc-data-tables'), $top_n_current)); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Field to sort by before slicing to the top-N rows. Pick "Top-N disabled" or set count to 0 to turn the feature off entirely.', 'tc-data-tables'); ?>"></span>
                    </div>
                    <div class="gt-form-row">
                        <label><?php _e('Top-N direction', 'tc-data-tables'); ?></label>
                        <select name="top_n_direction">
                            <?php $td = isset($table_settings['top_n_direction']) ? $table_settings['top_n_direction'] : 'desc'; ?>
                            <option value="desc" <?php selected($td, 'desc'); ?>><?php _e('Descending (highest values first)', 'tc-data-tables'); ?></option>
                            <option value="asc" <?php selected($td, 'asc'); ?>><?php _e('Ascending (lowest values first)', 'tc-data-tables'); ?></option>
                        </select>
                    </div>
                </div>



                
                
                <!-- Group 1: Selection & Editing -->
                <div class="gt-feature-group">
                    <h4><?php _e('Selection &amp; Editing', 'tc-data-tables'); ?></h4>
                    
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_selection"
                               <?php checked(isset($table_settings['show_selection']) ? $table_settings['show_selection'] : true); ?>>
                        <?php _e('Show selection checkboxes', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Render a checkbox column so visitors can pick rows. Required for bulk actions on the frontend.', 'tc-data-tables'); ?>"></span>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_bulk_actions"
                               <?php checked(isset($table_settings['show_bulk_actions']) ? $table_settings['show_bulk_actions'] : true); ?>>
                        <?php _e('Show actions column', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Shows individual edit/delete actions for each row', 'tc-data-tables'); ?>"></span>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_advanced_filters"
                               <?php checked(isset($table_settings['show_advanced_filters']) ? $table_settings['show_advanced_filters'] : true); ?>>
                        <?php _e('Show advanced filters', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Show the advanced filter panel and Filter button in the table toolbar. Disabled in error by a May 9 refactor (#1728) — re-enabled here.', 'tc-data-tables'); ?>"></span>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="enable_frontend_editing"
                               <?php checked(isset($table_settings['enable_frontend_editing']) ? $table_settings['enable_frontend_editing'] : false); ?>>
                        <?php _e('Enable frontend editing', 'tc-data-tables'); ?>
                    </label>

                    <div class="gt-form-row">
                        <label for="gt-owner-field-id"><?php _e('Owner field (restrict editing to entry owner)', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-owner-field-id" name="owner_field_id" value="<?php echo esc_attr(isset($table_settings['owner_field_id']) ? (string) $table_settings['owner_field_id'] : ''); ?>" style="width:100%; max-width:200px;">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('GF field id holding the owning user id (e.g. a Driver/User field). When set, non-admin frontend editors can edit only entries whose owner field matches their user id. Leave empty to let any permitted role edit all rows.', 'tc-data-tables'); ?>"></span>
                    </div>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="enable_delete"
                               <?php checked(isset($table_settings['enable_delete']) ? $table_settings['enable_delete'] : false); ?>>
                        <?php _e('Enable delete functionality', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Allow users to permanently delete entries from the table', 'tc-data-tables'); ?>"></span>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="enable_duplicate"
                               <?php checked( ! empty( $table_settings['enable_duplicate'] ) ); ?>>
                        <?php _e('Enable one-click entry duplicate (Pro)', 'tc-data-tables'); ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Adds a ⧉ duplicate icon to each row\'s actions column. Clicking it creates a copy of that entry with all field values preserved. Pro only.', 'tc-data-tables'); ?>"></span>
                    </label>

                    <!-- #1748 Email Alerts (Pro) -->
                    <div class="gt-feature-group" style="margin-top:18px; border-top:1px solid #eee; padding-top:14px;">
                        <h4><?php _e('Email Alerts (Pro)', 'tc-data-tables'); ?></h4>
                        <p class="description" style="margin-bottom:10px;"><?php _e('Send an email when a field value crosses a threshold after an inline edit, bulk fill, or entry duplicate.', 'tc-data-tables'); ?></p>
                        <div id="gt-alert-rules-container">
                            <?php
                            $alert_rules = isset( $table_settings['email_alert_rules'] ) && is_array( $table_settings['email_alert_rules'] )
                                ? $table_settings['email_alert_rules'] : [];
                            $all_roles = wp_roles()->get_names();
                            foreach ( $alert_rules as $i => $rule ) : ?>
                            <div class="gt-alert-rule" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">
                                <label><?php _e('Field ID', 'tc-data-tables'); ?>
                                    <input type="text" name="alert_field_id" value="<?php echo esc_attr( $rule['field_id'] ); ?>" style="width:80px;" placeholder="e.g. 3">
                                </label>
                                <label><?php _e('Operator', 'tc-data-tables'); ?>
                                    <select name="alert_operator">
                                        <?php foreach ( [ '>', '<', '=', '>=', '<=', 'contains' ] as $op ) : ?>
                                        <option value="<?php echo esc_attr( $op ); ?>" <?php selected( $rule['operator'], $op ); ?>><?php echo esc_html( $op ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><?php _e('Threshold', 'tc-data-tables'); ?>
                                    <input type="text" name="alert_threshold" value="<?php echo esc_attr( $rule['threshold'] ); ?>" style="width:100px;" placeholder="e.g. 1000">
                                </label>
                                <label><?php _e('Recipient email', 'tc-data-tables'); ?>
                                    <input type="email" name="alert_recipient" value="<?php echo esc_attr( $rule['recipient'] ); ?>" style="width:200px;" placeholder="you@example.com">
                                </label>
                                <button type="button" class="button gt-remove-alert-rule" style="margin-top:0;"><?php _e('Remove', 'tc-data-tables'); ?></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="gt-add-alert-rule" class="button"><?php _e('+ Add alert rule', 'tc-data-tables'); ?></button>
                    </div>

                    <div class="gt-form-row">
                        <label for="gt-airtable-record-id-field"><?php _e('Airtable record-id field', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-airtable-record-id-field" name="airtable_record_id_field" value="<?php echo esc_attr(isset($table_settings['airtable_record_id_field']) ? (string) $table_settings['airtable_record_id_field'] : ''); ?>" style="width:100%; max-width:200px;">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('GF field id that holds the Airtable record id.', 'tc-data-tables'); ?>"></span>
                    </div>

                    <?php /* #1013 v4.183.0 — Legacy Airtable-specific sync_direction picker removed.
                          * The unified picker at the top of the page (rendered for json + airtable + notion)
                          * now handles all three external sources. Saved tables retain whichever value
                          * the legacy picker last wrote; the push consumer in class-tc-ajax.php (#1011)
                          * accepts both legacy and canonical naming so behavior is preserved. */ ?>
                </div>

                <!-- Group 2: Layout & Sticky -->
                <div class="gt-feature-group">
                    <h4><?php _e('Layout &amp; Sticky', 'tc-data-tables'); ?></h4>
                    
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="sticky_header"
                               <?php checked(isset($table_settings['sticky_header']) ? $table_settings['sticky_header'] : false); ?>>
                        <?php _e('Sticky header', 'tc-data-tables'); ?>
                    </label>

                    <div class="gt-form-row" style="margin-left: 24px;">
                        <label for="gt-frozen-top-rows" style="font-weight: normal;">
                            <?php _e('Header rows to freeze (1–10)', 'tc-data-tables'); ?>
                        </label>
                        <input type="number" id="gt-frozen-top-rows" name="frozen_top_rows" min="1" max="10" step="1" style="width: 80px;"
                               value="<?php
                               $gt_frozen_rows = isset($table_settings['frozen_top_rows']) ? (int) $table_settings['frozen_top_rows'] : 1;
                               echo esc_attr($gt_frozen_rows);
                               ?>">
                    </div>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="freeze_first_column"
                               <?php checked(isset($table_settings['freeze_first_column']) ? $table_settings['freeze_first_column'] : false); ?>>
                        <?php _e('Freeze first column', 'tc-data-tables'); ?>
                    </label>

                    <?php /* #1601 — AI table summary line (rule-based; no API key required). */ ?>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_table_summary"
                               <?php checked(isset($table_settings['show_table_summary']) ? $table_settings['show_table_summary'] : false); ?>>
                        <?php _e('Show summary line above the table (row count, date span, numeric min/max/avg)', 'tc-data-tables'); ?>
                    </label>

                    <div class="gt-form-row">
                        <label><?php _e('Responsive Mode', 'tc-data-tables'); ?></label>
                        <select name="responsive_mode">
                            <option value="disabled" <?php selected(isset($table_settings['responsive_mode']) ? $table_settings['responsive_mode'] : 'basic', 'disabled'); ?>>
                                <?php _e('Disabled (horizontal scroll)', 'tc-data-tables'); ?>
                            </option>
                            <option value="basic" <?php selected(isset($table_settings['responsive_mode']) ? $table_settings['responsive_mode'] : 'basic', 'basic'); ?>>
                                <?php _e('Basic (text wrap)', 'tc-data-tables'); ?>
                            </option>
                            <option value="enhanced" <?php selected(isset($table_settings['responsive_mode']) ? $table_settings['responsive_mode'] : 'basic', 'enhanced'); ?>>
                                <?php _e('Enhanced (mobile cards)', 'tc-data-tables'); ?>
                            </option>
                            <option value="collapse" <?php selected(isset($table_settings['responsive_mode']) ? $table_settings['responsive_mode'] : 'basic', 'collapse'); ?>>
                                <?php _e('Collapse (expand rows)', 'tc-data-tables'); ?>
                            </option>
                            <option value="modal" <?php selected(isset($table_settings['responsive_mode']) ? $table_settings['responsive_mode'] : 'basic', 'modal'); ?>>
                                <?php _e('Modal (detail overlay)', 'tc-data-tables'); ?>
                            </option>
                        </select>
                    </div>

                    <div class="gt-form-row">
                        <label><?php _e('Processing Mode', 'tc-data-tables'); ?></label>
                        <select name="processing_mode">
                            <option value="client" <?php selected(isset($table_settings['processing_mode']) ? $table_settings['processing_mode'] : 'client', 'client'); ?>>
                                <?php _e('Client-side (all rows loaded)', 'tc-data-tables'); ?>
                            </option>
                            <option value="server" <?php selected(isset($table_settings['processing_mode']) ? $table_settings['processing_mode'] : 'client', 'server'); ?>>
                                <?php _e('Server-side (for large data)', 'tc-data-tables'); ?>
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Group 3: Toolbar & Export -->
                <div class="gt-feature-group">
                    <h4><?php _e('Toolbar &amp; Export', 'tc-data-tables'); ?></h4>
                    
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_toolbar_copy"
                               <?php checked(isset($table_settings['show_toolbar_copy']) ? $table_settings['show_toolbar_copy'] : false); ?>>
                        <?php _e('Copy button', 'tc-data-tables'); ?>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_toolbar_csv"
                               <?php checked(isset($table_settings['show_toolbar_csv']) ? $table_settings['show_toolbar_csv'] : false); ?>>
                        <?php _e('CSV download button', 'tc-data-tables'); ?>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_toolbar_excel"
                               <?php checked(isset($table_settings['show_toolbar_excel']) ? $table_settings['show_toolbar_excel'] : false); ?>>
                        <?php _e('Excel download button', 'tc-data-tables'); ?>
                    </label>

                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="show_pdf_export"
                               <?php checked(isset($table_settings['show_pdf_export']) ? $table_settings['show_pdf_export'] : false); ?>>
                        <?php _e('Save as PDF button', 'tc-data-tables'); ?>
                    </label>

                    <hr style="margin: 15px 0; border-top: 1px solid #eee;">

                    <h5><?php _e('Toolbar Visibility', 'tc-data-tables'); ?></h5>
                    <?php
                    $gt_tv_settings = (class_exists('TC_Toolbar_Visibility_Service') && isset($table_settings['toolbar_visibility']) && is_array($table_settings['toolbar_visibility']))
                        ? TC_Toolbar_Visibility_Service::normalize($table_settings['toolbar_visibility'])
                        : (class_exists('TC_Toolbar_Visibility_Service') ? TC_Toolbar_Visibility_Service::defaults() : array());
                    $gt_tv_components = array(
                        'global_search'   => __('Global search input', 'tc-data-tables'),
                        'pagination'      => __('Pagination controls', 'tc-data-tables'),
                        'length_selector' => __('Length selector', 'tc-data-tables'),
                        'info_label'      => __('Info label', 'tc-data-tables'),
                        'column_filters'  => __('Per-column filters', 'tc-data-tables'),
                        'export_buttons'  => __('Export buttons', 'tc-data-tables'),
                    );
                    foreach ($gt_tv_components as $gt_tv_key => $gt_tv_label):
                        $gt_tv_checked = !empty($gt_tv_settings[$gt_tv_key]);
                    ?>
                        <label class="gt-checkbox-label">
                            <input type="checkbox" name="toolbar_visibility[<?php echo esc_attr($gt_tv_key); ?>]" value="1" <?php checked($gt_tv_checked); ?>>
                            <?php echo esc_html($gt_tv_label); ?>
                        </label>
                    <?php endforeach; ?>

                    <label class="gt-checkbox-label" style="margin-top:10px;">
                        <input type="checkbox" name="persistent_filters"
                               <?php checked(isset($table_settings['persistent_filters']) ? $table_settings['persistent_filters'] : true); ?>>
                        <?php _e('Keep filters open by default', 'tc-data-tables'); ?>
                    </label>
                </div>
<div class="gt-feature-group">
                    <h4><?php _e('Bulk Actions', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Choose which bulk actions appear in this table\'s toolbar. Requires the global Settings → Bulk Actions toggle (Pro plan) and the per-table &quot;Show bulk actions&quot; option. Tables saved before per-action opt-in default to all three checked.', 'tc-data-tables'); ?>"></span>
                    <?php
                    // #635 Option A wire-up. Default to all three checked when
                    // bulk_actions is unset (legacy tables saved before this UI
                    // existed). When set, check based on array membership.
                    $gt_bulk_actions_setting = (isset($table_settings['bulk_actions']) && is_array($table_settings['bulk_actions']))
                        ? $table_settings['bulk_actions']
                        : array('delete', 'edit', 'export');
                    ?>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="bulk_delete"
                               <?php checked(in_array('delete', $gt_bulk_actions_setting, true)); ?>>
                        <?php _e('Allow bulk delete', 'tc-data-tables'); ?>
                    </label>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="bulk_export"
                               <?php checked(in_array('export', $gt_bulk_actions_setting, true)); ?>>
                        <?php _e('Allow bulk export', 'tc-data-tables'); ?>
                    </label>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="bulk_edit"
                               <?php checked(in_array('edit', $gt_bulk_actions_setting, true)); ?>>
                        <?php _e('Allow bulk edit', 'tc-data-tables'); ?>
                    </label>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Row Expiry (#501)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Auto-hide / strike / sink rows whose date in the chosen column is in the past. Admin preview always shows every row regardless. The runtime gate runs after each render in assets/js/frontend.js (applyRowExpiry).', 'tc-data-tables'); ?>"></span>
                    <?php
                    $gt_re_field_id = isset($table_settings['expiry_field_id']) ? (string) $table_settings['expiry_field_id'] : '';
                    $gt_re_behavior = isset($table_settings['expiry_behavior']) ? (string) $table_settings['expiry_behavior'] : 'hide';
                    $gt_re_grace    = isset($table_settings['expiry_grace_days']) ? (int) $table_settings['expiry_grace_days'] : 0;
                    $gt_re_inverse  = !empty($table_settings['expiry_inverse']);
                    // Source the form's date-shaped fields for the dropdown.
                    // Falls back to all fields if GFAPI is unavailable so the
                    // builder still renders in non-GF contexts.
                    $gt_re_date_fields = array();
                    if (!empty($table_data->form_id) && class_exists('GFAPI')) {
                        $gt_re_gf_form = GFAPI::get_form((int) $table_data->form_id);
                        if ($gt_re_gf_form && !is_wp_error($gt_re_gf_form) && !empty($gt_re_gf_form['fields'])) {
                            foreach ($gt_re_gf_form['fields'] as $gt_re_f) {
                                $gt_re_type = strtolower((string) $gt_re_f->type);
                                if (in_array($gt_re_type, array('date', 'datetime', 'time'), true)) {
                                    $gt_re_date_fields[(string) $gt_re_f->id] = (string) $gt_re_f->label;
                                }
                            }
                        }
                    }
                    ?>
                    <div class="gt-form-row">
                        <label for="gt-expiry-field-id"><?php _e('Expiry column', 'tc-data-tables'); ?></label>
                        <select id="gt-expiry-field-id" name="expiry_field_id">
                            <option value=""><?php _e('— Disabled —', 'tc-data-tables'); ?></option>
                            <?php foreach ($gt_re_date_fields as $gt_re_id => $gt_re_label): ?>
                                <option value="<?php echo esc_attr($gt_re_id); ?>" <?php selected($gt_re_field_id, $gt_re_id); ?>>
                                    <?php echo esc_html($gt_re_label . ' (#' . $gt_re_id . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-expiry-behavior"><?php _e('Behavior when expired', 'tc-data-tables'); ?></label>
                        <select id="gt-expiry-behavior" name="expiry_behavior">
                            <option value="hide"          <?php selected($gt_re_behavior, 'hide'); ?>><?php _e('Hide row', 'tc-data-tables'); ?></option>
                            <option value="strikethrough" <?php selected($gt_re_behavior, 'strikethrough'); ?>><?php _e('Strikethrough (line-through, stays visible)', 'tc-data-tables'); ?></option>
                            <option value="move_bottom"   <?php selected(in_array($gt_re_behavior, array('move_bottom', 'bottom'), true), true); ?>><?php _e('Move to bottom', 'tc-data-tables'); ?></option>
                        </select>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-expiry-grace-days"><?php _e('Grace period (days)', 'tc-data-tables'); ?></label>
                        <input type="number" id="gt-expiry-grace-days" name="expiry_grace_days" min="0" step="1" style="width: 80px;"
                               value="<?php echo esc_attr($gt_re_grace); ?>">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Wait this many days past the expiry date before applying the behavior. 0 = apply immediately.', 'tc-data-tables'); ?>"></span>
                    </div>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="expiry_inverse" value="1" <?php checked($gt_re_inverse); ?>>
                        <?php _e('Inverse mode — show only upcoming rows (hide everything in the past)', 'tc-data-tables'); ?>
                    </label>
                </div>

                <div class="gt-feature-group" id="gt-computed-columns">
                    <h4><?php _e('Computed columns (#1598)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Add read-only columns computed from {field:N} expressions, e.g. {field:3} * {field:5} for qty x rate. Supports + - * / %, ROUND, ABS, IF, CONCAT. Values compute per row when the table loads and flow into CSV exports.', 'tc-data-tables'); ?>"></span>
                    <div class="gt-cc-rows">
                        <?php
                        $gt_cc_defs = isset($table_settings['computed_columns']) && is_array($table_settings['computed_columns'])
                            ? $table_settings['computed_columns']
                            : array();
                        foreach ($gt_cc_defs as $gt_cc_def):
                            if (!is_array($gt_cc_def)) { continue; }
                        ?>
                        <div class="gt-cc-row" style="margin-bottom:6px;">
                            <input type="text" class="gt-cc-label" placeholder="<?php esc_attr_e('Column label', 'tc-data-tables'); ?>"
                                   value="<?php echo esc_attr((string) ($gt_cc_def['label'] ?? '')); ?>">
                            <input type="text" class="gt-cc-formula code" style="width:260px;" placeholder="{field:3} * {field:5}"
                                   value="<?php echo esc_attr((string) ($gt_cc_def['formula'] ?? '')); ?>">
                            <?php $gt_cc_fmt = (string) ($gt_cc_def['format'] ?? ''); /* #1621 */ ?>
                            <select class="gt-cc-format">
                                <option value=""    <?php selected($gt_cc_fmt, ''); ?>><?php _e('Raw', 'tc-data-tables'); ?></option>
                                <option value="int" <?php selected($gt_cc_fmt, 'int'); ?>>1,234</option>
                                <option value="2dp" <?php selected($gt_cc_fmt, '2dp'); ?>>1,234.00</option>
                            </select>
                            <button type="button" class="button-link-delete gt-cc-remove" aria-label="<?php esc_attr_e('Remove computed column', 'tc-data-tables'); ?>">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button gt-cc-add"><?php _e('Add computed column', 'tc-data-tables'); ?></button>
                </div>

                <div class="gt-feature-group" id="gt-data-quality" data-form-id="<?php echo esc_attr(!empty($table_data->form_id) ? (int) $table_data->form_id : 0); ?>">
                    <h4><?php _e('Data Quality (#1601)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Scan a column for cleanup suggestions (stray whitespace, doubled spaces). Rule-based — no AI key required. Accepted fixes write back through the normal entry-update path.', 'tc-data-tables'); ?>"></span>
                    <?php
                    // All scannable (scalar text) fields for the dropdown.
                    $gt_dq_fields = array();
                    if (!empty($table_data->form_id) && class_exists('GFAPI')) {
                        $gt_dq_form = GFAPI::get_form((int) $table_data->form_id);
                        if ($gt_dq_form && !is_wp_error($gt_dq_form) && !empty($gt_dq_form['fields'])) {
                            foreach ($gt_dq_form['fields'] as $gt_dq_f) {
                                $gt_dq_type = strtolower((string) $gt_dq_f->type);
                                if (in_array($gt_dq_type, array('text', 'textarea', 'email', 'phone', 'website', 'number', 'select', 'hidden', 'post_title'), true)) {
                                    $gt_dq_fields[(string) $gt_dq_f->id] = (string) $gt_dq_f->label;
                                }
                            }
                        }
                    }
                    ?>
                    <div class="gt-form-row">
                        <label for="gt-dq-column"><?php _e('Column to scan', 'tc-data-tables'); ?></label>
                        <select id="gt-dq-column">
                            <option value=""><?php _e('— Select a column —', 'tc-data-tables'); ?></option>
                            <?php foreach ($gt_dq_fields as $gt_dq_id => $gt_dq_label): ?>
                                <option value="<?php echo esc_attr($gt_dq_id); ?>">
                                    <?php echo esc_html($gt_dq_label . ' (#' . $gt_dq_id . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button gt-dq-scan"><?php _e('Scan for cleanup suggestions', 'tc-data-tables'); ?></button>
                    </div>
                    <div class="gt-dq-results"></div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Scheduled export (#519)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Auto-write the table to CSV / XLSX on a WP-Cron schedule. Files land under wp-content/uploads/gravity-tables-exports/. When email recipients are set, the file is also sent as an attachment via wp_mail.', 'tc-data-tables'); ?>"></span>
                    <?php
                    $gt_se_enabled    = !empty($table_settings['scheduled_export_enabled']);
                    $gt_se_recurrence = isset($table_settings['scheduled_export_recurrence']) ? (string) $table_settings['scheduled_export_recurrence'] : 'daily';
                    $gt_se_format     = isset($table_settings['scheduled_export_format']) ? (string) $table_settings['scheduled_export_format'] : 'csv';
                    $gt_se_pattern    = isset($table_settings['scheduled_export_filename_pattern']) ? (string) $table_settings['scheduled_export_filename_pattern'] : '';
                    $gt_se_emails     = isset($table_settings['scheduled_export_email_recipients']) ? (string) $table_settings['scheduled_export_email_recipients'] : '';
                    $gt_se_honor      = !empty($table_settings['scheduled_export_honor_filters']);
                    ?>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="scheduled_export_enabled" value="1" <?php checked($gt_se_enabled); ?>>
                        <?php _e('Enable scheduled export', 'tc-data-tables'); ?>
                    </label>
                    <div class="gt-form-row">
                        <label for="gt-se-recurrence"><?php _e('Recurrence', 'tc-data-tables'); ?></label>
                        <select id="gt-se-recurrence" name="scheduled_export_recurrence">
                            <option value="hourly"      <?php selected($gt_se_recurrence, 'hourly'); ?>><?php _e('Hourly', 'tc-data-tables'); ?></option>
                            <option value="gt_every_6h" <?php selected($gt_se_recurrence, 'gt_every_6h'); ?>><?php _e('Every 6 hours', 'tc-data-tables'); ?></option>
                            <option value="daily"       <?php selected($gt_se_recurrence, 'daily'); ?>><?php _e('Daily', 'tc-data-tables'); ?></option>
                            <option value="weekly"      <?php selected($gt_se_recurrence, 'weekly'); ?>><?php _e('Weekly', 'tc-data-tables'); ?></option>
                        </select>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-se-format"><?php _e('Format', 'tc-data-tables'); ?></label>
                        <select id="gt-se-format" name="scheduled_export_format">
                            <option value="csv"  <?php selected($gt_se_format, 'csv'); ?>>CSV</option>
                            <option value="xlsx" <?php selected($gt_se_format, 'xlsx'); ?>>XLSX</option>
                        </select>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-se-pattern"><?php _e('Filename pattern', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-se-pattern" name="scheduled_export_filename_pattern" style="width: 100%; max-width: 400px;"
                               placeholder="{table_name}-{YYYY-MM-DD}.csv"
                               value="<?php echo esc_attr($gt_se_pattern); ?>">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Tokens: {table_name} {table_id} {YYYY} {MM} {DD} {YYYY-MM-DD} {HH} {mm} {ss} {HHMMSS} {timestamp}. Empty = default {table_name}-{YYYY-MM-DD}.<ext>.', 'tc-data-tables'); ?>"></span>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-se-emails"><?php _e('Email recipients', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-se-emails" name="scheduled_export_email_recipients" style="width: 100%; max-width: 400px;"
                               placeholder="alice@example.com, bob@example.com"
                               value="<?php echo esc_attr($gt_se_emails); ?>">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Comma- or whitespace-separated. Leave empty to skip email and only write to uploads.', 'tc-data-tables'); ?>"></span>
                    </div>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="scheduled_export_honor_filters" value="1" <?php checked($gt_se_honor); ?>>
                        <?php _e('Honor current filters (advanced — wire via gt_scheduled_export_search_criteria filter)', 'tc-data-tables'); ?>
                    </label>
                    <div class="gt-form-row">
                        <button type="button" class="button" id="gt-run-export-now" data-gt-action="run-export-now"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('gt_run_scheduled_export')); ?>">
                            <?php _e('Run export now', 'tc-data-tables'); ?>
                        </button>
                        <span id="gt-run-export-now-status" style="margin-left: 8px; color: #50575e;"></span>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Pivot view (#562)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Switch the table to a pivot view: group rows by one column, aggregate one or more other columns (sum / count / avg / min / max). One summary column renders per aggregate, and visitors get a toolbar toggle between the pivot summary and the raw rows.', 'tc-data-tables'); ?>"></span>
                    <?php
                    $gt_pivot_cfg = class_exists('TC_Pivot_Service')
                        ? TC_Pivot_Service::normalize(is_array($table_settings['pivot_config'] ?? null) ? $table_settings['pivot_config'] : array())
                        : ['mode' => 'raw', 'group_by' => null, 'aggregates' => []];
                    $gt_pivot_mode = $gt_pivot_cfg['mode'] ?? 'raw';
                    $gt_pivot_group = $gt_pivot_cfg['group_by'] ?? '';
                    // Source form fields for the column dropdowns.
                    $gt_pivot_fields = array();
                    if (!empty($table_data->form_id) && class_exists('GFAPI')) {
                        $gt_pivot_form = GFAPI::get_form((int) $table_data->form_id);
                        if ($gt_pivot_form && !is_wp_error($gt_pivot_form) && !empty($gt_pivot_form['fields'])) {
                            foreach ($gt_pivot_form['fields'] as $gt_pivot_f) {
                                $gt_pivot_fields[(string) $gt_pivot_f->id] = (string) $gt_pivot_f->label;
                            }
                        }
                    }
                    $gt_pivot_ops = class_exists('TC_Pivot_Service') ? TC_Pivot_Service::operators() : array('sum', 'count', 'avg', 'min', 'max');
                    ?>
                    <div class="gt-form-row">
                        <label for="gt-pivot-mode"><?php _e('Mode', 'tc-data-tables'); ?></label>
                        <select id="gt-pivot-mode" name="pivot_mode">
                            <option value="raw"   <?php selected($gt_pivot_mode, 'raw'); ?>><?php _e('Raw (default)', 'tc-data-tables'); ?></option>
                            <option value="pivot" <?php selected($gt_pivot_mode, 'pivot'); ?>><?php _e('Pivot', 'tc-data-tables'); ?></option>
                        </select>
                    </div>
                    <div class="gt-form-row">
                        <label for="gt-pivot-group-by"><?php _e('Group by column', 'tc-data-tables'); ?></label>
                        <select id="gt-pivot-group-by" name="pivot_group_by">
                            <option value=""><?php _e('— None —', 'tc-data-tables'); ?></option>
                            <?php foreach ($gt_pivot_fields as $gt_pivot_fid => $gt_pivot_label): ?>
                                <option value="<?php echo esc_attr($gt_pivot_fid); ?>" <?php selected($gt_pivot_group, $gt_pivot_fid); ?>>
                                    <?php echo esc_html($gt_pivot_label . ' (#' . $gt_pivot_fid . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php /* #1617 — multi-aggregate repeater: one row per
                           * aggregate; the engine and the v6.1.6 frontend
                           * pivot view always supported several. */ ?>
                    <div class="gt-pivot-aggregates">
                        <label><?php _e('Aggregates', 'tc-data-tables'); ?></label>
                        <div class="gt-pa-rows">
                            <?php foreach (($gt_pivot_cfg['aggregates'] ?? array()) as $gt_pa_agg): ?>
                            <div class="gt-pa-row" style="margin-bottom:6px;">
                                <select class="gt-pa-col">
                                    <option value=""><?php _e('— None —', 'tc-data-tables'); ?></option>
                                    <?php foreach ($gt_pivot_fields as $gt_pivot_fid => $gt_pivot_label): ?>
                                        <option value="<?php echo esc_attr($gt_pivot_fid); ?>" <?php selected((string) $gt_pa_agg['col'], $gt_pivot_fid); ?>>
                                            <?php echo esc_html($gt_pivot_label . ' (#' . $gt_pivot_fid . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="gt-pa-op">
                                    <?php foreach ($gt_pivot_ops as $gt_pivot_op): ?>
                                        <option value="<?php echo esc_attr($gt_pivot_op); ?>" <?php selected($gt_pa_agg['op'], $gt_pivot_op); ?>>
                                            <?php echo esc_html(strtoupper($gt_pivot_op)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button-link-delete gt-pa-remove" aria-label="<?php esc_attr_e('Remove aggregate', 'tc-data-tables'); ?>">&times;</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button gt-pa-add"><?php _e('Add aggregate', 'tc-data-tables'); ?></button>
                        <template id="gt-pa-row-template">
                            <div class="gt-pa-row" style="margin-bottom:6px;">
                                <select class="gt-pa-col">
                                    <option value=""><?php _e('— None —', 'tc-data-tables'); ?></option>
                                    <?php foreach ($gt_pivot_fields as $gt_pivot_fid => $gt_pivot_label): ?>
                                        <option value="<?php echo esc_attr($gt_pivot_fid); ?>">
                                            <?php echo esc_html($gt_pivot_label . ' (#' . $gt_pivot_fid . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="gt-pa-op">
                                    <?php foreach ($gt_pivot_ops as $gt_pivot_op): ?>
                                        <option value="<?php echo esc_attr($gt_pivot_op); ?>">
                                            <?php echo esc_html(strtoupper($gt_pivot_op)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button-link-delete gt-pa-remove" aria-label="<?php esc_attr_e('Remove aggregate', 'tc-data-tables'); ?>">&times;</button>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Server-side pagination (#560)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Switch the table to server-side pagination via the REST endpoint /wp-json/gt/v1/tables/{id}/rows. Best for tables with 1000+ rows where loading the full dataset on every page view is slow. Slice 3 will bind DataTables to the endpoint automatically; for slice 2 the endpoint is available but the table builder still uses the existing client-side pagination.', 'tc-data-tables'); ?>"></span>
                    <?php
                    $gt_pag_settings = class_exists('TC_Pagination_Service')
                        ? TC_Pagination_Service::normalize_settings(is_array($table_settings) ? $table_settings : array())
                        : array('server_side' => false, 'default_page_size' => 50);
                    ?>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" name="server_side_pagination" value="1" <?php checked(!empty($gt_pag_settings['server_side'])); ?>>
                        <?php _e('Enable server-side pagination (REST endpoint)', 'tc-data-tables'); ?>
                    </label>
                    <div class="gt-form-row">
                        <label for="gt-pag-default-page-size"><?php _e('Default page size (1–500)', 'tc-data-tables'); ?></label>
                        <input type="number" id="gt-pag-default-page-size" name="default_page_size" min="1" max="500" step="1" style="width: 80px;"
                               value="<?php echo esc_attr((int) $gt_pag_settings['default_page_size']); ?>">
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Default image fallback (#526)', 'tc-data-tables'); ?></h4>
                    <?php
                    $gt_mf_active = class_exists('TC_Media_Folder_Adapter') ? TC_Media_Folder_Adapter::config() : null;
                    $gt_mf_tooltip = ($gt_mf_active && !empty($gt_mf_active['supports_folder_ui']))
                        ? sprintf(__('Folder browser via %s detected — pick from organized folders.', 'tc-data-tables'), $gt_mf_active['plugin_label'])
                        : __('Pick an image to display when an image-type column has no value. Install FileBird / FolderPress / WP Media Folder / Real Media Library for a folder browser.', 'tc-data-tables');
                    $gt_mf_fallback = isset($table_settings['default_image_fallback_url']) ? (string) $table_settings['default_image_fallback_url'] : '';
                    ?>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php echo esc_attr($gt_mf_tooltip); ?>"></span>
                    <div class="gt-form-row">
                        <label for="gt-mf-fallback-url"><?php _e('Fallback image URL', 'tc-data-tables'); ?></label>
                        <input type="url" id="gt-mf-fallback-url" name="default_image_fallback_url" style="width: 100%; max-width: 500px;"
                               placeholder="https://example.com/placeholder.png"
                               value="<?php echo esc_attr($gt_mf_fallback); ?>">
                        <button type="button" class="button" id="gt-mf-pick-image-btn"><?php _e('Pick image', 'tc-data-tables'); ?></button>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Paste data (#516)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Paste rows copied from Excel / Sheets / Numbers. Append adds the parsed rows; Replace trashes existing active entries first and then adds the parsed rows. The first row can be treated as headers and skipped.', 'tc-data-tables'); ?>"></span>
                    <div class="gt-form-row">
                        <label for="gt-paste-tsv"><?php _e('Clipboard payload (TSV)', 'tc-data-tables'); ?></label>
                        <textarea id="gt-paste-tsv" name="gt_clipboard_paste_tsv" rows="6" style="width: 100%; max-width: 700px; font-family: Menlo, Consolas, monospace; font-size: 12px;" placeholder="Paste rows here (Cmd/Ctrl+V)"></textarea>
                    </div>
                    <label class="gt-checkbox-label">
                        <input type="checkbox" id="gt-paste-has-headers" checked>
                        <?php _e('First row is headers (skip during apply)', 'tc-data-tables'); ?>
                    </label>
                    <div class="gt-form-row">
                        <label><?php _e('Mode', 'tc-data-tables'); ?></label>
                        <label class="gt-checkbox-label"><input type="radio" name="gt-paste-mode" value="append" checked> <?php _e('Append', 'tc-data-tables'); ?></label>
                        <label class="gt-checkbox-label"><input type="radio" name="gt-paste-mode" value="replace"> <?php _e('Replace (trash existing first)', 'tc-data-tables'); ?></label>
                    </div>
                    <div class="gt-form-row">
                        <button type="button" class="button" id="gt-paste-preview-btn"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('gt_clipboard_paste')); ?>"><?php _e('Preview', 'tc-data-tables'); ?></button>
                        <button type="button" class="button button-primary" id="gt-paste-apply-btn"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('gt_clipboard_paste')); ?>"><?php _e('Apply', 'tc-data-tables'); ?></button>
                        <span id="gt-paste-status" style="margin-left: 8px; color: #50575e;"></span>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('User Permissions', 'tc-data-tables'); ?></h4>

                    <div class="gt-form-row">
                        <label><?php _e('Table Access Control', 'tc-data-tables'); ?></label>
                        <select name="allowed_user_roles[]" multiple size="6" style="width: 100%; max-width: 300px;">
                            <?php
                            $roles = wp_roles()->get_names();
                            $selected_roles = isset($table_settings['allowed_user_roles']) ? $table_settings['allowed_user_roles'] : array();
                            // Handle backward compatibility with single user_role_filter
                            if (empty($selected_roles) && !empty($table_settings['user_role_filter'])) {
                                $selected_roles = array($table_settings['user_role_filter']);
                            }
                            foreach ($roles as $role_key => $role_name):
                                $is_selected = in_array($role_key, (array) $selected_roles);
                            ?>
                                <option value="<?php echo esc_attr($role_key); ?>" <?php selected($is_selected, true); ?>>
                                    <?php echo esc_html($role_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Select which user roles can view this table. Leave empty to allow all users. Hold Ctrl/Cmd to select multiple roles.', 'tc-data-tables'); ?>"></span>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('SEO / Structured Data (#547)', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Emit schema.org JSON-LD alongside the table so search engines can index the data. The runtime hook in templates/table.php has shipped since v4.7.66; until v4.8.18 there was no admin UI and customers had to hand-edit the saved JSON to enable it.', 'tc-data-tables'); ?>"></span>
                    <?php
                    $schema_settings = isset($table_settings['schema']) && is_array($table_settings['schema']) ? $table_settings['schema'] : array();
                    $schema_type_current = isset($schema_settings['schema_type']) ? (string) $schema_settings['schema_type'] : 'off';
                    ?>
                    <div class="gt-form-row">
                        <label for="gt-schema-type"><?php _e('Schema type', 'tc-data-tables'); ?></label>
                        <select id="gt-schema-type" name="schema[schema_type]">
                            <option value="off"     <?php selected($schema_type_current, 'off'); ?>><?php _e('Off (no JSON-LD emitted)', 'tc-data-tables'); ?></option>
                            <option value="Table"   <?php selected($schema_type_current, 'Table'); ?>><?php _e('Table (generic — uses table title for name + about)', 'tc-data-tables'); ?></option>
                            <option value="Dataset" <?php selected($schema_type_current, 'Dataset'); ?>><?php _e('Dataset (best for pricing / spec / data tables — Google Rich Results)', 'tc-data-tables'); ?></option>
                        </select>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('The Dataset type is the most useful for SEO; Google has a dedicated Rich Results path for it. ProductList / EventList types are pending the column-to-property mapping UI (slice 3 of #547).', 'tc-data-tables'); ?>"></span>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Custom Styling', 'tc-data-tables'); ?></h4>
                    <div class="gt-form-row">
                        <label for="gt-custom-css"><?php _e('Custom CSS', 'tc-data-tables'); ?></label>
                        <textarea id="gt-custom-css" name="custom_css" rows="8" style="width: 100%; font-family: Menlo, Consolas, monospace; font-size: 12px;" placeholder="/* Selectors are auto-scoped to this table. Example:&#10;.gt-table thead th { background: #2c3e50; color: #fff; }&#10;.gt-row:hover { background: #fafafa; } */"><?php echo esc_textarea($table_settings['custom_css'] ?? ''); ?></textarea>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('CSS entered here is rendered inline and scoped to this table only. Disallowed: &lt;script&gt;, expression(), javascript: URLs.', 'tc-data-tables'); ?>"></span>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('Notifications', 'tc-data-tables'); ?></h4>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Send a webhook or email when entries are edited from the frontend. The action hook gravity_tables_entry_updated also fires for custom integrations.', 'tc-data-tables'); ?>"></span>

                    <div class="gt-form-row">
                        <label for="gt-webhook-url"><?php _e('Webhook URL', 'tc-data-tables'); ?></label>
                        <input type="url" id="gt-webhook-url" name="webhook_url" style="width: 100%; max-width: 500px;"
                               value="<?php echo esc_attr($table_settings['webhook_url'] ?? ''); ?>"
                               placeholder="https://example.com/webhook">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('JSON payload (event, entry_id, changes, user, timestamp) is POSTed to this URL.', 'tc-data-tables'); ?>"></span>
                    </div>

                    <div class="gt-form-row">
                        <label for="gt-notify-emails"><?php _e('Notification Emails', 'tc-data-tables'); ?></label>
                        <input type="text" id="gt-notify-emails" name="notify_emails" style="width: 100%; max-width: 500px;"
                               value="<?php echo esc_attr($table_settings['notify_emails'] ?? ''); ?>"
                               placeholder="alice@example.com, bob@example.com">
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Comma-separated list of recipients.', 'tc-data-tables'); ?>"></span>
                    </div>

                    <div class="gt-form-row">
                        <label><?php _e('Trigger on', 'tc-data-tables'); ?></label>
                        <?php
                        $events = (array) ($table_settings['notify_events'] ?? array('updated'));
                        ?>
                        <label class="gt-checkbox-label"><input type="checkbox" name="notify_events[]" value="created" <?php checked(in_array('created', $events, true)); ?>> <?php _e('Created', 'tc-data-tables'); ?></label>
                        <label class="gt-checkbox-label"><input type="checkbox" name="notify_events[]" value="updated" <?php checked(in_array('updated', $events, true)); ?>> <?php _e('Updated', 'tc-data-tables'); ?></label>
                        <label class="gt-checkbox-label"><input type="checkbox" name="notify_events[]" value="deleted" <?php checked(in_array('deleted', $events, true)); ?>> <?php _e('Deleted', 'tc-data-tables'); ?></label>
                    </div>
                </div>

                <div class="gt-feature-group">
                    <h4><?php _e('WooCommerce', 'tc-data-tables'); ?></h4>
                    <?php if (!class_exists('WooCommerce')): ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('WooCommerce is not active. Install and activate WooCommerce to enable product creation from table rows.', 'tc-data-tables'); ?>"></span>
                    <?php else: ?>
                        <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Map fields from your form to WooCommerce product attributes. The "Create Product" row action will create a draft product from the entry, using these fields.', 'tc-data-tables'); ?>"></span>
                        <?php
                        $wc_mapping = (array) ($table_settings['wc_mapping'] ?? array());
                        $form_fields_for_map = array();
                        if (!empty($table_data->form_id) && class_exists('GFAPI')) {
                            $gf_form = GFAPI::get_form((int) $table_data->form_id);
                            if ($gf_form && !is_wp_error($gf_form) && !empty($gf_form['fields'])) {
                                foreach ($gf_form['fields'] as $f) {
                                    $form_fields_for_map[(string) $f->id] = (string) $f->label;
                                }
                            }
                        }
                        $wc_attrs = array(
                            'title'       => __('Title (required)', 'tc-data-tables'),
                            'price'       => __('Regular price', 'tc-data-tables'),
                            'sku'         => __('SKU', 'tc-data-tables'),
                            'description' => __('Description', 'tc-data-tables'),
                        );
                        foreach ($wc_attrs as $key => $label):
                            $current = (string) ($wc_mapping[$key] ?? '');
                        ?>
                            <div class="gt-form-row">
                                <label for="gt-wc-map-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                                <select id="gt-wc-map-<?php echo esc_attr($key); ?>" name="wc_mapping[<?php echo esc_attr($key); ?>]" style="min-width: 240px">
                                    <option value=""><?php esc_html_e('— Not mapped —', 'tc-data-tables'); ?></option>
                                    <?php foreach ($form_fields_for_map as $fid => $flabel): ?>
                                        <option value="<?php echo esc_attr($fid); ?>" <?php selected($current, $fid); ?>><?php echo esc_html(sprintf('%s (#%s)', $flabel, $fid)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            </div>

            <!-- Section Save Button -->
            <div class="gt-section-save">
                <button type="button" class="button-primary gt-save-button" data-save-location="table-features">
                    <?php _e('Save', 'tc-data-tables'); ?>
                </button>
            </div>
        </div>

        <!-- Step 4: Preview & Save -->
        <div class="gt-builder-section" id="preview-save">
            <div class="gt-section-header gt-collapsible" data-target="preview-save-content">
                <div class="gt-section-title">
                    <h2><?php _e('4. Preview & Save', 'tc-data-tables'); ?></h2>
                    <span class="gt-toggle-icon" title="Click to toggle section">▼</span>
                </div>
                <p><?php _e('Review your table configuration and save.', 'tc-data-tables'); ?></p>
            </div>
            <div class="gt-section-content" id="preview-save-content">

                <div class="gt-preview-container">
                <div class="gt-shortcode-display">
                    <h4><?php _e('Generated Shortcode', 'tc-data-tables'); ?></h4>
                    <div class="gt-shortcode-box">
                        <?php if ($table_id): ?>
                            <code id="generated-shortcode">[gravity_table id="<?php echo esc_attr($table_id); ?>"]</code>
                            <button type="button" class="button" id="copy-shortcode"><?php _e('Copy', 'tc-data-tables'); ?></button>
                        <?php else: ?>
                            <code id="generated-shortcode" style="color: #999;"><?php _e('Save table to generate shortcode', 'tc-data-tables'); ?></code>
                            <button type="button" class="button" id="copy-shortcode" disabled><?php _e('Copy', 'tc-data-tables'); ?></button>
                        <?php endif; ?>
                    </div>
                    <span class="dashicons dashicons-editor-help gt-tooltip-icon" data-tooltip="<?php esc_attr_e('Use this shortcode to display your table on any page or post.', 'tc-data-tables'); ?>"></span>
                </div>

                <div class="gt-table-preview">
                    <div class="gt-preview-header">
                        Table Preview
                        <span class="gt-preview-badge">PREVIEW</span>
                        <span class="gt-preview-viewport-toggles">
                            <button type="button" class="button gt-viewport-toggle active" data-viewport="desktop" aria-label="<?php esc_attr_e('Desktop preview', 'tc-data-tables'); ?>"><?php _e('Desktop', 'tc-data-tables'); ?></button>
                            <button type="button" class="button gt-viewport-toggle" data-viewport="tablet" aria-label="<?php esc_attr_e('Tablet preview (768px)', 'tc-data-tables'); ?>"><?php _e('Tablet', 'tc-data-tables'); ?></button>
                            <button type="button" class="button gt-viewport-toggle" data-viewport="mobile" aria-label="<?php esc_attr_e('Mobile preview (375px)', 'tc-data-tables'); ?>"><?php _e('Mobile', 'tc-data-tables'); ?></button>
                        </span>
                    </div>
                    <div id="table-preview-area" class="gt-responsive-preview gt-rows-sortable">
                        <!-- Preview will be loaded here -->
                    </div>
                    <!-- Row drag-handle template used by admin.js (#440) -->
                    <template id="gt-drag-handle-tpl">
                        <span class="gt-drag-handle" tabindex="0" aria-label="<?php esc_attr_e('Drag to reorder row', 'tc-data-tables'); ?>" role="button">&#8942;&#8942;</span>
                    </template>
                    <div class="gt-preview-note">
                        <h4>Preview Information</h4>
                        <p>This is a live preview of your table showing real data from your Gravity Form:</p>
                        <ul>
                            <li>- Displays actual entries with live pagination</li>
                            <li>- All interactive features work with real data</li>
                            <li>- Lookup fields show resolved values</li>
                        </ul>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <!-- Save Section -->
        <div class="gt-builder-navigation">
            <div class="gt-final-save-section">
                <h3><?php _e('Final Step: Save Your Table', 'tc-data-tables'); ?></h3>
                <p><?php _e('Review your settings above and save your table to make it available.', 'tc-data-tables'); ?></p>
                <div class="gt-final-save-buttons">
                    <button type="button" class="button-primary gt-save-button" id="save-table" data-save-location="final">
                        <?php echo $table_id ? __('Update Table', 'tc-data-tables') : __('Save Table', 'tc-data-tables'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Floating Sticky Save Button -->
        <div class="gt-floating-save" id="gt-floating-save" style="display: none;">
            <button type="button" class="button-primary gt-save-button" data-save-location="floating">
                <span class="gt-save-text"><?php echo $table_id ? __('Update', 'tc-data-tables') : __('Save', 'tc-data-tables'); ?></span>
            </button>
            <div class="gt-save-status-floating" id="gt-save-status-floating">
                <span class="gt-save-spinner" style="display: none;">
                    <span class="dashicons dashicons-update"></span>
                </span>
            </div>
        </div>

        <!-- Hidden form for data submission -->
        <form id="table-data-form" style="display: none;">
            <input type="hidden" name="table_id" value="<?php echo $table_id; ?>">
            <input type="hidden" name="action" value="gt_save_table">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('gt_admin_nonce'); ?>">
        </form>
    </div>
</div>

<!-- Conditional Formatting Rule Template -->
<script type="text/x-template" id="gt-conditional-formatting-template">
    <div class="gt-conditional-formatting-rule">
        <div class="gt-rule-row">
            <div class="gt-rule-column">
                <label><?php _e('If cell value', 'tc-data-tables'); ?></label>
                <select class="gt-formatting-rule-if-clause">
                    <option value="lt">&lt;</option>
                    <option value="lteq">≤</option>
                    <option value="eq" selected="selected">=</option>
                    <option value="gteq">≥</option>
                    <option value="gt">&gt;</option>
                    <option value="neq">≠</option>
                    <option value="contains"><?php _e('contains', 'tc-data-tables'); ?></option>
                    <option value="contains_not"><?php _e('does not contain', 'tc-data-tables'); ?></option>
                    <option value="empty"><?php _e('is empty', 'tc-data-tables'); ?></option>
                    <option value="not_empty"><?php _e('is not empty', 'tc-data-tables'); ?></option>
                </select>
            </div>
            
            <div class="gt-rule-column">
                <label><?php _e('Value', 'tc-data-tables'); ?></label>
                <input type="text" class="gt-formatting-rule-cell-value" placeholder="<?php _e('Enter value', 'tc-data-tables'); ?>">
            </div>
            
            <div class="gt-rule-column">
                <label><?php _e('Action', 'tc-data-tables'); ?></label>
                <select class="gt-formatting-rule-action">
                    <option value=""><?php _e('Select action...', 'tc-data-tables'); ?></option>
                    <option value="setCellColor"><?php _e('Set cell color', 'tc-data-tables'); ?></option>
                    <option value="setCellContent"><?php _e('Set cell content', 'tc-data-tables'); ?></option>
                    <option value="setCellClass"><?php _e('Set cell CSS class', 'tc-data-tables'); ?></option>
                    <option value="setRowColor"><?php _e('Set row color', 'tc-data-tables'); ?></option>
                    <option value="setRowClass"><?php _e('Set row CSS class', 'tc-data-tables'); ?></option>
                </select>
            </div>
            
            <div class="gt-rule-column">
                <label><?php _e('Set to', 'tc-data-tables'); ?></label>
                <input type="text" class="gt-formatting-rule-set-value" placeholder="<?php _e('Choose action first', 'tc-data-tables'); ?>">
            </div>
            
            <div class="gt-rule-column gt-rule-actions">
                <button type="button" class="button gt-delete-conditional-rule" title="<?php _e('Remove rule', 'tc-data-tables'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        </div>
    </div>
</script>

<!-- Debug: Collapsible sections implementation v2.0.5 -->
<script>
// Initialize table builder JavaScript
document.addEventListener('DOMContentLoaded', function() {
    var checkJQuery = setInterval(function() {
        if (typeof jQuery !== 'undefined') {
            clearInterval(checkJQuery);
            
            // Execute logic with jQuery
            (function($) {
                // window.gtTableData for edits is set via wp_add_inline_script() on gravity-tables-admin (see TC_Admin::enqueue_admin_scripts).

                // Initialize the table builder
                if (typeof TC_TableBuilder !== 'undefined') {
                    TC_TableBuilder.init();
                    
                    // Additional debugging for collapsible sections
                    setTimeout(function() {
                        $('.gt-collapsible').each(function(i, elem) {
                            // console.log('GT Admin: Section', i, 'target:', $(elem).data('target'));
                        });
                    }, 1000);
                }
            })(jQuery);
        }
    }, 100);
});
</script>