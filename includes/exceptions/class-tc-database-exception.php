<?php
/**
 * Database Exception for Gravity Tables
 *
 * Custom exception for database-related errors
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
 * DatabaseException class
 *
 * Thrown when database operations fail
 */
class TC_Database_Exception extends Exception {
    
    /**
     * SQL query that failed
     *
     * @var string
     */
    private string $query;
    
    /**
     * Database error message
     *
     * @var string
     */
    private string $dbError;
    
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param string $query SQL query that failed
     * @param string $dbError Database error message
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception
     */
    public function __construct(
        string $message = '',
        string $query = '',
        string $dbError = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->query = $query;
        $this->dbError = $dbError;
        
        if (empty($message)) {
            $message = __('Database operation failed', 'tc-data-tables');
        }
        
        parent::__construct($message, $code, $previous);
    }
    
    /**
     * Get failed query
     *
     * @return string SQL query
     */
    public function getQuery(): string {
        return $this->query;
    }
    
    /**
     * Get database error message
     *
     * @return string Database error
     */
    public function getDatabaseError(): string {
        return $this->dbError;
    }
    
    /**
     * Convert to array for JSON responses
     *
     * @return array{message: string, query?: string, db_error?: string, code: int}
     */
    public function toArray(): array {
        $data = [
            'message' => $this->getMessage(),
            'code' => $this->getCode()
        ];
        
        // Only include sensitive data in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // @codeCoverageIgnoreStart
            $data['query'] = $this->query;
            $data['db_error'] = $this->dbError;
            // @codeCoverageIgnoreEnd
        }
        
        return $data;
    }
    
    /**
     * Create from WordPress database error
     *
     * @param wpdb $wpdb WordPress database instance
     * @param string $query Failed query
     * @return static Database exception
     */
    public static function fromWpdb(\wpdb $wpdb, string $query = ''): static {
        return new static(
            __('Database query failed', 'tc-data-tables'),
            $query,
            $wpdb->last_error
        );
    }
}