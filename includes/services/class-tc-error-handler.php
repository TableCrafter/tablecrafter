<?php
/**
 * Error Handler Service for Gravity Tables
 *
 * Centralized error handling and logging
 *
 * @package GravityTables
 * @since 2.0.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
/**
 * ErrorHandler class
 *
 * Handles all error processing and logging for the plugin
 */
class TC_Error_Handler {
    
    /**
     * Log file path
     *
     * @var string
     */
    private string $logFile;
    
    /**
     * Debug mode flag
     *
     * @var bool
     */
    private bool $debugMode;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logFile = WP_CONTENT_DIR . '/gravity-tables-error.log';
        $this->debugMode = defined('WP_DEBUG') && WP_DEBUG;
    }
    
    /**
     * Handle AJAX errors and send appropriate response
     *
     * @param Throwable $exception The exception that occurred
     * @param string $context Context where the error occurred
     * @return never
     */
    public function handleAjaxError(Throwable $exception, string $context): never {
        // Log the error
        $this->logError($exception, $context);
        
        // Get user-friendly message
        $message = $this->getUserFriendlyMessage($exception);
        
        // Send JSON error response
        wp_send_json_error([
            'message' => $message,
            'code' => $exception->getCode(),
            'context' => $context,
            'debug' => $this->debugMode ? $this->getDebugInfo($exception) : null
        ]);
    }
    
    /**
     * Handle general errors (non-AJAX)
     *
     * @param Throwable $exception The exception that occurred
     * @param string $context Context where the error occurred
     * @return string User-friendly error message
     */
    public function handleError(Throwable $exception, string $context): string {
        // Log the error
        $this->logError($exception, $context);
        
        // Return user-friendly message
        return $this->getUserFriendlyMessage($exception);
    }
    
    /**
     * Log error to file and WordPress error log
     *
     * @param Throwable $exception The exception to log
     * @param string $context Context information
     * @return void
     */
    public function logError(Throwable $exception, string $context = ''): void {
        $errorData = [
            'timestamp' => current_time('mysql'),
            'context' => $context,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'user_id' => get_current_user_id(),
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];
        
        // Add validation errors if applicable
        if ($exception instanceof TC_Validation_Exception) {
            $errorData['validation_errors'] = $exception->getErrors();
        }
        
        $logEntry = sprintf(
            "[%s] GT Error in %s: %s\nFile: %s:%d\nUser: %d\nURL: %s\nTrace: %s\n%s\n",
            $errorData['timestamp'],
            $context,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $errorData['user_id'],
            $errorData['url'],
            $this->debugMode ? $exception->getTraceAsString() : 'Trace hidden (debug mode off)',
            str_repeat('-', 80)
        );
        
        // Write to WordPress error log
        // error_log($logEntry);
        
        // Write to plugin-specific log file in debug mode
        if ($this->debugMode) {
            $this->writeToLogFile($logEntry);
        }
        
        // Fire action hook for custom error handling
        do_action('gravity_tables_error', $exception, $context, $errorData);
    }
    
    /**
     * Handle an exception by logging it (no return value, no exit).
     *
     * Alias for logError() - added in slice 68 to close #1193. TC_Entry_Service
     * (and any future consumer) catches a Throwable and routes it through this
     * shorter name without needing to pass a context string. Use logError()
     * directly when you have a meaningful context label to attach.
     *
     * @param Throwable $exception The exception to log
     * @return void
     */
    public function handleException(Throwable $exception): void {
        $this->logError($exception, '');
    }

    /**
     * Get user-friendly error message
     *
     * @param Throwable $exception The exception
     * @return string User-friendly message
     */
    private function getUserFriendlyMessage(Throwable $exception): string {
        return match(get_class($exception)) {
            TC_Validation_Exception::class => $exception->getFirstError() ?: __('Validation failed', 'tc-data-tables'),
            TC_Permission_Exception::class => __('You do not have permission to perform this action', 'tc-data-tables'),
            TC_Database_Exception::class => __('A database error occurred. Please try again', 'tc-data-tables'),
            InvalidArgumentException::class => __('Invalid parameters provided', 'tc-data-tables'),
            default => $this->debugMode 
                ? $exception->getMessage() 
                : __('An unexpected error occurred. Please try again', 'tc-data-tables')
        };
    }
    
    /**
     * Get debug information for the exception
     *
     * @param Throwable $exception The exception
     * @return array<string, mixed> Debug information
     */
    private function getDebugInfo(Throwable $exception): array {
        return [
            'exception_class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_slice($exception->getTrace(), 0, 5) // Limit trace depth
        ];
    }
    
    /**
     * Write log entry to file
     *
     * @param string $logEntry Log entry to write
     * @return void
     */
    private function writeToLogFile(string $logEntry): void {
        try {
            file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Fallback to // error_log if file writing fails
            // error_log("GT Error Handler: Failed to write to log file - " . $e->getMessage());
        }
    }
    
    /**
     * Handle WordPress admin notices for errors
     *
     * @param string $message Error message to display
     * @param string $type Notice type (error, warning, info, success)
     * @return void
     */
    public function addAdminNotice(string $message, string $type = 'error'): void {
        add_action('admin_notices', function() use ($message, $type) {
            $class = 'notice notice-' . $type;
            printf('<div class="%s"><p>%s</p></div>', esc_attr($class), esc_html($message));
        });
    }
    
    /**
     * Clear error log file
     *
     * @return bool Success status
     */
    public function clearErrorLog(): bool {
        if (file_exists($this->logFile)) {
            return unlink($this->logFile);
        }
        return true;
    }
    
    /**
     * Get recent error log entries
     *
     * @param int $lines Number of lines to retrieve
     * @return string[] Log entries
     */
    public function getRecentErrors(int $lines = 50): array {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $file = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($file === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }
        
        return array_slice($file, -$lines);
    }
    
    /**
     * Validate error handling setup
     *
     * @return array{status: bool, issues: string[]} Validation result
     */
    public function validateSetup(): array {
        $issues = [];
        
        // Check if log directory is writable
        if (!is_writable(dirname($this->logFile))) {
            $issues[] = 'Log directory is not writable: ' . dirname($this->logFile);
        }
        
        // Check if WordPress debug logging is enabled
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            $issues[] = 'WordPress debug logging is not enabled';
        }
        
        // Check disk space
        $freeSpace = disk_free_space(dirname($this->logFile));
        if ($freeSpace !== false && $freeSpace < 10 * 1024 * 1024) { // Less than 10MB
            // @codeCoverageIgnoreStart
            $issues[] = 'Low disk space for logging';
            // @codeCoverageIgnoreEnd
        }
        
        return [
            'status' => empty($issues),
            'issues' => $issues
        ];
    }
}