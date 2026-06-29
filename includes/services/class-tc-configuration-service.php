<?php
/**
 * Configuration Service for Gravity Tables
 *
 * Centralized configuration management
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
 * ConfigurationService class
 *
 * Manages all plugin configuration and settings
 */
class TC_Configuration_Service {
    
    // Default configuration constants
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 100;
    public const MIN_PER_PAGE = 1;
    public const DEFAULT_DATE_FORMAT = 'm/d/Y';
    public const DEFAULT_DATETIME_FORMAT = 'm/d/Y g:i A';
    
    // Field type constants
    public const NON_EDITABLE_FIELD_TYPES = [
        'id',
        'date_created',
        'date_updated',
        'is_starred',
        'is_read',
        'ip',
        'source_url',
        'user_id'
    ];
    
    public const FILTERABLE_FIELD_TYPES = [
        'text',
        'textarea', 
        'select',
        'radio',
        'checkbox',
        'multiselect',
        'number',
        'date',
        'email',
        'website',
        'phone',
        'hidden'
    ];
    
    public const SORTABLE_FIELD_TYPES = [
        'text',
        'number',
        'date',
        'email',
        'select',
        'radio',
        'checkbox',
        'phone',
        'website'
    ];
    
    /**
     * Plugin settings
     *
     * @var array<string, mixed>
     */
    private array $settings;
    
    /**
     * Constructor
     *
     * @param array<string, mixed> $settings Optional settings override
     */
    public function __construct(array $settings = []) {
        $this->settings = array_merge($this->getDefaultSettings(), $settings);
    }
    
    /**
     * Get default plugin settings
     *
     * @return array<string, mixed> Default settings
     */
    public function getDefaultSettings(): array {
        return [
            'per_page' => self::DEFAULT_PER_PAGE,
            'show_search' => true,
            'show_pagination' => true,
            'show_selection' => true,
            'show_bulk_actions' => true,
            'show_advanced_filters' => true,
            'show_entry_info' => true,
            'enable_frontend_editing' => false,
            'sticky_header' => false,
            'freeze_first_column' => false,
            'responsive_table' => true,
            'persistent_filters' => false,
            'date_format' => self::DEFAULT_DATE_FORMAT,
            'datetime_format' => self::DEFAULT_DATETIME_FORMAT,
            'bulk_actions' => ['delete', 'export'],
            'user_role_filter' => '',
            'allowed_user_roles' => [],
            'cache_duration' => 3600, // 1 hour
            'max_entries_per_request' => 1000,
            'enable_debug_logging' => false
        ];
    }
    
