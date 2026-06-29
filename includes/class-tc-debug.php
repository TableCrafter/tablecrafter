<?php
/**
 * Gravity Tables Debug System
 * 
 * Centralized debug logging with configurable categories for both
 * backend PHP logging and frontend JavaScript console logging.
 * 
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Debug {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Debug categories and their enabled status
     */
    private $debug_categories = array(
        'ajax' => false,           // AJAX requests and responses
        'filtering' => false,      // Filter processing and queries
        'sorting' => false,        // Sort processing and queries
        'lookup' => false,         // Lookup field processing
        'permissions' => false,    // User permissions and access control
        'database' => false,       // Database queries and operations
        'frontend' => false,       // Frontend JavaScript operations
        'conditional' => false,    // Conditional formatting
        'performance' => false,    // Performance timing and metrics
        'validation' => false,     // Data validation and sanitization
        'all' => false            // Master switch for all debugging
    );
    
    /**
     * Performance timing storage
     */
    private $timers = array();
    
    /**
     * Get singleton instance
     *
     * #667 slice 28 — PHPUnit-shim test seam (issue #1087).
     *
     * Production safety: the override branch is gated on the
     * TC_PHPUNIT_SHIM constant which is ONLY defined by
     * tests/PHPUnitShimTest.php and tests/bootstrap.php. Production
     * WordPress never defines that constant; production callers fall
     * through to the byte-identical pre-slice singleton path below.
     *
     * Why the seam exists: under the PHPUnit shim, bootstrap.php loads
     * the plugin, which declares this production TC_Debug class. The
     * class_exists guard in test-issue-72 / test-issue-90 then skips
     * their own redeclared stub. Tests that need to intercept
     * TC_Debug::log() install an override instance into
     * $GLOBALS['gt_test_debug_override']; this gate routes
     * get_instance() callers to it.
     *
     * Contract pinned by tests/GTAdminLoggerSeamTest.php.
     */
    public static function get_instance() {
        if (defined('TC_PHPUNIT_SHIM')
            && array_key_exists('gt_test_debug_override', $GLOBALS)
            && $GLOBALS['gt_test_debug_override'] !== null
        ) {
            return $GLOBALS['gt_test_debug_override'];
        }

        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Load debug settings
     */
    private function __construct() {
        $this->load_debug_settings();
        
        // Add admin hooks for debug controls
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_debug_admin_page'), 16);
            add_action('admin_init', array($this, 'register_debug_settings'));
            add_action('wp_ajax_gt_debug_action', array($this, 'ajax_debug_action'));
            add_action('wp_ajax_gt_debug_frontend', array($this, 'ajax_frontend_debug'));
        }
        
        // Add frontend debug script if any category is enabled
        if ($this->has_any_enabled_category()) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_debug_script'));
        }
    }
    
    /**
     * Load debug settings from WordPress options
     */
    private function load_debug_settings() {
        $saved_settings = get_option('gt_debug_settings', array());
        
        if (!empty($saved_settings) && is_array($saved_settings)) {
            $this->debug_categories = array_merge($this->debug_categories, $saved_settings);
        }
        
        // Environment-based overrides
        if (defined('TC_DEBUG_ALL') && TC_DEBUG_ALL) {
            // @codeCoverageIgnoreStart
            $this->debug_categories['all'] = true;
            // @codeCoverageIgnoreEnd
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG && defined('TC_DEBUG_AUTO_ENABLE') && TC_DEBUG_AUTO_ENABLE) {
            // @codeCoverageIgnoreStart
            $this->debug_categories['ajax'] = true;
            $this->debug_categories['database'] = true;
            // @codeCoverageIgnoreEnd
        }
    }
    
    /**
     * Check if a debug category is enabled
     */
    public function is_enabled($category) {
        return !empty($this->debug_categories['all']) || !empty($this->debug_categories[$category]);
    }
    
    /**
     * Check if any debug category is enabled
     */
    public function has_any_enabled_category() {
        foreach ($this->debug_categories as $category => $enabled) {
            if ($enabled) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Log a debug message to specified category
     */
    public function log($category, $message, $data = null) {
        if (!$this->is_enabled($category)) {
            return;
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = "[GT Debug - {$category}] [{$timestamp}] {$message}";
        
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $formatted_message .= "\nData: " . print_r($data, true);
            } else {
                $formatted_message .= " | Data: {$data}";
            }
        }
        
        error_log($formatted_message);
    }
    
    /**
     * Start a performance timer
     */
    public function start_timer($name, $category = 'performance') {
        if (!$this->is_enabled($category) && !$this->is_enabled('all')) {
            return;
        }
        
        $this->timers[$name] = microtime(true);
        $this->log($category, "Timer started: {$name}");
    }
    
    /**
     * End a performance timer and log the result
     */
    public function end_timer($name, $category = 'performance') {
        if (!$this->is_enabled($category) && !$this->is_enabled('all')) {
            return;
        }
        
        if (!isset($this->timers[$name])) {
            $this->log($category, "Timer '{$name}' was not started");
            return;
        }
        
        $elapsed = microtime(true) - $this->timers[$name];
        $elapsed_ms = round($elapsed * 1000, 2);
        
        $this->log($category, "Timer '{$name}' completed in {$elapsed_ms}ms");
        unset($this->timers[$name]);
        
        return $elapsed;
    }
    
    /**
     * Log database query with timing
     */
    public function log_query($query, $params = null, $category = 'database') {
        if (!$this->is_enabled($category)) {
            return;
        }
        
        $data = array(
            'query' => $query,
            'params' => $params,
            'backtrace' => wp_debug_backtrace_summary(__CLASS__)
        );
        
        $this->log($category, "Database Query", $data);
    }
    
    /**
     * Log AJAX request details
     */
    public function log_ajax($action, $request_data = null, $response_data = null) {
        if (!$this->is_enabled('ajax')) {
            return;
        }
        
        $data = array(
            'action' => $action,
            'user_id' => get_current_user_id(),
            'user_roles' => wp_get_current_user()->roles ?? array(),
            'request_data' => $request_data,
            'response_data' => $response_data
        );
        
        $this->log('ajax', "AJAX Request: {$action}", $data);
    }
    
    /**
     * Enable/disable a debug category
     */
    public function set_category($category, $enabled) {
        if (array_key_exists($category, $this->debug_categories)) {
            $this->debug_categories[$category] = (bool) $enabled;
            $this->save_debug_settings();
            return true;
        }
        return false;
    }
    
    /**
     * Get all debug categories and their status
     */
    public function get_categories() {
        return $this->debug_categories;
    }
    
    /**
     * Save debug settings to WordPress options
     */
    private function save_debug_settings() {
        update_option('gt_debug_settings', $this->debug_categories);
    }
    
    /**
     * Add debug admin page
     */
    public function add_debug_admin_page() {
        add_submenu_page(
            'gravity-tables',
            'GT Debug Settings',
            'Debug Settings',
            'manage_options',
            'gt-debug',
            array($this, 'render_debug_admin_page')
        );
    }
    
    /**
     * Register debug settings
     */
    public function register_debug_settings() {
        register_setting('gt_debug_settings_group', 'gt_debug_settings');
    }
    
    /**
     * Render debug admin page
     */
    public function render_debug_admin_page() {
        if (isset($_POST['submit'])) {
            $this->handle_debug_form_submission();
        }
        
        $categories = $this->get_categories();
        ?>
        <div class="wrap">
            <h1>TableCrafter - Debug Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>Debug System:</strong> Toggle debugging for specific components. Debug logs are written to your WordPress debug.log file.</p>
                <p><strong>Location:</strong> <code><?php echo WP_CONTENT_DIR; ?>/debug.log</code></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('gt_debug_settings', 'gt_debug_nonce'); ?>
                
                <table class="form-table">
                    <tbody>
                        <?php foreach ($categories as $category => $enabled): ?>
                            <tr>
                                <th scope="row">
                                    <label for="debug_<?php echo esc_attr($category); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $category))); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="checkbox" 
                                           id="debug_<?php echo esc_attr($category); ?>" 
                                           name="debug_categories[<?php echo esc_attr($category); ?>]" 
                                           value="1" 
                                           <?php checked($enabled); ?>
                                           <?php echo ($category === 'all') ? 'onchange="toggleAllDebug(this)"' : ''; ?>>
                                    <span class="description">
                                        <?php echo $this->get_category_description($category); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php submit_button('Save Debug Settings'); ?>
            </form>
            
            <div class="gt-debug-actions">
                <h2>Debug Actions</h2>
                <p>
                    <button type="button" class="button" onclick="clearDebugLog()">Clear Debug Log</button>
                    <button type="button" class="button" onclick="downloadDebugLog()">Download Debug Log</button>
                    <button type="button" class="button button-primary" onclick="testDebugSystem()">Test Debug System</button>
                </p>
            </div>
            
            <script>
            function toggleAllDebug(checkbox) {
                const checkboxes = document.querySelectorAll('input[name^="debug_categories["]');
                checkboxes.forEach(cb => {
                    if (cb !== checkbox) {
                        cb.checked = checkbox.checked;
                    }
                });
            }
            
            function clearDebugLog() {
                if (confirm('Are you sure you want to clear the debug log?')) {
                    jQuery.post(ajaxurl, {
                        action: 'gt_debug_action',
                        debug_action: 'clear_log',
                        nonce: '<?php echo wp_create_nonce('gt_debug_action'); ?>'
                    }, function(response) {
                        alert(response.data || 'Debug log cleared');
                    });
                }
            }
            
            function downloadDebugLog() {
                window.open('<?php echo admin_url('admin-ajax.php?action=gt_debug_action&debug_action=download_log&nonce=' . wp_create_nonce('gt_debug_action')); ?>');
            }
            
            function testDebugSystem() {
                jQuery.post(ajaxurl, {
                    action: 'gt_debug_action',
                    debug_action: 'test',
                    nonce: '<?php echo wp_create_nonce('gt_debug_action'); ?>'
                }, function(response) {
                    alert('Debug test completed. Check debug log for results.');
                });
            }
            </script>
        </div>
        <?php
    }
    
    /**
     * Get description for debug category
     */
    private function get_category_description($category) {
        $descriptions = array(
            'all' => 'Enable all debug categories (master switch)',
            'ajax' => 'Log AJAX requests, responses, and user context',
            'filtering' => 'Log filter processing, queries, and results',
            'sorting' => 'Log sort processing and SQL generation',
            'lookup' => 'Log lookup field processing and option generation',
            'permissions' => 'Log user permission checks and access control',
            'database' => 'Log database queries and performance',
            'frontend' => 'Enable frontend JavaScript console debugging',
            'conditional' => 'Log conditional formatting rule processing',
            'performance' => 'Log performance timers and metrics',
            'validation' => 'Log data validation and sanitization processes'
        );
        
        return isset($descriptions[$category]) ? $descriptions[$category] : 'Debug logging for ' . $category;
    }
    
    /**
     * Handle debug form submission
     */
    private function handle_debug_form_submission() {
        if (!wp_verify_nonce($_POST['gt_debug_nonce'], 'gt_debug_settings')) {
            wp_die(__('Security check failed', 'tc-data-tables'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tc-data-tables'));
        }
        
        $new_categories = $_POST['debug_categories'] ?? array();
        
        // Reset all categories to false first
        foreach ($this->debug_categories as $category => $enabled) {
            $this->debug_categories[$category] = false;
        }
        
        // Set enabled categories
        foreach ($new_categories as $category => $value) {
            if (array_key_exists($category, $this->debug_categories)) {
                $this->debug_categories[$category] = true;
            }
        }
        
        $this->save_debug_settings();
        
        echo '<div class="notice notice-success"><p>Debug settings saved successfully!</p></div>';
    }
    
    /**
     * AJAX handler for debug actions
     */
    public function ajax_debug_action() {
        if (!wp_verify_nonce($_REQUEST['nonce'], 'gt_debug_action')) {
            wp_send_json_error(__('Security check failed', 'tc-data-tables'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'tc-data-tables'));
        }
        
        $action = sanitize_text_field($_REQUEST['debug_action'] ?? '');
        
        switch ($action) {
            case 'clear_log':
                if (file_exists(WP_CONTENT_DIR . '/debug.log')) {
                    file_put_contents(WP_CONTENT_DIR . '/debug.log', '');
                    wp_send_json_success(__('Debug log cleared successfully', 'tc-data-tables'));
                } else {
                    wp_send_json_error(__('Debug log file not found', 'tc-data-tables'));
                }
                // @codeCoverageIgnoreStart
                break;
                // @codeCoverageIgnoreEnd
                
            case 'download_log':
                $log_path = WP_CONTENT_DIR . '/debug.log';
                if (file_exists($log_path)) {
                    // @codeCoverageIgnoreStart
                    header('Content-Type: text/plain');
                    header('Content-Disposition: attachment; filename="gravity-tables-debug-' . date('Y-m-d-H-i-s') . '.log"');
                    readfile($log_path);
                    exit;
                    // @codeCoverageIgnoreEnd
                } else {
                    wp_send_json_error(__('Debug log file not found', 'tc-data-tables'));
                }
                // @codeCoverageIgnoreStart
                break;
                // @codeCoverageIgnoreEnd
                
            case 'test':
                $this->run_debug_test();
                wp_send_json_success(__('Debug test completed', 'tc-data-tables'));
                // @codeCoverageIgnoreStart
                break;
                // @codeCoverageIgnoreEnd
                
            default:
                wp_send_json_error(__('Unknown debug action', 'tc-data-tables'));
        }
    }
    
    /**
     * Run debug system test
     */
    private function run_debug_test() {
        foreach ($this->debug_categories as $category => $enabled) {
            if ($enabled && $category !== 'all') {
                $this->log($category, "Debug test for category: {$category}", array(
                    'timestamp' => current_time('mysql'),
                    'test_data' => 'This is test data for ' . $category
                ));
            }
        }
    }
    
    /**
     * Enqueue frontend debug script
     */
    public function enqueue_debug_script() {
        if (!$this->has_any_enabled_category()) {
            return;
        }
        
        wp_enqueue_script(
            'gt-debug-frontend',
            plugin_dir_url(__FILE__) . '../assets/js/debug.js',
            array('jquery'),
            TC_VERSION,
            true
        );
        
        wp_localize_script('gt-debug-frontend', 'gtDebug', array(
            'enabled' => $this->has_any_enabled_category(),
            'categories' => $this->get_categories(),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gt_debug_frontend')
        ));
    }
    
    /**
     * Handle frontend debug submissions
     */
    public function ajax_frontend_debug() {
        if (!wp_verify_nonce($_POST['nonce'], 'gt_debug_frontend')) {
            wp_send_json_error(__('Security check failed', 'tc-data-tables'));
        }
        
        $category = sanitize_text_field($_POST['category'] ?? '');
        $message = sanitize_text_field($_POST['message'] ?? '');
        $data = $_POST['data'] ?? null;
        $level = sanitize_text_field($_POST['level'] ?? 'log');
        $url = sanitize_url($_POST['url'] ?? '');
        $user_agent = sanitize_text_field($_POST['userAgent'] ?? '');
        
        // Add frontend context to the log
        $frontend_data = array(
            'frontend_data' => $data,
            'url' => $url,
            'user_agent' => $user_agent,
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql')
        );
        
        $this->log($category, "[Frontend] {$message}", $frontend_data);
        
        wp_send_json_success();
    }
    
    /**
     * Get debug statistics
     */
    public function get_debug_stats() {
        $log_path = WP_CONTENT_DIR . '/debug.log';
        $stats = array(
            'log_exists' => file_exists($log_path),
            'log_size' => 0,
            'log_lines' => 0,
            'gt_entries' => 0
        );
        
        if ($stats['log_exists']) {
            $stats['log_size'] = filesize($log_path);
            
            if ($stats['log_size'] > 0) {
                $content = file_get_contents($log_path);
                $stats['log_lines'] = substr_count($content, "\n");
                $stats['gt_entries'] = substr_count($content, '[GT Debug');
            }
        }
        
        return $stats;
    }
}