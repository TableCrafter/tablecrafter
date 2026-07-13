<?php
/**
 * TC_Abilities_Registry
 *
 * Issue #503 - slice 1 of 3. Foundational ability schema + registry
 * for the WordPress 7.0 Abilities API. Exposes Gravity Tables
 * capabilities (list / read / query / write) so external AI tooling
 * and other plugins can discover and invoke our table operations
 * through the platform-level layer instead of bespoke per-plugin
 * integration.
 *
 * Slice 1 (this file): canonical ability list + registration mechanism
 * with feature detection. Callbacks are stubs that throw a clear
 * "not yet wired" error so the registration shape is locked but the
 * concrete handlers land in slice 2.
 *
 * Slice 2: wires each ability to its concrete handler (delegates to
 *   `TC_Admin` / `TC_Ajax` / `TC_REST_API`).
 * Slice 3: MCP adapter integration, premium gating enforcement,
 *   admin notice for WP <7.0, developer docs.
 *
 * Read-only abilities (list / schema / query) are free-tier; write
 * abilities (update / create / delete) sit behind `gt_is_premium()`
 * to keep parity with existing premium gating. Slice 3 enforces the
 * gate; slice 1 just records the flag.
 *
 * @since 4.7.31
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Abilities_Registry {

    /**
     * Return the canonical ability list. Each entry is a struct with:
     *   - id           string  (e.g. 'gravity_tables.list_tables')
     *   - label        string  (human-readable name)
     *   - description  string  (one-line summary)
     *   - capability   string  (WordPress capability slug required)
     *   - is_write     bool    (true for create/update/delete)
     *   - is_premium   bool    (true when the ability is premium-only)
     *   - args         array   (associative array of arg-name =>
     *                           { type, required, description })
     *
     * @return array<int,array{id:string,label:string,description:string,
     *                          capability:string,is_write:bool,
     *                          is_premium:bool,args:array}>
     */
    public static function abilities(): array {
        return [
            [
                'id'          => 'gravity_tables.list_tables',
                'label'       => __('List tables', 'tc-data-tables'),
                'description' => __('Return the list of active TableCrafter tables on this site, with id / title / form id.', 'tc-data-tables'),
                'capability'  => 'view_gravity_tables',
                'is_write'    => false,
                'is_premium'  => false,
                'args'        => [],
            ],
            [
                'id'          => 'gravity_tables.get_table_schema',
                'label'       => __('Get table schema', 'tc-data-tables'),
                'description' => __('Return the column definitions for a given table id.', 'tc-data-tables'),
                'capability'  => 'view_gravity_tables',
                'is_write'    => false,
                'is_premium'  => false,
                'args'        => [
                    'table_id' => ['type' => 'integer', 'required' => true, 'description' => 'Gravity Tables table id'],
                ],
            ],
            [
                'id'          => 'gravity_tables.query_rows',
                'label'       => __('Query rows', 'tc-data-tables'),
                'description' => __('Return rows from a table with optional filter, sort, and pagination.', 'tc-data-tables'),
                'capability'  => 'view_gravity_tables',
                'is_write'    => false,
                'is_premium'  => false,
                'args'        => [
                    'table_id'  => ['type' => 'integer', 'required' => true,  'description' => 'Gravity Tables table id'],
                    'filter'    => ['type' => 'object',  'required' => false, 'description' => 'Optional filter map (field_id => value)'],
                    'sort'      => ['type' => 'string',  'required' => false, 'description' => 'Optional sort field id'],
                    'order'     => ['type' => 'string',  'required' => false, 'description' => 'asc | desc (default desc)'],
                    'page'      => ['type' => 'integer', 'required' => false, 'description' => 'Page number (1-based)'],
                    'per_page'  => ['type' => 'integer', 'required' => false, 'description' => 'Page size (default 25)'],
                ],
            ],
            [
                'id'          => 'gravity_tables.update_cell',
                'label'       => __('Update cell', 'tc-data-tables'),
                'description' => __('Update a single cell value on an existing entry.', 'tc-data-tables'),
                'capability'  => 'edit_gravity_tables',
                'is_write'    => true,
                'is_premium'  => true,
                'args'        => [
                    'table_id' => ['type' => 'integer', 'required' => true, 'description' => 'Gravity Tables table id'],
                    'entry_id' => ['type' => 'integer', 'required' => true, 'description' => 'Gravity Forms entry id'],
                    'field_id' => ['type' => 'string',  'required' => true, 'description' => 'Gravity Forms field id'],
                    'value'    => ['type' => 'string',  'required' => true, 'description' => 'New cell value'],
                ],
            ],
            [
                'id'          => 'gravity_tables.create_row',
                'label'       => __('Create row', 'tc-data-tables'),
                'description' => __('Create a new entry on the underlying Gravity Form.', 'tc-data-tables'),
                'capability'  => 'edit_gravity_tables',
                'is_write'    => true,
                'is_premium'  => true,
                'args'        => [
                    'table_id' => ['type' => 'integer', 'required' => true, 'description' => 'Gravity Tables table id'],
                    'fields'   => ['type' => 'object',  'required' => true, 'description' => 'Map of field_id => value'],
                ],
            ],
            [
                'id'          => 'gravity_tables.delete_row',
                'label'       => __('Delete row', 'tc-data-tables'),
                'description' => __('Soft-delete (trash) an entry on the underlying form.', 'tc-data-tables'),
                'capability'  => 'delete_gravity_tables',
                'is_write'    => true,
                'is_premium'  => true,
                'args'        => [
                    'table_id' => ['type' => 'integer', 'required' => true, 'description' => 'Gravity Tables table id'],
                    'entry_id' => ['type' => 'integer', 'required' => true, 'description' => 'Gravity Forms entry id'],
                ],
            ],
        ];
    }

    /**
     * Feature-detect the WordPress 7.0 Abilities API. Returns true
     * when the registry registration function or class is loaded.
     */
    public static function is_api_available(): bool {
        return function_exists('wp_register_ability') || class_exists('WP_Abilities_Registry');
    }

    /**
     * Register every ability with the WP Abilities API.
     * Returns the count registered. Returns 0 (graceful no-op) when
     * the API is not available.
     *
     * Slice 2 (v4.62.0) routes every registered callback through
     * `self::dispatch()`. Read abilities resolve to real handlers;
     * write abilities still return the slice-1 stub until slice 3
     * lands premium gating + MCP adapter.
     */
    public static function register(): int {
        if (!self::is_api_available()) {
            return 0;
        }
        $count = 0;
        foreach (self::abilities() as $ability) {
            $args = [
                'label'             => $ability['label'],
                'description'       => $ability['description'],
                'permission_check'  => function () use ($ability) {
                    return current_user_can($ability['capability']);
                },
                'args'              => $ability['args'],
                'callback'          => function ($args = []) use ($ability) {
                    return self::dispatch($ability['id'], is_array($args) ? $args : []);
                },
            ];
            if (function_exists('wp_register_ability')) {
                wp_register_ability($ability['id'], $args);
            }
            $count++;
        }
        return $count;
    }

    /**
     * Dispatch an ability invocation. Returns real data on success
     * or a WP_Error variant on failure:
     *   - gt_ability_unknown          (status 404) - unrecognized id
     *   - gt_ability_invalid_args     (status 400) - required arg missing
     *   - gt_ability_not_found        (status 404) - referenced row missing
     *   - gt_ability_unavailable      (status 503) - TC_Admin / GFAPI not loaded
     *   - gt_ability_premium_required (status 402) - free tier hit a write
     *
     * Slice 2 wired read abilities; slice 3 wires write abilities and
     * adds the premium gate that fires BEFORE the handler runs.
     *
     * @param string $ability_id  Canonical id (e.g. 'gravity_tables.list_tables').
     * @param array  $args        Argument map; shape per ability definition.
     * @return mixed              Real data on success, or WP_Error.
     */
    public static function dispatch(string $ability_id, array $args = []) {
        // Premium gate: write abilities require a premium license.
        // Fires before the handler so we never hit GF / DB on the
        // free-tier denial path.
        if (self::ability_requires_premium($ability_id) && !self::is_premium()) {
            return new \WP_Error(
                'gt_ability_premium_required',
                sprintf('Ability %s requires a premium license.', $ability_id),
                ['status' => 402]
            );
        }

        switch ($ability_id) {
            case 'gravity_tables.list_tables':
                return self::handle_list_tables($args);
            case 'gravity_tables.get_table_schema':
                return self::handle_get_table_schema($args);
            case 'gravity_tables.query_rows':
                return self::handle_query_rows($args);
            case 'gravity_tables.update_cell':
                return self::handle_update_cell($args);
            case 'gravity_tables.create_row':
                return self::handle_create_row($args);
            case 'gravity_tables.delete_row':
                return self::handle_delete_row($args);
            default:
                return new \WP_Error(
                    'gt_ability_unknown',
                    sprintf('Unknown ability id: %s', $ability_id),
                    ['status' => 404]
                );
        }
    }

    /**
     * Slice 3 bootstrap. Call once from the plugin entrypoint after
     * the require_once for this file lands.
     *
     * WP 6.9 moved ability registration into the dedicated
     * `wp_abilities_api_init` action and rejects wp_register_ability()
     * calls from any other hook with a _doing_it_wrong notice (#1608).
     * We always arm the dedicated action (a no-op on cores that never
     * fire it) and keep the legacy `init` fallback only on cores that
     * predate the dedicated window.
     */
    public static function boot(): void {
        if (!function_exists('add_action')) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        add_action('wp_abilities_api_init', [self::class, 'register']);
        if (!self::core_has_abilities_init_action()) {
            add_action('init', [self::class, 'register_on_legacy_init']);
        }
        add_action('admin_init', [self::class, 'maybe_arm_legacy_wp_notice']);
    }

    /**
     * Legacy `init` registration path for pre-6.9 cores (and unknown-
     * version environments). Skips when the dedicated action already
     * fired so middle-ground builds never double-register.
     */
    public static function register_on_legacy_init(): int {
        if (function_exists('did_action') && did_action('wp_abilities_api_init')) {
            return 0;
        }
        return self::register();
    }

    /**
     * True when this WordPress core provides the dedicated
     * `wp_abilities_api_init` registration window (6.9+). Unknown
     * versions (tests / CLI) report false so the legacy fallback stays.
     */
    private static function core_has_abilities_init_action(): bool {
        $ver = isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : '';
        return $ver !== '' && version_compare($ver, '6.9', '>=');
    }

    /**
     * Arm a dismissible admin notice on WP <7.0 sites so site admins
     * know the Abilities API integration is dormant. One-shot via
     * the `gt_abilities_legacy_notice_dismissed` option (the JS in
     * `is-dismissible` flips it client-side; on a true REST-aware
     * dismissal you would POST a dedicated handler - out of scope
     * for this slice).
     */
    public static function maybe_arm_legacy_wp_notice(): void {
        if (self::is_api_available()) {
            return;
        }
        if (function_exists('get_option') && get_option('gt_abilities_legacy_notice_dismissed', false)) {
            return;
        }
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            return;
        }
        if (function_exists('add_action')) {
            add_action('admin_notices', [self::class, 'render_legacy_wp_notice']);
        }
    }

    /**
     * Emit the WP <7.0 dismissible notice. Public so the action
     * callback can resolve; not part of the contract beyond that.
     */
    public static function render_legacy_wp_notice(): void {
        $msg = __(
            'Gravity Tables Abilities API integration is inactive - your site is running WordPress &lt; 7.0. The plugin works exactly as before; AI tooling discovery via the platform layer activates automatically once WP 7.0 is installed.',
            'tc-data-tables'
        );
        echo '<div class="notice notice-info is-dismissible" data-gt-abilities-notice="1"><p>'
            . (function_exists('esc_html') ? esc_html($msg) : $msg)
            . '</p></div>';
    }

    /**
     * Does this ability require a premium license?
     * Reads `is_premium` from the canonical ability definitions so
     * the truth lives in one place (abilities()).
     */
    private static function ability_requires_premium(string $ability_id): bool {
        foreach (self::abilities() as $a) {
            if ($a['id'] === $ability_id) {
                return !empty($a['is_premium']);
            }
        }
        return false;
    }

    /**
     * Test-overrideable premium-license probe. Delegates to
     * `gt_is_premium()` in `includes/helpers-license.php` when
     * available; falls back to false (safest) when not loaded.
     */
    private static function is_premium(): bool {
        return function_exists('gt_is_premium') ? (bool) gt_is_premium() : false;
    }

    private static function handle_update_cell(array $args) {
        $entry_id = isset($args['entry_id']) ? (int) $args['entry_id'] : 0;
        $field_id = isset($args['field_id']) ? (string) $args['field_id'] : '';
        if ($entry_id <= 0 || $field_id === '') {
            return new \WP_Error('gt_ability_invalid_args', 'entry_id and field_id are required', ['status' => 400]);
        }
        if (!class_exists('GFAPI')) {
            return new \WP_Error('gt_ability_unavailable', 'Gravity Forms not loaded', ['status' => 503]);
        }
        $entry = \GFAPI::get_entry($entry_id);
        if (function_exists('is_wp_error') && is_wp_error($entry)) {
            return $entry;
        }
        if (!is_array($entry)) {
            return new \WP_Error('gt_ability_not_found', sprintf('Entry %d not found', $entry_id), ['status' => 404]);
        }
        $entry[$field_id] = isset($args['value']) ? (string) $args['value'] : '';
        $result = \GFAPI::update_entry($entry);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            return $result;
        }
        return ['entry_id' => $entry_id, 'field_id' => $field_id, 'updated' => true];
    }

    private static function handle_create_row(array $args) {
        $table_id = isset($args['table_id']) ? (int) $args['table_id'] : 0;
        if ($table_id <= 0) {
            return new \WP_Error('gt_ability_invalid_args', 'table_id is required', ['status' => 400]);
        }
        if (empty($args['fields']) || !is_array($args['fields'])) {
            return new \WP_Error('gt_ability_invalid_args', 'fields map is required', ['status' => 400]);
        }
        $admin = self::admin_instance();
        if ($admin === null) {
            // @codeCoverageIgnoreStart
            return new \WP_Error('gt_ability_unavailable', 'TC_Admin not loaded', ['status' => 503]);
            // @codeCoverageIgnoreEnd
        }
        $row = $admin->get_table($table_id);
        if (!$row) {
            return new \WP_Error('gt_ability_not_found', sprintf('Table %d not found', $table_id), ['status' => 404]);
        }
        if (!class_exists('GFAPI')) {
            return new \WP_Error('gt_ability_unavailable', 'Gravity Forms not loaded', ['status' => 503]);
        }
        $form_id = (int) ($row->form_id ?? 0);
        $entry   = ['form_id' => $form_id];
        foreach ($args['fields'] as $fid => $val) {
            $entry[(string) $fid] = (string) $val;
        }
        $new_id = \GFAPI::add_entry($entry);
        if (function_exists('is_wp_error') && is_wp_error($new_id)) {
            return $new_id;
        }
        return ['entry_id' => (int) $new_id, 'table_id' => $table_id, 'form_id' => $form_id];
    }

    private static function handle_delete_row(array $args) {
        $entry_id = isset($args['entry_id']) ? (int) $args['entry_id'] : 0;
        if ($entry_id <= 0) {
            return new \WP_Error('gt_ability_invalid_args', 'entry_id is required', ['status' => 400]);
        }
        if (!class_exists('GFAPI')) {
            return new \WP_Error('gt_ability_unavailable', 'Gravity Forms not loaded', ['status' => 503]);
        }
        // Soft-delete: move to trash via update_entry(['status' => 'trash'], $id).
        // Matches the issue description ("Soft-delete (trash) an entry") and the
        // existing pattern in includes/class-tc-import.php:401.
        $result = \GFAPI::update_entry(['status' => 'trash'], $entry_id);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            return $result;
        }
        return ['entry_id' => $entry_id, 'deleted' => true, 'soft' => true];
    }

    /**
     * Resolve a TC_Admin instance if the class is loaded. Returns null
     * in test / CLI environments where the plugin entrypoint hasn't
     * booted. Handlers degrade to WP_Error in that case rather than
     * fataling.
     */
    private static function admin_instance(): ?object {
        if (!class_exists('TC_Admin')) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        if (!method_exists('TC_Admin', 'get_instance')) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }
        return TC_Admin::get_instance();
    }

    private static function handle_list_tables(array $args) {
        $admin = self::admin_instance();
        if ($admin === null) {
            // @codeCoverageIgnoreStart
            return new \WP_Error('gt_ability_unavailable', 'TC_Admin not loaded', ['status' => 503]);
            // @codeCoverageIgnoreEnd
        }
        $rows = $admin->get_tables();
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'      => (int) ($row->id ?? 0),
                'title'   => (string) ($row->title ?? ''),
                'form_id' => (int) ($row->form_id ?? 0),
            ];
        }
        return $out;
    }

    private static function handle_get_table_schema(array $args) {
        $table_id = isset($args['table_id']) ? (int) $args['table_id'] : 0;
        if ($table_id <= 0) {
            return new \WP_Error('gt_ability_invalid_args', 'table_id is required', ['status' => 400]);
        }
        $admin = self::admin_instance();
        if ($admin === null) {
            // @codeCoverageIgnoreStart
            return new \WP_Error('gt_ability_unavailable', 'TC_Admin not loaded', ['status' => 503]);
            // @codeCoverageIgnoreEnd
        }
        $row = $admin->get_table($table_id);
        if (!$row) {
            return new \WP_Error('gt_ability_not_found', sprintf('Table %d not found', $table_id), ['status' => 404]);
        }
        $config        = $admin->get_table_config($table_id);
        $columns       = (isset($config['columns']) && is_array($config['columns'])) ? $config['columns'] : [];
        $column_labels = (isset($config['column_labels']) && is_array($config['column_labels'])) ? $config['column_labels'] : [];
        $schema        = [];
        foreach ($columns as $field_id) {
            $field_id_str = (string) $field_id;
            $schema[] = [
                'field_id' => $field_id_str,
                'label'    => isset($column_labels[$field_id_str]) ? (string) $column_labels[$field_id_str] : '',
            ];
        }
        return [
            'table_id' => $table_id,
            'title'    => (string) ($row->title ?? ''),
            'form_id'  => (int) ($row->form_id ?? 0),
            'columns'  => $schema,
        ];
    }

    private static function handle_query_rows(array $args) {
        $table_id = isset($args['table_id']) ? (int) $args['table_id'] : 0;
        if ($table_id <= 0) {
            return new \WP_Error('gt_ability_invalid_args', 'table_id is required', ['status' => 400]);
        }
        $admin = self::admin_instance();
        if ($admin === null) {
            // @codeCoverageIgnoreStart
            return new \WP_Error('gt_ability_unavailable', 'TC_Admin not loaded', ['status' => 503]);
            // @codeCoverageIgnoreEnd
        }
        $row = $admin->get_table($table_id);
        if (!$row) {
            return new \WP_Error('gt_ability_not_found', sprintf('Table %d not found', $table_id), ['status' => 404]);
        }
        if (!class_exists('GFAPI')) {
            return new \WP_Error('gt_ability_unavailable', 'Gravity Forms not loaded', ['status' => 503]);
        }
        $form_id = (int) ($row->form_id ?? 0);
        if ($form_id <= 0) {
            return new \WP_Error('gt_ability_invalid_args', 'Table is not bound to a Gravity Form', ['status' => 400]);
        }

        $per_page = isset($args['per_page']) ? max(1, min(200, (int) $args['per_page'])) : 25;
        $page     = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $offset   = ($page - 1) * $per_page;

        $sorting = [];
        if (!empty($args['sort'])) {
            $direction = (isset($args['order']) && strtolower((string) $args['order']) === 'asc') ? 'ASC' : 'DESC';
            $sorting   = ['key' => (string) $args['sort'], 'direction' => $direction];
        }

        $search_criteria = ['status' => 'active'];
        if (!empty($args['filter']) && is_array($args['filter'])) {
            $field_filters = [];
            foreach ($args['filter'] as $fid => $val) {
                $field_filters[] = ['key' => (string) $fid, 'value' => (string) $val];
            }
            if ($field_filters) {
                $search_criteria['field_filters'] = $field_filters;
            }
        }

        $entries = \GFAPI::get_entries($form_id, $search_criteria, $sorting, ['offset' => $offset, 'page_size' => $per_page]);
        if (function_exists('is_wp_error') && is_wp_error($entries)) {
            return $entries;
        }
        $total = \GFAPI::count_entries($form_id, $search_criteria);

        $rows = is_array($entries) ? array_values($entries) : [];

        // #795 / #806 - post-process rows for MCP / abilities consumers:
        //   - list fields: deserialise the serialised PHP blob into JSON
        //     so external clients see a real array.
        //   - creditcard fields: redact via TC_Creditcard_Field_Renderer
        //     so PAN sub-inputs N.1..N.5 NEVER ship over the wire.
        //     Defence in depth - even though get_entries already scrubs,
        //     the abilities path can be invoked independently.
        $needs_list = class_exists('TC_List_Field_Renderer') && !empty($rows);
        $needs_cc   = class_exists('TC_Creditcard_Field_Renderer') && !empty($rows);
        if (($needs_list || $needs_cc) && class_exists('\\GFAPI')) {
            $form = \GFAPI::get_form($form_id);
            if (is_array($form) && !empty($form['fields'])) {
                $list_field_ids = [];
                $cc_field_ids   = [];
                foreach ($form['fields'] as $f) {
                    if (!isset($f->type)) { continue; }
                    if ($f->type === 'list')       { $list_field_ids[] = (string) $f->id; }
                    if ($f->type === 'creditcard') { $cc_field_ids[]   = (string) $f->id; }
                }
                if ($list_field_ids || $cc_field_ids) {
                    foreach ($rows as $i => $row) {
                        if ($needs_list) {
                            foreach ($list_field_ids as $fid) {
                                if (isset($row[$fid]) && is_string($row[$fid]) && $row[$fid] !== '') {
                                    $rows[$i][$fid] = TC_List_Field_Renderer::unserialize_to_array($row[$fid]);
                                }
                            }
                        }
                        if ($needs_cc) {
                            foreach ($cc_field_ids as $fid) {
                                $rows[$i] = TC_Creditcard_Field_Renderer::redact_for_payload($rows[$i], $fid);
                            }
                        }
                    }
                }
            }
        }

        return [
            'table_id' => $table_id,
            'form_id'  => $form_id,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => is_int($total) ? $total : 0,
            'rows'     => $rows,
        ];
    }
}
