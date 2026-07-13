<?php
/**
 * REST API for Gravity Tables.
 *
 * Namespace: gravity-tables/v1
 *
 * Routes:
 *   GET    /tables                              List active tables
 *   GET    /tables/(?P<id>\d+)                  Get a single table config
 *   GET    /tables/(?P<id>\d+)/entries          List entries with filter/sort/page params
 *   POST   /tables/(?P<id>\d+)/entries          Create a new entry
 *   PUT    /tables/(?P<id>\d+)/entries/(?P<entry_id>\d+)   Update an entry
 *   DELETE /tables/(?P<id>\d+)/entries/(?P<entry_id>\d+)   Trash an entry
 *
 * Auth: WordPress cookie + REST nonce (default), or Application Passwords.
 * Permission callbacks reuse the table's allowed_user_roles + Gravity Forms caps.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_REST_API
{
    private static ?TC_REST_API $instance = null;
    private const NAMESPACE_V1 = 'gravity-tables/v1';

    public static function get_instance(): TC_REST_API
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes.
     *
     * AUDIT INVARIANT (#545): every `register_rest_route()` call below MUST
     * include a `permission_callback` set to one of:
     *   - `permission_read` - read-only routes (login required).
     *   - `permission_read_table` - read routes whose table-role allowlist
     *                                 is checked.
     *   - `permission_write_table` - write routes (login + table-role +
     *                                 strong capability such as edit_posts /
     *                                 publish_posts / gravityforms_edit_entries
     *                                 / administrator).
     *
     * Forbidden patterns:
     *   - `'permission_callback' => '__return_true'` - public-by-default routes
     *     are NOT permitted in this codebase. If a future route legitimately
     *     needs to be public, prefix the callsite with a `// PUBLIC_ROUTE_OK:`
     *     comment explaining why; the audit suite at
     *     `tests/test-issue-545-rest-permission-callback-guard.php` enforces
     *     this exception via allowlist marker.
     *   - `'permission_callback' => 'is_user_logged_in'` for any
     *     write-capable method (CREATABLE / EDITABLE / DELETABLE) - a bare
     *     login check is insufficient; require a capability.
     *
     * The audit suite static-scans every `register_rest_route()` call site
     * under `includes/` and fails the build if the invariant drifts.
     */
    public function register_routes(): void
    {
        $ns = self::NAMESPACE_V1;

        register_rest_route($ns, '/tables', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'list_tables'),
                'permission_callback' => array($this, 'permission_read'),
            ),
        ));

        register_rest_route($ns, '/tables/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_table'),
                'permission_callback' => array($this, 'permission_read'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                ),
            ),
        ));

        register_rest_route($ns, '/tables/(?P<id>\d+)/entries', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'list_entries'),
                'permission_callback' => array($this, 'permission_read_table'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                    'page' => array('type' => 'integer', 'default' => 1),
                    'per_page' => array('type' => 'integer', 'default' => 25),
                    'sort' => array('type' => 'string'),
                    'order' => array('type' => 'string', 'enum' => array('asc', 'desc'), 'default' => 'desc'),
                    'search' => array('type' => 'string'),
                    'filter' => array('type' => 'object'),
                ),
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_entry'),
                'permission_callback' => array($this, 'permission_write_table'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                    'fields' => array('type' => 'object', 'required' => true),
                ),
            ),
        ));

        register_rest_route($ns, '/tables/(?P<id>\d+)/entries/(?P<entry_id>\d+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_entry'),
                'permission_callback' => array($this, 'permission_write_table'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                    'entry_id' => array('type' => 'integer', 'required' => true),
                    'fields' => array('type' => 'object', 'required' => true),
                ),
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_entry'),
                'permission_callback' => array($this, 'permission_write_table'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                    'entry_id' => array('type' => 'integer', 'required' => true),
                ),
            ),
        ));
    }

    /* ---------------------------------------------------------------------
     * Permission callbacks
     * ------------------------------------------------------------------- */

    public function permission_read(): bool|WP_Error
    {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', __('Authentication required.', 'tc-data-tables'), array('status' => 401));
        }
        return true;
    }

    public function permission_read_table(WP_REST_Request $request): bool|WP_Error
    {
        $check = $this->permission_read();
        if (is_wp_error($check)) return $check;

        $table = $this->load_table_or_error((int) $request['id']);
        if (is_wp_error($table)) return $table;

        if (!$this->user_can_view_table($table)) {
            return new WP_Error('rest_forbidden', __('You cannot view this table.', 'tc-data-tables'), array('status' => 403));
        }

        // #1632 - enforce the per-table password the same way the
        // shortcode does. Without this the password feature was bypassable
        // by hitting the REST data endpoints directly.
        $settings = json_decode($table->settings, true) ?: array();
        $pw_hash  = isset($settings['table_password_hash']) ? (string) $settings['table_password_hash'] : '';
        if ($pw_hash !== '' && class_exists('TC_Table_Password_Service')
            && !TC_Table_Password_Service::request_is_unlocked((int) $request['id'], $pw_hash)) {
            return new WP_Error('rest_forbidden', __('This table is password protected.', 'tc-data-tables'), array('status' => 401));
        }
        return true;
    }

    public function permission_write_table(WP_REST_Request $request): bool|WP_Error
    {
        $check = $this->permission_read_table($request);
        if (is_wp_error($check)) return $check;

        // #1635 - gate writes on the plugin's dedicated capabilities
        // instead of edit_posts (held by Contributors). DELETE requires
        // delete_gravity_tables; POST/PUT require edit_gravity_tables.
        // Both are admin-only by default and admin-grantable per role.
        $is_delete = strtoupper((string) $request->get_method()) === 'DELETE';
        $required  = $is_delete ? 'delete_gravity_tables' : 'edit_gravity_tables';
        if (!class_exists('TC_Capabilities_Service')
            || !TC_Capabilities_Service::current_user_can($required)) {
            return new WP_Error('rest_forbidden', __('You cannot modify entries.', 'tc-data-tables'), array('status' => 403));
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * Route handlers
     * ------------------------------------------------------------------- */

    public function list_tables(WP_REST_Request $request): WP_REST_Response
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $rows = $wpdb->get_results("SELECT id, title, form_id, created_at, updated_at FROM {$wpdb->prefix}gravity_tables WHERE status = 'active' ORDER BY id ASC");

        $out = array();
        foreach ((array) $rows as $row) {
            // Hide tables the current user can't view
            $full = $this->load_table_or_error((int) $row->id);
            if (is_wp_error($full)) continue;
            if (!$this->user_can_view_table($full)) continue;

            $out[] = array(
                'id' => (int) $row->id,
                'title' => $row->title,
                'form_id' => (int) $row->form_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            );
        }

        return new WP_REST_Response($out, 200);
    }

    public function get_table(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $table = $this->load_table_or_error((int) $request['id']);
        if (is_wp_error($table)) return $table;
        if (!$this->user_can_view_table($table)) {
            return new WP_Error('rest_forbidden', __('You cannot view this table.', 'tc-data-tables'), array('status' => 403));
        }

        $settings = json_decode($table->settings, true) ?: array();

        // #1076 finding #3 - flip from deny-list (unset of known sensitive keys)
        // to allow-list (keep only documented safe keys). Drift-prone before:
        // any new sensitive field added to settings JSON leaked by default.
        // Allow-list fails closed for unknown future keys; sensitive keys are
        // ALWAYS stripped even if a filter callback tries to opt them in.
        // See includes/helpers-secrets.php :: gt_rest_filter_safe_settings().
        $safe_settings = function_exists('gt_rest_filter_safe_settings')
            ? gt_rest_filter_safe_settings($settings)
            // @codeCoverageIgnoreStart
            : $settings;
            // @codeCoverageIgnoreEnd

        return new WP_REST_Response(array(
            'id' => (int) $table->id,
            'title' => $table->title,
            'form_id' => (int) $table->form_id,
            'columns' => $settings['columns'] ?? array(),
            'column_labels' => $settings['column_labels'] ?? array(),
            'settings' => $safe_settings,
        ), 200);
    }

    public function list_entries(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $table = $this->load_table_or_error((int) $request['id']);
        if (is_wp_error($table)) return $table;

        $form_id = (int) $table->form_id;
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(200, max(1, (int) $request->get_param('per_page')));
        $sort = (string) ($request->get_param('sort') ?? 'date_created');
        $order = strtoupper((string) ($request->get_param('order') ?? 'desc'));
        $search = (string) ($request->get_param('search') ?? '');
        $filter = (array) ($request->get_param('filter') ?? array());

        if (!class_exists('GFAPI')) {
            return new WP_Error('gf_required', __('Gravity Forms not active', 'tc-data-tables'), array('status' => 500));
        }

        $search_criteria = array('status' => 'active');
        if ($search !== '') {
            $search_criteria['field_filters'][] = array('key' => 'any', 'operator' => 'contains', 'value' => $search);
        }
        foreach ($filter as $field_id => $value) {
            if ($value === '' || $value === null) continue;
            $search_criteria['field_filters'][] = array(
                'key' => (string) $field_id,
                'operator' => 'is',
                'value' => (string) $value,
            );
        }

        $sorting = array('key' => $sort, 'direction' => in_array($order, array('ASC', 'DESC'), true) ? $order : 'DESC');
        $paging = array('page_size' => $per_page, 'current_page' => $page);

        $total = GFAPI::count_entries($form_id, $search_criteria);
        $entries = GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging);
        if (is_wp_error($entries)) {
            return new WP_Error('gf_error', $entries->get_error_message(), array('status' => 500));
        }

        $response = new WP_REST_Response(array_values($entries), 200);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per_page)));
        return $response;
    }

    public function create_entry(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $table = $this->load_table_or_error((int) $request['id']);
        if (is_wp_error($table)) return $table;

        $fields = (array) ($request->get_param('fields') ?? array());
        if (empty($fields)) {
            return new WP_Error('missing_fields', __('fields is required', 'tc-data-tables'), array('status' => 400));
        }

        // Use the `+` union operator (left-hand wins) instead of array_merge
        // to preserve numeric-string field keys. array_merge() re-canonicalises
        // numeric-string keys to ints AND reindexes them, so a client posting
        // `fields={"5":"foo"}` would land the value at key 0 rather than the GF
        // field id 5. See #1168.
        $entry = $this->normalize_fields($fields) + array(
            'form_id' => (int) $table->form_id,
            'status' => 'active',
            'created_by' => get_current_user_id(),
        );

        $entry_id = GFAPI::add_entry($entry);
        if (is_wp_error($entry_id)) {
            return new WP_Error('create_failed', $entry_id->get_error_message(), array('status' => 400));
        }

        do_action('gravity_tables_entry_updated', (int) $entry_id, (int) $table->form_id, 'created', array());

        $created = GFAPI::get_entry($entry_id);
        return new WP_REST_Response($created, 201);
    }

    public function update_entry(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $table = $this->load_table_or_error((int) $request['id']);
        if (is_wp_error($table)) return $table;

        $entry_id = (int) $request['entry_id'];
        $existing = GFAPI::get_entry($entry_id);
        if (is_wp_error($existing) || !$existing) {
            return new WP_Error('not_found', __('Entry not found', 'tc-data-tables'), array('status' => 404));
        }
        if ((int) $existing['form_id'] !== (int) $table->form_id) {
            return new WP_Error('mismatch', __('Entry does not belong to this table', 'tc-data-tables'), array('status' => 400));
        }

        $fields = (array) ($request->get_param('fields') ?? array());
        if (empty($fields)) {
            return new WP_Error('missing_fields', __('fields is required', 'tc-data-tables'), array('status' => 400));
        }

        $changes = array();
        foreach ($this->normalize_fields($fields) as $key => $val) {
            $old = $existing[$key] ?? null;
            $result = GFAPI::update_entry_field($entry_id, $key, $val);
            if (is_wp_error($result)) {
                return new WP_Error('update_failed', $result->get_error_message(), array('status' => 500));
            }
            if ((string) $old !== (string) $val) {
                $changes[(string) $key] = array('old' => $old, 'new' => (string) $val);
            }
        }

        do_action('gravity_tables_entry_updated', $entry_id, (int) $table->form_id, 'updated', $changes);

        $fresh = GFAPI::get_entry($entry_id);
        return new WP_REST_Response($fresh, 200);
    }

    public function delete_entry(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $table = $this->load_table_or_error((int) $request['id']);
        if (is_wp_error($table)) return $table;

        $entry_id = (int) $request['entry_id'];
        $existing = GFAPI::get_entry($entry_id);
        if (is_wp_error($existing) || !$existing) {
            return new WP_Error('not_found', __('Entry not found', 'tc-data-tables'), array('status' => 404));
        }
        if ((int) $existing['form_id'] !== (int) $table->form_id) {
            return new WP_Error('mismatch', __('Entry does not belong to this table', 'tc-data-tables'), array('status' => 400));
        }

        $result = GFAPI::delete_entry($entry_id);
        if (is_wp_error($result)) {
            return new WP_Error('delete_failed', $result->get_error_message(), array('status' => 500));
        }

        do_action('gravity_tables_entry_updated', $entry_id, (int) $table->form_id, 'deleted', array());

        return new WP_REST_Response(array('deleted' => true, 'entry_id' => $entry_id), 200);
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    private function load_table_or_error(int $id)
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $id
        ));
        if (!$row) {
            return new WP_Error('not_found', __('Table not found', 'tc-data-tables'), array('status' => 404));
        }
        return $row;
    }

    private function user_can_view_table(object $table): bool
    {
        $settings = json_decode($table->settings, true) ?: array();
        $allowed = (array) ($settings['allowed_user_roles'] ?? array());
        if (empty($allowed)) return true;

        $user = wp_get_current_user();
        if (!$user || !$user->ID) return false;
        if (in_array('administrator', (array) $user->roles, true)) return true;

        foreach ((array) $user->roles as $role) {
            if (in_array($role, $allowed, true)) return true;
        }
        return false;
    }

    private function normalize_fields(array $fields): array
    {
        // Keys may arrive as integers (JSON object); coerce to string keys consistently
        $out = array();
        foreach ($fields as $k => $v) {
            $out[(string) $k] = is_scalar($v) ? (string) $v : wp_json_encode($v);
        }
        return $out;
    }
}
