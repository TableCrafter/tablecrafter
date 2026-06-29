<?php
/**
 * Table Configuration Model for Gravity Tables
 *
 * Represents configuration for a single table
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
 * TableConfiguration class
 *
 * Immutable configuration object for tables
 */
class TC_Table_Configuration {
    
    /**
     * Configuration data
     *
     * @var array<string, mixed>
     */
    private array $config;
    
    /**
     * Constructor
     *
     * @param array<string, mixed> $config Configuration data
     */
    public function __construct(array $config) {
        $this->config = $config;
    }
    
    /**
     * Get table ID
     *
     * @return int|null Table ID
     */
    public function getTableId(): ?int {
        $id = $this->config['table_id'] ?? null;
        return $id ? (int) $id : null;
    }
    
    /**
     * Get form ID
     *
     * @return int Form ID
     */
    public function getFormId(): int {
        return (int) ($this->config['form_id'] ?? 0);
    }
    
    /**
     * Get table title
     *
     * @return string Table title
     */
    public function getTitle(): string {
        return $this->config['title'] ?? '';
    }
    
    /**
     * Get selected fields
     *
     * @return int[] Selected field IDs
     */
    public function getSelectedFields(): array {
        $fields = $this->config['selected_fields'] ?? [];
        return is_array($fields) ? array_map('intval', $fields) : [];
    }

    /**
     * Get the configured column order as an array of field IDs.
     *
     * Returns the column IDs (integers or system-field strings) in exactly
     * the order they were saved by the admin — never sorted numerically.
     * Use this instead of getSelectedFields() wherever column render order matters.
     *
     * @return array<int, int|string> Field IDs in configured display order.
     */
    public function getColumnOrder(): array {
        $columns = $this->config['columns'] ?? $this->config['selected_fields'] ?? [];
        if (!is_array($columns) || empty($columns)) {
            return [];
        }
        // Preserve insertion order exactly — do NOT cast to int so that system
        // field IDs like 'entry_id' and 'date_created' remain strings.
        return array_values($columns);
    }
    
    /**
     * Get per page setting
     *
     * @return int Items per page
     */
    public function getPerPage(): int {
        $perPage = (int) ($this->config['per_page'] ?? TC_Configuration_Service::DEFAULT_PER_PAGE);
        return max(
            TC_Configuration_Service::MIN_PER_PAGE,
            min(TC_Configuration_Service::MAX_PER_PAGE, $perPage)
        );
    }
    
    /**
     * Check if search is enabled
     *
     * @return bool True if search enabled
     */
    public function isSearchEnabled(): bool {
        return $this->config['show_search'] ?? true;
    }
    
    /**
     * Check if pagination is enabled
     *
     * @return bool True if pagination enabled
     */
    public function isPaginationEnabled(): bool {
        return $this->config['show_pagination'] ?? true;
    }
    
    /**
     * Check if selection is enabled
     *
     * @return bool True if selection enabled
     */
    public function isSelectionEnabled(): bool {
        return $this->config['show_selection'] ?? true;
    }
    
    /**
     * Check if bulk actions are enabled
     *
     * @return bool True if bulk actions enabled
     */
    public function isBulkActionsEnabled(): bool {
        return $this->config['show_bulk_actions'] ?? true;
    }
    
    /**
     * Check if advanced filters are enabled
     *
     * @return bool True if advanced filters enabled
     */
    public function isAdvancedFiltersEnabled(): bool {
        return $this->config['show_advanced_filters'] ?? true;
    }
    
    /**
     * Check if entry info is enabled
     *
     * @return bool True if entry info enabled
     */
    public function isEntryInfoEnabled(): bool {
        return $this->config['show_entry_info'] ?? true;
    }
    
    /**
     * Check if frontend editing is enabled
     *
     * @return bool True if frontend editing enabled
     */
    public function isFrontendEditingEnabled(): bool {
        return $this->config['enable_frontend_editing'] ?? false;
    }
    