    /**
     * Get setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if setting doesn't exist
     * @return mixed Setting value
     */
    public function get(string $key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
    
    /**
     * Set setting value
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return void
     */
    public function set(string $key, $value): void {
        $this->settings[$key] = $value;
    }
    
    /**
     * Get per page setting with validation
     *
     * @return int Valid per page value
     */
    public function getPerPage(): int {
        $perPage = (int) $this->get('per_page', self::DEFAULT_PER_PAGE);
        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));
    }
    
    /**
     * Get date format setting
     *
     * @return string Date format
     */
    public function getDateFormat(): string {
        return $this->get('date_format', self::DEFAULT_DATE_FORMAT);
    }
    
    /**
     * Get datetime format setting
     *
     * @return string Datetime format
     */
    public function getDateTimeFormat(): string {
        return $this->get('datetime_format', self::DEFAULT_DATETIME_FORMAT);
    }
    
    /**
     * Check if field type is editable
     *
     * @param string $fieldType Field type
     * @param string $fieldId Field ID (for special cases)
     * @return bool True if editable
     */
    public function isFieldEditable(string $fieldType, string $fieldId = ''): bool {
        // Check if field ID is in non-editable list
        if (in_array($fieldId, self::NON_EDITABLE_FIELD_TYPES, true)) {
            return false;
        }
        
        // Check if field type is generally editable
        return !in_array($fieldType, self::NON_EDITABLE_FIELD_TYPES, true);
    }
    
    /**
     * Check if field type is filterable
     *
     * @param string $fieldType Field type
     * @return bool True if filterable
     */
    public function isFieldFilterable(string $fieldType): bool {
        return in_array($fieldType, self::FILTERABLE_FIELD_TYPES, true);
    }
    
    /**
     * Check if field type is sortable
     *
     * @param string $fieldType Field type
     * @return bool True if sortable
     */
    public function isFieldSortable(string $fieldType): bool {
        return in_array($fieldType, self::SORTABLE_FIELD_TYPES, true);
    }
    
    /**
     * Get allowed bulk actions
     *
     * @return string[] Allowed bulk actions
     */
    public function getAllowedBulkActions(): array {
        return $this->get('bulk_actions', ['delete', 'export']);
    }
    
    /**
     * Get cache duration in seconds
     *
     * @return int Cache duration
     */
    public function getCacheDuration(): int {
        return max(0, (int) $this->get('cache_duration', 3600));
    }
    
    /**
     * Get maximum entries per request
     *
     * @return int Max entries
     */
    public function getMaxEntriesPerRequest(): int {
        return max(1, (int) $this->get('max_entries_per_request', 1000));
    }
    
    /**
     * Check if debug logging is enabled
     *
     * @return bool True if debug logging enabled
     */
    public function isDebugLoggingEnabled(): bool {
        return $this->get('enable_debug_logging', false) || (defined('WP_DEBUG') && WP_DEBUG);
    }
    
    /**
     * Get field configuration defaults
     *
     * @param string $fieldType Field type
     * @return array<string, mixed> Field configuration
     */
    public function getFieldDefaults(string $fieldType): array {
        $defaults = [
            'editable' => $this->isFieldEditable($fieldType),
            'filterable' => $this->isFieldFilterable($fieldType),
            'sortable' => $this->isFieldSortable($fieldType),
            'width' => '',
            'custom_label' => '',
            'lookup_enabled' => false
        ];
        
        // Add type-specific defaults
        switch ($fieldType) {
            case 'date':
                $defaults['filter_config'] = [
                    'type' => 'date',
                    'date_range' => 'single',
                    'show_presets' => true
                ];
                break;
                
            case 'number':
            case 'phone':
                $defaults['filter_config'] = [
                    'type' => 'range',
                    'range_step' => 1,
                    'range_format' => 'number'
                ];
                break;
                
            case 'select':
            case 'radio':
                $defaults['filter_config'] = [
                    'type' => 'dropdown',
                    'multiple' => false,
                    'sort_options' => 'alphabetical'
                ];
                break;
                
            case 'multiselect':
            case 'checkbox':
                $defaults['filter_config'] = [
                    'type' => 'checkboxes',
                    'checkboxes_logic' => 'or',
                    'show_select_all' => true
                ];
                break;
                
            default:
                $defaults['filter_config'] = [
                    'type' => 'text',
                    'case_sensitive' => false,
                    'exact_match' => false
                ];
                break;
        }
        
        return $defaults;
    }
    
    /**
     * Create table configuration with defaults
     *
     * @param array<string, mixed> $overrides Configuration overrides
     * @return TC_Table_Configuration Table configuration object
     */
    public function createTableConfiguration(array $overrides = []): TC_Table_Configuration {
        return new TC_Table_Configuration(
            array_merge($this->settings, $overrides)
        );
    }
    
    /**
     * Validate and sanitize configuration
     *
     * @param array<string, mixed> $config Configuration to validate
     * @return array<string, mixed> Validated configuration
     * @throws TC_Validation_Exception When validation fails
     */
    public function validateConfiguration(array $config): array {
        $validator = new TC_Validation_Service();
        $sanitizer = new TC_Sanitization_Service();
        
        $validated = [];
        
        // Validate per_page
        if (isset($config['per_page'])) {
            $perPage = $sanitizer->sanitizeInteger($config['per_page']);
            if ($perPage < self::MIN_PER_PAGE || $perPage > self::MAX_PER_PAGE) {
                throw new TC_Validation_Exception(
                    sprintf(
                        __('Items per page must be between %d and %d', 'tc-data-tables'),
                        self::MIN_PER_PAGE,
                        self::MAX_PER_PAGE
                    )
                );
            }
            $validated['per_page'] = $perPage;
        }
        
        // Validate boolean settings
        $booleanSettings = [
            'show_search', 'show_pagination', 'show_selection',
            'show_bulk_actions', 'enable_frontend_editing',
            'sticky_header', 'freeze_first_column', 'responsive_table'
        ];
        
        foreach ($booleanSettings as $setting) {
            if (isset($config[$setting])) {
                $validated[$setting] = $sanitizer->sanitizeBoolean($config[$setting]);
            }
        }
        
        // Validate bulk actions
        if (isset($config['bulk_actions']) && is_array($config['bulk_actions'])) {
            $allowedActions = ['delete', 'export', 'edit'];
            $validated['bulk_actions'] = array_intersect($config['bulk_actions'], $allowedActions);
        }
        
        return array_merge($this->settings, $validated);
    }
    
    /**
     * Get all settings as array
     *
     * @return array<string, mixed> All settings
     */
    public function toArray(): array {
        return $this->settings;
    }
    
    /**
     * Load settings from WordPress options
     *
     * @return void
     */
    public function loadFromOptions(): void {
        $savedSettings = get_option('gravity_tables_settings', []);
        if (is_array($savedSettings)) {
            $this->settings = array_merge($this->settings, $savedSettings);
        }
    }
    
    /**
     * Save settings to WordPress options
     *
     * @return bool Success status
     */
    public function saveToOptions(): bool {
        return update_option('gravity_tables_settings', $this->settings);
    }
}