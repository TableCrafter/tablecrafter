<?php
/**
 * GT Logger Class
 *
 * Dedicated logging system for Gravity Tables with file-based logs
 *
 * @package GravityTables
 * @since 3.1.14
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Logger {

    private static $instance = null;
    private $log_dir;
    private $log_file;
    private $max_log_size = 5242880; // 5MB
    private $max_log_files = 5;

    // #667 slice 28 - PHPUnit-shim fixture knob (issue #1087).
    //
    // tests/test-issue-72-insert-row-errors.php drives logging
    // assertions by writing to TC_Logger::$last_log. Under
    // tools/test-all.sh the test's own redeclared TC_Logger class wins
    // and owns the prop. Under the PHPUnit shim, tests/bootstrap.php
    // loads production TC_Logger first, the test's class-redeclaration
    // becomes a no-op via class_exists guard, and writes to the static
    // prop target THIS class. Declaring it here lets those writes
    // succeed without "Access to undeclared static property" fatals.
    //
    // Production callers NEVER read this prop - it exists solely as a
    // test fixture sink. Default is the empty array so a stray
    // production read would be safe-by-default.
    //
    // Contract pinned by tests/GTAdminLoggerSeamTest.php.
    public static $last_log = array();

    private function __construct() {
        // Use WordPress content directory for logs (more secure and standard)
        $this->log_dir = WP_CONTENT_DIR . '/gravity-tables-logs/';
        $this->log_file = $this->log_dir . 'gravity-tables-debug.log';
        $this->ensure_log_directory();
    }

    public static function get_instance() {
        // #667 slice 28 - PHPUnit-shim test seam (issue #1087).
        //
        // Production safety: this branch is gated on the TC_PHPUNIT_SHIM
        // constant which is ONLY defined by tests/PHPUnitShimTest.php and
        // tests/bootstrap.php. Production WordPress never defines that
        // constant; production callers fall through to the byte-identical
        // pre-slice singleton path below.
        //
        // Why the seam exists: under the PHPUnit shim, bootstrap.php loads
        // the plugin, which declares this production TC_Logger class. The
        // class_exists guard in test-issue-72 / test-issue-90 then skips
        // their own redeclared stub. Tests that need to intercept
        // TC_Logger::log() install an override instance into
        // $GLOBALS['gt_test_logger_override']; this gate routes
        // get_instance() callers to it.
        //
        // Contract pinned by tests/GTAdminLoggerSeamTest.php.
        if (defined('TC_PHPUNIT_SHIM')
            && array_key_exists('gt_test_logger_override', $GLOBALS)
            && $GLOBALS['gt_test_logger_override'] !== null
        ) {
            return $GLOBALS['gt_test_logger_override'];
        }

        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Ensure log directory exists and is writable
     */
    private function ensure_log_directory() {
        try {
            if (!file_exists($this->log_dir)) {
                wp_mkdir_p($this->log_dir);
            }
            
            // Create .htaccess to protect log files
            $htaccess_file = $this->log_dir . '.htaccess';
            if (!file_exists($htaccess_file)) {
                @file_put_contents($htaccess_file, "deny from all\n");
            }
            
            // Create index.php to prevent directory listing
            $index_file = $this->log_dir . 'index.php';
            if (!file_exists($index_file)) {
                @file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }
        // @codeCoverageIgnoreStart
        } catch (Exception $e) {
        // @codeCoverageIgnoreEnd
            // Silently fail if we can't create log directory
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
    }
    
    /**
     * Log a message with timestamp and context
     */
    public function log($level, $message, $context = array()) {
        try {
            // Only log if debugging is enabled
            if (!$this->is_debug_enabled()) {
                return;
            }
            
            $this->rotate_logs_if_needed();
            
            $timestamp = date('Y-m-d H:i:s.u');
            $level = strtoupper($level);
            
            // Format context data
            $context_str = '';
            if (!empty($context)) {
                $context_str = ' | Context: ' . json_encode($context, JSON_UNESCAPED_SLASHES);
            }
            
            $log_entry = "[{$timestamp}] [{$level}] {$message}{$context_str}\n";
            
            // Write to log file with error suppression
            @file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        // @codeCoverageIgnoreStart
        } catch (Exception $e) {
        // @codeCoverageIgnoreEnd
            // Silently fail if logging fails to prevent breaking the application
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
    }
    
    /**
     * Debug level logging
     */
    public function debug($message, $context = array()) {
        $this->log('DEBUG', $message, $context);
    }
    
    /**
     * Info level logging
     */
    public function info($message, $context = array()) {
        $this->log('INFO', $message, $context);
    }
    
    /**
     * Warning level logging
     */
    public function warning($message, $context = array()) {
        $this->log('WARNING', $message, $context);
    }
    
    /**
     * Error level logging
     */
    public function error($message, $context = array()) {
        $this->log('ERROR', $message, $context);
    }
    
    /**
     * Check if debug logging is enabled
     */
    private function is_debug_enabled() {
        return isset($_POST['debug']) && $_POST['debug'] === 'true' ||
               isset($_GET['gt_debug']) && $_GET['gt_debug'] === '1';
    }
    
    /**
     * Rotate logs if they get too large
     */
    private function rotate_logs_if_needed() {
        try {
            if (!file_exists($this->log_file)) {
                return;
            }
            
            $file_size = @filesize($this->log_file);
            if ($file_size === false || $file_size < $this->max_log_size) {
                return;
            }
            
            // Rotate existing logs
            for ($i = $this->max_log_files - 1; $i >= 1; $i--) {
                $old_file = $this->log_dir . "gravity-tables-debug.{$i}.log";
                $new_file = $this->log_dir . "gravity-tables-debug." . ($i + 1) . ".log";
                
                if (file_exists($old_file)) {
                    if ($i == $this->max_log_files - 1) {
                        @unlink($old_file); // Delete oldest log
                    } else {
                        @rename($old_file, $new_file);
                    }
                }
            }
            
            // Move current log to .1
            @rename($this->log_file, $this->log_dir . 'gravity-tables-debug.1.log');
        // @codeCoverageIgnoreStart
        } catch (Exception $e) {
        // @codeCoverageIgnoreEnd
            // Silently fail if rotation fails
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
    }
    
    /**
     * Get log file path for download/viewing
     */
    public function get_log_file_path() {
        return $this->log_file;
    }
    
    /**
     * Get all log files
     */
    public function get_log_files() {
        $log_files = array();
        
        if (file_exists($this->log_file)) {
            $log_files[] = array(
                'name' => 'gravity-tables-debug.log',
                'path' => $this->log_file,
                'size' => filesize($this->log_file),
                'modified' => filemtime($this->log_file)
            );
        }
        
        // Add rotated logs
        for ($i = 1; $i <= $this->max_log_files; $i++) {
            $rotated_file = $this->log_dir . "gravity-tables-debug.{$i}.log";
            if (file_exists($rotated_file)) {
                $log_files[] = array(
                    'name' => "gravity-tables-debug.{$i}.log",
                    'path' => $rotated_file,
                    'size' => filesize($rotated_file),
                    'modified' => filemtime($rotated_file)
                );
            }
        }
        
        return $log_files;
    }
    
    /**
     * Clear all log files
     */
    public function clear_logs() {
        $log_files = $this->get_log_files();
        foreach ($log_files as $log_file) {
            if (file_exists($log_file['path'])) {
                unlink($log_file['path']);
            }
        }
    }
    
    /**
     * Get recent log entries (last N lines)
     */
    public function get_recent_logs($lines = 100) {
        if (!file_exists($this->log_file)) {
            return array();
        }
        
        $file = new SplFileObject($this->log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();
        
        $start_line = max(0, $total_lines - $lines);
        $file->seek($start_line);
        
        $recent_logs = array();
        while (!$file->eof()) {
            $line = $file->fgets();
            if (trim($line)) {
                $recent_logs[] = trim($line);
            }
        }
        
        return $recent_logs;
    }
}