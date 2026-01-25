<?php
/**
 * Plugin Name: TableCrafter – Data to Beautiful Tables
 * Plugin URI: https://github.com/TableCrafter/wp-data-tables
 * Description: Transform any data source into responsive WordPress tables. WCAG 2.1 compliant, advanced export (Excel/PDF), keyboard navigation, screen readers.
 * Version: 3.5.3
 * Author: TableCrafter Team
 * Author URI: https://github.com/fahdi
 * License: GPLv2 or later
 * Text Domain: tablecrafter-wp-data-tables
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check PHP version compatibility
 */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>TableCrafter Error:</strong> This plugin requires PHP 8.0 or higher. ';
        echo 'You are running PHP ' . PHP_VERSION . '. Please upgrade your PHP version.';
        echo '</p></div>';
    });
    return;
}

/**
 * Global Constants
 */
define('TABLECRAFTER_VERSION', '3.5.3');
define('TABLECRAFTER_URL', plugin_dir_url(__FILE__));
define('TABLECRAFTER_PATH', plugin_dir_path(__FILE__));

/**
 * Include Dependencies
 */
// Core utilities (load first as others depend on them)
if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-security.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-security.php';
}

if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-cache.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-cache.php';
}

// Data sources
if (file_exists(TABLECRAFTER_PATH . 'includes/sources/class-tc-csv-source.php')) {
    require_once TABLECRAFTER_PATH . 'includes/sources/class-tc-csv-source.php';
}

// HTTP Request handler (load before data fetcher as it depends on it)
if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-http-request.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-http-request.php';
}

// Data Source Health Monitor (new TDD feature)
if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-data-source-health-monitor.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-data-source-health-monitor.php';
}

if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-data-fetcher.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-data-fetcher.php';
}

// Feature handlers
if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-export-handler.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-export-handler.php';
}

// Enhanced export handler (fixes export deception issue)
if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-export-handler-enhanced.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-export-handler-enhanced.php';
}

if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-performance-optimizer.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-performance-optimizer.php';
}

// Real-time collaboration system
if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-collaboration.php')) {
    require_once TABLECRAFTER_PATH . 'includes/class-tc-collaboration.php';
}

// Load Elementor widget only when Elementor is available
add_action('elementor/loaded', function () {
    if (file_exists(TABLECRAFTER_PATH . 'includes/class-tc-elementor-widget.php')) {
        require_once TABLECRAFTER_PATH . 'includes/class-tc-elementor-widget.php';

        // Register category first
        if (function_exists('add_tc_elementor_category')) {
            add_action('elementor/elements/categories_registered', 'add_tc_elementor_category');
        }

        // Register widget using both hooks for maximum compatibility  
        if (function_exists('register_tc_elementor_widget')) {
            // Try both registration methods for maximum compatibility
            add_action('elementor/widgets/register', 'register_tc_elementor_widget');
            add_action('elementor/widgets/widgets_registered', 'register_tc_elementor_widget');
        }
    }
});


/**
 * Main TableCrafter Class
 * 
 * Handles registration, rendering, security, and caching for the TableCrafter plugin.
 */
class TableCrafter
{

    /**
     * Singleton instance.
     * @var TableCrafter|null
     */
    private static $instance = null;

    /**
     * HTTP request handler
     * @var TC_HTTP_Request
     */
    private $http;



