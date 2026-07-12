<?php
/**
 * Validation Service for Gravity Tables
 *
 * Centralized validation logic for all plugin operations
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
 * ValidationService class
 *
 * Handles all validation logic for the plugin
 */
class TC_Validation_Service {
    
    /**
     * Validate table configuration data
     *
     * @param array<string, mixed> $data The table configuration data
     * @return array<string, mixed> Validated and sanitized data
     * @throws TC_Validation_Exception When validation fails
     */
    public function validateTableData(array $data): array {
        $errors = [];
        
        // Validate required fields
        if (empty($data['title'])) {
            $errors[] = __('Table title is required', 'tc-data-tables');
        }
        
        if (empty($data['form_id'])) {
            $errors[] = __('Gravity Form selection is required', 'tc-data-tables');
        } elseif (!$this->isValidFormId($data['form_id'])) {
            $errors[] = __('Selected Gravity Form does not exist', 'tc-data-tables');
        }
        
        if (empty($data['selected_fields']) || !is_array($data['selected_fields'])) {
            $errors[] = __('At least one field must be selected', 'tc-data-tables');
        }
        
        // Validate settings
        if (isset($data['settings'])) {
            $settingsErrors = $this->validateSettings($data['settings']);
            $errors = array_merge($errors, $settingsErrors);
        }
        
        if (!empty($errors)) {
            throw new TC_Validation_Exception(
                __('Validation failed', 'tc-data-tables'),
                $errors
            );
        }
        
        return $this->sanitizeTableData($data);
    }
    
