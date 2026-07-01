<?php
/**
 * Entry Service for Gravity Tables
 *
 * Handles all entry-related operations
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
 * EntryService class
 *
 * Manages entry operations like CRUD, bulk actions, and data retrieval
 */
class TC_Entry_Service {
    
    /**
     * Validation service
     *
     * @var TC_Validation_Service
     */
    private TC_Validation_Service $validator;
    
    /**
     * Sanitization service
     *
     * @var TC_Sanitization_Service
     */
    private TC_Sanitization_Service $sanitizer;
    
    /**
     * Error handler
     *
     * @var TC_Error_Handler
     */
    private TC_Error_Handler $errorHandler;
    
    /**
     * Configuration service
     *
     * @var TC_Configuration_Service
     */
    private TC_Configuration_Service $config;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->validator = new TC_Validation_Service();
        $this->sanitizer = new TC_Sanitization_Service();
        $this->errorHandler = new TC_Error_Handler();
        $this->config = new TC_Configuration_Service();
    }
    
    /**
     * Get entries for a form with filtering and pagination
     *
     * @param int $form_id Form ID
     * @param array $params Query parameters
     * @return array Entry data with pagination info
     */
    public function getEntries(int $form_id, array $params): array {
        try {
            // Validate form ID
            if ($form_id <= 0) {
                throw new TC_Validation_Exception(__('Invalid form ID', 'tc-data-tables'));
            }
            
            // Set defaults
            $defaults = [
                'page' => 1,
                'per_page' => $this->config->getPerPage(),
                'search' => '',
                'user_filter' => '',
                'date_from' => '',
                'date_to' => '',
                'sort_field' => 'date_created',
                'sort_order' => 'desc',
                'columns' => [],
                'lookup_fields' => [],
                'filters' => []
            ];
            
            $params = array_merge($defaults, $params);
            
            // Validate and sanitize parameters
            $params['page'] = max(1, intval($params['page']));
            $params['per_page'] = max(1, min(100, intval($params['per_page'])));
            $params['search'] = $this->sanitizer->sanitizeText($params['search']);
            $params['user_filter'] = $this->sanitizer->sanitizeText($params['user_filter']);
            $params['date_from'] = $this->sanitizer->sanitizeText($params['date_from']);
            $params['date_to'] = $this->sanitizer->sanitizeText($params['date_to']);
            $params['sort_field'] = $this->sanitizer->sanitizeText($params['sort_field']);
            $params['sort_order'] = in_array($params['sort_order'], ['asc', 'desc']) ? $params['sort_order'] : 'desc';
            
            // Build and execute query
            return $this->executeEntriesQuery($form_id, $params);
            
        } catch (Exception $e) {
            $this->errorHandler->handleException($e);
            return [
                'entries' => [],
                'total' => 0,
                'page' => $params['page'] ?? 1,
                'per_page' => $params['per_page'] ?? 25,
                'total_pages' => 0,
                'columns' => $params['columns'] ?? []
            ];
        }
    }
    
    /**
     * Update a single entry
     *
     * @param int $entry_id Entry ID
     * @param array $updates Field updates
     * @param int $user_id Current user ID
     * @return array Update result
     * @throws TC_Permission_Exception
     * @throws TC_Validation_Exception
     */
    public function updateEntry(int $entry_id, array $updates, int $user_id): array {
        // Validate entry ID
        if ($entry_id <= 0) {
            throw new TC_Validation_Exception(__('Invalid entry ID', 'tc-data-tables'));
        }
        
        // Check permissions
        if (!$this->canUserEditEntry($entry_id, $user_id)) {
            throw new TC_Permission_Exception(
                __('Insufficient permissions to edit this entry', 'tc-data-tables'),
                'edit_posts',
                $user_id
            );
        }
        
        if (empty($updates)) {
            throw new TC_Validation_Exception(__('No updates provided', 'tc-data-tables'));
        }
        
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        
        $updated_fields = [];
        $success = true;
        
        foreach ($updates as $field_id => $value) {
            $sanitized_value = $this->sanitizer->sanitizeFieldValue($value, $field_id);
            
            $result = $wpdb->update(
                $wpdb->prefix . 'gf_entry_meta',
                ['meta_value' => $sanitized_value],
                [
                    'entry_id' => $entry_id,
                    'meta_key' => $field_id
                ],
                ['%s'],
                ['%d', '%s']
            );
            
            if ($result !== false) {
                $updated_fields[$field_id] = $sanitized_value;
            } else {
                $success = false;
                break;
            }
        }
        
        if ($success) {
            // Update entry timestamp
            $wpdb->update(
                $wpdb->prefix . 'gf_entry',
                ['date_updated' => current_time('mysql')],
                ['id' => $entry_id],
                ['%s'],
                ['%d']
            );
            
            return [
                'success' => true,
                'message' => __('Entry updated successfully', 'tc-data-tables'),
                'updated_fields' => $updated_fields
            ];
        } else {
            throw new TC_Database_Exception(__('Failed to update entry', 'tc-data-tables'));
        }
    }
    
    /**
     * Perform bulk action on entries
     *
     * @param string $action Bulk action type
     * @param array $entry_ids Entry IDs
     * @param int $user_id Current user ID
     * @param array $additional_data Additional data for specific actions
     * @return array Action result
     * @throws TC_Permission_Exception
     * @throws TC_Validation_Exception
     */
    public function bulkAction(string $action, array $entry_ids, int $user_id, array $additional_data = []): array {
        if (empty($action) || empty($entry_ids)) {
            throw new TC_Validation_Exception(__('Invalid bulk action data', 'tc-data-tables'));
        }
        
        // Validate entry IDs
        $entry_ids = array_map('intval', $entry_ids);
        $entry_ids = array_filter($entry_ids, function($id) { return $id > 0; });
        
        if (empty($entry_ids)) {
            throw new TC_Validation_Exception(__('No valid entry IDs provided', 'tc-data-tables'));
        }
        
        // Check permissions
        if (!$this->canUserBulkEditEntries($entry_ids, $user_id)) {
            throw new TC_Permission_Exception(
                __('Insufficient permissions to edit these entries', 'tc-data-tables'),
                'edit_posts',
                $user_id
            );
        }
        
        switch ($action) {
            case 'delete':
                return $this->bulkDeleteEntries($entry_ids);
                
            case 'export':
                return $this->bulkExportEntries($entry_ids, $additional_data['form_id'] ?? 1);
                
            case 'edit':
                $updates = $additional_data['bulk_updates'] ?? [];
                return $this->bulkEditEntries($entry_ids, $updates);
                
            default:
                throw new TC_Validation_Exception(__('Unknown bulk action', 'tc-data-tables'));
        }
    }
    
    /**
     * Create new entry
     *
     * @param int $form_id Form ID
     * @param array $field_data Field data
     * @param int $user_id Current user ID
     * @return int New entry ID
     * @throws TC_Validation_Exception
     */
    public function createEntry(int $form_id, array $field_data, int $user_id): int {
        if (!class_exists('GFAPI')) {
            throw new TC_Validation_Exception(__('Gravity Forms is not available', 'tc-data-tables'));
        }
        
        $form = GFAPI::get_form($form_id);
        if (!$form || is_wp_error($form)) {
            throw new TC_Validation_Exception(__('Form not found', 'tc-data-tables'));
        }
        
        // Build entry array
        $entry = [
            'form_id' => $form_id,
            'date_created' => current_time('mysql'),
            'is_starred' => 0,
            'is_read' => 0,
            // #1069 slice 32 — route $_SERVER reads through
            // gt_request_server_text() for slice 31 (#1073) parity. The
            // helper wraps each read in wp_unslash() + sanitize_text_field()
            // so wp_magic_quotes-added backslashes are stripped and
            // <script>-laden User-Agents don't reach the entry record
            // verbatim. submit_new_entry uses the same pattern; this is
            // the matching service-layer entry-build path.
            'ip' => gt_request_server_text('REMOTE_ADDR'),
            'source_url' => gt_request_server_text('HTTP_REFERER'),
            'user_agent' => gt_request_server_text('HTTP_USER_AGENT'),
            'currency' => 'USD',
            'payment_status' => null,
            'created_by' => $user_id,
            'status' => 'active'
        ];
        
        // Add field values
        foreach ($form['fields'] as $field) {
            $field_id = strval($field->id);
            if (isset($field_data[$field_id])) {
                $value = $field_data[$field_id];
                
                if (is_array($value)) {
                    $value = implode(',', array_map([$this->sanitizer, 'sanitizeText'], $value));
                } else {
                    $value = $this->sanitizer->sanitizeFieldValue($value, $field_id);
                }
                
                $entry[$field_id] = $value;
            }
        }
        
        // Create entry in Gravity Forms
        $result = GFAPI::add_entry($entry);
        
        if (is_wp_error($result)) {
            throw new TC_Validation_Exception(__('Error creating entry: ', 'tc-data-tables') . $result->get_error_message());
        } elseif ($result === false) {
            throw new TC_Database_Exception(__('Failed to create entry', 'tc-data-tables'));
        }
        
        return $result;
    }
    
    /**
     * Execute entries query
     *
     * @param int $form_id Form ID
     * @param array $params Query parameters
     * @return array Query results
     */
    private function executeEntriesQuery(int $form_id, array $params): array {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        
        $offset = ($params['page'] - 1) * $params['per_page'];
        
        // Build select fields
        $select_fields = ["e.id as entry_id"];
        
        if (in_array('date_created', $params['columns'])) {
            $select_fields[] = "e.date_created";
        }
        
        foreach ($params['columns'] as $field_id) {
            if (!in_array($field_id, ['date_created', 'entry_id'])) {
                $select_fields[] = "MAX(CASE WHEN em.meta_key = '{$field_id}' THEN em.meta_value END) as field_{$field_id}";
            }
        }
        
        $query = "
            SELECT " . implode(', ', $select_fields) . "
            FROM {$wpdb->prefix}gf_entry e
            LEFT JOIN {$wpdb->prefix}gf_entry_meta em ON e.id = em.entry_id
            WHERE e.form_id = %d AND e.status = 'active'
        ";
        
        $query_params = [$form_id];
        
        // Add filters
        $this->addSearchFilters($query, $query_params, $params);
        $this->addCustomFilters($query, $query_params, $params['filters']);
        
        $query .= " GROUP BY e.id";
        
        // Add sorting (date-aware)
        $this->addSorting($query, $params);
        
        // Get total count
        $total_count = $this->getEntriesCount($form_id, $params);
        
        // Add pagination
        $query .= " LIMIT %d OFFSET %d";
        $query_params[] = $params['per_page'];
        $query_params[] = $offset;
        
        // Execute query
        $results = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        if (!is_array($results)) {
            // @codeCoverageIgnoreStart
            $results = [];
            // @codeCoverageIgnoreEnd
        }
        
        // Process results
        $entries = $this->processEntryResults($results, $params);
        
        return [
            'entries' => $entries,
            'total' => $total_count,
            'page' => $params['page'],
            'per_page' => $params['per_page'],
            'total_pages' => ceil($total_count / $params['per_page']),
            'columns' => $params['columns']
        ];
    }
    
    /**
     * Check if user can edit entry
     *
     * @param int $entry_id Entry ID
     * @param int $user_id User ID
     * @return bool Can edit
     */
    private function canUserEditEntry(int $entry_id, int $user_id): bool {
        // Admins and editors can edit all entries
        if (current_user_can('edit_posts') || current_user_can('publish_posts')) {
            return true;
        }
        
        // Users with driver role can edit their own entries
        if (current_user_can('driver')) {
            // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
            global $wpdb;
            // @codeCoverageIgnoreEnd
            
            $user_field_value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}gf_entry_meta 
                 WHERE entry_id = %d 
                 AND meta_value = %s 
                 LIMIT 1",
                $entry_id,
                $user_id
            ));
            
            return !empty($user_field_value);
        }
        
        return false;
    }
    
    /**
     * Check if user can bulk edit entries
     *
     * @param array $entry_ids Entry IDs
     * @param int $user_id User ID
     * @return bool Can bulk edit
     */
    private function canUserBulkEditEntries(array $entry_ids, int $user_id): bool {
        foreach ($entry_ids as $entry_id) {
            if (!$this->canUserEditEntry($entry_id, $user_id)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Bulk delete entries
     *
     * @param array $entry_ids Entry IDs
     * @return array Result
     */
    private function bulkDeleteEntries(array $entry_ids): array {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        
        $deleted = 0;
        foreach ($entry_ids as $entry_id) {
            $result = $wpdb->update(
                $wpdb->prefix . 'gf_entry',
                ['status' => 'trash'],
                ['id' => $entry_id],
                ['%s'],
                ['%d']
            );
            
            if ($result !== false) {
                $deleted++;
            }
        }
        
        return [
            'success' => true,
            'message' => sprintf(__('%d entries deleted successfully', 'tc-data-tables'), $deleted),
            'deleted_count' => $deleted
        ];
    }
    
    /**
     * Bulk export entries
     *
     * @param array $entry_ids Entry IDs
     * @param int $form_id Form ID
     * @return array Result
     */
    private function bulkExportEntries(array $entry_ids, int $form_id): array {
        if (!class_exists('GFAPI')) {
            throw new TC_Validation_Exception(__('Gravity Forms is not available', 'tc-data-tables'));
        }
        
        // #1675 — fetch only the selected entries via an id/in field filter
        // (was: load the whole form's entries, then filter in PHP). Also pins
        // page_size so all selected entries are returned regardless of GF's
        // default paging.
        $entry_ids_int = array_map('intval', $entry_ids);
        $search_criteria = array(
            'field_filters' => array(
                array('key' => 'id', 'operator' => 'in', 'value' => $entry_ids_int),
            ),
        );
        $paging = array('offset' => 0, 'page_size' => max(1, count($entry_ids_int)));
        $entries = GFAPI::get_entries($form_id, $search_criteria, null, $paging);
        $form = GFAPI::get_form($form_id);
        
        $csv_data = [];
        $headers = ['Entry ID', 'Date Created'];
        
        // Build headers from form fields
        foreach ($form['fields'] as $field) {
            if (!in_array($field->type, ['html', 'section', 'page'])) {
                $headers[] = $field->label;
            }
        }
        
        $csv_data[] = $headers;
        
        // Build data rows ($entries is already scoped to the selected ids).
        foreach ($entries as $entry) {
            $row = [$entry['id'], $entry['date_created']];

            foreach ($form['fields'] as $field) {
                if (!in_array($field->type, ['html', 'section', 'page'])) {
                    $row[] = $entry[$field->id] ?? '';
                }
            }

            $csv_data[] = $row;
        }
        
        return [
            'success' => true,
            'message' => sprintf(__('%d entries exported', 'tc-data-tables'), count($entry_ids)),
            'csv_data' => $csv_data,
            'filename' => 'gravity_tables_export_' . date('Y-m-d_H-i-s') . '.csv'
        ];
    }
    
    /**
     * Bulk edit entries
     *
     * @param array $entry_ids Entry IDs
     * @param array $updates Field updates
     * @return array Result
     */
    private function bulkEditEntries(array $entry_ids, array $updates): array {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        
        $updated = 0;
        foreach ($entry_ids as $entry_id) {
            $entry_updated = false;
            
            foreach ($updates as $field_id => $value) {
                $sanitized_value = $this->sanitizer->sanitizeFieldValue($value, $field_id);
                
                $result = $wpdb->update(
                    $wpdb->prefix . 'gf_entry_meta',
                    ['meta_value' => $sanitized_value],
                    [
                        'entry_id' => $entry_id,
                        'meta_key' => $field_id
                    ],
                    ['%s'],
                    ['%d', '%s']
                );
                
                if ($result !== false) {
                    $entry_updated = true;
                }
            }
            
            if ($entry_updated) {
                $wpdb->update(
                    $wpdb->prefix . 'gf_entry',
                    ['date_updated' => current_time('mysql')],
                    ['id' => $entry_id],
                    ['%s'],
                    ['%d']
                );
                $updated++;
            }
        }
        
        return [
            'success' => true,
            'message' => sprintf(__('%d entries updated successfully', 'tc-data-tables'), $updated),
            'updated_count' => $updated
        ];
    }
    
    /**
     * Get total entries count
     *
     * @param int $form_id Form ID
     * @param array $params Query parameters
     * @return int Total count
     */
    private function getEntriesCount(int $form_id, array $params): int {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        
        $count_query = "
            SELECT COUNT(DISTINCT e.id) as total
            FROM {$wpdb->prefix}gf_entry e
            LEFT JOIN {$wpdb->prefix}gf_entry_meta em ON e.id = em.entry_id
            WHERE e.form_id = %d AND e.status = 'active'
        ";
        
        $count_params = [$form_id];
        
        $this->addSearchFilters($count_query, $count_params, $params);
        $this->addCustomFilters($count_query, $count_params, $params['filters']);
        
        return intval($wpdb->get_var($wpdb->prepare($count_query, $count_params)));
    }
    
    /**
     * Add search filters to query
     *
     * @param string &$query Query reference
     * @param array &$params Parameters reference
     * @param array $search_params Search parameters
     * @return void
     */
    private function addSearchFilters(string &$query, array &$params, array $search_params): void {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        
        if (!empty($search_params['search'])) {
            $query .= " AND (
                EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_search 
                    WHERE em_search.entry_id = e.id AND em_search.meta_value LIKE %s
                ) OR
                e.date_created LIKE %s OR
                CAST(e.id AS CHAR) LIKE %s
            )";
            $search_term = '%' . $wpdb->esc_like($search_params['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        if (!empty($search_params['user_filter'])) {
            $query .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em2 
                WHERE em2.entry_id = e.id AND em2.meta_value = %s
            )";
            $params[] = $search_params['user_filter'];
        }
        
        if (!empty($search_params['date_from'])) {
            $query .= " AND e.date_created >= %s";
            $params[] = $search_params['date_from'] . ' 00:00:00';
        }
        
        if (!empty($search_params['date_to'])) {
            $query .= " AND e.date_created <= %s";
            $params[] = $search_params['date_to'] . ' 23:59:59';
        }
    }
    
    /**
     * Add custom filters to query
     *
     * @param string &$query Query reference
     * @param array &$params Parameters reference
     * @param array $filters Custom filters
     * @return void
     */
    private function addCustomFilters(string &$query, array &$params, array $filters): void {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        
        foreach ($filters as $field_id => $filter_data) {
            if (!is_array($filter_data) || !isset($filter_data['type'])) {
                continue;
            }
            
            switch ($filter_data['type']) {
                case 'text':
                    if (!empty($filter_data['value'])) {
                        $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_text WHERE em_text.entry_id = e.id AND em_text.meta_key = %s AND em_text.meta_value LIKE %s)";
                        $params[] = $field_id;
                        $params[] = '%' . $wpdb->esc_like($filter_data['value']) . '%';
                    }
                    break;
                    
                case 'dropdown':
                case 'lookup':
                    if (!empty($filter_data['value'])) {
                        $query .= " AND EXISTS (SELECT 1 FROM {$wpdb->prefix}gf_entry_meta em_dropdown WHERE em_dropdown.entry_id = e.id AND em_dropdown.meta_key = %s AND em_dropdown.meta_value = %s)";
                        $params[] = $field_id;
                        $params[] = $filter_data['value'];
                    }
                    break;
            }
        }
    }
    
    /**
     * Add sorting to query
     *
     * @param string &$query Query reference
     * @param array $params Sort parameters
     * @return void
     */
    private function addSorting(string &$query, array $params): void {
        $sort_field = $params['sort_field'];
        $sort_order = $params['sort_order'] === 'asc' ? 'ASC' : 'DESC';
        
        if ($sort_field === 'entry_id') {
            $query .= " ORDER BY e.id {$sort_order}";
            return;
        }
        
        if ($sort_field === 'date_created') {
            $query .= " ORDER BY e.date_created {$sort_order}";
            return;
        }
        
        // Only apply when column exists in select
        if (!in_array($sort_field, $params['columns'])) {
            $query .= " ORDER BY e.date_created {$sort_order}";
            return;
        }
        
        // Attempt date-aware sort for Gravity Forms date fields
        $dateService = new TC_Date_Service();
        $fieldFormat = $dateService->getFieldDateFormat(intval($params['form_id'] ?? 0), strval($sort_field));
        
        if (!empty($fieldFormat['mysql_format'])) {
            $mysql_format = $fieldFormat['mysql_format'];
            $field_alias = "field_{$sort_field}";
            $query .= " ORDER BY CASE WHEN {$field_alias} = '' OR {$field_alias} IS NULL THEN "
                . ($sort_order === 'ASC' ? "'1900-01-01'" : "'2099-12-31'")
                . " WHEN STR_TO_DATE({$field_alias}, '{$mysql_format}') IS NULL THEN "
                . ($sort_order === 'ASC' ? "'1900-01-01'" : "'2099-12-31'")
                . " ELSE STR_TO_DATE({$field_alias}, '{$mysql_format}') END {$sort_order}";
            return;
        }
        
        // Fallback to string sort
        // @codeCoverageIgnoreStart
        $query .= " ORDER BY field_{$sort_field} {$sort_order}";
        // @codeCoverageIgnoreEnd
    }
    
    /**
     * Process entry results
     *
     * @param array $results Raw results
     * @param array $params Query parameters
     * @return array Processed entries
     */
    private function processEntryResults(array $results, array $params): array {
        $lookup_processor = TC_Lookup::get_instance();
        $entries = [];

        // #1664 — pre-resolve each lookup column's values in one batch before
        // the row loop, instead of one process_lookup_value() per row.
        $lookup_maps = [];
        if (!empty($params['lookup_fields'])) {
            foreach ($params['lookup_fields'] as $lf_field_id => $lf_config) {
                if (!in_array($lf_field_id, $params['columns'])) {
                    continue;
                }
                $lf_field_name = 'field_' . str_replace('.', '_', $lf_field_id);
                $lf_values = [];
                foreach ($results as $row) {
                    $lf_v = $row->$lf_field_name ?? '';
                    if ($lf_v !== '' && $lf_v !== null) {
                        $lf_values[] = $lf_v;
                    }
                }
                if (!empty($lf_values)) {
                    $lookup_maps[$lf_field_id] = $lookup_processor->process_lookup_values_batch(
                        array_values(array_unique($lf_values)),
                        $lf_config
                    );
                }
            }
        }

        foreach ($results as $row) {
            $entry = ['entry_id' => $row->entry_id];
            
            if (in_array('entry_id', $params['columns'])) {
                $entry['entry_id'] = $row->entry_id;
            }
            
            if (in_array('date_created', $params['columns'])) {
                $entry['date_created'] = $this->formatDateDisplay($row->date_created ?? '');
            }
            
            foreach ($params['columns'] as $field_id) {
                if (in_array($field_id, ['date_created', 'entry_id'])) {
                    continue;
                }
                
                // Handle field IDs with dots (convert to underscores for SQL aliases)
                $field_key = str_replace('.', '_', $field_id);
                $field_name = "field_{$field_key}";
                $value = $row->$field_name ?? '';
                
                // Process lookup if configured
                if (!empty($params['lookup_fields'][$field_id])) {
                    // #1664 — use the pre-batched map; per-value fallback only
                    // for values not present in the batch result.
                    if (isset($lookup_maps[$field_id]) && array_key_exists($value, $lookup_maps[$field_id])) {
                        $value = $lookup_maps[$field_id][$value];
                    } else {
                        $value = $lookup_processor->process_lookup_value($value, $params['lookup_fields'][$field_id]);
                    }
                }
                
                $entry[$field_id] = $value;
            }
            
            $entries[] = $entry;
        }
        
        return $entries;
    }
    
    /**
     * Format date for display
     *
     * @param string $date_value Date value
     * @return string Formatted date
     */
    private function formatDateDisplay(string $date_value): string {
        if (empty($date_value)) {
            return '';
        }
        
        $date_format = $this->config->getDateFormat();
        $datetime_format = $this->config->getDateTimeFormat();
        
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date_value);
        if ($date) {
            if ($date->format('H:i:s') !== '00:00:00') {
                return $date->format($datetime_format);
            } else {
                return $date->format($date_format);
            }
        }
        
        return $date_value;
    }
}