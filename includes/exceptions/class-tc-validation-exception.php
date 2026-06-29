<?php
/**
 * Validation Exception for Gravity Tables
 *
 * Custom exception for validation errors
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
 * ValidationException class
 *
 * Thrown when validation fails
 */
class TC_Validation_Exception extends Exception {
    
    /**
     * Array of validation errors
     *
     * @var string[]
     */
    private array $errors;
    
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param string[] $errors Array of validation errors
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception
     */
    public function __construct(
        string $message = '',
        array $errors = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->errors = $errors;
        parent::__construct($message, $code, $previous);
    }
    
    /**
     * Get validation errors
     *
     * @return string[] Array of error messages
     */
    public function getErrors(): array {
        return $this->errors;
    }
    
    /**
     * Get first error message
     *
     * @return string First error message or empty string
     */
    public function getFirstError(): string {
        return $this->errors[0] ?? '';
    }
    
    /**
     * Check if there are errors
     *
     * @return bool True if there are errors
     */
    public function hasErrors(): bool {
        return !empty($this->errors);
    }
    
    /**
     * Get errors as formatted string
     *
     * @param string $separator Separator between errors
     * @return string Formatted error string
     */
    public function getErrorsAsString(string $separator = "\n"): string {
        return implode($separator, $this->errors);
    }
    
    /**
     * Convert to array for JSON responses
     *
     * @return array{message: string, errors: string[], code: int}
     */
    public function toArray(): array {
        return [
            'message' => $this->getMessage(),
            'errors' => $this->errors,
            'code' => $this->getCode()
        ];
    }
}