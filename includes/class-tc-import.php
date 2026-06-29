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
 * Deferred follow-ups: Excel (.xlsx) via PhpSpreadsheet, manual column
 * mapping UI, update-existing-by-key, background processing of very
 * large files, persistent import history log.
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
                <?php esc_html_e('Upload a CSV file. The first row is treated as headers and matched to Gravity Form field labels (case-insensitive). Each remaining row creates a new entry.', 'tc-data-tables'); ?>
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
                                <th scope="row"><label for="gt-import-file"><?php esc_html_e('CSV File', 'tc-data-tables'); ?></label></th>
                                <td>
                                    <input id="gt-import-file" type="file" name="csv_file" accept=".csv,text/csv" required>
                                    <p class="description"><?php esc_html_e('Maximum 5 MB. Headers must match Gravity Form field labels.', 'tc-data-tables'); ?></p>
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
        if ($size > 5 * 1024 * 1024) {
            $this->redirect_with_error(__('File too large (5 MB maximum).', 'tc-data-tables'));
        }

        global $wpdb;
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

        $tmp = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmp, 'r');
        if ($handle === false) {
            // @codeCoverageIgnoreStart
            $this->redirect_with_error(__('Could not open uploaded file.', 'tc-data-tables'));
            // @codeCoverageIgnoreEnd
        }

        // #1253 — pass explicit $escape '\\' to silence the PHP 8.4
        // deprecation and preserve pre-8.4 behavior. PHP 9.0 will default
        // $escape to '' (RFC 4180), but our existing CSVs assume the
        // legacy escape-on-backslash semantics.
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        // Strip UTF-8 BOM (\xef\xbb\xbf) that Excel "Save As CSV UTF-8" prepends.
        if ($headers !== false && isset($headers[0]) && str_starts_with($headers[0], "\xef\xbb\xbf")) {
            $headers[0] = substr($headers[0], 3);
        }
        if ($headers === false || empty($headers)) {
            fclose($handle);
            $this->redirect_with_error(__('CSV is empty or unreadable.', 'tc-data-tables'));
        }

        // Map each column index to a GF field id (or null for unmapped)
        $col_to_field = array();
        $unmapped = array();
        foreach ($headers as $idx => $hdr) {
            $key = strtolower(trim((string) $hdr));
            if ($key === '') {
                // @codeCoverageIgnoreStart
                $col_to_field[$idx] = null;
                continue;
                // @codeCoverageIgnoreEnd
            }
            if (isset($label_map[$key])) {
                $col_to_field[$idx] = $label_map[$key];
            } else {
                $col_to_field[$idx] = null;
                $unmapped[] = (string) $hdr;
            }
        }

        // Identify which GF field IDs are configured as toggle columns (#325)
        $table_settings    = is_string($table->settings) ? (array) json_decode($table->settings, true) : array();
        $column_config     = $table_settings['column_config'] ?? array();
        $toggle_field_ids  = array();
        foreach ($column_config as $fid => $col) {
            if (isset($col['type']) && $col['type'] === 'toggle') {
                $toggle_field_ids[] = (string) $fid;
            }
        }

        // Snapshot hidden (trashed) entries before import so they can be restored
        // afterwards — prevents re-import from accidentally making hidden rows visible (#157)
        $hidden_entry_ids = $this->preserve_hidden_entries((int) $table->form_id);

        $imported = 0;
        $failed = 0;
        $errors = array();
        // #525 slice 3: collect informational warnings about formulas that
        // reference functions the GT formula engine does not support. The
        // import still succeeds — the cell is stored verbatim — but the user
        // is told it won't evaluate at render time so they can fix the source
        // CSV instead of being silently dropped.
        $formula_warnings = array();
        $row_num = 1; // header is row 1
        $user_id = get_current_user_id();

        // Allow unlimited execution time for large files on shared hosting (#438)
        set_time_limit(0);

        try {
            // #1253 — explicit $escape '\\' to silence PHP 8.4 deprecation.
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $row_num++;
                // Skip blank lines
                if (count($row) === 1 && trim((string) $row[0]) === '') continue;

                $entry = array(
                    'form_id' => (int) $table->form_id,
                    'status' => 'active',
                    'created_by' => $user_id,
                );
                foreach ($row as $idx => $cell) {
                    $field_id = $col_to_field[$idx] ?? null;
                    if (!$field_id) continue;
                    // Slice 1b of #525: route every cell through the canonical
                    // transform helper so the same logic (sanitize_text_field
                    // XSS guard from #241, toggle normalization from #325, and
                    // formula detection + wrap from #539) is exercised in unit
                    // tests with synthetic fixtures.
                    $is_toggle = in_array((string) $field_id, $toggle_field_ids, true);

                    // Slice 3 of #525: surface "imported but won't render" warnings
                    // for cells that are formulas referencing functions the GT
                    // formula engine does not support. Cap warnings at 25 to
                    // mirror the existing error-collection limit and avoid
                    // ballooning the result transient.
                    if (!$is_toggle
                        && count($formula_warnings) < 25
                        && class_exists('TC_CSV_Formula_Detector')) {
                        $analysis_value = sanitize_text_field(is_scalar($cell) ? (string) $cell : '');
                        if (TC_CSV_Formula_Detector::is_formula_cell($analysis_value)) {
                            $unsupported = TC_CSV_Formula_Detector::unsupported_functions($analysis_value);
                            if (!empty($unsupported)) {
                                $formula_warnings[] = array(
                                    'row' => $row_num,
                                    'field_id' => (string) $field_id,
                                    'formula' => $analysis_value,
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
                    $errors[] = array(
                        'row' => $row_num,
                        'message' => $result->get_error_message(),
                    );
                    if (count($errors) >= 25) {
                        $errors[] = array('row' => $row_num, 'message' => __('Further errors truncated.', 'tc-data-tables'));
                        break;
                    }
                } else {
                    $imported++;
                }
            }
        // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            fclose($handle);
            $this->redirect_with_error(
                __('Import failed: ', 'tc-data-tables') . esc_html($e->getMessage())
            );
        // @codeCoverageIgnoreEnd
        }

        fclose($handle);

        $this->restore_hidden_entries((int) $table->form_id, $hidden_entry_ids);

        do_action('gravity_tables_after_import', $table_id, $imported);

        $this->save_result($user_id, array(
            'imported' => $imported,
            'failed' => $failed,
            'errors' => $errors,
            'formula_warnings' => $formula_warnings,
            'unmapped_headers' => $unmapped,
            'table_title' => (string) $table->title,
            'table_id' => $table_id,
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
        global $wpdb;
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
        global $wpdb;
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
}