    /**
     * Validate table settings
     *
     * @param array<string, mixed> $settings Settings array
     * @return string[] Array of error messages
     */
    private function validateSettings(array $settings): array {
        $errors = [];
        
        // Validate per_page setting
        if (isset($settings['per_page'])) {
            $perPage = (int) $settings['per_page'];
            if ($perPage < 1 || $perPage > 100) {
                $errors[] = __('Items per page must be between 1 and 100', 'tc-data-tables');
            }
        }
        
        // Validate boolean settings
        $booleanSettings = [
            'show_search',
            'show_pagination', 
            'show_selection',
            'show_bulk_actions',
            'enable_frontend_editing',
            'sticky_header',
            'freeze_first_column',
            'responsive_table'
        ];
        
        foreach ($booleanSettings as $setting) {
            if (isset($settings[$setting]) && !is_bool($settings[$setting])) {
                $errors[] = sprintf(
                    __('%s must be a boolean value', 'tc-data-tables'),
                    $setting
                );
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate AJAX request data
     *
     * @param array<string, mixed> $data Request data
     * @param string[] $requiredFields Required field names
     * @return array<string, mixed> Validated data
     * @throws TC_Validation_Exception When validation fails
     */
    public function validateAjaxRequest(array $data, array $requiredFields = []): array {
        $errors = [];
        
        // Check required fields
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = sprintf(
                    __('Required field "%s" is missing', 'tc-data-tables'),
                    $field
                );
            }
        }
        
        // Validate nonce if present
        if (isset($data['nonce']) && !wp_verify_nonce($data['nonce'], 'gravity_tables_nonce')) {
            $errors[] = __('Security check failed', 'tc-data-tables');
        }
        
        if (!empty($errors)) {
            throw new TC_Validation_Exception(
                __('Invalid request', 'tc-data-tables'),
                $errors
            );
        }
        
        return $data;
    }
    
    /**
     * Validate field ID
     *
     * @param mixed $fieldId Field ID to validate
     * @return int Valid field ID
     * @throws TC_Validation_Exception When field ID is invalid
     */
    public function validateFieldId($fieldId): int {
        if (!is_numeric($fieldId) || (int) $fieldId <= 0) {
            throw new TC_Validation_Exception(
                __('Invalid field ID', 'tc-data-tables')
            );
        }
        
        return (int) $fieldId;
    }
    
    /**
     * Validate form ID exists
     *
     * @param mixed $formId Form ID to validate
     * @return bool True if valid
     */
    private function isValidFormId($formId): bool {
        if (!class_exists('GFAPI')) {
            return false;
        }
        
        $form = GFAPI::get_form($formId);
        return $form && !is_wp_error($form);
    }
    
    /**
     * Sanitize table data
     *
     * @param array<string, mixed> $data Raw data
     * @return array<string, mixed> Sanitized data
     */
    private function sanitizeTableData(array $data): array {
        $sanitizer = new TC_Sanitization_Service();
        
        return [
            'title' => $sanitizer->sanitizeText($data['title']),
            'form_id' => $sanitizer->sanitizeInteger($data['form_id']),
            'selected_fields' => $sanitizer->sanitizeFieldIds($data['selected_fields']),
            'settings' => $sanitizer->sanitizeSettings($data['settings'] ?? []),
            'field_labels' => $sanitizer->sanitizeFieldLabels($data['field_labels'] ?? []),
            'lookup_fields' => $sanitizer->sanitizeLookupFields($data['lookup_fields'] ?? []),
            'conditional_formatting' => $sanitizer->sanitizeConditionalFormatting($data['conditional_formatting'] ?? [])
        ];
    }
    
    /**
     * Validate pagination parameters
     *
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array{page: int, per_page: int} Validated parameters
     */
    public function validatePaginationParams(int $page, int $perPage): array {
        return [
            'page' => max(1, $page),
            'per_page' => min(max(1, $perPage), 100)
        ];
    }
    
    /**
     * Validate search term
     *
     * @param string $searchTerm Search term to validate
     * @return string Sanitized search term
     */
    public function validateSearchTerm(string $searchTerm): string {
        // Remove potentially dangerous characters but preserve useful search characters
        $cleaned = preg_replace('/[<>"\']/', '', $searchTerm);
        return trim($cleaned ?? '');
    }
    
    /**
     * Validate sort parameters
     *
     * @param string $sortField Field to sort by
     * @param string $sortOrder Sort order (asc/desc)
     * @param string[] $allowedFields Allowed sort fields
     * @return array{field: string, order: string} Validated sort parameters
     */
    public function validateSortParams(string $sortField, string $sortOrder, array $allowedFields): array {
        $field = in_array($sortField, $allowedFields) ? $sortField : 'date_created';
        $order = strtolower($sortOrder) === 'desc' ? 'desc' : 'asc';

        return [
            'field' => $field,
            'order' => $order
        ];
    }

    // ── Per-column inline-edit validation (#1742) ─────────────────────────

    /**
     * Validate a single cell value against a per-column rule set.
     *
     * Supported keys in $rules:
     *   required      (bool)   — must not be empty/whitespace
     *   min_length    (int)    — minimum character count; 0 = skip
     *   max_length    (int)    — maximum character count; 0 = skip
     *   regex         (string) — PCRE pattern without delimiters; invalid patterns silently skipped
     *   regex_message (string) — user-facing message on regex failure
     *   min_value     (float)  — minimum numeric value; skipped for non-numeric strings
     *   max_value     (float)  — maximum numeric value; skipped for non-numeric strings
     *
     * @param string $value Raw cell value.
     * @param array  $rules Sanitized rule set for this column.
     * @return array{valid: bool, message: string}
     * @since 6.3.8
     */
    public static function validate( string $value, array $rules ): array {
        if ( empty( $rules ) ) {
            return [ 'valid' => true, 'message' => '' ];
        }

        if ( ! empty( $rules['required'] ) && trim( $value ) === '' ) {
            return [ 'valid' => false, 'message' => __( 'This field is required.', 'tc-data-tables' ) ];
        }

        if ( isset( $rules['min_length'] ) && (int) $rules['min_length'] > 0 && mb_strlen( $value ) < (int) $rules['min_length'] ) {
            return [
                'valid'   => false,
                'message' => sprintf( __( 'Minimum %d characters required.', 'tc-data-tables' ), (int) $rules['min_length'] ),
            ];
        }

        if ( isset( $rules['max_length'] ) && (int) $rules['max_length'] > 0 && mb_strlen( $value ) > (int) $rules['max_length'] ) {
            return [
                'valid'   => false,
                'message' => sprintf( __( 'Maximum %d characters allowed.', 'tc-data-tables' ), (int) $rules['max_length'] ),
            ];
        }

        if ( ! empty( $rules['regex'] ) ) {
            $pattern = '/' . str_replace( '/', '\/', (string) $rules['regex'] ) . '/u';
            $match   = @preg_match( $pattern, $value );
            if ( $match !== false && ! $match ) {
                $msg = ! empty( $rules['regex_message'] ) ? (string) $rules['regex_message'] : __( 'Invalid format.', 'tc-data-tables' );
                return [ 'valid' => false, 'message' => $msg ];
            }
        }

        if ( isset( $rules['min_value'] ) && $rules['min_value'] !== null && $rules['min_value'] !== '' && is_numeric( $value ) ) {
            if ( (float) $value < (float) $rules['min_value'] ) {
                return [
                    'valid'   => false,
                    'message' => sprintf( __( 'Minimum value is %s.', 'tc-data-tables' ), $rules['min_value'] ),
                ];
            }
        }

        if ( isset( $rules['max_value'] ) && $rules['max_value'] !== null && $rules['max_value'] !== '' && is_numeric( $value ) ) {
            if ( (float) $value > (float) $rules['max_value'] ) {
                return [
                    'valid'   => false,
                    'message' => sprintf( __( 'Maximum value is %s.', 'tc-data-tables' ), $rules['max_value'] ),
                ];
            }
        }

        return [ 'valid' => true, 'message' => '' ];
    }

    // ── Per-column cell-value validation (#2282) ──────────────────────────

    /**
     * Validate a single cell value against a per-column rule set.
     *
     * Supported rules (in addition to the existing rules handled by validate()):
     *   oneOf       (string[]) — value must be in this list (strict comparison)
     *   notOneOf    (string[]) — value must NOT be in this list (strict comparison)
     *   phone       (bool|'permissive') — E.164 when true; permissive digit-strip when 'permissive'
     *   unique      (bool) — value must not appear in any other entry for this field/form
     *
     * After all built-in rules pass, the filter hook fires:
     *
     *   apply_filters( 'tablecrafter_validate_cell', true, $value, $col_config, $entry_id, $table_id )
     *
     *   Hook contract: return a WP_Error to veto the save; return the unmodified
     *   first argument (true) to pass. The hook fires ONLY when all built-in rules
     *   have already passed; a built-in rejection short-circuits before the hook.
     *
     * TOCTOU note (unique): There is no DB-level unique constraint. Two concurrent
     * saves could both pass this check and both commit. This race is acceptable
     * for editorial use (not transactional/inventory). Callers needing stricter
     * guarantees should add their own DB-level constraints or use a distributed lock.
     *
     * @param string $field_id   Gravity Forms field ID.
     * @param string $value      Raw cell value (string; arrays handled by callers).
     * @param array  $col_config Per-column config (may contain 'choices' etc.).
     * @param array  $rules      Sanitized rule set for this column.
     * @param int    $entry_id   Current entry being edited (excluded from unique scan).
     * @param int    $form_id    Gravity Forms form ID (for GFAPI unique query). 0 = skip.
     * @param int    $table_id   TableCrafter table ID (passed to filter hook). 0 = unknown.
     * @return true|\WP_Error true on pass; WP_Error on reject.
     * @since 8.0.42
     */
    public static function validate_cell_value(
        string $field_id,
        string $value,
        array $col_config,
        array $rules,
        int $entry_id,
        int $form_id = 0,
        int $table_id = 0
    ): bool|\WP_Error {

        // ── oneOf ──────────────────────────────────────────────────────────
        if ( isset( $rules['oneOf'] ) && is_array( $rules['oneOf'] ) && ! empty( $rules['oneOf'] ) ) {
            if ( ! in_array( $value, $rules['oneOf'], true ) ) {
                return new \WP_Error(
                    'validation_failed',
                    __( 'Value is not one of the allowed options.', 'tc-data-tables' )
                );
            }
        }

        // ── notOneOf ───────────────────────────────────────────────────────
        if ( isset( $rules['notOneOf'] ) && is_array( $rules['notOneOf'] ) && ! empty( $rules['notOneOf'] ) ) {
            if ( in_array( $value, $rules['notOneOf'], true ) ) {
                return new \WP_Error(
                    'validation_failed',
                    __( 'This value is not allowed.', 'tc-data-tables' )
                );
            }
        }

        // ── phone ──────────────────────────────────────────────────────────
        if ( ! empty( $rules['phone'] ) ) {
            if ( $rules['phone'] === 'permissive' ) {
                // Permissive mode: strip formatting chars then require 7–15 digits.
                $stripped = preg_replace( '/[\s().+\-]/', '', $value );
                if ( ! preg_match( '/^\d{7,15}$/', $stripped ) ) {
                    return new \WP_Error(
                        'invalid_phone',
                        __( 'Please enter a valid phone number.', 'tc-data-tables' )
                    );
                }
            } else {
                // E.164 mode: optional + then [1-9] followed by 1–14 more digits.
                if ( ! preg_match( '/^\+?[1-9]\d{1,14}$/', $value ) ) {
                    return new \WP_Error(
                        'invalid_phone',
                        __( 'Please enter a valid phone number (E.164 format).', 'tc-data-tables' )
                    );
                }
            }
        }

        // ── unique ─────────────────────────────────────────────────────────
        if ( ! empty( $rules['unique'] ) && $form_id > 0 ) {
            if ( class_exists( 'GFAPI' ) ) {
                $search_criteria = [
                    'field_filters' => [
                        [ 'key' => $field_id, 'value' => $value, 'operator' => 'is' ],
                    ],
                ];
                $entries = GFAPI::get_entries( $form_id, $search_criteria );
                if ( is_array( $entries ) ) {
                    foreach ( $entries as $e ) {
                        if ( (int) ( $e['id'] ?? 0 ) !== $entry_id ) {
                            return new \WP_Error(
                                'not_unique',
                                __( 'This value must be unique.', 'tc-data-tables' )
                            );
                        }
                    }
                }
            }
            // When GFAPI is unavailable (e.g. test environment), skip the check.
            // The server is still the authoritative guard; the client-side also checks.
        }

        // ── developer filter hook ──────────────────────────────────────────
        // Fires AFTER all built-in rules pass. Callbacks return WP_Error to veto
        // or return the unmodified $result (true) to pass.
        // Signature: ( bool|WP_Error $result, string $value, array $col_config, int $entry_id, int $table_id )
        $filter_result = apply_filters( 'tablecrafter_validate_cell', true, $value, $col_config, $entry_id, $table_id );
        if ( is_wp_error( $filter_result ) ) {
            return $filter_result;
        }

        return true;
    }

    /**
     * Sanitize a raw column_validations map from POST data or stored settings.
     *
     * @param mixed $raw Untrusted input (expected: field_id => rule array).
     * @return array field_id (string) => sanitized rule array
     * @since 6.3.8
     */
    public static function sanitize_rules( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $field_id => $rules ) {
            if ( ! is_array( $rules ) ) {
                continue;
            }
            $r = [];
            if ( isset( $rules['required'] ) ) {
                $r['required'] = (bool) $rules['required'];
            }
            if ( isset( $rules['min_length'] ) && is_numeric( $rules['min_length'] ) ) {
                $r['min_length'] = max( 0, (int) $rules['min_length'] );
            }
            if ( isset( $rules['max_length'] ) && is_numeric( $rules['max_length'] ) ) {
                $r['max_length'] = max( 0, (int) $rules['max_length'] );
            }
            if ( isset( $rules['regex'] ) ) {
                $r['regex'] = (string) $rules['regex'];
            }
            if ( isset( $rules['regex_message'] ) ) {
                $r['regex_message'] = sanitize_text_field( (string) $rules['regex_message'] );
            }
            if ( isset( $rules['min_value'] ) && is_numeric( $rules['min_value'] ) ) {
                $r['min_value'] = (float) $rules['min_value'];
            }
            if ( isset( $rules['max_value'] ) && is_numeric( $rules['max_value'] ) ) {
                $r['max_value'] = (float) $rules['max_value'];
            }
            // #2282 — unique (bool)
            if ( isset( $rules['unique'] ) ) {
                $r['unique'] = (bool) $rules['unique'];
            }
            // #2282 — oneOf (string[])
            if ( isset( $rules['oneOf'] ) && is_array( $rules['oneOf'] ) && ! empty( $rules['oneOf'] ) ) {
                $clean = [];
                foreach ( $rules['oneOf'] as $v ) {
                    $clean[] = sanitize_text_field( (string) $v );
                }
                if ( ! empty( $clean ) ) {
                    $r['oneOf'] = $clean;
                }
            }
            // #2282 — notOneOf (string[])
            if ( isset( $rules['notOneOf'] ) && is_array( $rules['notOneOf'] ) && ! empty( $rules['notOneOf'] ) ) {
                $clean = [];
                foreach ( $rules['notOneOf'] as $v ) {
                    $clean[] = sanitize_text_field( (string) $v );
                }
                if ( ! empty( $clean ) ) {
                    $r['notOneOf'] = $clean;
                }
            }
            // #2282 — phone (bool|'permissive')
            if ( isset( $rules['phone'] ) ) {
                $r['phone'] = ( $rules['phone'] === 'permissive' ) ? 'permissive' : (bool) $rules['phone'];
            }
            if ( ! empty( $r ) ) {
                $out[ (string) $field_id ] = $r;
            }
        }
        return $out;
    }
}