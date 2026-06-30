<?php
/**
 * Admin functionality for Gravity Tables
 * 
 * Manages the WordPress admin interface for creating and configuring tables.
 * Provides the table builder interface, settings management, and bulk operations.
 * 
 * Features include drag-and-drop column configuration, live preview,
 * role-based access control, and comprehensive table management.
 *
 * @package GravityTables
 * @author Fahad Murtaza <business@isupercoder.com>
 * @since 1.0.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
#[\AllowDynamicProperties]
class TC_Admin
{

    // #667 slice 3 — public so tests can reset the singleton between
    // it() blocks via `TC_Admin::$instance = null;` (matches the
    // visibility of the test-stub class). Production code reads /
    // writes this property exclusively via self::get_instance(); no
    // external caller depends on it being private (verified via grep
    // across includes/ and admin/).
    public static ?TC_Admin $instance = null;

    // #667 slice 28 — PHPUnit-shim fixture knobs (issue #1087).
    //
    // tests/test-issue-90-safe-save.php drives save_table() failure-mode
    // assertions by setting these statics directly on TC_Admin. Under
    // tools/test-all.sh the test's own redeclared TC_Admin class wins
    // and owns the props. Under the PHPUnit shim, tests/bootstrap.php
    // loads production TC_Admin first, the test's class-redeclaration
    // becomes a no-op via class_exists guard, and writes to the static
    // props target THIS class. Declaring them here lets those writes
    // succeed without "Access to undeclared static property" fatals.
    //
    // Production callers NEVER read these props — the seamed
    // get_instance() routes save_table() calls through the test-installed
    // override instance ($GLOBALS['gt_test_admin_override']), which is
    // the only consumer of the static state. Defaults are deliberately
    // neutral (false / empty / false) so a stray production read in the
    // future would be safe-by-default.
    //
    // Contract pinned by tests/GTAdminLoggerSeamTest.php.
    public static bool $should_throw        = false;
    public static string $throw_message     = '';
    public static bool $should_return_false = false;

    /**
     * #667 slice 28 — the explicit `: TC_Admin` return-type declaration was
     * dropped to make room for the test-override path (issue #1087). Under
     * the PHPUnit shim, get_instance() can return an anonymous-class fake
     * installed by tests/test-issue-90-safe-save.php; an enforced return
     * type would TypeError on that fake. Production callers still receive
     * a `TC_Admin` instance byte-identically — the return-type relaxation
     * is a runtime no-op for them, and PHPStan / IDEs can still infer the
     * production-path return type from the `self::$instance` write below.
     */
    public static function get_instance()
    {
        // #667 slice 28 — PHPUnit-shim test seam (issue #1087).
        //
        // Production safety: this branch is gated on the TC_PHPUNIT_SHIM
        // constant which is ONLY defined by tests/PHPUnitShimTest.php and
        // tests/bootstrap.php. Production WordPress never defines that
        // constant; production callers fall through to the byte-identical
        // pre-slice singleton path below.
        //
        // Why the seam exists: under the PHPUnit shim, bootstrap.php loads
        // the plugin, which declares this production TC_Admin class. The
        // class_exists guard in test-issue-90 then skips its own redeclared
        // stub. The test installs an override instance into
        // $GLOBALS['gt_test_admin_override']; this gate routes
        // get_instance() callers to it so save_table() failure paths can
        // be exercised against fixture-controlled flags.
        //
        // Contract pinned by tests/GTAdminLoggerSeamTest.php.
        if (defined('TC_PHPUNIT_SHIM')
            && array_key_exists('gt_test_admin_override', $GLOBALS)
            && $GLOBALS['gt_test_admin_override'] !== null
        ) {
            return $GLOBALS['gt_test_admin_override'];
        }

        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'), 15);
        add_action('admin_menu', array($this, 'add_debug_logs_menu'), 20);
        add_action('admin_menu', array($this, 'add_documentation_menu'), 25);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_notices', array($this, 'render_highlights_notice'));
        add_action('admin_notices', array($this, 'render_account_page_header'));
        add_filter('admin_body_class', array($this, 'add_account_page_body_class'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_init', array($this, 'redirect_legacy_slugs'));
        add_filter('parent_file', array($this, 'fix_menu_highlighting'));
        add_filter('submenu_file', array($this, 'fix_submenu_highlighting'));
        // Classic (TinyMCE) editor integration — add "Insert Gravity Table" toolbar button (#129)
        add_filter('mce_external_plugins', array($this, 'register_tinymce_plugin'));
        add_filter('mce_buttons', array($this, 'add_tinymce_button'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        // Late reorder: All Tables registers first (removes the WP auto-generated
        // "TableCrafter" duplicate), then we swap it with Dashboard so the visible
        // order is Dashboard → All Tables → Create New → Wizard.
        add_action('admin_menu', array($this, 'reorder_submenu_dashboard_first'), 999);
    }

    public function register_tinymce_plugin(array $plugins): array
    {
        $plugins['gravity_tables'] = TC_PLUGIN_URL . 'assets/js/gt-tinymce.js';
        return $plugins;
    }

    public function add_tinymce_button(array $buttons): array
    {
        $buttons[] = 'gravity_tables';
        return $buttons;
    }

    public function render_highlights_notice(): void
    {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (strpos($page, 'gravity-tables') === false && strpos($page, 'tablecrafter') === false) {
            return;
        }
        $user_id = get_current_user_id();
        if (!$user_id || get_user_meta($user_id, 'gt_v8_highlights_dismissed', true)) {
            return;
        }
        $nonce    = wp_create_nonce('gt_dismiss_v8_highlights');
        $ajax_url = admin_url('admin-ajax.php');
        $docs_url = esc_url(admin_url('admin.php?page=gravity-tables-docs#whats-new'));
        ?>
        <div class="notice notice-info is-dismissible gt-v8-highlights-notice"
             data-gt-dismiss-nonce="<?php echo esc_attr($nonce); ?>"
             style="border-left-color:#0073aa;">
            <p>
                <strong><?php esc_html_e("What's new in TableCrafter v8.x", 'tc-data-tables'); ?></strong>
                —
                <?php esc_html_e('One converged, source-agnostic product: Gravity Forms, JSON / REST, CSV, Google Sheets, Airtable, Notion, External DB and Excel sources, one-click demo tables, External DB connection management, and the [tablecrafter] block.', 'tc-data-tables'); ?>
                <a href="<?php echo $docs_url; ?>"><?php esc_html_e('View highlights →', 'tc-data-tables'); ?></a>
            </p>
        </div>
        <script>
        (function () {
            var notice = document.querySelector('.gt-v8-highlights-notice');
            if (!notice) return;
            notice.addEventListener('click', function (e) {
                if (!e.target.classList.contains('notice-dismiss')) return;
                var form = new FormData();
                form.append('action', 'gt_dismiss_v8_highlights');
                form.append('nonce', notice.getAttribute('data-gt-dismiss-nonce'));
                fetch(<?php echo wp_json_encode($ajax_url); ?>, {
                    method: 'POST', body: form, credentials: 'same-origin'
                }).catch(function () {});
            });
        }());
        </script>
        <?php
    }

    public function add_account_page_body_class(string $classes): string
    {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ('gravity-tables-account' === $page) {
            $classes .= ' gt-account-page';
        }
        return $classes;
    }

    public function render_account_page_header(): void
    {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ('gravity-tables-account' !== $page) {
            return;
        }
        $is_premium  = function_exists('gt_is_premium') && gt_is_premium();
        $plan_label  = $is_premium ? __('Pro', 'tc-data-tables') : __('Free', 'tc-data-tables');
        $badge_class = $is_premium ? 'gt-acct-badge--pro' : 'gt-acct-badge--free';
        ?>
        <div class="gt-acct-header">
            <div class="gt-acct-header-inner">
                <span class="dashicons <?php echo $is_premium ? 'dashicons-awards' : 'dashicons-admin-users'; ?> gt-acct-header-icon"></span>
                <div>
                    <h1 class="gt-acct-header-title"><?php esc_html_e('TableCrafter Account', 'tc-data-tables'); ?></h1>
                    <p class="gt-acct-header-sub"><?php esc_html_e('Manage your license, billing, and payment history.', 'tc-data-tables'); ?></p>
                </div>
                <span class="gt-acct-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($plan_label); ?></span>
            </div>
        </div>
        <style>
        /* Make the Freemius account-page action buttons readable + on-brand
           (default rendered green text on a blue button — low contrast). */
        .gt-account-page .button-primary,
        .gt-account-page a.button-primary {
            background: #0d9488 !important; border-color: #0d9488 !important;
            color: #fff !important; text-shadow: none !important; box-shadow: none !important;
        }
        .gt-account-page .button-primary:hover { background: #0f766e !important; border-color: #0f766e !important; color: #fff !important; }
        .gt-account-page .button-primary .dashicons,
        .gt-account-page .button-primary i { color: #fff !important; }
        </style>
        <?php
    }

    public function add_admin_menu(): void
    {
        // Main menu page
        add_menu_page(
            __('TableCrafter', 'tc-data-tables'),
            __('TableCrafter', 'tc-data-tables'),
            'manage_options',
            'gravity-tables',
            array($this, 'admin_page_tables'),
            'dashicons-grid-view',
            30
        );

        // All Tables first — replaces the auto-generated duplicate "TableCrafter" entry
        add_submenu_page(
            'gravity-tables',
            __('All Tables', 'tc-data-tables'),
            __('All Tables', 'tc-data-tables'),
            'manage_options',
            'gravity-tables',
            array($this, 'admin_page_tables')
        );

        add_submenu_page(
            'gravity-tables',
            __('Dashboard', 'tc-data-tables'),
            __('Dashboard', 'tc-data-tables'),
            'manage_options',
            'tablecrafter-dashboard',
            array($this, 'admin_page_dashboard')
        );

        // Dynamic menu title based on free plan limits
        // #2025 — size caps removed: the free plan has unlimited tables, so the
        // menu no longer shows a "(count/limit)" suffix or "(Limit Reached)".
        $create_menu_title = __('Create New', 'tc-data-tables');

        add_submenu_page(
            'gravity-tables',
            __('Create New Table', 'tc-data-tables'),
            $create_menu_title,
            'manage_options',
            'gravity-tables-new',
            array($this, 'admin_page_new_table')
        );


        // Wizard — visible in nav, positioned after Create New.
        add_submenu_page(
            'gravity-tables',
            __('Create Table — Wizard (Beta)', 'tc-data-tables'),
            __('✦ Wizard', 'tc-data-tables') . ' <span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;background:#0d9488;color:#fff;border-radius:6px;padding:1px 5px;vertical-align:middle;">Beta</span>',
            'manage_options',
            'tablecrafter-wizard',
            array($this, 'admin_page_wizard')
        );

        add_submenu_page(
            'gravity-tables',
            __('Settings', 'tc-data-tables'),
            __('Settings', 'tc-data-tables'),
            'manage_options',
            'gravity-tables-settings',
            array($this, 'admin_page_settings')
        );

        // #2040 — External DB connection management (Pro). Gated on the premium
        // class so the free WP.org build (which strips it) never registers it.
        if (class_exists('TC_External_DB')) {
            add_submenu_page(
                'gravity-tables',
                __('Database Connections', 'tc-data-tables'),
                __('Database Connections', 'tc-data-tables'),
                'manage_options',
                'gravity-tables-db-connections',
                array($this, 'admin_page_db_connections')
            );
        }

        // Add License & Account page (always visible)
        add_submenu_page(
            'gravity-tables',
            __('License & Account', 'tc-data-tables'),
            __('License & Account', 'tc-data-tables'),
            'manage_options',
            'gravity-tables-license',
            array($this, 'admin_page_license')
        );

        // #972 v4.161.0 — Trash admin tab (phase 1c of #593 trash bin).
        add_submenu_page(
            'gravity-tables',
            __('Trash', 'tc-data-tables'),
            __('Trash', 'tc-data-tables'),
            'manage_options',
            'gravity-tables-trash',
            array($this, 'admin_page_trash')
        );

    }

    public function add_admin_bar_menu( \WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'gt_manage_tables' ) ) {
            return;
        }

        $wp_admin_bar->add_node( [
            'id'    => 'tablecrafter',
            'title' => '<span class="ab-icon dashicons-grid-view" style="font:400 18px/1 dashicons;vertical-align:middle;margin-right:4px;"></span>' . __( 'TableCrafter', 'tc-data-tables' ),
            'href'  => admin_url( 'admin.php?page=gravity-tables' ),
            'meta'  => [ 'title' => __( 'TableCrafter', 'tc-data-tables' ) ],
        ] );

        $wp_admin_bar->add_node( [
            'parent' => 'tablecrafter',
            'id'     => 'tablecrafter-all-tables',
            'title'  => __( 'All Tables', 'tc-data-tables' ),
            'href'   => admin_url( 'admin.php?page=gravity-tables' ),
        ] );

        $wp_admin_bar->add_node( [
            'parent' => 'tablecrafter',
            'id'     => 'tablecrafter-dashboard-bar',
            'title'  => __( 'Dashboard', 'tc-data-tables' ),
            'href'   => admin_url( 'admin.php?page=tablecrafter-dashboard' ),
        ] );

        $wp_admin_bar->add_node( [
            'parent' => 'tablecrafter',
            'id'     => 'tablecrafter-create-new',
            'title'  => __( 'Create New', 'tc-data-tables' ),
            'href'   => admin_url( 'admin.php?page=gravity-tables-new' ),
        ] );

        $wp_admin_bar->add_node( [
            'parent' => 'tablecrafter',
            'id'     => 'tablecrafter-wizard-bar',
            'title'  => '✦ ' . __( 'Wizard (Beta)', 'tc-data-tables' ),
            'href'   => admin_url( 'admin.php?page=tablecrafter-wizard' ),
        ] );
    }

    public function reorder_submenu_dashboard_first(): void {
        global $submenu;
        if ( empty( $submenu['gravity-tables'] ) ) {
            return;
        }
        $all_tables_idx = null;
        $dashboard_idx  = null;
        foreach ( $submenu['gravity-tables'] as $i => $item ) {
            if ( isset( $item[2] ) && $item[2] === 'gravity-tables' )        { $all_tables_idx = $i; }
            if ( isset( $item[2] ) && $item[2] === 'tablecrafter-dashboard' ) { $dashboard_idx  = $i; }
        }
        if ( $all_tables_idx !== null && $dashboard_idx !== null ) {
            $tmp = $submenu['gravity-tables'][ $all_tables_idx ];
            $submenu['gravity-tables'][ $all_tables_idx ] = $submenu['gravity-tables'][ $dashboard_idx ];
            $submenu['gravity-tables'][ $dashboard_idx ]  = $tmp;
        }
    }

    public function add_debug_logs_menu(): void
    {
        add_submenu_page(
            'gravity-tables',
            __('Debug Logs', 'tc-data-tables'),
            __('Debug Logs', 'tc-data-tables'),
            'manage_options',
            'gravity-tables-debug-logs',
            array($this, 'admin_page_debug_logs')
        );

        // #613 phase 2 v4.223.0 — Push Audit Log + Rate-limit status page.
        // Surfaces TC_Push_Audit_Log_Service (v4.205.0) events and
        // TC_Push_Rate_Limiter (v4.206.0) per-source caps for operators.
        add_submenu_page(
            'gravity-tables',
            __('Push Audit Log', 'tc-data-tables'),
            __('Push Audit Log', 'tc-data-tables'),
            'manage_options',
            'gravity-tables-push-audit',
            array($this, 'admin_page_push_audit')
        );
    }

    /**
     * #613 phase 2 v4.223.0 — push audit log admin page callback.
     */
    public function admin_page_push_audit(): void
    {
        $view = TC_PLUGIN_PATH . 'admin/views/push-audit-log.php';
        if (file_exists($view)) {
            include $view;
        }
    }

    public function add_documentation_menu(): void
    {
        add_submenu_page(
            'gravity-tables',
            __('Documentation', 'tc-data-tables'),
            __('Documentation', 'tc-data-tables'),
            'manage_options',
            'gravity-tables-docs',
            array($this, 'admin_page_docs')
        );
    }

    public function enqueue_admin_scripts(string $hook): void
    {
        if (strpos($hook, 'gravity-tables') === false && strpos($hook, 'tablecrafter') === false) {
            return;
        }

        // #2040 — Database Connections management screen (Pro only). Self-contained
        // jQuery script wiring add/delete/test against TC_External_DB ajax handlers.
        if (strpos($hook, 'gravity-tables-db-connections') !== false && class_exists('TC_External_DB')) {
            wp_enqueue_script(
                'gravity-tables-db-connections',
                TC_PLUGIN_URL . 'admin/js/db-connections.js',
                array('jquery'),
                TC_VERSION,
                true
            );
            wp_localize_script('gravity-tables-db-connections', 'gtDbConn', array(
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'manageNonce'  => wp_create_nonce('gt_manage_db_connections'),
                'testNonce'    => wp_create_nonce('gt_test_db_connection'),
                'i18nConfirm'  => __('Delete this connection? Tables using it will stop returning rows.', 'tc-data-tables'),
                'i18nTesting'  => __('Testing…', 'tc-data-tables'),
                'i18nSaving'   => __('Saving…', 'tc-data-tables'),
            ));
        }

        // SortableJS replaces jquery-ui-sortable/draggable/droppable for the
        // table builder's field reordering. The rest of admin.js still uses
        // jQuery, so we keep that dependency until a full vanilla migration.
        wp_enqueue_script('jquery');

        // Freemius / WP admin pointers expect jQuery.fn.pointer (wp-pointer script + styles).
        wp_enqueue_style('wp-pointer');

        wp_register_script(
            'gt-sortable',
            TC_PLUGIN_URL . 'admin/js/sortable.min.js',
            array(),
            '1.15.2',
            true
        );

        wp_enqueue_script(
            'gravity-tables-admin',
            TC_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery', 'wp-pointer', 'gt-sortable'),
            TC_VERSION,
            true
        );

        // #843 — first slice of admin.js split. Loads AFTER admin.js and
        // extends window.TC_TableBuilder via Object.assign.
        wp_enqueue_script(
            'gravity-tables-admin-core',
            TC_PLUGIN_URL . 'assets/js/admin/core.js',
            array('gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // #844 — second slice. Drag-and-drop column reorder + row reorder.
        // #1027 — depend on -admin-core so window.TC_TableBuilder.init is
        // guaranteed to be defined before this slice's Object.assign runs.
        wp_enqueue_script(
            'gravity-tables-admin-table-builder',
            TC_PLUGIN_URL . 'assets/js/admin/table-builder.js',
            array('gravity-tables-admin', 'gravity-tables-admin-core', 'gt-sortable'),
            TC_VERSION,
            true
        );

        // #845 — third slice. Per-field configuration modal lifecycle.
        wp_enqueue_script(
            'gravity-tables-admin-field-config-modal',
            TC_PLUGIN_URL . 'assets/js/admin/field-config-modal.js',
            array('gravity-tables-admin', 'gravity-tables-admin-core'),
            TC_VERSION,
            true
        );

        // #846 — fourth slice. Conditional formatting + color picker helpers.
        wp_enqueue_script(
            'gravity-tables-admin-conditional-format-rules',
            TC_PLUGIN_URL . 'assets/js/admin/conditional-format-rules.js',
            array('gravity-tables-admin', 'gravity-tables-admin-core'),
            TC_VERSION,
            true
        );

        // #954 — fifth slice. saveTable AJAX flow.
        wp_enqueue_script(
            'gravity-tables-admin-save-table',
            TC_PLUGIN_URL . 'assets/js/admin/save-table.js',
            array('gravity-tables-admin', 'gravity-tables-admin-core'),
            TC_VERSION,
            true
        );

        // #955 — sixth slice. loadTableData + applySavedSettings (load-side AJAX flow).
        wp_enqueue_script(
            'gravity-tables-admin-load-table-data',
            TC_PLUGIN_URL . 'assets/js/admin/load-table-data.js',
            array('gravity-tables-admin', 'gravity-tables-admin-core'),
            TC_VERSION,
            true
        );

        // #957 — seventh slice. Field-selection UI cluster.
        wp_enqueue_script(
            'gravity-tables-admin-field-list',
            TC_PLUGIN_URL . 'assets/js/admin/field-list.js',
            array('gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // #959 — eighth slice. Live preview generation + shortcode helpers.
        wp_enqueue_script(
            'gravity-tables-admin-preview-shortcode',
            TC_PLUGIN_URL . 'assets/js/admin/preview-and-shortcode.js',
            array('gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // #964 — ninth slice. bindEvents event delegator (the big one).
        wp_enqueue_script(
            'gravity-tables-admin-bind-events',
            TC_PLUGIN_URL . 'assets/js/admin/bind-events.js',
            array('gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // #966 — TENTH and FINAL slice. Misc utilities + initViewportToggles bug fix.
        // After this, admin.js is just IIFE boot scaffolding (~166 lines vs 3,464 original).
        wp_enqueue_script(
            'gravity-tables-admin-utilities',
            TC_PLUGIN_URL . 'assets/js/admin/utilities.js',
            array('gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // #1601 — builder Data Quality panel (cleanup-suggester UI).
        wp_enqueue_script(
            'gravity-tables-admin-data-quality',
            TC_PLUGIN_URL . 'assets/js/admin/data-quality.js',
            array('jquery', 'gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // #1598 — builder Computed Columns repeater.
        wp_enqueue_script(
            'gravity-tables-admin-computed-columns',
            TC_PLUGIN_URL . 'assets/js/admin/computed-columns.js',
            array('jquery', 'gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // #1615 — Table History modal on the tables list.
        wp_enqueue_script(
            'gravity-tables-admin-revisions-modal',
            TC_PLUGIN_URL . 'assets/js/admin/revisions-modal.js',
            array('jquery'),
            TC_VERSION,
            true
        );
        wp_localize_script('gravity-tables-admin-revisions-modal', 'gtRevisions', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'admin_post'  => admin_url('admin-post.php'),
            'builder_url' => admin_url('admin.php?page=gravity-tables-new'),
        ));

        // #1614 — Find & Replace modal on the tables list.
        wp_enqueue_script(
            'gravity-tables-admin-find-replace-modal',
            TC_PLUGIN_URL . 'assets/js/admin/find-replace-modal.js',
            array('jquery'),
            TC_VERSION,
            true
        );
        wp_localize_script('gravity-tables-admin-find-replace-modal', 'gtFindReplace', array(
            'ajax_url' => admin_url('admin-ajax.php'),
        ));

        // #1617 — builder pivot multi-aggregate repeater.
        wp_enqueue_script(
            'gravity-tables-admin-pivot-aggregates',
            TC_PLUGIN_URL . 'assets/js/admin/pivot-aggregates.js',
            array('jquery', 'gravity-tables-admin'),
            TC_VERSION,
            true
        );

        // Auto-save draft state in the admin editor (#455).
        wp_enqueue_script(
            'gt-admin-autosave',
            TC_PLUGIN_URL . 'admin/js/gt-admin-autosave.js',
            array(),
            TC_VERSION,
            true
        );

        $autosave_table_id = isset($_GET['table_id']) ? intval($_GET['table_id']) : 0;
        $autosave_updated_at = '';
        if ($autosave_table_id > 0) {
            global $wpdb;
            $autosave_updated_at = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT updated_at FROM {$wpdb->prefix}gravity_tables WHERE id = %d",
                $autosave_table_id
            ));
        }
        wp_localize_script('gt-admin-autosave', 'gtAdminAutosaveData', array(
            'tableId'         => $autosave_table_id,
            'intervalMs'      => (int) apply_filters('gravity_tables_autosave_interval_ms', 60000),
            'serverUpdatedAt' => $autosave_updated_at,
        ));

        wp_enqueue_style(
            'gravity-tables-admin',
            TC_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            TC_VERSION
        );

        if ( isset( $_GET['page'] ) && 'tablecrafter-dashboard' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_enqueue_style(
                'gravity-tables-admin-dashboard',
                TC_PLUGIN_URL . 'assets/css/admin-dashboard.css',
                array( 'gravity-tables-admin' ),
                TC_VERSION
            );
        }

        if ( isset( $_GET['page'] ) && 'tablecrafter-wizard' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_enqueue_style(
                'gt-admin-wizard',
                TC_PLUGIN_URL . 'admin/css/admin-wizard.css',
                array( 'gravity-tables-admin', 'dashicons' ),
                TC_VERSION
            );
            wp_enqueue_script(
                'gt-admin-wizard',
                TC_PLUGIN_URL . 'admin/js/admin-wizard.js',
                array(),
                TC_VERSION,
                true
            );
            wp_add_inline_script(
                'gt-admin-wizard',
                'var gtWizardData = ' . wp_json_encode( array(
                    'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                    'nonce'      => wp_create_nonce( 'gt_admin_nonce' ),
                    'builderUrl' => admin_url( 'admin.php?page=gravity-tables-new' ),
                ) ) . ';',
                'before'
            );
        }

        if ( isset( $_GET['page'] ) && 'gravity-tables-docs' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_enqueue_style(
                'gt-admin-docs',
                TC_PLUGIN_URL . 'admin/css/admin-docs.css',
                array( 'gravity-tables-admin', 'dashicons' ),
                TC_VERSION
            );
            wp_enqueue_script(
                'gt-admin-docs',
                TC_PLUGIN_URL . 'admin/js/admin-docs.js',
                array(),
                TC_VERSION,
                true
            );
            wp_add_inline_script(
                'gt-admin-docs',
                'window.gtDocsData = ' . wp_json_encode( array( 'version' => TC_VERSION ) ) . ';',
                'before'
            );
        }

        wp_enqueue_style(
            'gravity-tables-builder',
            TC_PLUGIN_URL . 'admin/css/table-builder.css',
            array(),
            TC_VERSION
        );

        $gt_admin_l10n = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gt_admin_nonce'),
            // #1601 — Data Quality panel nonces: dedicated scan nonce +
            // the table nonce the gt_update_entry apply path expects.
            'ai_cleanup_nonce' => wp_create_nonce('gt_ai_cleanup_suggest'),
            // #1621 — computed-columns inline formula validation.
            'cc_validate_nonce' => wp_create_nonce('gt_cc_validate_formula'),
            'entry_update_nonce' => wp_create_nonce('gravity_tables_nonce'),
            'is_premium' => gt_is_premium(),
            // #2025 — size caps removed: -1 means unlimited for every plan.
            'limits' => array(
                'max_tables'  => -1,
                'max_columns' => -1,
                'max_entries' => -1,
            ),
            'upgrade_url' => function_exists('wgt_fs') ? wgt_fs()->get_upgrade_url() : '',
            'trial_url' => function_exists('wgt_fs') ? wgt_fs()->get_trial_url() : '',
            'license_url' => admin_url('admin.php?page=gravity-tables-license'),
            'can_trial' => function_exists('wgt_fs') ? (!wgt_fs()->is_trial() && !wgt_fs()->is_trial_utilized()) : false,
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this table?', 'tc-data-tables'),
                'saving' => __('Saving...', 'tc-data-tables'),
                'saved' => __('Saved!', 'tc-data-tables'),
                'error' => __('Error occurred', 'tc-data-tables'),
                'upgrade_required' => __('Upgrade to Pro to unlock this feature', 'tc-data-tables'),
                'trial_available' => __('Start your 7-day free trial to unlock this feature', 'tc-data-tables'),
                'free_limit_reached' => __('Free plan limit reached. Upgrade to Pro for unlimited access.', 'tc-data-tables')
            ),
        );

        // Table-builder edit: ship boot payload inside gtAdmin so wp_json_encode emits one valid script block.
        // (Separate wp_add_inline_script concatenation caused intermittent `Unexpected token ','` in some setups.)
        if (
            isset($_GET['page'], $_GET['id'])
            && sanitize_text_field(wp_unslash((string) $_GET['page'])) === 'gravity-tables-new'
        ) {
            $boot_id = absint($_GET['id']);
            if ($boot_id > 0) {
                $row = $this->get_table($boot_id);
                if ($row) {
                    $decoded  = json_decode($row->settings, true);
                    $settings = is_array($decoded) ? $decoded : array();
                    $boot_title = isset($row->title) ? (string) $row->title : '';

                    // #1615 — review-before-commit revision load: when
                    // &gt_revision=N is present, boot the builder from
                    // that snapshot instead of the current state.
                    // NOTHING is saved until the admin clicks Save.
                    $from_revision = null;
                    if (isset($_GET['gt_revision']) && class_exists('TC_Revision_Snapshot_Service')) {
                        $rev_index = absint($_GET['gt_revision']);
                        $revisions = TC_Revision_Snapshot_Service::load($boot_id, 'get_option');
                        if (isset($revisions[$rev_index]['payload']) && is_string($revisions[$rev_index]['payload'])) {
                            $snapshot = json_decode($revisions[$rev_index]['payload'], true);
                            if (is_array($snapshot)) {
                                if (isset($snapshot['settings']) && is_array($snapshot['settings'])) {
                                    $settings = $snapshot['settings'];
                                }
                                if (isset($snapshot['title'])) {
                                    $boot_title = (string) $snapshot['title'];
                                }
                                $from_revision = $rev_index;
                            }
                        }
                    }

                    $gt_admin_l10n['boot_table'] = array(
                        'id'       => $boot_id,
                        'title'    => $boot_title,
                        'form_id'  => isset($row->form_id) ? (int) $row->form_id : 0,
                        'settings' => self::sanitize_for_inline_js_json($settings),
                    );
                    if ($from_revision !== null) {
                        $gt_admin_l10n['boot_table']['from_revision'] = $from_revision;
                    }
                }
            }
        }

        wp_localize_script('gravity-tables-admin', 'gtAdmin', $gt_admin_l10n);
    }

    public function redirect_legacy_slugs(): void
    {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( '' === $page ) {
            return;
        }
        $legacy_map = array(
            'gravity-tables-dashboard'    => 'tablecrafter-dashboard',
            // #2147 — 3.5.x free plugin's admin slug. Old bookmarks land here.
            'tablecrafter-wp-data-tables' => 'gravity-tables',
        );
        if ( isset( $legacy_map[ $page ] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . $legacy_map[ $page ] ) );
            exit;
        }
    }

    public function admin_init(): void
    {
        register_setting('gt_settings', 'gt_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
        $this->maybe_run_shortcode_migration();
    }

    /**
     * One-time migration: rewrite stale [gravity_table] shortcodes in DB to [tablecrafter].
     * Guarded by a WP option so it runs exactly once after v7.6.3 deploys.
     */
    public function maybe_run_shortcode_migration(): void
    {
        if (get_option('gt_shortcode_migration_v763', false)) {
            return;
        }
        $this->update_shortcodes_to_new_format();
        update_option('gt_shortcode_migration_v763', true);
    }

    /**
     * Sanitize and validate settings to prevent premium feature bypass
     * 
     * @param array $input Raw settings input
     * @return array Sanitized settings
     */
    public function sanitize_settings($input)
    {
        $sanitized = array();

        // Always allowed settings (free and premium)
        $always_allowed = array(
            'default_per_page' => 'intval',
            'date_format' => 'sanitize_text_field',
            'time_format' => 'sanitize_text_field',
            'css_framework' => 'sanitize_text_field'
        );

        // Premium-only settings
        $premium_only = array(
            'enable_frontend_editing',
            'enable_bulk_actions',
            'enable_advanced_filters'
        );

        // Sanitize always-allowed settings
        foreach ($always_allowed as $key => $sanitize_func) {
            if (isset($input[$key])) {
                $sanitized[$key] = $sanitize_func($input[$key]);
            }
        }

        // Handle user roles (always allowed)
        if (isset($input['user_roles_can_edit']) && is_array($input['user_roles_can_edit'])) {
            $sanitized['user_roles_can_edit'] = array_map('sanitize_text_field', $input['user_roles_can_edit']);
        }

        // Handle delete functionality (always allowed)
        $sanitized['enable_delete'] = isset($input['enable_delete']) && $input['enable_delete'];
        // #1747 — one-click entry duplicate (Pro). Sanitized as boolean; Pro gate enforced at render time.
        $sanitized['enable_duplicate'] = gt_is_premium() && isset( $input['enable_duplicate'] ) && $input['enable_duplicate'];
        // #1748 — email alert rules (Pro).
        if ( gt_is_premium() && isset( $input['email_alert_rules'] ) && is_array( $input['email_alert_rules'] ) ) {
            $sanitized['email_alert_rules'] = TC_Email_Alerts::sanitize_rules( $input['email_alert_rules'] );
        } else {
            $sanitized['email_alert_rules'] = [];
        }

        // Handle premium-only features
        foreach ($premium_only as $feature) {
            if (isset($input[$feature])) {
                if (gt_is_premium()) {
                    // Premium user - allow the setting
                    $sanitized[$feature] = (bool) $input[$feature];
                } else {
                    // Free user trying to enable premium feature
                    if ($input[$feature]) {
                        // Log the attempt
                        error_log("GT Security: Free user attempted to enable premium feature '{$feature}' via settings");

                        // Add admin notice
                        add_settings_error(
                            'gt_settings',
                            $feature,
                            sprintf(
                                __('%s is a Pro feature. Please upgrade to enable this functionality.', 'tc-data-tables'),
                                ucwords(str_replace('_', ' ', str_replace('enable_', '', $feature)))
                            ),
                            'error'
                        );
                    }
                    // Force to false for free users
                    $sanitized[$feature] = false;
                }
            }
        }

        return $sanitized;
    }

    public function admin_page_dashboard(): void
    {
        include TC_PLUGIN_PATH . 'admin/views/dashboard.php';
    }

    public function admin_page_tables(): void
    {
        include TC_PLUGIN_PATH . 'admin/views/tables-list.php';
    }

    public function admin_page_new_table(): void
    {
        // Check free plan limits for new tables (not when editing existing ones)
        if (gt_is_free_plan() && !isset($_GET['id'])) {
            global $wpdb;
            $existing_tables = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gravity_tables WHERE status = 'active'");

            if ($existing_tables >= TC_FREE_MAX_TABLES) {
                // Redirect to main tables page with error message
                wp_redirect(admin_url('admin.php?page=gravity-tables&error=limit_reached'));
                // @codeCoverageIgnoreStart
                exit;
                // @codeCoverageIgnoreEnd
            }
        }

        // #2116 — funnel: the user opened the table builder. Guarded so the
        // option is written once, not on every builder page view.
        if (class_exists('TC_Activation_Funnel') && !TC_Activation_Funnel::has('builder_opened')) {
            TC_Activation_Funnel::record('builder_opened');
        }

        include TC_PLUGIN_PATH . 'admin/views/table-builder.php';
    }

    public function admin_page_wizard(): void
    {
        if (gt_is_free_plan()) {
            global $wpdb;
            $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}gravity_tables WHERE status = 'active'");
            if ($existing >= TC_FREE_MAX_TABLES) {
                wp_redirect(admin_url('admin.php?page=gravity-tables&error=limit_reached'));
                // @codeCoverageIgnoreStart
                exit;
                // @codeCoverageIgnoreEnd
            }
        }
        include TC_PLUGIN_PATH . 'admin/views/wizard/layout.php';
    }

    public function admin_page_settings(): void
    {
        include TC_PLUGIN_PATH . 'admin/views/settings.php';
    }

    public function admin_page_license(): void
    {
        include TC_PLUGIN_PATH . 'admin/views/license.php';
    }

    public function admin_page_docs(): void
    {
        include TC_PLUGIN_PATH . 'admin/views/documentation.php';
    }

    public function admin_page_debug_logs(): void
    {
        // #1542 — guard the include because the view file is optional. The
        // TC_Debug class (includes/class-tc-debug.php) handles its own admin
        // page registration when its category settings page is enabled; this
        // method is a separate menu entry that ships a static dump if a site
        // has built one out. Without the guard, sites without the optional
        // view file emit a PHP Warning under real WordPress.
        $view = TC_PLUGIN_PATH . 'admin/views/debug-logs.php';
        if (file_exists($view)) {
            include $view;
            return;
        }
        // @codeCoverageIgnoreStart
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Debug Logs', 'tc-data-tables') . '</h1>';
        echo '<p>' . esc_html__('The debug logs view is not installed on this site. Open TableCrafter → Debug Settings to enable category-scoped logging.', 'tc-data-tables') . '</p>';
        echo '</div>';
        // @codeCoverageIgnoreEnd
    }


    /**
     * Get all saved tables
     */
    public function get_tables(): ?array
    {
        global $wpdb;

        // #968 v4.159.0 — exclude soft-deleted rows from the main listing.
        // Restore + Trash-tab queries (phase 1c, not yet shipped) will set
        // `deleted_at IS NOT NULL` explicitly.
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE status = 'active' AND deleted_at IS NULL ORDER BY updated_at DESC"
        );
    }

    /**
     * Get table by ID
     */
    public function get_table(int $id): ?object
    {
        // Request-level memoization: avoids duplicate queries when the same shortcode
        // appears more than once on a page (#131)
        static $cache = [];

        if (isset($cache[$id])) {
            return $cache[$id];
        }

        // Persistent object-cache layer (Redis/Memcached): skip DB on warm hits (#131)
        $cache_key = 'gt_table_' . $id;
        $cached = wp_cache_get($cache_key, 'gravity_tables');
        if ($cached !== false) {
            // @codeCoverageIgnoreStart
            $cache[$id] = $cached ?: null;
            return $cache[$id];
            // @codeCoverageIgnoreEnd
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'gravity_tables';

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id)
        );

        wp_cache_set($cache_key, $row ?: 0, 'gravity_tables', HOUR_IN_SECONDS);
        $cache[$id] = $row ?: null;
        return $cache[$id];
    }

    /**
     * Return the decoded settings array for a table, or [] on missing/corrupt data (#401).
     */
    public function get_table_config(int $id): array
    {
        $row = $this->get_table($id);
        if (!$row || empty($row->settings)) {
            return [];
        }
        $decoded = json_decode($row->settings, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Recursively sanitize values destined for wp_json_encode in inline &lt;script&gt;.
     * Prevents encode failures from NAN/INF floats or stray objects from corrupt payloads.
     *
     * @param mixed $value Raw decoded settings subtree.
     * @return mixed JSON-serializable value.
     */
    public static function sanitize_for_inline_js_json($value)
    {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $out[$k] = self::sanitize_for_inline_js_json($v);
            }
            return $out;
        }
        if (is_object($value)) {
            return self::sanitize_for_inline_js_json((array) $value);
        }
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return null;
        }
        return $value;
    }

    /**
     * JSON string safe for embedding in HTML (`window.gtTableData = …`).
     * Never returns empty — avoids invalid JS like `window.gtTableData = ;`.
     *
     * @param array<string, mixed> $data Payload keys must survive wp_json_encode.
     */
    public static function inline_js_json(array $data): string
    {
        $clean = self::sanitize_for_inline_js_json($data);
        $opts  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            // constant() avoids parse-time undefined constant on PHP < 7.2.
            $opts |= (int) constant('JSON_INVALID_UTF8_SUBSTITUTE');
        }
        $json = wp_json_encode($clean, $opts);
        if ($json !== false && $json !== '') {
            return $json;
        }

        // @codeCoverageIgnoreStart
        return '{"id":0,"title":"","form_id":0,"settings":{}}';
        // @codeCoverageIgnoreEnd
    }

    /**
     * Save table configuration
     */
    public function save_table(array $data): int|bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'gravity_tables';

        // Prepare settings array
        $settings = array();

        // Add basic settings
        if (isset($data['settings']) && is_array($data['settings'])) {
            $settings = $data['settings'];

            // Debug logging
            // error_log('GT Admin: Saving table settings - Input: ' . print_r($data['settings'], true));

            // Ensure boolean values are properly handled
            $boolean_fields = [
                'show_search',
                'show_pagination',
                'show_selection',
                'show_bulk_actions',
                'show_advanced_filters',
                'show_entry_info',
                'show_add_entry',
                'enable_frontend_editing',
                'enable_delete',
                'sticky_header',
                'freeze_first_column',
                'responsive_table',
                'persistent_filters',
                'show_deleted_entries',
                'filter_user_entries',
                'enable_vertical_scroll',
                'show_table_summary',
            ];

            // Handle responsive mode (extends responsive_table boolean)
            // error_log('GT Admin: Processing responsive_mode - Input: ' . var_export($settings['responsive_mode'] ?? 'not set', true));
            if (isset($settings['responsive_mode'])) {
                $valid_modes = ['disabled', 'basic', 'enhanced', 'flip'];
                $settings['responsive_mode'] = in_array($settings['responsive_mode'], $valid_modes)
                    ? $settings['responsive_mode']
                    : 'basic';
                // error_log('GT Admin: Final responsive_mode set to: ' . $settings['responsive_mode']);
            } else {
                // error_log('GT Admin: No responsive_mode provided, will default to basic');
            }

            // Handle responsive settings for fields
            if (isset($settings['responsive_settings']) && is_array($settings['responsive_settings'])) {
                // error_log('GT Admin: Processing responsive_settings - Input: ' . print_r($settings['responsive_settings'], true));
                $settings['responsive_settings'] = $this->sanitize_responsive_settings($settings['responsive_settings']);
                // error_log('GT Admin: Sanitized responsive_settings: ' . print_r($settings['responsive_settings'], true));
            } else {
                // error_log('GT Admin: No responsive_settings found in data or not an array');
            }

            foreach ($boolean_fields as $field) {
                if (isset($settings[$field])) {
                    $original_value = $settings[$field];
                    $settings[$field] = filter_var($settings[$field], FILTER_VALIDATE_BOOLEAN);
                    // error_log("GT Admin: Boolean field '$field' - Original: " . var_export($original_value, true) . " -> Converted: " . var_export($settings[$field], true));
                }
            }

            // Ensure numeric values are properly handled
            if (isset($settings['per_page'])) {
                $settings['per_page'] = intval($settings['per_page']);
            }
            // #1743 — auto-refresh interval (Free). Clamp to 0 (off) or 5+ seconds.
            if (isset($settings['auto_refresh_interval'])) {
                $interval = (int) $settings['auto_refresh_interval'];
                $settings['auto_refresh_interval'] = $interval > 0 ? max(5, $interval) : 0;
            }
            // #1744 — column visibility picker toggle (Free).
            $settings['show_column_picker'] = ! empty( $settings['show_column_picker'] );
            // #1747 — one-click entry duplicate toggle (Pro).
            $settings['enable_duplicate'] = gt_is_premium() && ! empty( $settings['enable_duplicate'] );
            // #1748 — email alert rules (Pro).
            if ( gt_is_premium() && isset( $settings['email_alert_rules'] ) && is_array( $settings['email_alert_rules'] ) ) {
                $settings['email_alert_rules'] = TC_Email_Alerts::sanitize_rules( $settings['email_alert_rules'] );
            } else {
                $settings['email_alert_rules'] = [];
            }

            // Sanitize vertical_scroll_max_height: allow numeric+unit values (#324)
            if (isset($settings['vertical_scroll_max_height'])) {
                $vh = sanitize_text_field($settings['vertical_scroll_max_height']);
                if ($vh === '' || preg_match('/^\d+(\.\d+)?(px|vh|em|rem|%)$/', $vh)) {
                    $settings['vertical_scroll_max_height'] = $vh;
                } else {
                    $settings['vertical_scroll_max_height'] = '';
                }
            }

            // Sanitize table_width: allow 'auto', '100%', or numeric+unit values (#85)
            if (isset($settings['table_width'])) {
                $tw = sanitize_text_field($settings['table_width']);
                if ($tw === '' || $tw === 'auto' || $tw === '100%'
                    || preg_match('/^\d+(\.\d+)?(px|rem|em|%|vw|ch)$/', $tw)) {
                    $settings['table_width'] = $tw;
                } else {
                    $settings['table_width'] = '';
                }
            }

            if (isset($settings['custom_css'])) {
                $settings['custom_css'] = self::sanitize_custom_css((string) $settings['custom_css']);
            }

            if (isset($settings['webhook_url'])) {
                $url = esc_url_raw(trim((string) $settings['webhook_url']));
                $settings['webhook_url'] = (preg_match('@^https?://@i', $url) ? $url : '');
            }

            if (isset($settings['notify_emails'])) {
                $raw = (string) $settings['notify_emails'];
                $emails = array_filter(array_map('sanitize_email', array_map('trim', explode(',', $raw))));
                $emails = array_values(array_unique(array_filter($emails, 'is_email')));
                $settings['notify_emails'] = implode(', ', $emails);
            }

            if (isset($settings['notify_events'])) {
                $allowed = array('created', 'updated', 'deleted');
                $events = is_array($settings['notify_events']) ? $settings['notify_events'] : array();
                $settings['notify_events'] = array_values(array_intersect($allowed, array_map('strval', $events)));
            }

            if (isset($settings['wc_mapping'])) {
                $allowed_keys = array('title', 'price', 'sku', 'description');
                $clean = array();
                $raw = is_array($settings['wc_mapping']) ? $settings['wc_mapping'] : array();
                foreach ($allowed_keys as $k) {
                    $v = isset($raw[$k]) ? (string) $raw[$k] : '';
                    if (preg_match('/^\d+(\.\d+)?$/', $v)) {
                        $clean[$k] = $v;
                    }
                }
                $settings['wc_mapping'] = $clean;
            }

            // Debug logging
            // error_log('GT Admin: Final settings to save: ' . print_r($settings, true));
        }

        // Add selected fields (support both parameter names)
        if (isset($data['selected_fields']) && is_array($data['selected_fields'])) {
            $settings['columns'] = $data['selected_fields'];
        } elseif (isset($data['columns']) && is_array($data['columns'])) {
            $settings['columns'] = $data['columns'];
        }

        // Add field labels if provided (support both parameter names)
        if (isset($data['field_labels']) && is_array($data['field_labels'])) {
            $settings['column_labels'] = $data['field_labels'];
        } elseif (isset($data['column_labels']) && is_array($data['column_labels'])) {
            // @codeCoverageIgnoreStart
            $settings['column_labels'] = $data['column_labels'];
            // @codeCoverageIgnoreEnd
        }

        // Add editable fields if provided
        if (isset($data['editable_fields']) && is_array($data['editable_fields'])) {
            $settings['editable_fields'] = $data['editable_fields'];
        }

        // Add sortable fields if provided
        if (isset($data['sortable_fields']) && is_array($data['sortable_fields'])) {
            $settings['sortable_fields'] = $data['sortable_fields'];
        }

        // Add filterable fields if provided
        if (isset($data['filterable_fields']) && is_array($data['filterable_fields'])) {
            $settings['filterable_fields'] = $data['filterable_fields'];
        }

        // Add manual row order if provided (#440)
        if (isset($data['row_order']) && is_array($data['row_order'])) {
            $settings['row_order'] = array_values(array_map('intval', array_filter($data['row_order'], 'is_numeric')));
        }

        // Add lookup fields if provided
        if (isset($data['lookup_fields']) && is_array($data['lookup_fields'])) {
            $settings['lookup_fields'] = $data['lookup_fields'];
        }

        // Add column alignments if provided (sanitize to allowed values)
        if (isset($data['column_alignments']) && is_array($data['column_alignments'])) {
            $allowed_alignments = ['left', 'center', 'right', 'justify'];
            $settings['column_alignments'] = array_map(
                function ($v) use ($allowed_alignments) {
                    return in_array($v, $allowed_alignments, true) ? $v : 'left';
                },
                $data['column_alignments']
            );
        }

        // #531 slice 1: print-all-rows toggle. When true (default), the
        // Print toolbar button fetches every entry into the DOM before
        // calling window.print(), then restores the paginated view on
        // the browser's afterprint event. Defaults to true at read time
        // because most users expect the whole list when they hit Print.
        if (isset($data['print_all_rows'])) {
            $settings['print_all_rows'] = filter_var($data['print_all_rows'], FILTER_VALIDATE_BOOLEAN);
        }

        // #567 slice 1: per-table row-link template. URL string with
        // `{field_id}` placeholders that the frontend resolves per row, e.g.
        // `/loads/{1}` or `https://example.com/p/{5}/{6}`. Empty string
        // disables the feature; existing tables behave identically.
        if (isset($data['row_link_template'])) {
            $settings['row_link_template'] = esc_url_raw(trim((string) $data['row_link_template']));
        }

        // #567 slice 2.4: per-table "always open in new tab" toggle. Pairs
        // with TC_Row_Link_Service's row_link_open_new_tab contract. When
        // true, every click navigates via window.open regardless of
        // modifier keys; when false (default), modifier-key + middle-click
        // path from slice 2 is the only route to a new tab.
        if (isset($data['row_link_open_new_tab'])) {
            $settings['row_link_open_new_tab'] = filter_var($data['row_link_open_new_tab'], FILTER_VALIDATE_BOOLEAN);
        }

        // #501 slice 1: row expiry settings. When set, the frontend JS gate
        // hides / strikes-through / moves-to-bottom rows whose date in
        // `expiry_field_id` is in the past (or in the future, when the
        // inverse mode is on for sneak-peek / pre-order patterns).
        // Admin preview always shows every row regardless of these settings.
        if (isset($data['expiry_field_id'])) {
            $settings['expiry_field_id'] = sanitize_text_field((string) $data['expiry_field_id']);
        }
        if (isset($data['expiry_behavior'])) {
            // #501 slice 2.2: accept both 'bottom' (legacy from v4.7.78) and
            // 'move_bottom' (canonical name in TC_Row_Expiry_Service). Slice
            // 3's server-side wire-up will use the PHP-service name; existing
            // tables persisted as 'bottom' continue to work.
            $allowed_expiry_behaviors = ['hide', 'strikethrough', 'bottom', 'move_bottom'];
            $val = (string) $data['expiry_behavior'];
            $settings['expiry_behavior'] = in_array($val, $allowed_expiry_behaviors, true) ? $val : 'hide';
        }
        if (isset($data['expiry_grace_days'])) {
            $settings['expiry_grace_days'] = max(0, (int) $data['expiry_grace_days']);
        }
        if (isset($data['expiry_inverse'])) {
            $settings['expiry_inverse'] = filter_var($data['expiry_inverse'], FILTER_VALIDATE_BOOLEAN);
        }

        // #1598 — computed columns. The builder posts a JSON list of
        // {label, formula}; the service sanitizer validates formulas,
        // strips labels, caps the count, and assigns gtc_N ids.
        if (isset($data['computed_columns']) && class_exists('TC_Formula_Service')) {
            $settings['computed_columns'] = TC_Formula_Service::sanitize_computed_columns(
                is_string($data['computed_columns']) ? wp_unslash($data['computed_columns']) : $data['computed_columns']
            );
        }

        // v4.8.x / v4.9.x sanitizer block extracted into a private helper
        // so the function save_table → wp_cache_delete locality window
        // (test #468) doesn't fight every new sanitizer addition.
        $this->apply_v48_v49_sanitizers($settings, $data);

        // #518 slice 1: per-column auto-merge of consecutive duplicate
        // values into rowspan groups. Each entry is a boolean keyed by
        // field_id; absent / false means render every <td> normally.
        if (isset($data['column_auto_merge']) && is_array($data['column_auto_merge'])) {
            $settings['column_auto_merge'] = array_map(
                function ($v) { return filter_var($v, FILTER_VALIDATE_BOOLEAN); },
                $data['column_auto_merge']
            );
        }

        // #568 slice 2: click-to-filter per-column opt-in. Storage shape
        // is a flat list of field_id strings (the columns where
        // click-to-filter is enabled); admin.js converts the per-field
        // boolean into the list. Sanitizer delegates to slice-1 service
        // so duplicate / non-string / empty entries are stripped.
        if (isset($data['drilldown_columns']) && class_exists('TC_Drilldown_Filter_Service')) {
            $normalized = TC_Drilldown_Filter_Service::normalize_settings([
                'drilldown_columns' => is_array($data['drilldown_columns']) ? $data['drilldown_columns'] : [],
            ]);
            $settings['drilldown_columns'] = $normalized['drilldown_columns'];
        }

        // #521 slice 2: granular toolbar-component visibility map. Six
        // canonical components (global_search / pagination / length_selector /
        // info_label / column_filters / export_buttons), all default-visible.
        // Sanitizer delegates to the slice-1 service so the source of truth
        // (component list + bool coercion + unknown-key drop) lives in one
        // place. Slice 3 will wire templates/table.php to consult is_visible
        // before rendering each component.
        if (isset($data['toolbar_visibility']) && class_exists('TC_Toolbar_Visibility_Service')) {
            $raw = is_array($data['toolbar_visibility']) ? $data['toolbar_visibility'] : array();
            $settings['toolbar_visibility'] = TC_Toolbar_Visibility_Service::normalize($raw);
        }

        // #519 slice 3 — Scheduled outbound export per-table settings.
        // Six top-level POST keys land in $data; we sanitize each into
        // $settings so the slice-2 runner picks them up from
        // $table_config at run time. Then we reconcile the WP-Cron
        // queue: when `enabled` is on, call schedule_for_table with
        // the requested recurrence; when off, clear_schedule_for_table.
        // No-op when the slice-2 service class isn't loaded (defensive).
        if (isset($data['scheduled_export_enabled'])) {
            $settings['scheduled_export_enabled'] = filter_var($data['scheduled_export_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['scheduled_export_recurrence'])) {
            $allowed_recurrences = ['hourly', 'gt_every_6h', 'daily', 'weekly'];
            $rec = (string) $data['scheduled_export_recurrence'];
            $settings['scheduled_export_recurrence'] = in_array($rec, $allowed_recurrences, true) ? $rec : 'daily';
        }
        if (isset($data['scheduled_export_format'])) {
            $fmt = strtolower((string) $data['scheduled_export_format']);
            $settings['scheduled_export_format'] = in_array($fmt, ['csv', 'xlsx'], true) ? $fmt : 'csv';
        }
        if (isset($data['scheduled_export_filename_pattern'])) {
            // Strip slashes / nulls / parent-traversal at the sanitizer
            // boundary. The slice-1 TC_Export_Filename_Service::expand
            // does this again at run time as defence in depth.
            $pat = (string) $data['scheduled_export_filename_pattern'];
            $pat = str_replace(["\0", "\r", "\n", "..", "/", "\\"], '', $pat);
            $settings['scheduled_export_filename_pattern'] = sanitize_text_field($pat);
        }
        if (isset($data['scheduled_export_email_recipients'])) {
            // Permissive on the sanitizer side — service-side parse_
            // recipients() runs sanitize_email + is_email on each
            // entry before wp_mail is called.
            $settings['scheduled_export_email_recipients'] = sanitize_text_field((string) $data['scheduled_export_email_recipients']);
        }
        if (isset($data['scheduled_export_honor_filters'])) {
            $settings['scheduled_export_honor_filters'] = filter_var($data['scheduled_export_honor_filters'], FILTER_VALIDATE_BOOLEAN);
        }
        // #562 slice 2 — pivot view admin opt-in. Composes the four
        // flat keys (mode / group_by / aggregate_col / aggregate_op)
        // into the pivot_config object shape and delegates to slice-1
        // TC_Pivot_Service::normalize for the mode whitelist + group_by
        // string coerce + aggregates filter.
        if ((isset($data['pivot_mode']) || isset($data['pivot_group_by'])
                || isset($data['pivot_aggregates'])
                || isset($data['pivot_aggregate_col']) || isset($data['pivot_aggregate_op']))
            && class_exists('TC_Pivot_Service')
        ) {
            // #1617 — repeater payload wins; the legacy single
            // col/op pair stays as the fallback for pre-repeater
            // clients (and as the empty-state no-op).
            $raw_aggregates = [];
            if (isset($data['pivot_aggregates'])) {
                $raw_aggregates = TC_Pivot_Service::parse_aggregates_input(
                    is_string($data['pivot_aggregates']) ? wp_unslash($data['pivot_aggregates']) : $data['pivot_aggregates']
                );
            }
            if ($raw_aggregates === []) {
                $agg_col = isset($data['pivot_aggregate_col']) ? (string) $data['pivot_aggregate_col'] : '';
                $agg_op  = isset($data['pivot_aggregate_op'])  ? (string) $data['pivot_aggregate_op']  : '';
                if ($agg_col !== '' && $agg_op !== '') {
                    $raw_aggregates[] = ['col' => $agg_col, 'op' => $agg_op];
                }
            }
            $raw_cfg = [
                'mode'       => $data['pivot_mode']     ?? 'raw',
                'group_by'   => $data['pivot_group_by'] ?? null,
                'aggregates' => $raw_aggregates,
            ];
            $settings['pivot_config'] = TC_Pivot_Service::normalize($raw_cfg);
        }

        // #560 slice 2 — server-side pagination opt-in + default page size.
        // Delegates to slice-1 TC_Pagination_Service::normalize_settings
        // for the bool coerce + page-size clamp (1..500).
        if ((isset($data['server_side_pagination']) || isset($data['default_page_size']))
            && class_exists('TC_Pagination_Service')
        ) {
            $raw = [
                'server_side'       => $data['server_side_pagination'] ?? false,
                'default_page_size' => $data['default_page_size']      ?? null,
            ];
            $norm = TC_Pagination_Service::normalize_settings($raw);
            $settings['server_side']       = $norm['server_side'];
            $settings['default_page_size'] = $norm['default_page_size'];
        }

        // #526 slice 2/3 — per-table fallback image URL picked via the
        // GT Media Folder adapter (which surfaces folder-plugin UI in
        // the wp.media frame). Stored as a raw URL; the frontend
        // renderer reads it from $table_settings when an image-cell
        // value is empty.
        if (isset($data['default_image_fallback_url'])) {
            $settings['default_image_fallback_url'] = esc_url_raw(trim((string) $data['default_image_fallback_url']));
        }

        // #519 slice 3 — WP-Cron reconciliation happens after $table_id
        // is known. See the update / insert branches below; both
        // dispatch to TC_Scheduled_Export_Service::schedule_for_table
        // or clear_schedule_for_table based on the toggle.

        // #517 slice 4c: per-table mapping of "GF field that holds the
        // Airtable record id". Customers populate this during initial import
        // (or manually) so push-back can resolve which Airtable record to
        // PATCH from a given GF entry. Empty / unset means push-back skips
        // for this table even when sync_direction != pull_only.
        if (isset($data['airtable_record_id_field'])) {
            $f = (string) $data['airtable_record_id_field'];
            // GF supports composite ids like "3.2" — allow alphanumeric + dot.
            $f = preg_replace('/[^a-zA-Z0-9_.]+/', '', $f);
            $settings['airtable_record_id_field'] = $f;
        }

        // #517 slice 4b: per-table Airtable sync_direction (write-back gate).
        // Whitelist: pull_only (default — read-only, current behavior) /
        // push_only / two_way. Invalid values default to pull_only (safest:
        // never push without explicit opt-in). Slice 4c will wire the AJAX
        // endpoint to consult this when deciding whether to invoke the
        // slice-4a PATCH service. Slice 4d+ adds conflict resolution + rate
        // limiting + audit log per #613.
        if (isset($data['sync_direction'])) {
            $allowed_directions = ['pull_only', 'push_only', 'two_way'];
            $raw = (string) $data['sync_direction'];
            $settings['sync_direction'] = in_array($raw, $allowed_directions, true) ? $raw : 'pull_only';
        }

        // #1648 — per-table owner field: the GF field id holding the owning
        // user id. When set, non-admin frontend editors may only edit entries
        // whose owner field matches their user id (TC_Entry_Owner_Guard).
        if (isset($data['owner_field_id'])) {
            $owner = (string) $data['owner_field_id'];
            $owner = preg_replace('/[^a-zA-Z0-9_.]+/', '', $owner);
            $settings['owner_field_id'] = $owner;
        }

        // #544 slice 2: multi-row sticky header count knob. Existing
        // sticky_header boolean stays; new frozen_top_rows int (1..10,
        // default 1) is clamped via the slice-1 service. Slice 3 will
        // ship the per-N CSS in templates/table.php.
        if (isset($data['frozen_top_rows']) && class_exists('TC_Sticky_Rows_Service')) {
            $raw = $data['frozen_top_rows'];
            // Numeric strings ("3") are common from <input type="number"> POSTs;
            // cast them to int before handing off to the service so its
            // is_int / is_float gate can succeed.
            if (is_string($raw) && is_numeric($raw)) {
                $raw = (int) $raw;
            }
            $normalized = TC_Sticky_Rows_Service::normalize(['frozen_top_rows' => $raw]);
            $settings['frozen_top_rows'] = $normalized['frozen_top_rows'];
        }

        // #549 slice 1: per-column vertical alignment (top/middle/bottom).
        // Slice 2 (v4.45.0): whitelist source-of-truth delegated to the
        // slice-1 service so the allowed-values list lives in one place.
        // Empty-string fallback means "emit no inline style" so the browser's
        // default (middle for table cells) wins; this preserves backwards
        // compatibility with every existing table.
        if (isset($data['column_vertical_alignments']) && is_array($data['column_vertical_alignments'])) {
            $allowed_v_alignments = class_exists('TC_Vertical_Align_Service')
                ? TC_Vertical_Align_Service::alignments()
                // @codeCoverageIgnoreStart
                : ['top', 'middle', 'bottom'];
                // @codeCoverageIgnoreEnd
            $settings['column_vertical_alignments'] = array_map(
                function ($v) use ($allowed_v_alignments) {
                    return in_array($v, $allowed_v_alignments, true) ? $v : '';
                },
                $data['column_vertical_alignments']
            );
        }

        // #549 slice 3: per-cell vertical alignment overrides.
        if (isset($data['cell_vertical_alignments']) && is_array($data['cell_vertical_alignments'])) {
            if (class_exists('TC_Vertical_Align_Service')) {
                $settings['cell_vertical_alignments'] = TC_Vertical_Align_Service::sanitize_cell_map($data['cell_vertical_alignments']);
            }
        }

        // Add column link settings if provided
        if (isset($data['column_link_settings']) && is_array($data['column_link_settings'])) {
            $settings['column_link_settings'] = $this->sanitize_column_link_settings($data['column_link_settings']);
        }

        // Add per-column wrap modes if provided (#520)
        if (isset($data['column_wrap_modes']) && is_array($data['column_wrap_modes'])) {
            if (class_exists('TC_Wrap_Mode_Service')) {
                $settings['column_wrap_modes'] = TC_Wrap_Mode_Service::sanitize_map($data['column_wrap_modes']);
            }
        }

        // Add conditional formatting rules if provided
        if (isset($data['conditional_formatting']) && is_array($data['conditional_formatting'])) {
            $settings['conditional_formatting'] = $this->sanitize_conditional_formatting($data['conditional_formatting']);
        }

        // Add filter configurations if provided
        if (isset($data['filter_configurations']) && is_array($data['filter_configurations'])) {
            // error_log('GT Admin: Saving filter configurations - Input: ' . print_r($data['filter_configurations'], true));
            $settings['filter_configurations'] = $this->sanitize_filter_configurations($data['filter_configurations']);
            // error_log('GT Admin: Sanitized filter configurations: ' . print_r($settings['filter_configurations'], true));
        } else {
            // error_log('GT Admin: No filter configurations found in save data');
        }

        // Add field configurations if provided
        if (isset($data['field_configurations']) && is_array($data['field_configurations'])) {
            // error_log('GT Admin: Saving field configurations - Input: ' . print_r($data['field_configurations'], true));
            $settings['field_configurations'] = $this->sanitize_field_configurations($data['field_configurations']);
            // error_log('GT Admin: Sanitized field configurations: ' . print_r($settings['field_configurations'], true));
        } else {
            // error_log('GT Admin: No field configurations found in save data');
        }

        // Add responsive settings if provided
        if (isset($data['responsive_settings']) && is_array($data['responsive_settings'])) {
            // error_log('GT Admin: Saving responsive settings - Input: ' . print_r($data['responsive_settings'], true));
            $settings['responsive_settings'] = $this->sanitize_responsive_settings($data['responsive_settings']);
            // error_log('GT Admin: Sanitized responsive settings: ' . print_r($settings['responsive_settings'], true));
        } else {
            // error_log('GT Admin: No responsive settings found in save data');
        }

        // When updating an existing table, load the current settings from the DB
        // and merge the incoming payload on top. This preserves any style keys
        // (e.g. custom colors, border styles) that the admin form did not include
        // in this save — which is the root cause of styles resetting after a
        // plugin update (issue #186).
        if ( isset( $data['table_id'] ) && $data['table_id'] ) {
            $existing_json = $wpdb->get_var( $wpdb->prepare(
                "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d",
                intval( $data['table_id'] )
            ) );
            if ( $existing_json ) {
                $existing = json_decode( $existing_json, true );
                if ( is_array( $existing ) ) {
                    // Incoming payload wins on conflicts; existing keys not in payload survive.
                    $settings = array_merge( $existing, $settings );
                }
            }
        }

        $table_data = array(
            'title' => sanitize_text_field($data['title']),
            'form_id' => intval($data['form_id']),
            'settings' => wp_json_encode($settings)
        );

        if (isset($data['table_id']) && $data['table_id']) {
            // Update existing table
            $table_data['updated_at'] = current_time('mysql');
            $table_data['shortcode'] = '[tablecrafter id="' . intval($data['table_id']) . '"]';

            $result = $wpdb->update(
                $table_name,
                $table_data,
                array('id' => intval($data['table_id'])),
                array('%s', '%d', '%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $table_id = intval($data['table_id']);
                $user_id  = get_current_user_id();
                wp_cache_delete('gt_table_' . $table_id, 'gravity_tables');
                // #519 slice 3 — reconcile scheduled export cron.
                if (isset($data['scheduled_export_enabled']) && class_exists('TC_Scheduled_Export_Service')) {
                    // @codeCoverageIgnoreStart
                    if (!empty($settings['scheduled_export_enabled'])) {
                        TC_Scheduled_Export_Service::schedule_for_table($table_id, (string) ($settings['scheduled_export_recurrence'] ?? 'daily'));
                    // @codeCoverageIgnoreEnd
                    } else {
                        // @codeCoverageIgnoreStart
                        TC_Scheduled_Export_Service::clear_schedule_for_table($table_id);
                        // @codeCoverageIgnoreEnd
                    }
                }
                do_action('gravity_tables_after_update_table', $table_id, $data, $user_id);
                do_action('gravity_tables_after_save_table', $table_id, $data, $user_id, false);
            }

            return $result;
        } else {
            // Create new table
            $table_data['created_at'] = current_time('mysql');
            $table_data['updated_at'] = current_time('mysql');
            $table_data['status'] = 'active';
            $table_data['shortcode'] = '[tablecrafter id="PLACEHOLDER"]'; // Temporary, will be updated below

            $result = $wpdb->insert(
                $table_name,
                $table_data,
                array('%s', '%d', '%s', '%s', '%s', '%s', '%s')
            );

            // Update the shortcode with the actual table ID
            if ($result !== false) {
                $table_id = $wpdb->insert_id;
                $wpdb->update(
                    $table_name,
                    array('shortcode' => '[tablecrafter id="' . $table_id . '"]'),
                    array('id' => $table_id),
                    array('%s'),
                    array('%d')
                );
                $user_id = get_current_user_id();
                // #519 slice 3 — reconcile scheduled export cron for newly created tables.
                if (isset($data['scheduled_export_enabled']) && class_exists('TC_Scheduled_Export_Service')) {
                    if (!empty($settings['scheduled_export_enabled'])) {
                        // @codeCoverageIgnoreStart
                        TC_Scheduled_Export_Service::schedule_for_table($table_id, (string) ($settings['scheduled_export_recurrence'] ?? 'daily'));
                        // @codeCoverageIgnoreEnd
                    } else {
                        TC_Scheduled_Export_Service::clear_schedule_for_table($table_id);
                    }
                }
                do_action('gravity_tables_after_create_table', $table_id, $data, $user_id);
                do_action('gravity_tables_after_save_table', $table_id, $data, $user_id, true);
            }

            // Return the new table ID (not rows-affected=1) so callers don't
            // need to read $wpdb->insert_id after action hooks may have changed it.
            return ($result !== false) ? $table_id : false;
        }
    }

    /**
     * Update shortcodes to new format for existing tables
     */
    public function update_shortcodes_to_new_format(): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'gravity_tables';

        // Find rows still using any deprecated shortcode name.
        $tables = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}gravity_tables
             WHERE shortcode LIKE '[gravity_table %'
                OR shortcode LIKE '[gravity_tables %'
                OR shortcode = '[gravity_table]'
                OR shortcode = '[gravity_tables]'"
        );

        foreach ($tables as $table) {
            $wpdb->update(
                $table_name,
                array('shortcode' => '[tablecrafter id="' . $table->id . '"]'),
                array('id' => $table->id),
                array('%s'),
                array('%d')
            );
        }

        return count($tables);
    }

    /**
     * #972 v4.161.0 — Get all trashed tables for the Trash admin tab.
     *
     * Returns rows where soft-delete has been invoked: status='deleted'
     * AND deleted_at IS NOT NULL. Ordered by deleted_at desc so the
     * most-recently-trashed item is at the top (easier to spot the row
     * the user just accidentally deleted).
     */
    public function get_trashed_tables(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE status = 'deleted' AND deleted_at IS NOT NULL ORDER BY deleted_at DESC"
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * #972 v4.161.0 — Restore a soft-deleted table.
     *
     * Inverse of delete_table(): clears the deleted_at timestamp and
     * flips status back to 'active'. Cache invalidated so the next
     * listing query sees the restored row.
     */
    public function restore_table(int $id): int|false
    {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'gravity_tables',
            array(
                'status'     => 'active',
                'deleted_at' => null,
            ),
            array('id' => intval($id)),
            array('%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_cache_delete('gt_table_' . intval($id), 'gravity_tables');
        }

        return $result;
    }

    /**
     * #972 v4.161.0 — Permanently delete a trashed table.
     *
     * The real DELETE FROM. Only callable from the Trash admin tab's
     * "Delete Permanently" action (and, in phase 1d, the WP-cron
     * auto-purge after the retention window).
     */
    public function force_delete_table(int $id): int|false
    {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'gravity_tables',
            array('id' => intval($id)),
            array('%d')
        );

        if ($result !== false) {
            wp_cache_delete('gt_table_' . intval($id), 'gravity_tables');
        }

        return $result;
    }

    /**
     * #978 v4.164.0 — Auto-purge past-retention soft-deleted rows.
     *
     * Called by the daily WP-cron job (hook gravity_tables_purge_expired_trash,
     * registered in the activation hook of the main plugin file). Hard-deletes
     * every soft-deleted row whose deleted_at is older than the configured
     * retention window (default 30 days, overrideable via the
     * gravity_tables_trash_retention_days filter).
     *
     * Returns the deletion count for logging. Live rows (status='active') and
     * within-window trashed rows are NEVER touched — the WHERE clause filters
     * on BOTH status='deleted' AND deleted_at < cutoff so a misconfigured
     * filter cannot accidentally take out live data.
     */
    public function purge_expired_trash(): int
    {
        global $wpdb;

        $retention_days = (int) apply_filters('gravity_tables_trash_retention_days', TC_TRASH_RETENTION_DAYS);
        if ($retention_days < 1) {
            $retention_days = 1;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retention_days * DAY_IN_SECONDS));

        // Snapshot ids first so we can invalidate per-row cache after the bulk DELETE.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}gravity_tables WHERE status = 'deleted' AND deleted_at IS NOT NULL AND deleted_at < %s",
            $cutoff
        ));
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        if (empty($ids)) {
            return 0;
        }

        // Automated background purge of past-retention soft-deleted rows.
        // Gated by status='deleted' AND deleted_at < cutoff so live data is
        // protected. See gravity_tables_trash_retention_days filter for the cutoff.
        $count = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}gravity_tables WHERE status = 'deleted' AND deleted_at IS NOT NULL AND deleted_at < %s" # DATA_INTEGRITY_OK: cron-driven retention purge, see method docblock
            ,
            $cutoff
        ));

        foreach ($ids as $id) {
            wp_cache_delete('gt_table_' . $id, 'gravity_tables');
        }

        do_action('gravity_tables_after_cron_purge', $count, $cutoff);

        return $count;
    }

    /**
     * #974 v4.162.0 — Bulk permanent-delete every row currently in the Trash.
     *
     * Used by the "Empty Trash" button on the Trash admin tab (phase 1c-2
     * of #593). Returns the number of rows deleted (zero is allowed and
     * meaningful — means the trash was already empty).
     */
    public function empty_trash(): int
    {
        global $wpdb;

        // Snapshot the ids first so we can invalidate per-row cache after
        // the bulk DELETE. Avoids the per-row wp_cache_delete call we'd
        // otherwise need to issue inside a loop.
        $ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}gravity_tables WHERE status = 'deleted' AND deleted_at IS NOT NULL"
        );
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        if (empty($ids)) {
            return 0;
        }

        // User-initiated bulk permanent-delete via the Trash tab "Empty Trash"
        // button (#974, phase 1c-2 of #593). Gated by confirm() in the view +
        // nonce + manage_options capability check in the AJAX handler.
        # phpcs:disable -- legitimate bulk delete; see Trash tab UI flow
        $count = (int) $wpdb->query(
            "DELETE FROM {$wpdb->prefix}gravity_tables WHERE status = 'deleted' AND deleted_at IS NOT NULL" # DATA_INTEGRITY_OK: see Trash tab flow above
        );
        # phpcs:enable

        foreach ($ids as $id) {
            wp_cache_delete('gt_table_' . $id, 'gravity_tables');
        }

        return $count;
    }

    /**
     * #972 v4.161.0 — Render the Trash admin page.
     */
    public function admin_page_trash(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tc-data-tables'));
        }
        $trashed = $this->get_trashed_tables();
        include TC_PLUGIN_PATH . 'admin/views/trash-list.php';
    }

    /**
     * #2040 — External DB connection management screen (Pro). Lists stored
     * connections and renders the add-connection form. All mutations run through
     * the nonce-protected TC_External_DB ajax handlers via db-connections.js.
     */
    public function admin_page_db_connections(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tc-data-tables'));
        }
        if (!class_exists('TC_External_DB')) {
            wp_die(__('External database support is not available on this build.', 'tc-data-tables'));
        }
        $connections = TC_External_DB::get_instance()->connections_for_display(
            TC_External_DB::get_instance()->get_connections()
        );
        $sqlsrv_available = TC_External_DB::get_instance()->is_sqlsrv_available();
        include TC_PLUGIN_PATH . 'admin/views/db-connections.php';
    }

    /**
     * Soft-delete a table.
     *
     * Sets `status = 'deleted'` (the existing flag, drives listing-filter)
     * AND `deleted_at = current_time('mysql')` (#970 v4.160.0, the
     * retention timestamp consumed by the Trash admin tab + WP-cron
     * auto-purge in phases 1c + 1d of #593).
     */
    public function delete_table(int $id): int|false
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'gravity_tables';

        $result = $wpdb->update(
            $table_name,
            array(
                'status'     => 'deleted',
                'deleted_at' => current_time('mysql'),
            ),
            array('id' => intval($id)),
            array('%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_cache_delete('gt_table_' . intval($id), 'gravity_tables');
        }

        return $result;
    }

    /**
     * Sanitize per-column hyperlink settings (target, color, underline).
     */
    private function sanitize_column_link_settings(array $column_link_settings): array
    {
        $sanitized = array();
        $allowed_targets = ['', '_self', '_blank', '_parent', '_top', 'new_tab'];

        foreach ($column_link_settings as $field_id => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            $clean = array();

            if (isset($settings['link_target'])) {
                $clean['link_target'] = in_array($settings['link_target'], $allowed_targets, true)
                    ? $settings['link_target']
                    : '_self';
            }

            if (isset($settings['link_color'])) {
                // Accept hex colors or named colors; sanitize_hex_color returns null for invalid values
                $hex = sanitize_hex_color((string) $settings['link_color']);
                $clean['link_color'] = $hex ?? sanitize_text_field((string) $settings['link_color']);
            }

            if (isset($settings['link_underline'])) {
                $clean['link_underline'] = filter_var($settings['link_underline'], FILTER_VALIDATE_BOOLEAN);
            }

            $sanitized[sanitize_key($field_id)] = $clean;
        }

        return $sanitized;
    }

    /**
     * Apply v4.8.x / v4.9.x feature sanitizers — extracted from save_table()
     * so the test #468 wp_cache_delete locality window doesn't fight every
     * new sanitizer addition. Each block here matches one wire-up release.
     *
     * @param array &$settings  Settings map to write into.
     * @param array $data       Raw POST data.
     */
    private function apply_v48_v49_sanitizers(array &$settings, array $data): void
    {
        // #599 slice 2 — cascading filter chain (parent → child).
        // Two flat scalar fields. The pair is normalized via the
        // service so self-references and empty inputs are rejected
        // consistently with slice 1's contract.
        if (isset($data['cascading_filter_parent_field']) || isset($data['cascading_filter_child_field'])) {
            $parent = isset($data['cascading_filter_parent_field']) ? (string) $data['cascading_filter_parent_field'] : '';
            $child  = isset($data['cascading_filter_child_field'])  ? (string) $data['cascading_filter_child_field']  : '';
            $parent = preg_replace('/[^a-zA-Z0-9_.]+/', '', $parent);
            $child  = preg_replace('/[^a-zA-Z0-9_.]+/', '', $child);
            if (class_exists('TC_Cascading_Filter_Service')) {
                $normalized = TC_Cascading_Filter_Service::normalize_chain([
                    'parent' => $parent,
                    'child'  => $child,
                ]);
                $settings['cascading_filter_parent_field'] = $normalized ? $normalized['parent'] : '';
                $settings['cascading_filter_child_field']  = $normalized ? $normalized['child']  : '';
            } else {
                // @codeCoverageIgnoreStart
                $settings['cascading_filter_parent_field'] = $parent;
                $settings['cascading_filter_child_field']  = $child;
                // @codeCoverageIgnoreEnd
            }
        }

        // #618 slice 5 — per-row action config (recipient field, webhook URL).
        if (isset($data['send_email_recipient_field'])) {
            $f = (string) $data['send_email_recipient_field'];
            // Allow empty (falls back to auto-detect) or a sanitized
            // alphanumeric+dot field id (GF supports composite ids like 3.2).
            $f = preg_replace('/[^a-zA-Z0-9_.]+/', '', $f);
            $settings['send_email_recipient_field'] = $f;
        }
        if (isset($data['per_row_webhook_url'])) {
            $url = (string) $data['per_row_webhook_url'];
            $settings['per_row_webhook_url'] = $url === '' ? '' : esc_url_raw($url);
        }
        // #607 slice 2 — per-table password.
        // Empty plaintext = keep existing hash. Non-empty plaintext =
        // hash + store. Sentinel '__GT_CLEAR__' = remove. Slice 1's
        // TC_Table_Password_Service (v4.10.1) does the actual hashing.
        if (isset($data['table_password']) && class_exists('TC_Table_Password_Service')) {
            $plain = (string) $data['table_password'];
            if ($plain === '__GT_CLEAR__') {
                unset($settings['table_password_hash']);
            } elseif ($plain !== '') {
                $settings['table_password_hash'] = TC_Table_Password_Service::hash($plain);
            }
            // else: empty + existing hash → preserve (nothing to do).
        }
        // #634 — export filename pattern.
        if (isset($data['export_filename_pattern'])) {
            $p = (string) $data['export_filename_pattern'];
            $p = preg_replace('/[\x00-\x1F\x7F]/u', '', $p);
            $settings['export_filename_pattern'] = trim(str_replace(['/', '\\', '..'], '', $p));
        }
        // #547 — schema (service normalize).
        if (isset($data['schema']) && is_array($data['schema']) && class_exists('TC_Schema_Service')) {
            $settings['schema'] = TC_Schema_Service::normalize($data['schema']);
        }
        // TC_Pagination_Label_Service — five customizable labels; empty = use default.
        foreach (['info_text', 'previous_label', 'next_label', 'no_results', 'loading'] as $k) {
            if (isset($data[$k])) {
                $settings[$k] = sanitize_text_field((string) $data[$k]);
            }
        }
        // #565 — multi-sort toggle (default ON via service).
        if (isset($data['enable_multi_sort'])) {
            $settings['enable_multi_sort'] = filter_var($data['enable_multi_sort'], FILTER_VALIDATE_BOOLEAN);
        }
        // #531 — print settings (service normalize).
        if (isset($data['print_settings']) && is_array($data['print_settings']) && class_exists('TC_Print_Settings_Service')) {
            $settings['print_settings'] = TC_Print_Settings_Service::normalize($data['print_settings']);
        }
        // TC_Border_Service preset whitelist (classic / rows_only / none / outer_only).
        if (isset($data['border_preset'])) {
            $bp = (string) $data['border_preset'];
            $settings['border_preset'] = in_array($bp, ['classic', 'rows_only', 'none', 'outer_only'], true)
                ? $bp
                : 'classic';
        }
        // TC_URL_Filter_Service master toggle (off by default for security).
        if (isset($data['allow_url_filters'])) {
            $settings['allow_url_filters'] = filter_var($data['allow_url_filters'], FILTER_VALIDATE_BOOLEAN);
        }
        // TC_Star_Rating_Service / TC_Badge_Service per-column cell type.
        if (isset($data['column_cell_types']) && is_array($data['column_cell_types'])) {
            $settings['column_cell_types'] = array_map(function ($v) {
                return in_array((string) $v, ['', 'star_rating', 'badge'], true) ? (string) $v : '';
            }, $data['column_cell_types']);
        }
        // #1741 — TC_Badge_Service per-column badge map. Sanitized by the service.
        if (isset($data['column_badge_maps']) && is_array($data['column_badge_maps']) && class_exists('TC_Badge_Service')) {
            $badge_maps = [];
            foreach ($data['column_badge_maps'] as $field_id => $raw_map) {
                if (is_array($raw_map)) {
                    $clean = TC_Badge_Service::sanitize_map($raw_map);
                    if (!empty($clean)) {
                        $badge_maps[(string)$field_id] = $clean;
                    }
                }
            }
            if (!empty($badge_maps)) {
                $settings['column_badge_maps'] = $badge_maps;
            }
        }
        // #1742 — TC_Validation_Service per-column inline-edit validation rules (Pro).
        // PERSIST-STRIP on free tier: the free-tier gate matches the server-side
        // gt_is_premium() check in templates/table.php (RENDER-STRIP). Both layers
        // must agree so a free user can never add validation rules to cells they
        // cannot edit anyway, and a downgraded site can never expose stale Pro rules.
        if ( gt_is_premium() && isset($data['column_validations']) && is_array($data['column_validations']) && class_exists('TC_Validation_Service')) {
            $validated_rules = TC_Validation_Service::sanitize_rules( $data['column_validations'] );
            if ( ! empty( $validated_rules ) ) {
                $settings['column_validations'] = $validated_rules;
            }
        }
        // TC_Formula_Service per-column totals-row aggregation (SUPPORTED_AGGREGATIONS whitelist).
        if (isset($data['column_aggregations']) && is_array($data['column_aggregations'])) {
            $allowed = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'COUNT_DISTINCT'];
            $settings['column_aggregations'] = array_map(function ($v) use ($allowed) {
                $u = strtoupper((string) $v);
                return in_array($u, $allowed, true) ? $u : '';
            }, $data['column_aggregations']);
        }
        // Data Bars (#1731) — Pro-gated per-column value bars. The service
        // persist-strips the whole block on the free tier (returns []), so
        // a free user can never persist it even via a hand-edited payload.
        // Only write the key when there is something to store.
        if (isset($data['column_data_bars']) && is_array($data['column_data_bars'])) {
            $gt_bars = TC_Data_Bars_Service::sanitize($data['column_data_bars'], gt_is_premium());
            if (!empty($gt_bars)) {
                $settings['column_data_bars'] = $gt_bars;
            }
        }
        // TC_Row_Height_Service — row_height / header_height accept preset
        // names or CSS values; service runtime validates via regex. Server
        // here just trims + sanitize_text_field; bad values render as
        // empty (no inline style emitted).
        foreach (['row_height', 'header_height'] as $rh_key) {
            if (isset($data[$rh_key])) {
                $settings[$rh_key] = sanitize_text_field((string) $data[$rh_key]);
            }
        }
        // Overflow mode whitelist.
        if (isset($data['row_overflow_mode'])) {
            $om = (string) $data['row_overflow_mode'];
            $settings['row_overflow_mode'] = in_array($om, ['ellipsis', 'expand'], true) ? $om : 'ellipsis';
        }
        // TC_Detail_Rows_Service — per-column detail-only flag (#556).
        // Service's normalize_column coerces truthy strings (1/true/on/yes)
        // + literal true / int 1; everything else is false. We mirror that
        // shape here for each entry in the flat field_id => bool map.
        if (isset($data['column_detail_only']) && is_array($data['column_detail_only'])) {
            $clean = array();
            foreach ($data['column_detail_only'] as $field_id => $val) {
                $col = class_exists('TC_Detail_Rows_Service')
                    ? TC_Detail_Rows_Service::normalize_column(array('detail_only' => $val))
                    // @codeCoverageIgnoreStart
                    : array('detail_only' => filter_var($val, FILTER_VALIDATE_BOOLEAN));
                    // @codeCoverageIgnoreEnd
                if (!empty($col['detail_only'])) {
                    $clean[(string) $field_id] = true;
                }
            }
            $settings['column_detail_only'] = $clean;
        }

        // #1746 — per-column role visibility (Pro).
        // column_role_visibility: field_id => string[] of allowed WP role slugs.
        if ( gt_is_premium() && isset( $data['column_role_visibility'] ) && is_array( $data['column_role_visibility'] ) ) {
            $crv = [];
            $known_roles = array_keys( wp_roles()->roles );
            foreach ( $data['column_role_visibility'] as $field_id => $raw_roles ) {
                $field_id = absint( $field_id );
                if ( ! $field_id ) { continue; }
                $clean_roles = is_array( $raw_roles )
                    ? array_values( array_intersect( array_map( 'sanitize_key', $raw_roles ), $known_roles ) )
                    : [];
                $crv[ (string) $field_id ] = $clean_roles;
            }
            $settings['column_role_visibility'] = $crv;
        }

        // TC_Default_Sort_Service — per-table default sort column + direction.
        // Service's get_sort_column runs sanitize_key, get_sort_direction
        // normalizes to asc/desc. Mirror the same shape here.
        if (isset($data['default_sort_column'])) {
            $settings['default_sort_column'] = sanitize_key((string) $data['default_sort_column']);
        }
        if (isset($data['default_sort_direction'])) {
            $dir = strtolower(trim((string) $data['default_sort_direction']));
            $settings['default_sort_direction'] = $dir === 'desc' ? 'desc' : 'asc';
        }
        // Persistent filters via browser localStorage (off by default).
        if (isset($data['persist_filters_localstorage'])) {
            $settings['persist_filters_localstorage'] = filter_var($data['persist_filters_localstorage'], FILTER_VALIDATE_BOOLEAN);
        }
        // TC_Collapsible_Service — whole-table collapse toggle (off by default).
        if (isset($data['collapsible_enabled'])) {
            $settings['collapsible_enabled'] = filter_var($data['collapsible_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['collapsible_default_collapsed'])) {
            $settings['collapsible_default_collapsed'] = filter_var($data['collapsible_default_collapsed'], FILTER_VALIDATE_BOOLEAN);
        }
        // Visitor-side length selector (off by default; CSV options whitelisted to ints).
        if (isset($data['show_length_selector'])) {
            $settings['show_length_selector'] = filter_var($data['show_length_selector'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['length_selector_options'])) {
            // Accept comma-separated ints (and -1 for "All"). Strip anything else.
            $raw = (string) $data['length_selector_options'];
            $clean = array();
            foreach (explode(',', $raw) as $piece) {
                $n = (int) trim($piece);
                if ($n === -1 || ($n >= 1 && $n <= 100000)) {
                    $clean[] = $n;
                }
            }
            $settings['length_selector_options'] = implode(',', array_unique($clean));
        }
    }

    /**
     * Sanitize conditional formatting rules
     *
     * @param array $conditional_formatting Raw conditional formatting data
     * @return array Sanitized conditional formatting rules
     */
    private function sanitize_conditional_formatting(array $conditional_formatting): array
    {
        $sanitized = array();

        if (!is_array($conditional_formatting)) {
            // @codeCoverageIgnoreStart
            return $sanitized;
            // @codeCoverageIgnoreEnd
        }

        foreach ($conditional_formatting as $field_id => $rules) {
            if (!is_array($rules)) {
                continue;
            }

            $sanitized_rules = array();

            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                // Validate and sanitize each rule
                $sanitized_rule = array();

                // Sanitize if clause (operator)
                $allowed_operators = ['lt', 'lteq', 'eq', 'gteq', 'gt', 'neq', 'contains', 'contains_not', 'empty', 'not_empty'];
                if (isset($rule['ifClause']) && in_array($rule['ifClause'], $allowed_operators)) {
                    $sanitized_rule['ifClause'] = sanitize_text_field($rule['ifClause']);
                }

                // Sanitize cell value (criteria)
                if (isset($rule['cellVal'])) {
                    $sanitized_rule['cellVal'] = sanitize_text_field($rule['cellVal']);
                }

                // Sanitize action
                $allowed_actions = [
                    'setCellColor',
                    'setCellContent',
                    'setCellClass',
                    'setRowColor',
                    'setRowClass'
                ];
                if (isset($rule['action']) && in_array($rule['action'], $allowed_actions)) {
                    $sanitized_rule['action'] = sanitize_text_field($rule['action']);
                }

                // Sanitize set value (result). Gated on isset($sanitized_rule['action'])
                // because if the action was rejected by the whitelist above,
                // reading $sanitized_rule['action'] here would emit a PHP 8
                // "Undefined array key" warning (and a TypeError under
                // convertWarningsToExceptions). Rules without an action get
                // dropped by the required-components check below anyway. #1538.
                if (isset($rule['setVal'], $sanitized_rule['action'])) {
                    // For color values, validate hex format or common color names
                    if (in_array($sanitized_rule['action'], ['setCellColor', 'setRowColor'])) {
                        $color_value = sanitize_text_field($rule['setVal']);
                        // Allow hex colors, rgb/rgba values, and named colors
                        if (
                            preg_match('/^#([a-f0-9]{3}){1,2}$/i', $color_value) ||
                            preg_match('/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[0-1]?(?:\.\d+)?)?\s*\)$/i', $color_value) ||
                            in_array(strtolower($color_value), ['red', 'blue', 'green', 'yellow', 'orange', 'purple', 'black', 'white', 'gray', 'grey'])
                        ) {
                            $sanitized_rule['setVal'] = $color_value;
                        }
                    } else {
                        // For other actions, sanitize as text or HTML depending on action
                        if ($sanitized_rule['action'] === 'setCellContent') {
                            $sanitized_rule['setVal'] = wp_kses_post($rule['setVal']);
                        } else {
                            $sanitized_rule['setVal'] = sanitize_text_field($rule['setVal']);
                        }
                    }
                }

                // Only add rule if it has all required components
                if (isset($sanitized_rule['ifClause'], $sanitized_rule['cellVal'], $sanitized_rule['action'], $sanitized_rule['setVal'])) {
                    $sanitized_rules[] = $sanitized_rule;
                }
            }

            if (!empty($sanitized_rules)) {
                $sanitized[sanitize_text_field($field_id)] = $sanitized_rules;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize filter configurations
     * 
     * @param array $filter_configurations Raw filter configuration data
     * @return array Sanitized filter configurations
     */
    private function sanitize_filter_configurations(array $filter_configurations): array
    {
        $sanitized = array();

        if (!is_array($filter_configurations)) {
            // @codeCoverageIgnoreStart
            return $sanitized;
            // @codeCoverageIgnoreEnd
        }

        foreach ($filter_configurations as $field_id => $config) {
            if (!is_array($config)) {
                continue;
            }

            $sanitized_config = array();

            // Sanitize filter type
            $allowed_types = ['text', 'dropdown', 'date', 'range', 'checkboxes'];
            if (isset($config['type']) && in_array($config['type'], $allowed_types)) {
                $sanitized_config['type'] = sanitize_text_field($config['type']);
            } else {
                $sanitized_config['type'] = 'text'; // Default fallback
            }

            // Sanitize placeholder text
            if (isset($config['placeholder'])) {
                $sanitized_config['placeholder'] = sanitize_text_field($config['placeholder']);
            }

            // Sanitize boolean options
            $boolean_fields = [
                'case_sensitive',
                'multiple',
                'exact_match',
                'show_presets',
                'show_select_all',
                'apply_as_default'
            ];
            foreach ($boolean_fields as $field) {
                if (isset($config[$field])) {
                    $sanitized_config[$field] = filter_var($config[$field], FILTER_VALIDATE_BOOLEAN);
                } else {
                    $sanitized_config[$field] = false;
                }
            }

            // Sanitize dropdown sort options
            $allowed_sort_options = ['alphabetical', 'frequency', 'original'];
            if (isset($config['sort_options']) && in_array($config['sort_options'], $allowed_sort_options)) {
                $sanitized_config['sort_options'] = sanitize_text_field($config['sort_options']);
            } else {
                $sanitized_config['sort_options'] = 'alphabetical';
            }

            // Sanitize date range type
            $allowed_date_ranges = ['single', 'range'];
            if (isset($config['date_range']) && in_array($config['date_range'], $allowed_date_ranges)) {
                $sanitized_config['date_range'] = sanitize_text_field($config['date_range']);
            } else {
                $sanitized_config['date_range'] = 'single';
            }

            // Sanitize range step (numeric)
            if (isset($config['range_step'])) {
                $sanitized_config['range_step'] = floatval($config['range_step']);
                if ($sanitized_config['range_step'] <= 0) {
                    $sanitized_config['range_step'] = 1;
                }
            } else {
                $sanitized_config['range_step'] = 1;
            }

            // Sanitize range format
            $allowed_range_formats = ['number', 'currency', 'percentage'];
            if (isset($config['range_format']) && in_array($config['range_format'], $allowed_range_formats)) {
                $sanitized_config['range_format'] = sanitize_text_field($config['range_format']);
            } else {
                $sanitized_config['range_format'] = 'number';
            }

            // Sanitize checkboxes logic
            $allowed_logic_types = ['or', 'and'];
            if (isset($config['checkboxes_logic']) && in_array($config['checkboxes_logic'], $allowed_logic_types)) {
                $sanitized_config['checkboxes_logic'] = sanitize_text_field($config['checkboxes_logic']);
            } else {
                $sanitized_config['checkboxes_logic'] = 'or';
            }

            // Sanitize default filter values (for backend-only filtering of hidden fields)
            if (isset($config['default_filter']) && is_array($config['default_filter'])) {
                $default_filter = $config['default_filter'];
                $sanitized_default = array();

                // Sanitize default filter type
                if (isset($default_filter['type']) && in_array($default_filter['type'], $allowed_types)) {
                    $sanitized_default['type'] = sanitize_text_field($default_filter['type']);

                    // Sanitize values based on filter type
                    switch ($sanitized_default['type']) {
                        case 'text':
                            if (isset($default_filter['value'])) {
                                $sanitized_default['value'] = sanitize_text_field($default_filter['value']);
                            }
                            break;

                        case 'dropdown':
                        // @codeCoverageIgnoreStart
                        case 'checkboxes':
                        // @codeCoverageIgnoreEnd
                            if (isset($default_filter['values']) && is_array($default_filter['values'])) {
                                $sanitized_default['values'] = array_map('sanitize_text_field', $default_filter['values']);
                            }
                            break;

                        // @codeCoverageIgnoreStart
                        case 'date_range':
                        case 'number_range':
                            if (isset($default_filter['from'])) {
                                $sanitized_default['from'] = sanitize_text_field($default_filter['from']);
                        // @codeCoverageIgnoreEnd
                            }
                            // @codeCoverageIgnoreStart
                            if (isset($default_filter['to'])) {
                                $sanitized_default['to'] = sanitize_text_field($default_filter['to']);
                            // @codeCoverageIgnoreEnd
                            }
                            // @codeCoverageIgnoreStart
                            if (isset($default_filter['min'])) {
                                $sanitized_default['min'] = floatval($default_filter['min']);
                            // @codeCoverageIgnoreEnd
                            }
                            // @codeCoverageIgnoreStart
                            if (isset($default_filter['max'])) {
                                $sanitized_default['max'] = floatval($default_filter['max']);
                            // @codeCoverageIgnoreEnd
                            }
                            // @codeCoverageIgnoreStart
                            break;
                            // @codeCoverageIgnoreEnd
                    }

                    if (!empty($sanitized_default)) {
                        $sanitized_config['default_filter'] = $sanitized_default;
                    }
                }
            }

            // Only add configuration if it has required components
            if (!empty($sanitized_config['type'])) {
                $sanitized[sanitize_text_field($field_id)] = $sanitized_config;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize field configurations
     *
     * @param array $field_configurations Field configurations to sanitize
     * @return array Sanitized field configurations
     */
    private function sanitize_field_configurations(array $field_configurations): array
    {
        $sanitized = array();

        if (!is_array($field_configurations)) {
            // @codeCoverageIgnoreStart
            return $sanitized;
            // @codeCoverageIgnoreEnd
        }

        foreach ($field_configurations as $field_id => $config) {
            if (!is_array($config)) {
                continue;
            }

            $sanitized_config = array();

            // Sanitize boolean fields
            $boolean_fields = ['editable', 'sortable', 'filterable'];
            foreach ($boolean_fields as $field) {
                if (isset($config[$field])) {
                    $sanitized_config[$field] = filter_var($config[$field], FILTER_VALIDATE_BOOLEAN);
                }
            }

            // Sanitize text fields; auto-generate a fallback label for blank column names
            // to prevent PHP fatals when column title is empty (#370).
            if (isset($config['custom_label'])) {
                $label = sanitize_text_field($config['custom_label']);
                if ($label === '') {
                    $label = 'Column ' . sanitize_key($field_id);
                }
                $sanitized_config['custom_label'] = $label;
            }

            if (isset($config['width'])) {
                $sanitized_config['width'] = sanitize_text_field($config['width']);
            }

            $sanitized[sanitize_text_field($field_id)] = $sanitized_config;
        }

        return $sanitized;
    }

    /**
     * Fix parent menu highlighting for edit pages
     */
    public function fix_menu_highlighting($parent_file)
    {
        global $plugin_page;

        // When editing a table (page=gravity-tables-new&id=X), keep parent highlighted
        if ($plugin_page === 'gravity-tables-new' && isset($_GET['id'])) {
            return 'gravity-tables';
        }

        return $parent_file;
    }

    /**
     * Fix submenu highlighting for edit pages
     */
    public function fix_submenu_highlighting($submenu_file)
    {
        global $plugin_page;

        // When editing a table, highlight "All Tables" instead of "Create New"
        if ($plugin_page === 'gravity-tables-new' && isset($_GET['id'])) {
            return 'gravity-tables'; // This highlights "All Tables"
        }

        return $submenu_file;
    }

    /**
     * Sanitize responsive settings for fields
     *
     * @param array $responsive_settings Raw responsive settings
     * @return array Sanitized responsive settings
     */
    private function sanitize_responsive_settings($responsive_settings)
    {
        $sanitized = array();

        if (!is_array($responsive_settings)) {
            return $sanitized;
        }

        foreach ($responsive_settings as $field_id => $settings) {
            if (!is_array($settings)) {
                continue;
            }

            $field_id = sanitize_text_field($field_id);
            $sanitized[$field_id] = array();

            // Sanitize mobile visibility
            if (isset($settings['mobile_visible'])) {
                $sanitized[$field_id]['mobile_visible'] = filter_var($settings['mobile_visible'], FILTER_VALIDATE_BOOLEAN);
            }

            // Sanitize tablet visibility
            if (isset($settings['tablet_visible'])) {
                $sanitized[$field_id]['tablet_visible'] = filter_var($settings['tablet_visible'], FILTER_VALIDATE_BOOLEAN);
            }

            // Sanitize mobile label override
            if (isset($settings['mobile_label']) && !empty($settings['mobile_label'])) {
                $sanitized[$field_id]['mobile_label'] = sanitize_text_field($settings['mobile_label']);
            }
        }

        return $sanitized;
    }

    /**
     * Prefix every top-level selector in a CSS string with a scope selector.
     * At-rules (@media, @keyframes, @supports) pass through with their inner
     * rules scoped. Returns scoped CSS safe to embed in a <style> block.
     */
    public static function scope_custom_css(string $css, string $scope_selector): string
    {
        $css = trim($css);
        if ($css === '' || $scope_selector === '') {
            return '';
        }

        // Walk the CSS character by character to handle nested at-rules properly.
        $out = '';
        $len = strlen($css);
        $i = 0;
        while ($i < $len) {
            // Skip whitespace
            while ($i < $len && ctype_space($css[$i])) {
                $out .= $css[$i];
                $i++;
            }
            if ($i >= $len) break;

            if ($css[$i] === '@') {
                // At-rule: copy verbatim until matching close brace (or semicolon for at-rules without blocks)
                $start = $i;
                $brace_pos = strpos($css, '{', $i);
                $semi_pos = strpos($css, ';', $i);
                if ($brace_pos === false || ($semi_pos !== false && $semi_pos < $brace_pos)) {
                    $end = $semi_pos === false ? $len : $semi_pos + 1;
                    $out .= substr($css, $start, $end - $start);
                    $i = $end;
                    continue;
                }
                // Has a block — scope the inner content
                $depth = 1;
                $j = $brace_pos + 1;
                while ($j < $len && $depth > 0) {
                    if ($css[$j] === '{') $depth++;
                    elseif ($css[$j] === '}') $depth--;
                    if ($depth === 0) break;
                    $j++;
                }
                $inner = substr($css, $brace_pos + 1, $j - $brace_pos - 1);
                $out .= substr($css, $start, $brace_pos - $start + 1);
                $out .= self::scope_custom_css($inner, $scope_selector);
                $out .= '}';
                $i = $j + 1;
                continue;
            }

            // Regular rule: scan to opening brace
            $brace_pos = strpos($css, '{', $i);
            if ($brace_pos === false) break;
            $selectors_raw = substr($css, $i, $brace_pos - $i);

            // Find matching close brace
            $depth = 1;
            $j = $brace_pos + 1;
            while ($j < $len && $depth > 0) {
                if ($css[$j] === '{') $depth++;
                elseif ($css[$j] === '}') $depth--;
                if ($depth === 0) break;
                $j++;
            }
            $body = substr($css, $brace_pos + 1, $j - $brace_pos - 1);

            $selectors = array_map('trim', explode(',', $selectors_raw));
            $prefixed = array();
            foreach ($selectors as $sel) {
                if ($sel === '') continue;
                // Don't double-prefix if user already typed the scope
                if (strpos($sel, $scope_selector) === 0) {
                    $prefixed[] = $sel;
                } else {
                    $prefixed[] = $scope_selector . ' ' . $sel;
                }
            }
            $out .= implode(', ', $prefixed) . ' {' . $body . '}';
            $i = $j + 1;
        }

        return $out;
    }

    /**
     * Sanitize per-table custom CSS. Strips <script> tags, expression(),
     * javascript: URLs, and HTML angle brackets that could escape a <style> block.
     */
    /**
     * Persist a manually reordered row sequence for a table (#440).
     *
     * @param int   $tableId Table ID.
     * @param array $order   Array of entry IDs in the desired display order.
     * @return bool True on success, false on failure.
     */
    public function save_row_order(int $tableId, array $order): bool
    {
        global $wpdb;

        $sanitized = array_values(array_map('intval', array_filter($order, 'is_numeric')));

        $existing_json = $wpdb->get_var($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d",
            $tableId
        ));

        if ($existing_json === null) {
            return false;
        }

        $settings = json_decode($existing_json, true) ?: [];
        $settings['row_order'] = $sanitized;

        $result = $wpdb->update(
            $wpdb->prefix . 'gravity_tables',
            ['settings' => wp_json_encode($settings), 'updated_at' => current_time('mysql')],
            ['id' => $tableId],
            ['%s', '%s'],
            ['%d']
        );

        if ($result !== false) {
            wp_cache_delete('gt_table_' . $tableId, 'gravity_tables');
        }

        return $result !== false;
    }

    public static function sanitize_custom_css(string $css): string
    {
        if ($css === '') {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }

        // Strip script/style blocks including their content before removing remaining tags
        $css = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $css);
        $css = wp_strip_all_tags($css);

        // Disallow CSS expressions and javascript: URLs (case-insensitive)
        $patterns = array(
            '/expression\s*\(/i',
            '/javascript\s*:/i',
            '/vbscript\s*:/i',
            '/data\s*:[^;\)]*script/i',
            '/@import\b/i',
            '/behavior\s*:/i',
        );
        $css = preg_replace($patterns, '/* removed */', $css);

        // Cap length to prevent abuse (10KB is plenty for per-table CSS)
        if (strlen($css) > 10240) {
            // @codeCoverageIgnoreStart
            $css = substr($css, 0, 10240);
            // @codeCoverageIgnoreEnd
        }

        return trim($css);
    }
}