    /**
     * Get singleton instance.
     * 
     * @return TableCrafter The single instance of the class.
     */
    public static function get_instance(): TableCrafter
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     * 
     * Initializes all WordPress hooks, shortcodes, and cron schedules.
     */
    private function __construct()
    {
        // Initialize enhanced export handler
        if (class_exists('TC_Export_Handler_Enhanced')) {
            TC_Export_Handler_Enhanced::get_instance();
        }

        add_action('init', array($this, 'register_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('init', array($this, 'register_block'));
        add_shortcode('tablecrafter', array($this, 'render_table'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'welcome_redirect'));
        add_action('wp_head', array($this, 'add_export_nonce'));
        add_action('admin_head', array($this, 'add_export_nonce'));

        // AJAX Proxy Handlers for frontend and admin
        add_action('wp_ajax_tc_proxy_fetch', array($this, 'ajax_proxy_fetch'));
        add_action('wp_ajax_nopriv_tc_proxy_fetch', array($this, 'ajax_proxy_fetch'));

        // Background Caching & Cron Logic
        add_action('tc_refresher_cron', array($this, 'automated_cache_refresh'));
        add_action('tc_refresh_single_source', array($this, 'refresh_source_cache'), 10, 1);

        // Lead Magnet Handler
        add_action('wp_ajax_tc_subscribe_lead', array($this, 'handle_lead_subscription'));
        add_action('wp_ajax_nopriv_tc_subscribe_lead', array($this, 'handle_lead_subscription'));

        // Advanced Export Handlers
        add_action('wp_ajax_tc_export_data', array($this, 'ajax_export_data'));
        add_action('wp_ajax_nopriv_tc_export_data', array($this, 'ajax_export_data'));
        add_action('wp_ajax_tc_download_export', array($this, 'ajax_download_export'));
        add_action('wp_ajax_nopriv_tc_download_export', array($this, 'ajax_download_export'));

        // Elementor Live Preview Handler
        add_action('wp_ajax_tc_elementor_preview', array($this, 'ajax_elementor_preview'));
        add_action('wp_ajax_nopriv_tc_elementor_preview', array($this, 'ajax_elementor_preview'));

        // Airtable Token Handler [v3.5.0]
        add_action('wp_ajax_tc_save_airtable_token', array($this, 'ajax_save_airtable_token'));

        if (!wp_next_scheduled('tc_refresher_cron')) {
            wp_schedule_event(time(), 'hourly', 'tc_refresher_cron');
        }

        // WP-CLI Integration
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('tablecrafter', array($this, 'cli_commands'));
        }


    }

    /**
     * Add admin menu page under the 'Tools' or 'Settings' category.
     * 
     * @return void
     */
    public function add_admin_menu(): void
    {
        add_menu_page(
            __('TableCrafter', 'tablecrafter-wp-data-tables'),
            __('TableCrafter', 'tablecrafter-wp-data-tables'),
            'manage_options',
            'tablecrafter-wp-data-tables',
            array($this, 'render_admin_page'),
            'dashicons-editor-table',
            20
        );

        add_submenu_page(
            'tablecrafter-wp-data-tables',
            __('Welcome to TableCrafter', 'tablecrafter-wp-data-tables'),
            __('Welcome', 'tablecrafter-wp-data-tables'),
            'manage_options',
            'tablecrafter-welcome',
            array($this, 'render_welcome_page')
        );
    }

    /**
     * Render the admin dashboard page.
     * 
     * Displays settings, shortcode generator, and live-preview playground.
     * 
     * @return void
     */
    public function render_admin_page(): void
    {
        // Enqueue preview assets
        wp_enqueue_script('tablecrafter-lib');
        wp_enqueue_style('tablecrafter-style');

        $users_url = TABLECRAFTER_URL . 'demo-data/users.json';
        $products_url = TABLECRAFTER_URL . 'demo-data/products.json';
        $metrics_url = TABLECRAFTER_URL . 'demo-data/metrics.json';
        $employees_url = TABLECRAFTER_URL . 'demo-data/employees.csv';
        $sheets_url = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit#gid=0';
        $airtable_demo_url = 'airtable://appSampleBaseId/Employees';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('TableCrafter', 'tablecrafter-wp-data-tables'); ?></h1>
            <p><?php esc_html_e('Generate dynamic HTML tables from any JSON data source.', 'tablecrafter-wp-data-tables'); ?>
            </p>
            <hr class="wp-header-end">

            <div class="tc-admin-layout">

                <div class="tc-sidebar">
                    <div class="card"
                        style="margin: 0 0 20px 0; max-width: none; background: linear-gradient(135deg, #e0f7fa 0%, #f0f9ff 100%); border-left: 4px solid #0891b2;">
                        <h2 style="color: #0e7490; margin-top: 0; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 20px;">🚀</span>
                            <?php esc_html_e('Quick Start Demos', 'tablecrafter-wp-data-tables'); ?>
                        </h2>
                        <p style="margin-bottom: 15px; color: #155e75; font-weight: 500;">
                            <?php esc_html_e('Click any dataset below to instantly load a demo table:', 'tablecrafter-wp-data-tables'); ?>
                        </p>
                        <ul class="tc-demo-links" style="margin: 0;">
                            <li style="margin-bottom: 8px;"><a href="#" class="button button-large"
                                    style="width: 100%; text-align: left; background: white; border: 2px solid #0891b2; color: #0e7490; font-weight: 600; transition: all 0.2s ease;"
                                    onmouseover="this.style.background='#0891b2'; this.style.color='white'"
                                    onmouseout="this.style.background='white'; this.style.color='#0e7490'"
                                    data-url="<?php echo esc_url($sheets_url); ?>">📑
                                    <?php esc_html_e('Student Grades (Google Sheet)', 'tablecrafter-wp-data-tables'); ?></a>
                            </li>
                            <li style="margin-bottom: 8px;"><a href="#" class="button button-large"
                                    style="width: 100%; text-align: left; background: white; border: 2px solid #0891b2; color: #0e7490; font-weight: 600; transition: all 0.2s ease;"
                                    onmouseover="this.style.background='#0891b2'; this.style.color='white'"
                                    onmouseout="this.style.background='white'; this.style.color='#0e7490'"
                                    data-url="<?php echo esc_url($users_url); ?>">👤
                                    <?php esc_html_e('User Directory (JSON)', 'tablecrafter-wp-data-tables'); ?></a></li>
                            <li style="margin-bottom: 8px;"><a href="#" class="button button-large"
                                    style="width: 100%; text-align: left; background: white; border: 2px solid #0891b2; color: #0e7490; font-weight: 600; transition: all 0.2s ease;"
                                    onmouseover="this.style.background='#0891b2'; this.style.color='white'"
                                    onmouseout="this.style.background='white'; this.style.color='#0e7490'"
                                    data-url="<?php echo esc_url($products_url); ?>">📦
                                    <?php esc_html_e('Product Inventory (JSON)', 'tablecrafter-wp-data-tables'); ?></a></li>
                            <li style="margin-bottom: 8px;"><a href="#" class="button button-large"
                                    style="width: 100%; text-align: left; background: white; border: 2px solid #0891b2; color: #0e7490; font-weight: 600; transition: all 0.2s ease;"
                                    onmouseover="this.style.background='#0891b2'; this.style.color='white'"
                                    onmouseout="this.style.background='white'; this.style.color='#0e7490'"
                                    data-url="<?php echo esc_url($employees_url); ?>">📊
                                    <?php esc_html_e('Employee List (CSV)', 'tablecrafter-wp-data-tables'); ?></a></li>
                            <li style="margin-bottom: 8px;"><a href="#" class="button button-large"
                                    style="width: 100%; text-align: left; background: white; border: 2px solid #0891b2; color: #0e7490; font-weight: 600; transition: all 0.2s ease;"
                                    onmouseover="this.style.background='#0891b2'; this.style.color='white'"
                                    onmouseout="this.style.background='white'; this.style.color='#0e7490'"
                                    data-url="<?php echo esc_url($metrics_url); ?>">📈
                                    <?php esc_html_e('Sales Metrics (JSON)', 'tablecrafter-wp-data-tables'); ?></a></li>
                            <li style="margin-bottom: 0;"><a href="#" class="button button-large"
                                    style="width: 100%; text-align: left; background: white; border: 2px solid #0891b2; color: #0e7490; font-weight: 600; transition: all 0.2s ease;"
                                    onmouseover="this.style.background='#0891b2'; this.style.color='white'"
                                    onmouseout="this.style.background='white'; this.style.color='#0e7490'"
                                    data-url="<?php echo esc_url($airtable_demo_url); ?>">🔮
                                    <?php esc_html_e('Airtable Base (API)', 'tablecrafter-wp-data-tables'); ?></a></li>
                        </ul>
                        <div
                            style="margin-top: 12px; padding: 8px 12px; background: rgba(8, 145, 178, 0.1); border-radius: 6px; border: 1px dashed #0891b2;">
                            <p style="margin: 0; font-size: 12px; color: #155e75; text-align: center;">
                                ↑ <strong>Instant Demo:</strong> No setup required! Each dataset shows different table features.
                            </p>
                        </div>
                    </div>

                    <div class="card" style="margin: 0 0 20px 0; max-width: none;">
                        <h2><?php esc_html_e('Settings', 'tablecrafter-wp-data-tables'); ?></h2>
                        <div style="margin-bottom: 15px;">
                            <label for="tc-preview-url"
                                style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e('Data Source URL', 'tablecrafter-wp-data-tables'); ?></label>

                            <div style="display: flex; gap: 5px; margin-bottom: 8px;">
                                <input type="text" id="tc-preview-url" class="widefat"
                                    placeholder="https://api.example.com/data.json" style="flex: 1;"
                                    value="<?php echo isset($_GET['demo_url']) ? esc_attr(sanitize_text_field(wp_unslash($_GET['demo_url']))) : ''; ?>">
                            </div>

                            <div class="button-group">
                                <button id="tc-upload-csv-btn" class="button button-secondary" type="button">
                                    <span class="dashicons dashicons-upload"
                                        style="margin-right: 4px; vertical-align: middle;"></span>
                                    <?php esc_html_e('Upload File (CSV/JSON)', 'tablecrafter-wp-data-tables'); ?>
                                </button>
                                <button id="tc-google-sheet-btn" class="button button-secondary" type="button"
                                    title="<?php esc_attr_e('Paste a Google Sheet URL', 'tablecrafter-wp-data-tables'); ?>">
                                    <span class="dashicons dashicons-media-spreadsheet"
                                        style="margin-right: 4px; vertical-align: middle;"></span>
                                    <?php esc_html_e('Google Sheets', 'tablecrafter-wp-data-tables'); ?>
                                </button>
                                <button id="tc-airtable-btn" class="button button-secondary" type="button"
                                    title="<?php esc_attr_e('Connect to Airtable Base', 'tablecrafter-wp-data-tables'); ?>">
                                    <span class="dashicons dashicons-database"
                                        style="margin-right: 4px; vertical-align: middle;"></span>
                                    <?php esc_html_e('Airtable', 'tablecrafter-wp-data-tables'); ?>
                                </button>
                            </div>

                            <p class="description" style="margin-top: 5px;">
                                <?php esc_html_e('Enter a remote URL, upload a file, or paste a public Google Sheet link.', 'tablecrafter-wp-data-tables'); ?>
                            </p>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label for="tc-data-root"
                                style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e('Data Root (Optional)', 'tablecrafter-wp-data-tables'); ?></label>
                            <input type="text" id="tc-data-root" class="widefat" placeholder="data.items">
                            <p class="description">
                                <?php esc_html_e('Path to the data array (e.g., results.items).', 'tablecrafter-wp-data-tables'); ?>
                            </p>
                        </div>

                        <div class="form-row">
                            <div>
                                <label for="tc-per-page"
                                    style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e('Rows Per Page', 'tablecrafter-wp-data-tables'); ?></label>
                                <input type="number" id="tc-per-page" class="widefat" value="10" min="1">
                            </div>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label for="tc-include-cols"
                                style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e('Include Columns', 'tablecrafter-wp-data-tables'); ?></label>
                            <input type="text" id="tc-include-cols" class="widefat" placeholder="name, price, stock">
                            <p class="description">
                                <?php esc_html_e('Comma-separated list of keys to show.', 'tablecrafter-wp-data-tables'); ?>
                            </p>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label for="tc-exclude-cols"
                                style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e('Exclude Columns', 'tablecrafter-wp-data-tables'); ?></label>
                            <input type="text" id="tc-exclude-cols" class="widefat" placeholder="id, password_hash">
                            <p class="description">
                                <?php esc_html_e('Comma-separated list of keys to hide.', 'tablecrafter-wp-data-tables'); ?>
                            </p>
                        </div>

                        <div class="checkbox-group">
                            <label style="font-weight: 600; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="tc-enable-search" checked>
                                <?php esc_html_e('Enable Search', 'tablecrafter-wp-data-tables'); ?>
                            </label>
                            <label style="font-weight: 600; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="tc-enable-filters" checked>
                                <?php esc_html_e('Enable Filters', 'tablecrafter-wp-data-tables'); ?>
                            </label>
                            <label style="font-weight: 600; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="tc-enable-export">
                                <?php esc_html_e('Enable Export', 'tablecrafter-wp-data-tables'); ?>
                            </label>
                        </div>

                        <div class="button-group">
                            <button id="tc-preview-btn"
                                class="button button-primary button-large"><?php esc_html_e('Preview Table', 'tablecrafter-wp-data-tables'); ?></button>
                        </div>
                    </div>

                    <div class="card" style="margin: 0; max-width: none;">
                        <h2><?php esc_html_e('Usage', 'tablecrafter-wp-data-tables'); ?></h2>
                        <p><?php esc_html_e('Copy the shortcode below to use this table:', 'tablecrafter-wp-data-tables'); ?>
                        </p>
                        <code id="tc-shortcode-display"
                            style="display: block; padding: 10px; background: #f0f0f1; margin: 10px 0; word-break: break-all;">[tablecrafter source="..."]</code>
                        <button id="tc-copy-shortcode" class="button button-secondary"
                            style="width: 100%;"><?php esc_html_e('Copy Shortcode', 'tablecrafter-wp-data-tables'); ?></button>
                    </div>
                </div>

                <div class="tc-preview-area">
                    <div class="card"
                        style="margin: 0; max-width: none; min-height: 500px; display: flex; flex-direction: column;">
                        <h2
                            style="border-bottom: 1px solid #f0f0f1; padding-bottom: 15px; margin-bottom: 15px; margin-top: 0; display: flex; align-items: center; justify-content: space-between;">
                            <span><?php esc_html_e('Live Preview', 'tablecrafter-wp-data-tables'); ?></span>
                            <small style="font-weight: normal; color: #666; font-size: 13px;">Try search, sort, filters &
                                export</small>
                        </h2>

                        <div id="tc-preview-wrap" style="flex: 1; overflow: auto; background: #fff;">
                            <div id="tc-preview-container"
                                style="display: flex; align-items: center; justify-content: center; height: 100%; color: #666; min-height: 400px;">
                                <div style="text-align: center;">
                                    <span class="dashicons dashicons-editor-table"
                                        style="font-size: 48px; width: 48px; height: 48px; color: #ddd;"></span>
                                    <p style="margin: 16px 0 8px; font-size: 16px; color: #333;">
                                        <?php esc_html_e('Ready to generate your table!', 'tablecrafter-wp-data-tables'); ?>
                                    </p>
                                    <p style="margin: 0; font-size: 14px; color: #666;">
                                        <?php esc_html_e('👈 Click a Quick Start Demo or enter your own URL', 'tablecrafter-wp-data-tables'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Register frontend assets (JS/CSS).
     * 
     * Registers the core TableCrafter library and frontend initialization scripts.
     * 
     * @return void
     */
    public function register_assets(): void
    {
        wp_register_script(
            'tablecrafter-lib',
            TABLECRAFTER_URL . 'assets/js/tablecrafter.js',
            array(),
            TABLECRAFTER_VERSION,
            true
        );

        wp_register_script(
            'tablecrafter-frontend',
            TABLECRAFTER_URL . 'assets/js/frontend.js',
            array('tablecrafter-lib'),
            TABLECRAFTER_VERSION,
            true
        );

        wp_localize_script('tablecrafter-frontend', 'tablecrafterData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_proxy_nonce'),
            'exportNonce' => wp_create_nonce('tc_export_nonce'),
            'downloadNonce' => wp_create_nonce('tc_download_nonce'),
            'i18n' => $this->get_js_i18n_strings()
        ));
        
        // Make export nonce globally available for enhanced export functionality
        wp_add_inline_script('tablecrafter-frontend', 'window.tcExportNonce = "' . wp_create_nonce('tc_export_nonce') . '";', 'before');

        wp_register_style(
            'tablecrafter-style',
            TABLECRAFTER_URL . 'assets/css/tablecrafter.css',
            array(),
            TABLECRAFTER_VERSION
        );

        // Collaboration styles (loaded separately for modularity)
        wp_register_style(
            'tablecrafter-collaboration',
            TABLECRAFTER_URL . 'assets/css/collaboration.css',
            array('tablecrafter-style'),
            TABLECRAFTER_VERSION
        );

        // Elementor preview script
        wp_register_script(
            'tc-elementor-preview',
            TABLECRAFTER_URL . 'assets/js/elementor-preview.js',
            array('jquery', 'tablecrafter-lib'),
            TABLECRAFTER_VERSION,
            true
        );

        wp_localize_script('tc-elementor-preview', 'tablecrafterData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_proxy_nonce')
        ));
    }

    /**
     * Enqueue admin assets.
     * 
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets($hook): void
    {
        if (strpos($hook, 'tablecrafter-wp-data-tables') === false) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            'tablecrafter-admin',
            TABLECRAFTER_URL . 'assets/js/admin.js',
            array('jquery'),
            TABLECRAFTER_VERSION,
            true
        );

        wp_localize_script('tablecrafter-admin', 'tablecrafterAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_proxy_nonce'),
            'i18n' => array(
                'enterUrl' => __('Please enter a valid URL', 'tablecrafter-wp-data-tables'),
                'loading' => __('Loading data from source...', 'tablecrafter-wp-data-tables'),
                'libMissing' => __('TableCrafter library not loaded.', 'tablecrafter-wp-data-tables'),
                'copyFailed' => __('Failed to copy to clipboard. Please copy manually.', 'tablecrafter-wp-data-tables'),
                'copied' => __('Copied to Clipboard!', 'tablecrafter-wp-data-tables')
            )
        ));

        $this->register_assets();
        wp_enqueue_script('tablecrafter-lib');
        wp_enqueue_style('tablecrafter-style');
    }

    /**
     * Register Gutenberg Native Block.
     * 
     * Registers 'tablecrafter/data-table' with native sidebar attributes.
     * 
     * @return void
     */
    public function register_block(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        // Ensure assets are registered before the block
        $this->register_assets();

        wp_register_script(
            'tablecrafter-block',
            TABLECRAFTER_URL . 'assets/js/block.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-server-side-render', 'tablecrafter-lib'),
            TABLECRAFTER_VERSION,
            true // Load in footer for better performance
        );

        wp_localize_script('tablecrafter-block', 'tablecrafterData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tc_proxy_nonce'),
            'demoUrls' => array(
                array('label' => __('Select a demo...', 'tablecrafter-wp-data-tables'), 'value' => ''),
                array('label' => __('👤 User Directory (JSON)', 'tablecrafter-wp-data-tables'), 'value' => TABLECRAFTER_URL . 'demo-data/users.json'),
                array('label' => __('📦 Product Inventory (JSON)', 'tablecrafter-wp-data-tables'), 'value' => TABLECRAFTER_URL . 'demo-data/products.json'),
                array('label' => __('📈 Sales Metrics (JSON)', 'tablecrafter-wp-data-tables'), 'value' => TABLECRAFTER_URL . 'demo-data/metrics.json'),
                array('label' => __('👥 Employee List (CSV)', 'tablecrafter-wp-data-tables'), 'value' => TABLECRAFTER_URL . 'demo-data/employees.csv'),
                array('label' => __('📋 Google Sheets Example', 'tablecrafter-wp-data-tables'), 'value' => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit#gid=0'),
                array('label' => __('🔮 Airtable Base (API)', 'tablecrafter-wp-data-tables'), 'value' => 'airtable://appSampleBaseId/Employees'),
            )
        ));

        register_block_type('tablecrafter/data-table', array(
            'editor_script' => 'tablecrafter-block',
            'style' => 'tablecrafter-style',
            'render_callback' => array($this, 'render_block_callback'),
            'attributes' => array(
                'source' => array('type' => 'string', 'default' => ''),
                'root' => array('type' => 'string', 'default' => ''),
                'include' => array('type' => 'string', 'default' => ''),
                'exclude' => array('type' => 'string', 'default' => ''), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- User-facing parameter, not a query param
                'search' => array('type' => 'boolean', 'default' => false),
                'filters' => array('type' => 'boolean', 'default' => true),
                'per_page' => array('type' => 'number', 'default' => 0),
                'export' => array('type' => 'boolean', 'default' => false),
                'id' => array('type' => 'string', 'default' => ''),
                // Auto-refresh attributes
                'auto_refresh' => array('type' => 'boolean', 'default' => false),
                'refresh_interval' => array('type' => 'number', 'default' => 300000),
                'refresh_indicator' => array('type' => 'boolean', 'default' => true),
                'refresh_countdown' => array('type' => 'boolean', 'default' => false),
                'refresh_last_updated' => array('type' => 'boolean', 'default' => true),
            ),
        ));
    }

    /**
     * Block Render Callback.
     * 
     * Bridge between Gutenberg block engine and PHP shortcode engine.
     * 
     * @param array $attributes Block attributes.
     * @return string Rendered HTML.
     */
    public function render_block_callback($attributes): string
    {
        if (empty($attributes['id'])) {
            $attributes['id'] = 'tc-block-' . uniqid();
        }
        return $this->render_table($attributes);
    }

    /**
     * Shortcode: [tablecrafter]
     * 
     * Main entry point for frontend rendering. Handles SWR caching (Stale-While-Revalidate).
     * 
     * @param array $atts User-defined attributes.
     * @return string HTML table container.
     */
    public function render_table($atts): string
    {
        $atts = shortcode_atts(array(
            'source' => '',
            'id' => 'tc-' . uniqid(),
            'include' => '',
            'exclude' => '', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Shortcode attribute, not a query param
            'root' => '',
            'search' => false,
            'filters' => true,
            'export' => false,
            'per_page' => 0,
            'sort' => '', // column:direction format (e.g., "price:desc")
            'auto_refresh' => false,
            'refresh_interval' => 300000, // 5 minutes default (milliseconds)
            'refresh_indicator' => true,
            'refresh_countdown' => false,
            'refresh_last_updated' => true
        ), $atts, 'tablecrafter');

        // Normalize boolean-ish attributes
        foreach (array('search', 'filters', 'export', 'auto_refresh', 'refresh_indicator', 'refresh_countdown', 'refresh_last_updated') as $bool_att) {
            if (is_string($atts[$bool_att])) {
                $lower = strtolower($atts[$bool_att]);
                $atts[$bool_att] = ($lower === 'true' || $lower === '1' || $lower === 'yes');
            } else {
                $atts[$bool_att] = (bool) $atts[$bool_att];
            }
        }

        $atts['source'] = esc_url_raw($atts['source']);

        if (empty($atts['source'])) {
            return $this->render_empty_source_placeholder();
        }

        // SWR (Stale-While-Revalidate) Logic
        // Include search, export, per_page, and sort in the cache key to prevent collision
        $cache_key = 'tc_html_' . md5(
            $atts['source'] .
            $atts['include'] .
            $atts['exclude'] .
            ($atts['search'] ? '1' : '0') .
            ($atts['filters'] ? '1' : '0') .
            ($atts['export'] ? '1' : '0') .
            $atts['per_page'] .
            $atts['sort'] .
            TABLECRAFTER_VERSION
        );
        $cache_data = get_transient($cache_key);
        $html_content = '';

        if ($cache_data !== false) {
            $html_content = isset($cache_data['html']) ? $cache_data['html'] : '';
            $initial_data = isset($cache_data['data']) ? $cache_data['data'] : array();
            $timestamp = isset($cache_data['time']) ? $cache_data['time'] : 0;

            // Trigger invisible refresh if cache is older than 5 mins
            if (time() - $timestamp > (5 * MINUTE_IN_SECONDS)) {
                if (!wp_next_scheduled('tc_refresh_single_source', array($atts))) {
                    wp_schedule_single_event(time(), 'tc_refresh_single_source', array($atts));
                }
            }
        } else {
            // First time render (Synch)
            $render_result = $this->fetch_and_render_php($atts);
            if (isset($render_result['html']) && !empty($render_result['html'])) {
                $html_content = $render_result['html'];
                $initial_data = isset($render_result['data']) ? $render_result['data'] : array();
                set_transient($cache_key, array(
                    'html' => $html_content,
                    'data' => $initial_data,
                    'time' => time()
                ), HOUR_IN_SECONDS);
            } elseif (isset($render_result['error'])) {
                // Return Error UI for Admins
                // Return Error UI for Admins
                if (current_user_can('manage_options')) {
                    return $this->render_admin_error_helper($render_result['error'], $atts);
                } else {
                    // Graceful Error for End Users
                    $message = !empty($atts['error_message']) ? esc_html($atts['error_message']) : esc_html__('Unable to load data. Please check back later.', 'tablecrafter-wp-data-tables');
                    return '<div class="tc-error-state" style="padding: 20px; background: #fafafa; border: 1px solid #ddd; border-radius: 4px; color: #666; text-align: center;">' . $message . '</div>';
                }
            }
        }

        $this->register_assets();
        wp_enqueue_script('tablecrafter-frontend');
        wp_enqueue_style('tablecrafter-style');

        ob_start();
        ?>
        <div id="<?php echo esc_attr($atts['id']); ?>" class="tablecrafter-container"
            data-source="<?php echo esc_url($atts['source']); ?>" data-include="<?php echo esc_attr($atts['include']); ?>"
            data-exclude="<?php echo esc_attr($atts['exclude']); ?>" data-root="<?php echo esc_attr($atts['root']); ?>"
            data-search="<?php echo $atts['search'] ? 'true' : 'false'; ?>"
            data-filters="<?php echo $atts['filters'] ? 'true' : 'false'; ?>"
            data-export="<?php echo $atts['export'] ? 'true' : 'false'; ?>"
            data-per-page="<?php echo esc_attr($atts['per_page']); ?>" data-sort="<?php echo esc_attr($atts['sort']); ?>"
            data-auto-refresh="<?php echo $atts['auto_refresh'] ? 'true' : 'false'; ?>"
            data-refresh-interval="<?php echo esc_attr($atts['refresh_interval']); ?>"
            data-refresh-indicator="<?php echo $atts['refresh_indicator'] ? 'true' : 'false'; ?>"
            data-refresh-countdown="<?php echo $atts['refresh_countdown'] ? 'true' : 'false'; ?>"
            data-refresh-last-updated="<?php echo $atts['refresh_last_updated'] ? 'true' : 'false'; ?>" data-ssr="true">
            <?php echo $html_content ? $this->sanitize_table_html($html_content) : '<div class="tc-loading">' . esc_html__('Loading TableCrafter...', 'tablecrafter-wp-data-tables') . '</div>'; ?>
            <?php if (!empty($initial_data)): ?>
                <script type="application/json" class="tc-initial-data"><?php echo wp_json_encode($initial_data); ?></script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX Handler: Save Airtable Token securely
     */
    public function ajax_save_airtable_token()
    {
        // Check permissions and nonce
        if (!current_user_can('manage_options') || !check_ajax_referer('tc_proxy_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (empty($token)) {
            wp_send_json_error(array('message' => 'Token is required.'));
        }

        // Encrypt and store
        $security = TC_Security::get_instance();
        $encrypted = $security->encrypt_token($token);

        if (empty($encrypted)) {
            wp_send_json_error(array('message' => 'Encryption failed.'));
        }

        update_option('tablecrafter_airtable_token', $encrypted);

        wp_send_json_success(array('message' => 'Token saved securely.'));
    }

    /**
     * Server-Side Fetcher & Renderer.
     * 
     * Performs remote API hit and converts JSON into crawlable HTML table.
     * 
     * @param array $atts Configuration attributes.
     * @return array|false array('html' => string, 'data' => array) or false on failure.
     */
    /**
     * Unified Data Fetcher (Local & Remote).
     * 
     * Handles local file resolution (performance/safety) and remote HTTP fetching.
     * 
     * @param string $url The source URL.
     * @return array|WP_Error Parsed JSON data (array/object) or error.
     */
    private function fetch_data_from_source(string $url)
    {
        // 1. Try Local File Resolution (Optimization & Loopback bypassing)
        $site_url = site_url();
        $home_url = home_url();
        $plugin_url = TABLECRAFTER_URL;

        if (strpos($url, $site_url) === 0 || strpos($url, $home_url) === 0 || strpos($url, $plugin_url) === 0) {
            $relative_path = str_replace(array($site_url, $home_url, $plugin_url), '', $url);
            $relative_path = ltrim($relative_path, '/');

            $possible_paths = array(
                ABSPATH . $relative_path,
                rtrim(ABSPATH, '/') . '/' . ltrim($relative_path, '/'),
                WP_CONTENT_DIR . '/' . $relative_path,
            );

            if (strpos($relative_path, 'wp-content/plugins/tablecrafter-wp-data-tables/') === 0) {
                $plugin_relative = str_replace('wp-content/plugins/tablecrafter-wp-data-tables/', '', $relative_path);
                $possible_paths[] = TABLECRAFTER_PATH . $plugin_relative;
            } else if (strpos($relative_path, 'tablecrafter-wp-data-tables/') !== false) {
                $parts = explode('tablecrafter-wp-data-tables/', $relative_path, 2);
                if (isset($parts[1])) {
                    $possible_paths[] = TABLECRAFTER_PATH . $parts[1];
                }
            }

            foreach ($possible_paths as $abs_path) {
                // Security Fix: Prevent Directory Traversal
                // 1. Resolve to absolute path
                $real_path = realpath($abs_path);

                // 2. Define Allowed Base Paths (Whitelist)
                // We allow files within ABSPATH (Site Root)
                $allowed_paths = array(
                    realpath(ABSPATH),
                    realpath(WP_CONTENT_DIR),
                    realpath(TABLECRAFTER_PATH)
                );

                if ($real_path && file_exists($real_path) && is_readable($real_path)) {
                    // 3. Check if the resolved path starts with any allowed path
                    $is_allowed = false;
                    foreach ($allowed_paths as $base_path) {
                        if ($base_path && strpos($real_path, $base_path) === 0) {
                            $is_allowed = true;
                            break;
                        }
                    }

                    if (!$is_allowed) {
                        // Log attempt? 
                        continue;
                    }

                    // 4. Content Type Check (Defense in Depth)
                    $ext = pathinfo($real_path, PATHINFO_EXTENSION);

                    // JSON Handling
                    if ($ext === 'json') {
                        $content = @file_get_contents($real_path);
                        if ($content !== false) {
                            $data = json_decode($content, true);
                            if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
                                return $data;
                            }
                        }
                    }

                    // CSV Handling (New v2.5.0)
                    if ($ext === 'csv') {
                        $content = @file_get_contents($real_path);
                        if ($content !== false) {
                            return TC_CSV_Source::parse($content);
                        }
                    }
                }
            }
        }

        // 2. Security Check for Remote URLs
        if (!$this->is_safe_url($url)) {
            $this->log_error('Security Block', array('url' => $url));
            return new WP_Error('security_error', 'The provided URL is blocked for safety (Local/Private IP).');
        }

        // Define $original_url for potential usage if we modify $url
        $original_url = $url;

        // --- CSV / Google Sheets Detection (v2.5.0) ---
        // Enhanced to support generic CSV URLs as well
        $is_google_sheet = preg_match('/docs\.google\.com\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url);
        $is_csv_ext = (substr($url, -4) === '.csv');

        if ($is_google_sheet || $is_csv_ext) {
            return TC_CSV_Source::fetch($url);
        }
        // -----------------------------

        // 3. Remote HTTP Fetch (Restored for JSON APIs)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        // SSL verification - enabled for security (prevents MITM attacks)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Use WordPress bundled CA certificates if available
        $ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if (file_exists($ca_bundle)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
        }

        curl_setopt($ch, CURLOPT_USERAGENT, 'TableCrafter/' . TABLECRAFTER_VERSION . ' (WordPress Plugin)');
        curl_setopt($ch, CURLOPT_COOKIEFILE, "");

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->log_error('CURL Fetch Failed', array('url' => $url, 'error' => $error));
            return new WP_Error('http_error', 'CURL Error: ' . $error);
        }

        if ($code !== 200) {
            $this->log_error('HTTP Error Code', array('url' => $url, 'code' => $code));
            return new WP_Error('http_error', 'Source returned HTTP ' . $code);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('JSON Parse Error', array('url' => $url, 'error' => json_last_error_msg()));
            return new WP_Error('json_error', 'The source did not return a valid JSON structure.');
        }

        return $data;
    }

    /**
     * Helper: Convert CSV String to JSON (Array of Objects)
     */
    private function parse_csv_to_json($csv_string)
    {
        $lines = explode("\n", trim($csv_string));
        if (empty($lines))
            return [];

        // Parse headers (Row 1)
        // Fix for PHP 8.4+ deprecation: explicit escape parameter
        $headers = str_getcsv(array_shift($lines), ",", "\"", "\\");

        $data = [];
        foreach ($lines as $line) {
            if (empty(trim($line)))
                continue;

            $row = str_getcsv($line, ",", "\"", "\\");

            // Skip rows that don't match header count
            if (count($row) !== count($headers))
                continue;

            // Combine into associative array
            $item = array();
            foreach ($headers as $index => $key) {
                // Sanitize key slightly to be a valid valid property? 
                // Actually JSON keys can be anything, so raw is fine.
                // Just utf8_encode if needed? WordPress handles UTF8 usually.
                $item[$key] = isset($row[$index]) ? $row[$index] : null;
            }
            $data[] = $item;
        }

        return $data;
    }


    private function fetch_and_render_php($atts)
    {
        // 1. Try Cache First
        $cache_key = 'tc_cache_' . md5($atts['source'] . TABLECRAFTER_VERSION);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            $data = $cached_data;
        } else {
            // 2. Unified Fetch
            $result = $this->fetch_data_from_source($atts['source']);

            if (is_wp_error($result)) {
                return array('error' => $result->get_error_message());
            }

            $data = $result;

            // 3. Set Cache for Next Time (Read-Through)
            if ($data) {
                set_transient($cache_key, $data, HOUR_IN_SECONDS);
            }
        }

        if (empty($data)) {
            return array('error' => 'Empty Source: The data received is empty.');
        }

        if (!empty($atts['root'])) {
            $path = explode('.', $atts['root']);
            foreach ($path as $segment) {
                if (isset($data[$segment])) {
                    $data = $data[$segment];
                } else {
                    return array('error' => "Path Error: Key '$segment' not found in data structure.");
                }
            }
        }

        if (!is_array($data)) {
            return array('error' => 'Structure Error: The target data is not a list/array.');
        }

        if (empty($data)) {
            return array('error' => 'Empty Dataset: No rows found at this path.');
        }

        // Check if the first row is an object/array (Standard table expected)
        $first_row = reset($data);
        if ($data && !is_array($first_row) && !is_object($first_row)) {
            // Auto-convert simple list to table format for better UX
            $new_data = array();
            foreach ($data as $item) {
                $new_data[] = array('Value' => $item);
            }
            $data = $new_data;
        } elseif (empty($data) || (!is_array($first_row) && !is_object($first_row))) {
            return array('error' => 'Rendering Error: The data structure at this level is a simple list, not a table (list of objects).');
        }

        $include_raw = !empty($atts['include']) ? array_map('trim', explode(',', $atts['include'])) : array();
        $exclude = !empty($atts['exclude']) ? array_map('trim', explode(',', $atts['exclude'])) : array();

        // Aliasing Logic
        $header_map = array();
        $include_keys = array();

        if (!empty($include_raw)) {
            foreach ($include_raw as $item) {
                if (strpos($item, ':') !== false) {
                    list($key, $alias) = explode(':', $item, 2);
                    $key = trim($key);
                    $include_keys[] = $key;
                    $header_map[$key] = trim($alias);
                } else {
                    $include_keys[] = $item;
                }
            }
        }

        $headers = array_keys((array) reset($data));

        if (!empty($include_keys)) {
            $headers = array_intersect($headers, $include_keys);
            // Re-sort headers to match the order in 'include'
            $sorted_headers = array();
            foreach ($include_keys as $k) {
                if (in_array($k, $headers)) {
                    $sorted_headers[] = $k;
                }
            }
            $headers = $sorted_headers;
        }

        if (!empty($exclude)) {
            $headers = array_diff($headers, $exclude);
        }

        if (empty($headers))
            return false;

        // Parse sort parameter (format: "column:direction")
        $sort_field = '';
        $sort_direction = '';
        if (!empty($sort)) {
            $sort_parts = explode(':', $sort, 2);
            if (count($sort_parts) === 2) {
                $sort_field = trim($sort_parts[0]);
                $sort_direction = strtolower(trim($sort_parts[1]));
            }
        }

        // Apply sorting to data if sort parameter is provided
        if (!empty($sort_field) && !empty($sort_direction) && in_array($sort_field, $headers)) {
            $data = $this->sort_data($data, $sort_field, $sort_direction);
        }

        $html = '<table class="tc-table">';
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $label = isset($header_map[$header]) ? $header_map[$header] : $this->format_header_php($header);

            // Set aria-sort based on current sort state
            $aria_sort = 'none';
            if ($sort_field === $header) {
                if ($sort_direction === 'asc' || $sort_direction === 'ascending') {
                    $aria_sort = 'ascending';
                } elseif ($sort_direction === 'desc' || $sort_direction === 'descending') {
                    $aria_sort = 'descending';
                }
            }

            $html .= '<th class="tc-sortable" tabindex="0" aria-sort="' . esc_attr($aria_sort) . '" data-field="' . esc_attr($header) . '">' . esc_html($label) . '</th>';
        }
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($data as $row) {
            $row = (array) $row;
            $html .= '<tr>';
            foreach ($headers as $header) {
                $val = isset($row[$header]) ? $row[$header] : '';
                $label = isset($header_map[$header]) ? $header_map[$header] : $this->format_header_php($header);
                $html .= '<td data-tc-label="' . esc_attr($label) . '">' . $this->render_value_php($val) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return array(
            'html' => $html,
            'data' => $data
        );
    }

    /**
     * Sort data array by specified field and direction.
     *
     * @param array $data The data to sort.
     * @param string $field The field to sort by.
     * @param string $direction Sort direction (asc/desc/ascending/descending).
     * @return array Sorted data.
     */
    private function sort_data(array $data, string $field, string $direction): array
    {
        if (empty($data)) {
            return $data;
        }

        // Determine sort direction
        $is_ascending = in_array($direction, ['asc', 'ascending']);

        // Sort data using usort with custom comparison function
        usort($data, function ($a, $b) use ($field, $is_ascending) {
            $a = (array) $a;
            $b = (array) $b;

            $val_a = isset($a[$field]) ? $a[$field] : '';
            $val_b = isset($b[$field]) ? $b[$field] : '';

            // Handle numeric values
            if (is_numeric($val_a) && is_numeric($val_b)) {
                $result = floatval($val_a) <=> floatval($val_b);
            } else {
                // String comparison (case-insensitive)
                $result = strcasecmp(strval($val_a), strval($val_b));
            }

            // Reverse result for descending order
            return $is_ascending ? $result : -$result;
        });

        return $data;
    }

    /**
     * Header Formatter.
     * 
     * @param string $str Raw key.
     * @return string Title Case string.
     */
    private function format_header_php(string $str): string
    {
        return ucwords(str_replace('_', ' ', $str));
    }

    /**
     * Admin Error Helper UI.
     * 
     * @param string $error The error message.
     * @param array $atts Configuration attributes.
     * @return string HTML helper block.
     */
    private function render_admin_error_helper(string $error, array $atts): string
    {
        ob_start();
        ?>
        <div class="tc-admin-error-helper"
            style="border: 2px dashed #d63638; background: #fff; padding: 20px; border-radius: 8px; margin: 10px 0;">
            <div style="display: flex; align-items: center; margin-bottom: 10px; color: #d63638;">
                <span class="dashicons dashicons-warning"
                    style="margin-right: 10px; font-size: 24px; width: 24px; height: 24px;"></span>
                <strong
                    style="font-size: 16px;"><?php esc_html_e('TableCrafter Setup Guide', 'tablecrafter-wp-data-tables'); ?></strong>
            </div>
            <p style="margin: 0 0 10px 0; color: #1d2327;">
                <?php
                echo sprintf(
                    /* translators: %s: Error message describing what went wrong with the data source */
                    esc_html__('We encountered an issue with your data source: %s', 'tablecrafter-wp-data-tables'),
                    '<code style="background: #f0f0f1; border-radius: 4px; padding: 2px 4px; color: #d63638;">' . esc_html($error) . '</code>'
                ); ?>
            </p>
            <div style="background: #f6f7f7; padding: 12px; border-radius: 4px; font-size: 13px;">
                <strong><?php esc_html_e('Troubleshooting Tips:', 'tablecrafter-wp-data-tables'); ?></strong>
                <ul style="margin: 8px 0 0 20px; padding: 0;">
                    <li><?php esc_html_e('Verify the Source URL is public and returns JSON.', 'tablecrafter-wp-data-tables'); ?>
                    </li>
                    <li><?php esc_html_e('Ensure the "JSON Root" path accurately matches your data nesting.', 'tablecrafter-wp-data-tables'); ?>
                    </li>
                    <li><?php esc_html_e('Check if your source is a list of objects (rows) and not a single value.', 'tablecrafter-wp-data-tables'); ?>
                    </li>
                </ul>
            </div>
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #646970;">
                <em><?php esc_html_e('Note: This helper is only visible to site administrators.', 'tablecrafter-wp-data-tables'); ?></em>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Smart Value Renderer with XSS Protection.
     * 
     * SECURITY: All user input is properly escaped to prevent XSS vulnerabilities
     * 
     * @param mixed $val Raw data.
     * @return string Sanitized HTML/Value.
     */
    private function render_value_php($val): string
    {
        $str = trim((string) $val);
        $lower = strtolower($str);

        // 1. Boolean
        if ($val === true || $lower === 'true') {
            return '<span class="tc-badge tc-yes">Yes</span>';
        }
        if ($val === false || $lower === 'false') {
            return '<span class="tc-badge tc-no">No</span>';
        }

        // 2. Images - Enhanced security validation
        if ($this->is_safe_image_url($str)) {
            $safe_url = $this->sanitize_image_url($str);
            if ($safe_url) {
                return sprintf(
                    '<img src="%s" style="max-width: 100px; height: auto; display: block;" alt="%s" loading="lazy">',
                    esc_url($safe_url),
                    esc_attr('Table image')
                );
            }
        }

        // 3. Email Addresses - Enhanced validation
        if (filter_var($str, FILTER_VALIDATE_EMAIL) && strlen($str) <= 254) {
            return sprintf(
                '<a href="mailto:%s" title="%s">%s</a>',
                esc_attr($str),
                esc_attr('Send email to ' . $str),
                esc_html($str)
            );
        }

        // 4. ISO Dates (YYYY-MM-DD) - Enhanced validation
        if ($this->is_valid_date_string($str)) {
            try {
                $date = new DateTime($str);
                $formatted_date = $date->format('M j, Y');
                return sprintf('<time datetime="%s">%s</time>', esc_attr($str), esc_html($formatted_date));
            } catch (Exception $e) {
                // Fallback to escaped string
            }
        }

        // 5. URLs - Enhanced security validation
        if ($this->is_safe_display_url($str)) {
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
                esc_url($str),
                esc_attr('Open link in new window'),
                esc_html($this->truncate_url($str))
            );
        }

        // 6. Arrays (Tags UI) - Enhanced security for nested data
        if (is_array($val)) {
            return $this->render_array_safely($val);
        }

        // 7. Objects - Handle objects safely
        if (is_object($val)) {
            return $this->render_object_safely($val);
        }

        // Default: Always escape HTML
        return esc_html($str);
    }

    /**
     * Security helper: Validate image URLs safely
     * 
     * @param string $url
     * @return bool
     */
    private function is_safe_image_url(string $url): bool
    {
        // Prevent javascript: and data: schemes except safe data:image
        if (preg_match('/^(javascript|vbscript|data:(?!image\/)):/i', $url)) {
            return false;
        }

        // Check for image file extensions
        if (preg_match('/\.(jpeg|jpg|gif|png|webp|bmp)$/i', $url)) {
            return true;
        }

        // Allow safe data:image URLs (but not SVG due to XSS risks)
        if (preg_match('/^data:image\/(jpeg|jpg|gif|png|webp|bmp);base64,/i', $url)) {
            return true;
        }

        return false;
    }

    /**
     * Security helper: Sanitize image URLs
     * 
     * @param string $url
     * @return string|false
     */
    private function sanitize_image_url(string $url)
    {
        // Additional validation for data URLs
        if (strpos($url, 'data:image') === 0) {
            // Validate base64 data URL format
            if (preg_match('/^data:image\/(jpeg|jpg|gif|png|webp|bmp);base64,[A-Za-z0-9+\/=]+$/i', $url)) {
                return $url;
            }
            return false;
        }

        // For regular URLs, use WordPress validation
        $clean_url = filter_var($url, FILTER_VALIDATE_URL);
        return $clean_url ? $clean_url : false;
    }

    /**
     * Security helper: Validate date strings safely
     * 
     * @param string $str
     * @return bool
     */
    private function is_valid_date_string(string $str): bool
    {
        // Only allow specific date patterns to prevent injection
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d{3})?Z?)?$/', $str)) {
            return false;
        }

        return (bool) strtotime($str);
    }

    /**
     * Security helper: Validate URLs safely for XSS prevention
     * 
     * @param string $str
     * @return bool
     */
    private function is_safe_display_url(string $str): bool
    {
        // Prevent javascript: and other dangerous schemes
        if (preg_match('/^(javascript|vbscript|data|file|ftp):/i', $str)) {
            return false;
        }

        // Only allow http/https
        if (!preg_match('/^https?:\/\//i', $str)) {
            return false;
        }

        // Validate URL format
        return filter_var($str, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Security helper: Truncate URLs for display
     * 
     * @param string $url
     * @return string
     */
    private function truncate_url(string $url): string
    {
        if (strlen($url) > 50) {
            return substr($url, 0, 47) . '...';
        }
        return $url;
    }

    /**
     * Security helper: Render arrays safely
     * 
     * @param array $val
     * @return string
     */
    private function render_array_safely(array $val): string
    {
        if (empty($val)) {
            return '';
        }

        // Check if it's an associative array (Object-like)
        if (array_keys($val) !== range(0, count($val) - 1)) {
            $display = $this->extract_safe_display_value($val);
            return sprintf('<span class="tc-tag">%s</span>', esc_html($display));
        }

        // Handle regular arrays
        $tags = [];
        $max_items = 10; // Prevent excessive DOM creation
        $items = array_slice($val, 0, $max_items);

        foreach ($items as $item) {
            $display = $this->extract_safe_display_value($item);
            $tags[] = sprintf('<span class="tc-tag">%s</span>', esc_html($display));
        }

        $result = '<div class="tc-tag-list">' . implode('', $tags);

        if (count($val) > $max_items) {
            $remaining = count($val) - $max_items;
            $result .= sprintf('<span class="tc-tag tc-more">+%d more</span>', $remaining);
        }

        $result .= '</div>';
        return $result;
    }

    /**
     * Security helper: Render objects safely
     * 
     * @param object $val
     * @return string
     */
    private function render_object_safely($val): string
    {
        // Convert object to array for safe handling
        $array_val = json_decode(json_encode($val), true);

        if (!is_array($array_val)) {
            return esc_html('[Object]');
        }

        return $this->render_array_safely($array_val);
    }

    /**
     * Security helper: Extract safe display value from complex data
     * 
     * @param mixed $item
     * @return string
     */
    private function extract_safe_display_value($item): string
    {
        if (is_string($item)) {
            // Limit string length to prevent DOM bloat
            return strlen($item) > 100 ? substr($item, 0, 97) . '...' : $item;
        }

        if (is_array($item)) {
            // Try common display field names
            $display_fields = ['name', 'title', 'label', 'text', 'value'];
            foreach ($display_fields as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $value = strlen($item[$field]) > 100 ? substr($item[$field], 0, 97) . '...' : $item[$field];
                    return $value;
                }
            }

            // Fallback to safe JSON representation
            return json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        }

        if (is_object($item)) {
            return $this->extract_safe_display_value((array) $item);
        }

        // Convert other types to string safely
        return (string) $item;
    }

    /**
     * Secure AJAX Data Proxy.
     * 
     * Bypasses CORS and provides caching for frontend requests.
     * 
     * @return void
     */
    /**
     * Rate Limiting Constants.
     * 
     * Configurable limits for AJAX proxy abuse prevention.
     */
    private const RATE_LIMIT_MAX_REQUESTS = 30;      // Max requests per window
    private const RATE_LIMIT_WINDOW_SECONDS = 60;    // Time window (1 minute)

    public function ajax_proxy_fetch(): void
    {
        check_ajax_referer('tc_proxy_nonce', 'nonce');

        // Allow both edit_posts (for frontend) and manage_options (for admin preview)
        if (!current_user_can('edit_posts') && !current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized: You do not have permission to fetch remote data.', 'tablecrafter-wp-data-tables'));
        }

        // Rate Limiting Check (Problem #1 Fix - Security)
        if ($this->is_rate_limited()) {
            status_header(429);
            wp_send_json_error(
                __('Rate limit exceeded. Please wait before making more requests.', 'tablecrafter-wp-data-tables'),
                429
            );
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

        if (empty($url)) {
            wp_send_json_error(__('Error: No URL provided.', 'tablecrafter-wp-data-tables'));
        }

        $cache_key = 'tc_cache_' . md5($url);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            wp_send_json_success($cached_data);
        }

        // Unified Fetch
        $result = $this->fetch_data_from_source($url);

        if (is_wp_error($result)) {
            wp_send_json_error('TableCrafter Proxy Error: ' . $result->get_error_message());
        }

        $data = $result;

        set_transient($cache_key, $data, HOUR_IN_SECONDS);
        $this->track_url($url);

        wp_send_json_success($data);
    }

    /**
     * URL Analytics & Tracking.
     * 
     * @param string $url URL to track for background warming.
     * @return void
     */
    private function track_url(string $url): void
    {
        $urls = get_option('tc_tracked_urls', array());
        if (!is_array($urls))
            $urls = array();

        if (!in_array($url, $urls)) {
            $urls[] = $url;
            update_option('tc_tracked_urls', array_slice($urls, -50));
        }
    }

    /**
     * Background Source Refresher.
     * 
     * @param array $atts Configuration to refresh.
     * @return void
     */
    public function refresh_source_cache(array $atts): void
    {
        $html = $this->fetch_and_render_php($atts);
        if ($html) {
            $cache_key = 'tc_html_' . md5($atts['source'] . $atts['include'] . $atts['exclude']);
            set_transient($cache_key, array(
                'html' => $html,
                'time' => time()
            ), HOUR_IN_SECONDS);
        }
    }

    /**
     * Hourly Automated Cache Warming (Cron).
     * 
     * @return void
     */
    public function automated_cache_refresh(): void
    {
        $urls = get_option('tc_tracked_urls', array());
        
        // Initialize HTTP handler if not already done
        if (!$this->http && class_exists('TC_HTTP_Request')) {
            $this->http = TC_HTTP_Request::get_instance();
        }
        
        foreach ($urls as $url) {
            if ($this->http) {
                $result = $this->http->request($url, TC_HTTP_Request::TYPE_CACHE_WARMUP);
                if (!is_wp_error($result)) {
                    set_transient('tc_cache_' . md5($url), $result, HOUR_IN_SECONDS);
                }
            } else {
                // Fallback to wp_remote_get if HTTP handler not available
                $response = wp_remote_get($url, array('timeout' => 10));
                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body);
                    if ($data) {
                        set_transient('tc_cache_' . md5($url), $data, HOUR_IN_SECONDS);
                    }
                }
            }
        }
    }

    /**
     * WP-CLI Utility Commands.
     *
     * Usage: wp tablecrafter [clear-cache|warm-cache]
     *
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function cli_commands(array $args, array $assoc_args): void
    {
        $action = isset($args[0]) ? $args[0] : '';

        if ($action === 'clear-cache') {
            $cleared = $this->clear_all_caches();
            WP_CLI::success(sprintf('TableCrafter cache cleared. %d transients removed.', $cleared));
        } elseif ($action === 'warm-cache') {
            $this->automated_cache_refresh();
            WP_CLI::success('TableCrafter cache warmed for all tracked URLs.');
        } else {
            WP_CLI::error('Usage: wp tablecrafter [clear-cache|warm-cache]');
        }
    }

    /**
     * Clear all TableCrafter caches using WordPress API.
     *
     * @return int Number of transients cleared.
     */
    private function clear_all_caches(): int
    {
        global $wpdb;
        $cleared = 0;

        // Get all TableCrafter transient names using prepared statement
        $transient_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_tc_cache_') . '%',
                $wpdb->esc_like('_transient_tc_html_') . '%',
                $wpdb->esc_like('_transient_tc_export_') . '%'
            )
        );

        // Delete each transient using WordPress API
        foreach ($transient_names as $transient_name) {
            // Remove '_transient_' prefix to get actual transient name
            $name = str_replace('_transient_', '', $transient_name);
            if (delete_transient($name)) {
                $cleared++;
            }
        }

        // Also clear rate limit transients
        $rate_transients = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_tc_rate_') . '%'
            )
        );

        foreach ($rate_transients as $transient_name) {
            $name = str_replace('_transient_', '', $transient_name);
            if (delete_transient($name)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * SSRF (Server Side Request Forgery) Prevention Helper.
     * 
     * Blocks private ranges and localhost to secure the proxy.
     * 
     * @param string $url The URL to validate.
     * @return bool True if safe, false if blocked.
     */
    private function is_safe_url(string $url): bool
    {
        // Use WordPress Core's robust validation
        // This handles private IP ranges, localhost normalization, and valid URL formatting.
        if (function_exists('wp_http_validate_url')) {
            return (bool) wp_http_validate_url($url);
        }

        // Fallback for very old WP versions (unlikely but safe)
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host)
            return false;

        if (in_array(strtolower($host), array('localhost', '127.0.0.1', '[::1]'))) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $is_private = !filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($is_private)
                return false;
        }

        return true;
    }

    /**
     * Rate Limiting Helper.
     * 
     * Checks and increments the request count for the current user/IP.
     * Uses WordPress transients for simple cross-request state.
     * 
     * @return bool True if rate limit exceeded, false if allowed.
     */
    private function is_rate_limited(): bool
    {
        // Build unique identifier: prefer user ID, fallback to IP
        $identifier = get_current_user_id();
        if ($identifier === 0) {
            $identifier = $this->get_client_ip();
        }

        $transient_key = 'tc_rate_' . md5((string) $identifier);
        $current_count = get_transient($transient_key);

        if ($current_count === false) {
            // First request in this window
            set_transient($transient_key, 1, self::RATE_LIMIT_WINDOW_SECONDS);
            return false;
        }

        if ((int) $current_count >= self::RATE_LIMIT_MAX_REQUESTS) {
            // Rate limit exceeded
            $this->log_error('Rate Limit Exceeded', array(
                'identifier' => $identifier,
                'count' => $current_count
            ));
            return true;
        }

        // Increment counter (preserve remaining TTL)
        set_transient($transient_key, (int) $current_count + 1, self::RATE_LIMIT_WINDOW_SECONDS);
        return false;
    }

    /**
     * Get translatable strings for JavaScript.
     *
     * @return array Associative array of translation strings.
     */
    private function get_js_i18n_strings(): array
    {
        return array(
            // Table labels
            'table' => __('Data table', 'tablecrafter-wp-data-tables'),
            'loading' => __('Loading data...', 'tablecrafter-wp-data-tables'),
            'noResults' => __('No results found', 'tablecrafter-wp-data-tables'),
            'noData' => __('No data available', 'tablecrafter-wp-data-tables'),
            'error' => __('Error loading data', 'tablecrafter-wp-data-tables'),

            // Search
            'search' => __('Search...', 'tablecrafter-wp-data-tables'),
            'searchPlaceholder' => __('Search table data', 'tablecrafter-wp-data-tables'),
            'clearSearch' => __('Clear search', 'tablecrafter-wp-data-tables'),

            // Sorting
            'sortAsc' => __('Sort ascending', 'tablecrafter-wp-data-tables'),
            'sortDesc' => __('Sort descending', 'tablecrafter-wp-data-tables'),
            'sortBy' => __('Sort by {column}', 'tablecrafter-wp-data-tables'),

            // Filtering
            'filter' => __('Filter', 'tablecrafter-wp-data-tables'),
            'filterBy' => __('Filter {column}', 'tablecrafter-wp-data-tables'),
            'clearFilters' => __('Clear all filters', 'tablecrafter-wp-data-tables'),
            'allItems' => __('All', 'tablecrafter-wp-data-tables'),

            // Pagination
            'pagination' => __('Table pagination', 'tablecrafter-wp-data-tables'),
            'previous' => __('Previous', 'tablecrafter-wp-data-tables'),
            'next' => __('Next', 'tablecrafter-wp-data-tables'),
            'first' => __('First', 'tablecrafter-wp-data-tables'),
            'last' => __('Last', 'tablecrafter-wp-data-tables'),
            'page' => __('Page', 'tablecrafter-wp-data-tables'),
            'of' => __('of', 'tablecrafter-wp-data-tables'),
            'rowsPerPage' => __('Rows per page', 'tablecrafter-wp-data-tables'),
            'showing' => __('Showing {start} to {end} of {total} entries', 'tablecrafter-wp-data-tables'),

            // Export
            'export' => __('Export', 'tablecrafter-wp-data-tables'),
            'exportCsv' => __('Export to CSV', 'tablecrafter-wp-data-tables'),
            'exportExcel' => __('Export to Excel', 'tablecrafter-wp-data-tables'),
            'exportPdf' => __('Export to PDF', 'tablecrafter-wp-data-tables'),
            'copyClipboard' => __('Copy to clipboard', 'tablecrafter-wp-data-tables'),
            'copied' => __('Copied!', 'tablecrafter-wp-data-tables'),
            'exportSuccess' => __('Export completed', 'tablecrafter-wp-data-tables'),
            'exportError' => __('Export failed', 'tablecrafter-wp-data-tables'),

            // Auto-refresh
            'autoRefresh' => __('Auto-refresh', 'tablecrafter-wp-data-tables'),
            'refreshPaused' => __('Auto-refresh paused', 'tablecrafter-wp-data-tables'),
            'refreshResumed' => __('Auto-refresh resumed', 'tablecrafter-wp-data-tables'),
            'lastUpdated' => __('Last updated: {time}', 'tablecrafter-wp-data-tables'),
            'nextRefresh' => __('Next refresh in {time}', 'tablecrafter-wp-data-tables'),
            'refreshNow' => __('Refresh now', 'tablecrafter-wp-data-tables'),

            // Boolean values
            'yes' => __('Yes', 'tablecrafter-wp-data-tables'),
            'no' => __('No', 'tablecrafter-wp-data-tables'),
            'true' => __('True', 'tablecrafter-wp-data-tables'),
            'false' => __('False', 'tablecrafter-wp-data-tables'),

            // Accessibility
            'expandRow' => __('Expand row', 'tablecrafter-wp-data-tables'),
            'collapseRow' => __('Collapse row', 'tablecrafter-wp-data-tables'),
            'selectRow' => __('Select row', 'tablecrafter-wp-data-tables'),
            'selectAll' => __('Select all', 'tablecrafter-wp-data-tables'),
            'deselectAll' => __('Deselect all', 'tablecrafter-wp-data-tables'),
            'selected' => __('{count} selected', 'tablecrafter-wp-data-tables'),
        );
    }

    /**
     * Get Client IP Address.
     *
     * Handles proxies and load balancers safely.
     * SECURITY: Only trusts proxy headers when explicitly configured via filter.
     *
     * @return string The client IP address.
     */
    private function get_client_ip(): string
    {
        // Allow site owners to specify trusted proxy headers via filter
        // Default: only trust REMOTE_ADDR (direct connection)
        $trusted_headers = apply_filters('tablecrafter_trusted_ip_headers', array());

        // Known safe proxy configurations (only if explicitly enabled)
        $proxy_headers = array(
            'cloudflare' => 'HTTP_CF_CONNECTING_IP',
            'forwarded' => 'HTTP_X_FORWARDED_FOR',
            'real_ip' => 'HTTP_X_REAL_IP',
        );

        // Check trusted proxy headers first
        foreach ($trusted_headers as $header_key) {
            if (isset($proxy_headers[$header_key])) {
                $header = $proxy_headers[$header_key];
                if (!empty($_SERVER[$header])) {
                    // X-Forwarded-For can contain multiple IPs, take the first (client IP)
                    $ip = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))[0];
                    $ip = trim($ip);

                    // Validate it's a proper IP and not a private/reserved range
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        // Default: use REMOTE_ADDR (cannot be spoofed at HTTP level)
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        // Fallback to a hash if no valid IP found (shouldn't happen)
        return 'unknown_' . wp_hash(wp_json_encode($_SERVER));
    }

    /**
     * Internal Error Logger.
     * 
     * @param string $message The error message.
     * @param array $context Additional context data.
     * @return void
     */
    private function log_error(string $message, array $context = array()): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log = sprintf('[TableCrafter] %s | Context: %s', $message, json_encode($context));
            error_log($log);
        }
    }

    /**
     * Render the Welcome Page.
     */
    public function render_welcome_page(): void
    {
        // Register and enqueue TableCrafter library and styles for the demo
        $this->register_assets();
        wp_enqueue_style('tablecrafter-style');
        wp_enqueue_script('tablecrafter-lib');

        include TABLECRAFTER_PATH . 'views/welcome.php';
    }

    /**
     * Handle Welcome Redirect on Activation.
     */
    public function welcome_redirect(): void
    {
        if (!get_option('tc_do_activation_redirect', false)) {
            return;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (is_network_admin()) {
            return;
        }

        delete_option('tc_do_activation_redirect');
        wp_safe_redirect(admin_url('admin.php?page=tablecrafter-welcome'));
        exit;
    }

    /**
     * Handle Lead Subscription (Lead Magnet).
     * 
     * Validates email and sends to external API.
     * 
     * @return void
     */
    public function handle_lead_subscription(): void
    {
        // Verify nonce with proper sanitization
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'tc_lead_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Validate email
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            wp_send_json_error('Invalid email address');
            return;
        }

        // Send lead directly to YOUR server (not stored on user's site)
        $response = wp_remote_post('https://fahdmurtaza.com/api/tablecrafter-lead', array(
            'body' => array(
                'email' => $email,
                'plugin_version' => TABLECRAFTER_VERSION,
                'site_url' => get_site_url(),
                'timestamp' => current_time('mysql'),
                'source' => 'welcome_screen'
            ),
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            )
        ));

        // Fallback: send email directly if API fails
        if (is_wp_error($response)) {
            wp_mail(
                'info@fahdmurtaza.com',
                'TableCrafter Lead: ' . $email,
                "New subscriber from TableCrafter plugin:\n\nEmail: " . $email . "\nSite: " . get_site_url() . "\nDate: " . current_time('mysql') . "\n\nNote: API call failed, sent via email fallback."
            );
        }

        wp_send_json_success(array(
            'message' => 'Subscription successful'
        ));
    }

    /**
     * Handle Advanced Export AJAX Request
     * 
     * Processes export requests for CSV, Excel, and PDF formats.
     * 
     * @return void
     */
    public function ajax_export_data(): void
    {
        // Verify nonce with proper sanitization
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'tc_export_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Check permissions
        if (!current_user_can('read')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Get export parameters
        $source = isset($_POST['source']) ? esc_url_raw(wp_unslash($_POST['source'])) : '';
        $format = isset($_POST['format']) ? sanitize_text_field(wp_unslash($_POST['format'])) : 'csv';
        $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : 'tablecrafter-export';
        $template = isset($_POST['template']) ? sanitize_text_field(wp_unslash($_POST['template'])) : 'default';
        $include_metadata = isset($_POST['include_metadata']) ? (bool) $_POST['include_metadata'] : false;

        // Validate required fields
        if (empty($source)) {
            wp_send_json_error('Source URL is required');
            return;
        }

        // Fetch data using existing method
        $data_result = $this->fetch_data_from_source($source);

        if (is_wp_error($data_result)) {
            wp_send_json_error('Data fetch failed: ' . $data_result->get_error_message());
            return;
        }

        if (empty($data_result) || !is_array($data_result)) {
            wp_send_json_error('No data available for export');
            return;
        }

        // Process data similar to render method
        $data = $data_result;
        $headers = array_keys((array) reset($data));

        // Apply column filtering if specified
        $include_cols = isset($_POST['include_cols']) ? array_map('trim', explode(',', sanitize_text_field(wp_unslash($_POST['include_cols'])))) : [];
        $exclude_cols = isset($_POST['exclude_cols']) ? array_map('trim', explode(',', sanitize_text_field(wp_unslash($_POST['exclude_cols'])))) : [];

        if (!empty($include_cols)) {
            $headers = array_intersect($headers, $include_cols);
        }

        if (!empty($exclude_cols)) {
            $headers = array_diff($headers, $exclude_cols);
        }

        // Get template settings
        $templates = TC_Export_Handler::get_export_templates();
        $template_settings = isset($templates[$template]) ? $templates[$template] : $templates['default'];

        // Export options
        $export_options = array_merge($template_settings, [
            'format' => $format,
            'filename' => $filename,
            'include_metadata' => $include_metadata,
            'filters_applied' => isset($_POST['filters_applied']) ? json_decode(wp_unslash($_POST['filters_applied']), true) : [],
            'sort_applied' => isset($_POST['sort_applied']) ? sanitize_text_field(wp_unslash($_POST['sort_applied'])) : '',
            'total_records' => count($data),
            'export_timestamp' => current_time('mysql')
        ]);

        // Generate export
        $export_result = TC_Export_Handler::export_data($data, $headers, $export_options);

        if (isset($export_result['error'])) {
            wp_send_json_error('Export failed: ' . $export_result['error']);
            return;
        }

        // Store export file temporarily with unique identifier
        $export_id = uniqid('tc_export_', true);
        set_transient('tc_export_' . $export_id, $export_result, 300); // 5 minutes

        wp_send_json_success([
            'export_id' => $export_id,
            'filename' => $export_result['filename'],
            'format' => $format,
            'size' => $export_result['size'],
            'download_url' => admin_url('admin-ajax.php?action=tc_download_export&export_id=' . $export_id . '&nonce=' . wp_create_nonce('tc_download_nonce'))
        ]);
    }

    /**
     * Handle Export Download
     * 
     * Serves the generated export file for download.
     * 
     * @return void
     */
    public function ajax_download_export(): void
    {
        // Verify nonce with proper sanitization
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'tc_download_nonce')) {
            wp_die('Invalid nonce');
        }

        // Get export ID
        $export_id = isset($_GET['export_id']) ? sanitize_text_field(wp_unslash($_GET['export_id'])) : '';

        if (empty($export_id)) {
            wp_die('Invalid export ID');
        }

        // Retrieve export data
        $export_data = get_transient('tc_export_' . $export_id);

        if (!$export_data) {
            wp_die('Export not found or expired');
        }

        // Verify file exists
        if (!isset($export_data['file_path']) || !file_exists($export_data['file_path'])) {
            wp_die('Export file not found');
        }

        // Set headers for download
        $filename = $export_data['filename'];
        $mime_type = $export_data['mime_type'];

        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $export_data['size']);
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        // Output file content
        readfile($export_data['file_path']);

        // Clean up
        TC_Export_Handler::cleanup_temp_file($export_data['file_path']);
        delete_transient('tc_export_' . $export_id);

        exit;
    }

    /**
     * Handle Elementor Live Preview AJAX Request
     * 
     * Fetches and processes data specifically for Elementor editor preview.
     * Optimized for preview performance with row limiting and caching.
     * 
     * @return void
     */
    public function ajax_elementor_preview(): void
    {
        // Verify nonce with proper sanitization
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'tc_proxy_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        // Check permissions (allow edit_posts for editors working with Elementor)
        if (!current_user_can('edit_posts') && !current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Get preview parameters
        $source = isset($_POST['source']) ? esc_url_raw(wp_unslash($_POST['source'])) : '';
        $root = isset($_POST['root']) ? sanitize_text_field(wp_unslash($_POST['root'])) : '';
        $include = isset($_POST['include']) ? sanitize_text_field(wp_unslash($_POST['include'])) : '';
        $exclude = isset($_POST['exclude']) ? sanitize_text_field(wp_unslash($_POST['exclude'])) : '';
        $preview_rows = isset($_POST['preview_rows']) ? intval($_POST['preview_rows']) : 5;

        // Validate required fields
        if (empty($source)) {
            wp_send_json_error('Source URL is required');
            return;
        }

        // Limit preview rows for performance
        $preview_rows = max(1, min(25, $preview_rows));

        try {
            // Build preview attributes
            $preview_atts = array(
                'source' => $source,
                'root' => $root,
                'include' => $include,
                'exclude' => $exclude,
                'search' => false,
                'filters' => false,
                'export' => false,
                'per_page' => 0, // No pagination in preview
                'sort' => ''
            );

            // Fetch and process data
            $result = $this->fetch_and_render_php($preview_atts);

            if (isset($result['error'])) {
                wp_send_json_error($result['error']);
                return;
            }

            if (!isset($result['data']) || !is_array($result['data'])) {
                wp_send_json_error('Invalid data structure');
                return;
            }

            // Limit data for preview performance
            $preview_data = array_slice($result['data'], 0, $preview_rows);

            wp_send_json_success($preview_data);

        } catch (Exception $e) {
            wp_send_json_error('Preview generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Plugin Activation Hook.
     */
    public static function activate(): void
    {
        add_option('tc_do_activation_redirect', true);
    }



    /**
     * Render user-friendly placeholder when no data source is configured
     * 
     * @return string HTML placeholder content
     */
    private function render_empty_source_placeholder()
    {
        // Check if we're in the block editor (Gutenberg)
        $is_in_editor = function_exists('get_current_screen') &&
            get_current_screen() &&
            get_current_screen()->is_block_editor;

        // Also check for REST API context (block editor preview)
        $is_rest_request = (defined('REST_REQUEST') && REST_REQUEST) ||
            (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/') !== false);

        if ($is_in_editor || $is_rest_request) {
            // Friendly message for block editor
            return '
                <div class="tc-placeholder" style="
                    border: 2px dashed #ddd; 
                    border-radius: 8px; 
                    padding: 40px 20px; 
                    text-align: center; 
                    background: #f9f9f9;
                    color: #666;
                    font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;
                ">
                    <div style="margin-bottom: 16px;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" style="opacity: 0.5; margin-bottom: 12px;">
                            <rect x="4" y="6" width="16" height="12" rx="1" stroke="currentColor" stroke-width="2" fill="none"/>
                            <line x1="4" y1="10" x2="20" y2="10" stroke="currentColor" stroke-width="2"/>
                            <line x1="4" y1="14" x2="20" y2="14" stroke="currentColor" stroke-width="2"/>
                            <line x1="12" y1="6" x2="12" y2="18" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </div>
                    <h3 style="margin: 0 0 8px 0; color: #333; font-size: 18px; font-weight: 600;">' .
                esc_html__('Configure Your Data Source', 'tablecrafter-wp-data-tables') .
                '</h3>
                    <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.5;">' .
                esc_html__('Add a JSON URL, CSV file, or Google Sheet to create your table.', 'tablecrafter-wp-data-tables') .
                '</p>
                    <p style="margin: 0; font-size: 12px; color: #888;">' .
                esc_html__('👉 Use the sidebar settings to get started', 'tablecrafter-wp-data-tables') .
                '</p>
                </div>';
        } else {
            // Simple message for frontend when block is published without source
            return '<div class="tc-error" style="
                padding: 16px; 
                background: #fff3cd; 
                border: 1px solid #ffeaa7; 
                border-radius: 4px; 
                color: #856404;
                font-size: 14px;
            ">' .
                esc_html__('⚠️ TableCrafter: Please configure a data source to display your table.', 'tablecrafter-wp-data-tables') .
                '</div>';
        }
    }

    /**
     * Sanitize table HTML content while preserving TableCrafter-specific elements
     * 
     * This method allows the HTML elements that TableCrafter generates (like email links,
     * image tags, date/time elements) while maintaining security against XSS attacks.
     * 
     * @param string $html_content Raw HTML content from table generation
     * @return string Sanitized HTML content
     */
    private function sanitize_table_html($html_content)
    {
        // Define allowed HTML tags and attributes for TableCrafter tables
        $allowed_tags = array(
            'table' => array('class' => true, 'id' => true),
            'thead' => array('class' => true),
            'tbody' => array('class' => true),
            'tr' => array('class' => true, 'data-tc-row-id' => true),
            'th' => array(
                'class' => true,
                'tabindex' => true,
                'aria-sort' => true,
                'data-field' => true,
                'scope' => true
            ),
            'td' => array('class' => true, 'data-tc-label' => true),
            'a' => array(
                'href' => true,
                'title' => true,
                'target' => true,
                'rel' => true,
                'class' => true
            ),
            'img' => array(
                'src' => true,
                'alt' => true,
                'style' => true,
                'loading' => true,
                'class' => true,
                'width' => true,
                'height' => true
            ),
            'span' => array('class' => true, 'title' => true),
            'time' => array('datetime' => true, 'class' => true),
            'div' => array('class' => true),
            'strong' => array('class' => true),
            'em' => array('class' => true),
            'small' => array('class' => true)
        );

        // Define allowed protocols for links (includes mailto for email links)
        $allowed_protocols = array('http', 'https', 'mailto');

        // Use wp_kses with our custom configuration
        // Add temporary filter to ensure mailto is always allowed
        $filter_callback = function ($protocols) {
            if (!in_array('mailto', $protocols)) {
                $protocols[] = 'mailto';
            }
            return $protocols;
        };

        add_filter('wp_kses_allowed_protocols', $filter_callback, 10, 1);

        $sanitized = wp_kses($html_content, $allowed_tags, $allowed_protocols);

        // Clean up the specific filter
        remove_filter('wp_kses_allowed_protocols', $filter_callback, 10);

        return $sanitized;
    }

    /**
     * Add export nonce to page head for enhanced export security
     */
    public function add_export_nonce(): void
    {
        $nonce = wp_create_nonce('tc_export_nonce');
        echo '<meta name="tc-export-nonce" content="' . esc_attr($nonce) . '">' . "\n";
    }

    /**
     * Initialize TableCrafter.
     */
}

/**
 * Initialize TableCrafter.
 */
TableCrafter::get_instance();

/**
 * Register Activation Hook.
 */
register_activation_hook(__FILE__, array('TableCrafter', 'activate'));