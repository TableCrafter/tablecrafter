<?php
/**
 * CSV Import for Gravity Tables (#37 MVP).
 *
 * Adds an "Import Entries" admin submenu under Advanced Tables. Accepts a
 * CSV upload, auto-maps header row to Gravity Form field labels (case-
 * insensitive), and creates entries via GFAPI::add_entry. Results (rows
 * imported / failed / errors) are surfaced via a transient that the
 * render method displays after redirect.
 *
 * Extended in #2322 to support multi-format file upload (XLSX/XLS/ODS/HTML/JSON
 * alongside CSV), ZIP recursion, add/replace/append import modes, and
 * share-URL auto-fixups via TC_Import_Format_Detector + TC_Multi_Format_Parser.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Import
{
    private static ?TC_Import $instance = null;
    private const ACTION = 'gt_import_csv';
    private const NONCE_KEY = 'gt_import_csv_nonce';
    private const RESULT_TRANSIENT_PREFIX = 'gt_import_result_';

    /**
     * Import modes (#2322).
     *
     * - MODE_ADD_NEW: each imported row creates a new GF entry (default; prior behaviour).
     * - MODE_APPEND:  synonym for ADD_NEW at the entry level — all rows are appended as
     *                 new entries. Semantically clearer name for UI purposes.
     * - MODE_REPLACE: trash all existing active entries for the form, then import rows
     *                 as new entries. Preserves hidden/trashed entries (existing #157 logic).
     */
    const MODE_ADD_NEW = 'add_new';
    const MODE_REPLACE = 'replace';
    const MODE_APPEND  = 'append';

    /**
     * Allowed import formats (used for UI accept= attributes and validation).
     */
    const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'xls', 'ods', 'html', 'htm', 'json', 'zip'];

    /**
     * Maximum upload file size: 20 MB (multi-format files may be larger than
     * the existing 5 MB CSV cap).
     */
    const MAX_UPLOAD_SIZE = 20971520;

    public static function get_instance(): TC_Import
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'), 22);
        add_action('admin_post_' . self::ACTION, array($this, 'handle_post'));
    }

    public function add_admin_menu(): void
    {
        add_submenu_page(
            'gravity-tables',
            __('Import Entries', 'tc-data-tables'),
            __('Import Entries', 'tc-data-tables'),
            'manage_options',
            'gravity-tables-import',
            array($this, 'render_page')
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'tc-data-tables'));
        }

        $tables = $this->load_tables();
        $result = $this->consume_result_for_user(get_current_user_id());

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import Entries', 'tc-data-tables'); ?></h1>
            <p class="description">
                <?php esc_html_e('Upload a file to import entries. Supported formats: CSV, XLSX, XLS, ODS, HTML, JSON, ZIP. The first row (or object keys for JSON) are matched to Gravity Form field labels (case-insensitive). Each remaining row creates or replaces entries depending on the selected mode.', 'tc-data-tables'); ?>
            </p>

            <?php if ($result): ?>
                <div class="notice notice-<?php echo $result['errors'] ? 'warning' : 'success'; ?>">
                    <p>
                        <strong><?php echo esc_html(sprintf(
                            __('Imported %d rows into table "%s". %d failed.', 'tc-data-tables'),
                            (int) $result['imported'],
                            $result['table_title'] ?? '',
                            (int) $result['failed']
                        )); ?></strong>
                    </p>
                    <?php if (!empty($result['errors'])): ?>
                        <details>
                            <summary><?php esc_html_e('Show errors', 'tc-data-tables'); ?></summary>
                            <ul>
                                <?php foreach ($result['errors'] as $err): ?>
                                    <li><?php echo esc_html(sprintf(__('Row %d: %s', 'tc-data-tables'), (int) $err['row'], $err['message'])); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
                <?php if (!empty($result['formula_warnings'])): ?>
                    <div class="notice notice-warning">
                        <p>
                            <strong><?php echo esc_html(sprintf(
                                _n(
                                    '%d imported formula references a function the GT formula engine does not support. The cell was stored verbatim but will not evaluate at render time.',
                                    '%d imported formulas reference functions the GT formula engine does not support. Cells were stored verbatim but will not evaluate at render time.',
                                    count($result['formula_warnings']),
                                    'tc-data-tables'
                                ),
                                count($result['formula_warnings'])
                            )); ?></strong>
                        </p>
                        <details>
                            <summary><?php esc_html_e('Show unsupported-function warnings', 'tc-data-tables'); ?></summary>
                            <ul>
                                <?php foreach ($result['formula_warnings'] as $w): ?>
                                    <li><?php echo esc_html(sprintf(
                                        __('Row %1$d, field %2$s: %3$s — unsupported: %4$s', 'tc-data-tables'),
                                        (int) $w['row'],
                                        (string) ($w['field_id'] ?? ''),
                                        (string) ($w['formula'] ?? ''),
                                        implode(', ', (array) ($w['unsupported'] ?? array()))
                                    )); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($tables)): ?>
                <div class="notice notice-warning"><p><?php esc_html_e('No active tables found. Create a table before importing.', 'tc-data-tables'); ?></p></div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_KEY); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">

                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="gt-import-table"><?php esc_html_e('Target Table', 'tc-data-tables'); ?></label></th>
                                <td>
                                    <select id="gt-import-table" name="table_id" required>
                                        <option value=""><?php esc_html_e('Select a table…', 'tc-data-tables'); ?></option>
                                        <?php foreach ($tables as $tbl): ?>
                                            <option value="<?php echo esc_attr($tbl->id); ?>"><?php echo esc_html(sprintf('#%d %s (form %d)', $tbl->id, $tbl->title, $tbl->form_id)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="gt-import-file"><?php esc_html_e('Import File', 'tc-data-tables'); ?></label></th>
                                <td>
                                    <input id="gt-import-file" type="file" name="csv_file"
                                        accept=".csv,.xlsx,.xls,.ods,.html,.htm,.json,.zip,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/vnd.oasis.opendocument.spreadsheet,text/html,application/json,application/zip"
                                        required>
                                    <p class="description">
                                        <?php esc_html_e('Supported formats: CSV, XLSX, XLS, ODS, HTML, JSON, ZIP (containing any of the above). Maximum 20 MB. Headers must match Gravity Form field labels.', 'tc-data-tables'); ?>
                                    </p>
                                    <p class="description">
                                        <?php esc_html_e('Google Sheets, OneDrive, and Dropbox share links are automatically converted to direct download URLs when used with the auto-import scheduler.', 'tc-data-tables'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Import Mode', 'tc-data-tables'); ?></th>
                                <td>
                                    <fieldset>
                                        <label>
                                            <input type="radio" name="import_mode" value="<?php echo esc_attr(self::MODE_ADD_NEW); ?>" checked>
                                            <?php esc_html_e('Add new entries — append imported rows as new entries (does not affect existing entries).', 'tc-data-tables'); ?>
                                        </label><br>
                                        <label>
                                            <input type="radio" name="import_mode" value="<?php echo esc_attr(self::MODE_APPEND); ?>">
                                            <?php esc_html_e('Append rows — same as "Add new entries" (explicit name for clarity).', 'tc-data-tables'); ?>
                                        </label><br>
                                        <label>
                                            <input type="radio" name="import_mode" value="<?php echo esc_attr(self::MODE_REPLACE); ?>">
                                            <strong><?php esc_html_e('Replace all entries — trash all existing active entries, then import the file. Hidden/trashed entries are preserved.', 'tc-data-tables'); ?></strong>
                                        </label>
                                    </fieldset>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Import Entries', 'tc-data-tables')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_post(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'tc-data-tables'));
        }
        check_admin_referer(self::NONCE_KEY);

        $table_id = intval($_POST['table_id'] ?? 0);
        if (!$table_id) {
            $this->redirect_with_error(__('Please select a table.', 'tc-data-tables'));
        }

        if (empty($_FILES['csv_file']['tmp_name']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->redirect_with_error(__('Upload failed. Please try again.', 'tc-data-tables'));
        }

        $size = (int) ($_FILES['csv_file']['size'] ?? 0);
        if ($size > self::MAX_UPLOAD_SIZE) {
            $this->redirect_with_error(
                sprintf(
                    /* translators: %s: max size in MB */
                    __('File too large (maximum %s).', 'tc-data-tables'),
                    '20 MB'
                )
            );
        }

        // Validate extension / MIME.
        $orig_name = sanitize_file_name($_FILES['csv_file']['name'] ?? 'upload.csv');
        $mime_type = (string) ($_FILES['csv_file']['type'] ?? '');
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

        if (class_exists('TC_Import_Format_Detector') && !empty($mime_type)) {
            if (!TC_Import_Format_Detector::is_allowed_mime($mime_type)) {
                // MIME not in allow-list — still allow it if extension is known (browser inconsistencies)
                if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    $this->redirect_with_error(
                        sprintf(
                            /* translators: %s: MIME type */
                            __('File type not allowed: %s', 'tc-data-tables'),
                            esc_html($mime_type)
                        )
                    );
                }
            }
        }

        // Import mode (#2322)
        $mode = sanitize_key($_POST['import_mode'] ?? self::MODE_ADD_NEW);
        if (!in_array($mode, [self::MODE_ADD_NEW, self::MODE_REPLACE, self::MODE_APPEND], true)) {
            $mode = self::MODE_ADD_NEW;
        }

        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $table = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        if (!$table) {
            $this->redirect_with_error(__('Table not found.', 'tc-data-tables'));
        }

        if (!class_exists('GFAPI')) {
            $this->redirect_with_error(__('Gravity Forms is not active.', 'tc-data-tables'));
        }

        $form = GFAPI::get_form((int) $table->form_id);
        if (!$form || is_wp_error($form)) {
            $this->redirect_with_error(__('Form for this table is missing.', 'tc-data-tables'));
        }

        // Build label -> field-id map (case-insensitive)
        $label_map = array();
        foreach ((array) ($form['fields'] ?? array()) as $field) {
            if (empty($field->label)) continue;
            $label_map[strtolower(trim((string) $field->label))] = (string) $field->id;
        }

        // Identify which GF field IDs are configured as toggle columns (#325)
        $table_settings   = is_string($table->settings) ? (array) json_decode($table->settings, true) : array();
        $column_config    = $table_settings['column_config'] ?? array();
        $toggle_field_ids = array();
        foreach ($column_config as $fid => $col) {
            if (isset($col['type']) && $col['type'] === 'toggle') {
                $toggle_field_ids[] = (string) $fid;
            }
        }

        // Read the uploaded file into memory
        $tmp_path = $_FILES['csv_file']['tmp_name'];
        // @codeCoverageIgnoreStart
        $raw_bytes = file_get_contents($tmp_path);
        if ($raw_bytes === false) {
            $this->redirect_with_error(__('Could not read uploaded file.', 'tc-data-tables'));
        }
        // @codeCoverageIgnoreEnd

        // Snapshot hidden entries before import (#157)
        $hidden_entry_ids = $this->preserve_hidden_entries((int) $table->form_id);

        // Replace mode: trash existing active entries first (#2322)
        if ($mode === self::MODE_REPLACE) {
            $existing = GFAPI::get_entries(
                (int) $table->form_id,
                array('status' => 'active'),
                null,
                array('offset' => 0, 'page_size' => 1000)
            );
            if (is_array($existing)) {
                foreach ($existing as $existing_entry) {
                    GFAPI::update_entry(array('status' => 'trash'), (int) $existing_entry['id']);
                }
            }
        }

        // Parse the uploaded file (#2322 multi-format)
        if (class_exists('TC_Import_Format_Detector') && class_exists('TC_Multi_Format_Parser')) {
            $format       = TC_Import_Format_Detector::detect($orig_name, $mime_type, strlen($raw_bytes) >= 8 ? substr($raw_bytes, 0, 8) : null);
            $parse_result = TC_Multi_Format_Parser::parse($raw_bytes, $format);

            if (is_wp_error($parse_result)) {
                $this->restore_hidden_entries((int) $table->form_id, $hidden_entry_ids);
                $this->redirect_with_error($parse_result->get_error_message());
            }

            // ZIP: flatten per-file row arrays into one list
            if ($format === 'zip') {
                $all_rows = array();
                foreach ($parse_result as $entry) {
                    if (!is_wp_error($entry['rows']) && is_array($entry['rows'])) {
                        $all_rows = array_merge($all_rows, $entry['rows']);
                    }
                }
            } else {
                $all_rows = $parse_result;
            }

            // Build unmapped headers list
            $unmapped     = array();
            $imported     = 0;
            $failed       = 0;
            $errors       = array();
            $formula_warnings = array();
            $user_id      = get_current_user_id();

            // Allow unlimited execution time for large files (#438)
            set_time_limit(0);

            $row_num = 1; // treat header row as row 1
            foreach ($all_rows as $row) {
                $row_num++;
                if (!is_array($row)) continue;

                $entry = array(
                    'form_id'    => (int) $table->form_id,
                    'status'     => 'active',
                    'created_by' => $user_id,
                );

                foreach ($row as $header => $cell) {
                    $key = strtolower(trim((string) $header));
                    if (!isset($label_map[$key])) {
                        if (!in_array((string) $header, $unmapped, true) && (string) $header !== '') {
                            $unmapped[] = (string) $header;
                        }
                        continue;
                    }
                    $field_id  = $label_map[$key];
                    $is_toggle = in_array((string) $field_id, $toggle_field_ids, true);

                    // Formula warnings (#525 slice 3)
                    if (!$is_toggle
                        && count($formula_warnings) < 25
                        && class_exists('TC_CSV_Formula_Detector')) {
                        $analysis_value = sanitize_text_field(is_scalar($cell) ? (string) $cell : '');
                        if (TC_CSV_Formula_Detector::is_formula_cell($analysis_value)) {
                            $unsupported = TC_CSV_Formula_Detector::unsupported_functions($analysis_value);
                            if (!empty($unsupported)) {
                                $formula_warnings[] = array(
                                    'row'        => $row_num,
                                    'field_id'   => (string) $field_id,
                                    'formula'    => $analysis_value,
                                    'unsupported' => $unsupported,
                                );
                            }
                        }
                    }

                    $entry[(string) $field_id] = self::transform_csv_cell($cell, $is_toggle);
                }

                $result = GFAPI::add_entry($entry);
                if (is_wp_error($result)) {
                    $failed++;
                    $errors[] = array('row' => $row_num, 'message' => $result->get_error_message());
                    if (count($errors) >= 25) {
                        $errors[] = array('row' => $row_num, 'message' => __('Further errors truncated.', 'tc-data-tables'));
                        break;
                    }
                } else {
                    $imported++;
                }
            }
        } else {
            // Fallback: legacy CSV-only path (TC_Multi_Format_Parser not loaded)
            // @codeCoverageIgnoreStart
            $handle = fopen($tmp_path, 'r');
            if ($handle === false) {
                $this->restore_hidden_entries((int) $table->form_id, $hidden_entry_ids);
                $this->redirect_with_error(__('Could not open uploaded file.', 'tc-data-tables'));
            }

            // #1253 — explicit $escape '\\' to silence PHP 8.4 deprecation.
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if ($headers !== false && isset($headers[0]) && str_starts_with($headers[0], "\xef\xbb\xbf")) {
                $headers[0] = substr($headers[0], 3);
            }
            if ($headers === false || empty($headers)) {
                fclose($handle);
                $this->restore_hidden_entries((int) $table->form_id, $hidden_entry_ids);
                $this->redirect_with_error(__('CSV is empty or unreadable.', 'tc-data-tables'));
            }

            $col_to_field = array();
            $unmapped     = array();
            foreach ($headers as $idx => $hdr) {
                $key = strtolower(trim((string) $hdr));
                if ($key === '') { $col_to_field[$idx] = null; continue; }
                if (isset($label_map[$key])) {
                    $col_to_field[$idx] = $label_map[$key];
                } else {
                    $col_to_field[$idx] = null;
                    $unmapped[] = (string) $hdr;
                }
            }

            $imported = 0; $failed = 0; $errors = array(); $formula_warnings = array();
            $row_num  = 1;
            $user_id  = get_current_user_id();
            set_time_limit(0);

            try {
                while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                    $row_num++;
                    if (count($row) === 1 && trim((string) $row[0]) === '') continue;
                    $entry = array('form_id' => (int) $table->form_id, 'status' => 'active', 'created_by' => $user_id);
                    foreach ($row as $idx => $cell) {
                        $field_id = $col_to_field[$idx] ?? null;
                        if (!$field_id) continue;
                        $is_toggle = in_array((string) $field_id, $toggle_field_ids, true);
                        if (!$is_toggle && count($formula_warnings) < 25 && class_exists('TC_CSV_Formula_Detector')) {
                            $av = sanitize_text_field(is_scalar($cell) ? (string) $cell : '');
                            if (TC_CSV_Formula_Detector::is_formula_cell($av)) {
                                $unsupported = TC_CSV_Formula_Detector::unsupported_functions($av);
                                if (!empty($unsupported)) {
                                    $formula_warnings[] = array('row' => $row_num, 'field_id' => (string) $field_id, 'formula' => $av, 'unsupported' => $unsupported);
                                }
                            }
                        }
                        $entry[(string) $field_id] = self::transform_csv_cell($cell, $is_toggle);
                    }
                    $result = GFAPI::add_entry($entry);
                    if (is_wp_error($result)) {
                        $failed++;
                        $errors[] = array('row' => $row_num, 'message' => $result->get_error_message());
                        if (count($errors) >= 25) { $errors[] = array('row' => $row_num, 'message' => __('Further errors truncated.', 'tc-data-tables')); break; }
                    } else { $imported++; }
                }
            } catch (\Throwable $e) {
                fclose($handle);
                $this->restore_hidden_entries((int) $table->form_id, $hidden_entry_ids);
                $this->redirect_with_error(__('Import failed: ', 'tc-data-tables') . esc_html($e->getMessage()));
            }
            fclose($handle);
            // @codeCoverageIgnoreEnd
        }

        $this->restore_hidden_entries((int) $table->form_id, $hidden_entry_ids);

        do_action('gravity_tables_after_import', $table_id, $imported ?? 0);

        $this->save_result($user_id ?? get_current_user_id(), array(
            'imported'         => $imported ?? 0,
            'failed'           => $failed ?? 0,
            'errors'           => $errors ?? array(),
            'formula_warnings' => $formula_warnings ?? array(),
            'unmapped_headers' => $unmapped ?? array(),
            'table_title'      => (string) $table->title,
            'table_id'         => $table_id,
            'import_mode'      => $mode,
            'format'           => $format ?? 'csv',
        ));

        wp_safe_redirect(admin_url('admin.php?page=gravity-tables-import'));
        // @codeCoverageIgnoreStart
        exit;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Canonical CSV cell transform pipeline. Pure / static / unit-testable.
     *
     * Slice 1b of #525 — extracted from `handle_post()` so the same logic
     * (XSS guard from #241, toggle normalization from #325, formula
     * detection + wrap from #539 / TC_CSV_Formula_Detector) can be
     * exercised in unit tests with synthetic fixtures.
     *
     * Pipeline:
     *   1. sanitize_text_field — strips HTML, normalizes whitespace.
     *   2. Toggle precedence (when $is_toggle): 1/true/yes → '1', else '0'.
     *      Toggle wins over formula detection so a `=TRUE()` cell in a
     *      toggle column never becomes a stored formula.
     *   3. Formula wrap (when TC_CSV_Formula_Detector::is_formula_cell()
     *      returns true on the post-sanitize value): prefix with the
     *      canonical `gt_formula:` storage marker.
     *   4. Otherwise: return the sanitized scalar as-is.
     */
    public static function transform_csv_cell($cell, bool $is_toggle): string
    {
        $cell_value = sanitize_text_field(is_scalar($cell) ? (string) $cell : '');
        if ($is_toggle) {
            $v = strtolower(trim($cell_value));
            return ($v === '1' || $v === 'true' || $v === 'yes') ? '1' : '0';
        }
        if (class_exists('TC_CSV_Formula_Detector')
            && TC_CSV_Formula_Detector::is_formula_cell($cell_value)) {
            return TC_CSV_Formula_Detector::wrap_for_storage($cell_value);
        }
        return $cell_value;
    }

    private function preserve_hidden_entries(int $form_id): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status = 'trash'",
            $form_id
        ));
        return array_map('intval', $results ?: array());
    }

    private function restore_hidden_entries(int $form_id, array $hidden_ids): void
    {
        if (empty($hidden_ids)) return;
        foreach ($hidden_ids as $entry_id) {
            GFAPI::update_entry(array('status' => 'trash'), $entry_id);
        }
    }

    private function load_tables(): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $rows = $wpdb->get_results("SELECT id, title, form_id FROM {$wpdb->prefix}gravity_tables WHERE status = 'active' ORDER BY id ASC");
        return is_array($rows) ? $rows : array();
    }

    private function save_result(int $user_id, array $result): void
    {
        set_transient(self::RESULT_TRANSIENT_PREFIX . $user_id, $result, 5 * MINUTE_IN_SECONDS);
    }

    private function consume_result_for_user(int $user_id): ?array
    {
        $key = self::RESULT_TRANSIENT_PREFIX . $user_id;
        $result = get_transient($key);
        if (!$result) return null;
        delete_transient($key);
        return is_array($result) ? $result : null;
    }

    private function redirect_with_error(string $message): void
    {
        $this->save_result(get_current_user_id(), array(
            'imported' => 0,
            'failed' => 0,
            'errors' => array(array('row' => 0, 'message' => $message)),
            'table_title' => '',
        ));
        wp_safe_redirect(admin_url('admin.php?page=gravity-tables-import'));
        // @codeCoverageIgnoreStart
        exit;
        // @codeCoverageIgnoreEnd
    }

    // -------------------------------------------------------------------------
    // Multi-format import API (#2322)
    // -------------------------------------------------------------------------

    /**
     * Parse a raw file body (bytes) into rows using the appropriate parser
     * based on format auto-detection.
     *
     * This is a pure static method: no WordPress dependencies, no DB writes.
     * It delegates to TC_Multi_Format_Parser::parse() after detecting the
     * format via TC_Import_Format_Detector::detect().
     *
     * ZIP files return an array of ['file' => string, 'rows' => array|WP_Error]
     * entries; all other formats return a flat array of assoc rows.
     *
     * @param string      $bytes       Raw file bytes.
     * @param string      $filename    Original filename (used for format detection).
     * @param string|null $mime_type   Optional: PHP-reported MIME type.
     * @param array       $options     Optional: Parser options passed to TC_Multi_Format_Parser.
     * @return array|WP_Error  Rows array or WP_Error on failure.
     */
    public static function parse_rows_from_file(
        string $bytes,
        string $filename,
        ?string $mime_type = null,
        array $options = []
    ) {
        if (!class_exists('TC_Import_Format_Detector') || !class_exists('TC_Multi_Format_Parser')) {
            // @codeCoverageIgnoreStart
            return new \WP_Error('tc_import_services_missing', __('Import format services are not available.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        $format = TC_Import_Format_Detector::detect(
            $filename,
            $mime_type,
            strlen($bytes) >= 8 ? substr($bytes, 0, 8) : null
        );

        // Apply share-URL fixup to URL-sourced filenames (noop for file uploads)
        return TC_Multi_Format_Parser::parse($bytes, $format, $options);
    }

    /**
     * Import rows from a raw data string into a table's GF entries.
     *
     * This method is called by TC_Auto_Import::do_import() for scheduled
     * auto-imports, and may also be invoked directly for programmatic imports.
     *
     * Supports add_new, append (synonym), and replace modes.
     * For ZIP files, each contained file's rows are imported in sequence.
     *
     * @param int    $table_id   Target table ID.
     * @param string $body       Raw file bytes (CSV, JSON, XLSX, etc.).
     * @param array  $settings   Table settings array (from DB JSON column).
     * @return true|\WP_Error
     */
    public function import_from_string(int $table_id, string $body, array $settings = []): bool|\WP_Error {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $table = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        if (!$table) {
            return new \WP_Error('tc_import_table_not_found', __('Table not found.', 'tc-data-tables'));
        }

        if (!class_exists('GFAPI')) {
            return new \WP_Error('tc_import_gf_missing', __('Gravity Forms is not active.', 'tc-data-tables'));
        }

        $form = GFAPI::get_form((int) $table->form_id);
        if (!$form || is_wp_error($form)) {
            return new \WP_Error('tc_import_form_missing', __('Form for this table is missing.', 'tc-data-tables'));
        }

        // Determine source URL for format detection (from settings) and filename
        $source_url  = $settings['auto_refresh_url'] ?? ($settings['auto_import_url'] ?? '');
        $filename    = $source_url ? basename(parse_url($source_url, PHP_URL_PATH) ?: 'import.csv') : 'import.csv';
        $mode        = $settings['import_mode'] ?? self::MODE_ADD_NEW;

        // Parse the body into rows
        $parse_result = self::parse_rows_from_file($body, $filename);
        if (is_wp_error($parse_result)) {
            return $parse_result;
        }

        // For ZIP files, $parse_result is an array of ['file' => ..., 'rows' => ...]
        // Flatten into a single rows list
        $all_rows = array();
        if (!empty($parse_result) && isset($parse_result[0]['file'])) {
            foreach ($parse_result as $entry) {
                if (!is_wp_error($entry['rows'])) {
                    $all_rows = array_merge($all_rows, $entry['rows']);
                }
            }
        } else {
            $all_rows = $parse_result;
        }

        // Build label → field-id map
        $label_map = array();
        foreach ((array) ($form['fields'] ?? array()) as $field) {
            if (empty($field->label)) continue;
            $label_map[strtolower(trim((string) $field->label))] = (string) $field->id;
        }

        // Build toggle field set from table settings
        $table_settings   = is_string($table->settings) ? (array) json_decode($table->settings, true) : array();
        $column_config    = $table_settings['column_config'] ?? array();
        $toggle_field_ids = array();
        foreach ($column_config as $fid => $col) {
            if (isset($col['type']) && $col['type'] === 'toggle') {
                $toggle_field_ids[] = (string) $fid;
            }
        }

        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;

        // Replace mode: trash existing active entries first
        if ($mode === self::MODE_REPLACE) {
            $hidden_entry_ids = $this->preserve_hidden_entries((int) $table->form_id);
            $existing = GFAPI::get_entries(
                (int) $table->form_id,
                array('status' => 'active'),
                null,
                array('offset' => 0, 'page_size' => 1000)
            );
            if (is_array($existing)) {
                foreach ($existing as $existing_entry) {
                    GFAPI::update_entry(array('status' => 'trash'), $existing_entry['id']);
                }
            }
        } else {
            $hidden_entry_ids = $this->preserve_hidden_entries((int) $table->form_id);
        }

        $imported = 0;
        $failed   = 0;
        $errors   = array();

        foreach ($all_rows as $row_idx => $row) {
            if (!is_array($row)) continue;

            $entry = array(
                'form_id'    => (int) $table->form_id,
                'status'     => 'active',
                'created_by' => $user_id,
            );

            foreach ($row as $header => $cell) {
                $key = strtolower(trim((string) $header));
                if (!isset($label_map[$key])) continue;
                $field_id  = $label_map[$key];
                $is_toggle = in_array((string) $field_id, $toggle_field_ids, true);
                $entry[(string) $field_id] = self::transform_csv_cell($cell, $is_toggle);
            }

            $result = GFAPI::add_entry($entry);
            if (is_wp_error($result)) {
                $failed++;
                $errors[] = array('row' => $row_idx + 2, 'message' => $result->get_error_message());
            } else {
                $imported++;
            }
        }

        $this->restore_hidden_entries((int) $table->form_id, $hidden_entry_ids ?? array());

        do_action('gravity_tables_after_import', $table_id, $imported);

        return true;
    }
}