    /**
     * Check if sticky header is enabled
     *
     * @return bool True if sticky header enabled
     */
    public function isStickyHeaderEnabled(): bool {
        return $this->config['sticky_header'] ?? false;
    }
    
    /**
     * Check if responsive table is enabled
     *
     * @return bool True if responsive table enabled
     */
    public function isResponsiveTableEnabled(): bool {
        return $this->config['responsive_table'] ?? true;
    }
    
    /**
     * Check if persistent filters are enabled
     *
     * @return bool True if persistent filters enabled
     */
    public function isPersistentFiltersEnabled(): bool {
        return $this->config['persistent_filters'] ?? false;
    }
    
    /**
     * Get date format
     *
     * @return string Date format
     */
    public function getDateFormat(): string {
        return $this->config['date_format'] ?? TC_Configuration_Service::DEFAULT_DATE_FORMAT;
    }
    
    /**
     * Get datetime format
     *
     * @return string Datetime format
     */
    public function getDateTimeFormat(): string {
        return $this->config['datetime_format'] ?? TC_Configuration_Service::DEFAULT_DATETIME_FORMAT;
    }
    
    /**
     * Get bulk actions
     *
     * @return string[] Enabled bulk actions
     */
    public function getBulkActions(): array {
        $actions = $this->config['bulk_actions'] ?? ['delete', 'export'];
        return is_array($actions) ? $actions : [];
    }
    
    /**
     * Get user role filter (backward compatibility)
     *
     * @return string User role filter
     */
    public function getUserRoleFilter(): string {
        return $this->config['user_role_filter'] ?? '';
    }
    
    /**
     * Get allowed user roles for table access control
     *
     * @return array User roles allowed to view this table
     */
    public function getAllowedUserRoles(): array {
        $roles = $this->config['allowed_user_roles'] ?? [];
        
        // Backward compatibility: if no allowed_user_roles but user_role_filter exists, use it
        if (empty($roles) && !empty($this->getUserRoleFilter())) {
            $roles = [$this->getUserRoleFilter()];
        }
        
        return is_array($roles) ? $roles : [];
    }
    
    /**
     * Check if current user can view this table
     *
     * @return bool True if user can view table
     */
    public function canCurrentUserViewTable(): bool {
        // Administrators always have access
        if (current_user_can('manage_options')) {
            return true;
        }
        
        $allowedRoles = $this->getAllowedUserRoles();
        
        // If no roles specified, table is accessible to everyone
        if (empty($allowedRoles)) {
            return true;
        }
        
        // Check if current user has any of the allowed roles
        $currentUser = wp_get_current_user();
        $userRoles = $currentUser->roles ?? [];
        
        return !empty(array_intersect($userRoles, $allowedRoles));
    }
    
    /**
     * Get field labels
     *
     * @return array<string, string> Field labels map
     */
    public function getFieldLabels(): array {
        $labels = $this->config['field_labels'] ?? [];
        return is_array($labels) ? $labels : [];
    }
    
    /**
     * Get editable fields
     *
     * @return int[] Editable field IDs
     */
    public function getEditableFields(): array {
        $fields = $this->config['editable_fields'] ?? [];
        return is_array($fields) ? array_map('intval', $fields) : [];
    }
    
    /**
     * Get sortable fields
     *
     * @return int[] Sortable field IDs
     */
    public function getSortableFields(): array {
        $fields = $this->config['sortable_fields'] ?? [];
        return is_array($fields) ? array_map('intval', $fields) : [];
    }
    
    /**
     * Get filterable fields
     *
     * @return int[] Filterable field IDs
     */
    public function getFilterableFields(): array {
        $fields = $this->config['filterable_fields'] ?? [];
        return is_array($fields) ? array_map('intval', $fields) : [];
    }
    
    /**
     * Get lookup fields configuration
     *
     * @return array<string, array<string, mixed>> Lookup fields config
     */
    public function getLookupFields(): array {
        $lookupFields = $this->config['lookup_fields'] ?? [];
        return is_array($lookupFields) ? $lookupFields : [];
    }
    
