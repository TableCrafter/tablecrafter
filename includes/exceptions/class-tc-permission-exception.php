<?php
/**
 * Permission Exception for Gravity Tables
 *
 * Custom exception for permission/authorization errors
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
 * PermissionException class
 *
 * Thrown when user lacks required permissions
 */
class TC_Permission_Exception extends Exception {
    
    /**
     * Required capability
     *
     * @var string
     */
    private string $requiredCapability;
    
    /**
     * User ID who attempted the action
     *
     * @var int
     */
    private int $userId;
    
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param string $requiredCapability Required capability
     * @param int $userId User ID
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception
     */
    public function __construct(
        string $message = '',
        string $requiredCapability = '',
        int $userId = 0,
        int $code = 403,
        ?Throwable $previous = null
    ) {
        $this->requiredCapability = $requiredCapability;
        $this->userId = $userId ?: get_current_user_id();
        
        if (empty($message)) {
            $message = sprintf(
                __('Access denied. Required capability: %s', 'tc-data-tables'),
                $requiredCapability
            );
        }
        
        parent::__construct($message, $code, $previous);
    }
    
    /**
     * Get required capability
     *
     * @return string Required capability
     */
    public function getRequiredCapability(): string {
        return $this->requiredCapability;
    }
    
    /**
     * Get user ID
     *
     * @return int User ID
     */
    public function getUserId(): int {
        return $this->userId;
    }
    
    /**
     * Convert to array for JSON responses
     *
     * @return array{message: string, required_capability: string, user_id: int, code: int}
     */
    public function toArray(): array {
        return [
            'message' => $this->getMessage(),
            'required_capability' => $this->requiredCapability,
            'user_id' => $this->userId,
            'code' => $this->getCode()
        ];
    }
}