    /**
     * Get conditional formatting rules
     *
     * @return array<string, array<array<string, mixed>>> Conditional formatting rules
     */
    public function getConditionalFormatting(): array {
        $formatting = $this->config['conditional_formatting'] ?? [];
        return is_array($formatting) ? $formatting : [];
    }
    
    /**
     * Get filter configurations
     *
     * @return array<string, array<string, mixed>> Filter configurations
     */
    public function getFilterConfigurations(): array {
        $configs = $this->config['filter_configurations'] ?? [];
        return is_array($configs) ? $configs : [];
    }
    
    /**
     * Check if field is editable
     *
     * @param int $fieldId Field ID
     * @return bool True if field is editable
     */
    public function isFieldEditable(int $fieldId): bool {
        return in_array($fieldId, $this->getEditableFields(), true);
    }
    
    /**
     * Check if field is sortable
     *
     * @param int $fieldId Field ID
     * @return bool True if field is sortable
     */
    public function isFieldSortable(int $fieldId): bool {
        return in_array($fieldId, $this->getSortableFields(), true);
    }
    
    /**
     * Check if field is filterable
     *
     * @param int $fieldId Field ID
     * @return bool True if field is filterable
     */
    public function isFieldFilterable(int $fieldId): bool {
        return in_array($fieldId, $this->getFilterableFields(), true);
    }
    
    /**
     * Get field label
     *
     * @param int $fieldId Field ID
     * @param string $defaultLabel Default label if custom not set
     * @return string Field label
     */
    public function getFieldLabel(int $fieldId, string $defaultLabel = ''): string {
        $labels = $this->getFieldLabels();
        return $labels[$fieldId] ?? $defaultLabel;
    }
    
    /**
     * Get lookup configuration for field
     *
     * @param int $fieldId Field ID
     * @return array<string, mixed>|null Lookup configuration or null
     */
    public function getLookupConfiguration(int $fieldId): ?array {
        $lookupFields = $this->getLookupFields();
        return $lookupFields[$fieldId] ?? null;
    }
    
    /**
     * Get conditional formatting rules for field
     *
     * @param int $fieldId Field ID
     * @return array<array<string, mixed>> Formatting rules
     */
    public function getConditionalFormattingRules(int $fieldId): array {
        $formatting = $this->getConditionalFormatting();
        return $formatting[$fieldId] ?? [];
    }
    
    /**
     * Get filter configuration for field
     *
     * @param int $fieldId Field ID
     * @return array<string, mixed>|null Filter configuration or null
     */
    public function getFilterConfiguration(int $fieldId): ?array {
        $configs = $this->getFilterConfigurations();
        return $configs[$fieldId] ?? null;
    }
    
    /**
     * Get raw configuration array
     *
     * @return array<string, mixed> Configuration array
     */
    public function toArray(): array {
        return $this->config;
    }
    
    /**
     * Create new configuration with updated values
     *
     * @param array<string, mixed> $updates Updates to apply
     * @return static New configuration instance
     */
    public function withUpdates(array $updates): static {
        return new static(array_merge($this->config, $updates));
    }
    
    /**
     * Validate configuration
     *
     * @return bool True if configuration is valid
     * @throws TC_Validation_Exception When validation fails
     */
    public function validate(): bool {
        $validator = new TC_Validation_Service();
        
        // Validate required fields
        if (empty($this->getTitle())) {
            throw new TC_Validation_Exception(__('Table title is required', 'tc-data-tables'));
        }
        
        if ($this->getFormId() <= 0) {
            throw new TC_Validation_Exception(__('Form ID is required', 'tc-data-tables'));
        }
        
        if (empty($this->getSelectedFields())) {
            throw new TC_Validation_Exception(__('At least one field must be selected', 'tc-data-tables'));
        }
        
        return true;
    }
}