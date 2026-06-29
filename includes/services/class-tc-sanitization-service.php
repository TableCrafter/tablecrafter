<?php
/**
 * Sanitization Service for Gravity Tables
 *
 * Centralized sanitization logic for all plugin operations
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
 * SanitizationService class
 *
 * Handles all sanitization logic for the plugin
 */
class TC_Sanitization_Service {
    
    /**
     * Sanitize text field
     *
     * @param mixed $value Value to sanitize
     * @return string Sanitized text
     */
    public function sanitizeText($value): string {
        return sanitize_text_field((string) $value);
    }
    
    /**
     * Sanitize textarea content
     *
     * @param mixed $value Value to sanitize
     * @return string Sanitized textarea content
     */
    public function sanitizeTextarea($value): string {
        return sanitize_textarea_field((string) $value);
    }
    
    /**
     * Sanitize integer value
     *
     * @param mixed $value Value to sanitize
     * @return int Sanitized integer
     */
    public function sanitizeInteger($value): int {
        return (int) $value;
    }
    
    /**
     * Sanitize boolean value
     *
     * @param mixed $value Value to sanitize
     * @return bool Sanitized boolean
     */
    public function sanitizeBoolean($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        
        return (bool) $value;
    }
    
    /**
     * Sanitize email address
     *
     * @param mixed $value Value to sanitize
     * @return string Sanitized email
     */
    public function sanitizeEmail($value): string {
        return sanitize_email((string) $value);
    }
    
    /**
     * Sanitize URL
     *
     * @param mixed $value Value to sanitize
     * @return string Sanitized URL
     */
    public function sanitizeUrl($value): string {
        return esc_url_raw((string) $value);
    }
    
    /**
     * Sanitize date value
     *
     * @param mixed $value Date value to sanitize
     * @return string Sanitized date in Y-m-d format
     */
    public function sanitizeDate($value): string {
        $date = DateTime::createFromFormat('Y-m-d', (string) $value);
        if ($date === false) {
            // Try alternative formats
            $formats = ['m/d/Y', 'd/m/Y', 'Y-m-d H:i:s', 'm/d/Y H:i:s'];
            foreach ($formats as $format) {
                $date = DateTime::createFromFormat($format, (string) $value);
                if ($date !== false) {
                    break;
                }
            }
        }
        
        return $date ? $date->format('Y-m-d') : '';
    }
    
    /**
     * Sanitize field value based on field type
     *
     * @param mixed $value Value to sanitize
     * @param string $fieldType Field type
     * @return mixed Sanitized value
     */
    public function sanitizeFieldValue($value, string $fieldType) {
        return match($fieldType) {
            'email' => $this->sanitizeEmail($value),
            'website', 'url' => $this->sanitizeUrl($value),
            'date' => $this->sanitizeDate($value),
            'number', 'phone' => $this->sanitizeInteger($value),
            'textarea' => $this->sanitizeTextarea($value),
            default => $this->sanitizeText($value)
        };
    }
    
    /**
     * Sanitize array of field IDs
     *
     * @param mixed $fieldIds Field IDs to sanitize
     * @return int[] Sanitized field IDs
     */
    public function sanitizeFieldIds($fieldIds): array {
        if (!is_array($fieldIds)) {
            return [];
        }
        
        return array_map('intval', array_filter($fieldIds, 'is_numeric'));
    }
    
    /**
     * Sanitize table settings
     *
     * @param array<string, mixed> $settings Settings to sanitize
     * @return array<string, mixed> Sanitized settings
     */
    public function sanitizeSettings(array $settings): array {
        $sanitized = [];
        
        // Boolean settings
        $booleanSettings = [
            'show_search',
            'show_pagination',
            'show_selection', 
            'show_bulk_actions',
            'show_advanced_filters',
            'show_entry_info',
            'enable_frontend_editing',
            'sticky_header',
            'freeze_first_column',
            'responsive_table',
            'persistent_filters'
        ];
        
        foreach ($booleanSettings as $setting) {
            if (isset($settings[$setting])) {
                $sanitized[$setting] = $this->sanitizeBoolean($settings[$setting]);
            }
        }
        
        // Integer settings
        if (isset($settings['per_page'])) {
            $sanitized['per_page'] = max(1, min(100, $this->sanitizeInteger($settings['per_page'])));
        }
        
        // String settings (backward compatibility)
        if (isset($settings['user_role_filter'])) {
            $sanitized['user_role_filter'] = $this->sanitizeText($settings['user_role_filter']);
        }
        
        // User role access control
        if (isset($settings['allowed_user_roles']) && is_array($settings['allowed_user_roles'])) {
            $validRoles = array_keys(wp_roles()->get_names());
            $sanitized['allowed_user_roles'] = array_values(array_intersect($settings['allowed_user_roles'], $validRoles));
        }
        
        // Array settings
        if (isset($settings['bulk_actions']) && is_array($settings['bulk_actions'])) {
            $allowedActions = ['delete', 'export', 'edit'];
            $sanitized['bulk_actions'] = array_intersect($settings['bulk_actions'], $allowedActions);
        }

        // #984 v4.167.0 — JSON data source storage layer (slice 3b-1 of #512).
        // Defines the contract for the new data_source='json' table type.
        // Admin UI (slice 3b-2) + frontend render (3b-3) consume these keys.
        if (isset($settings['data_source'])) {
            $allowedSources = ['gravity_forms', 'json'];
            $sanitized['data_source'] = in_array($settings['data_source'], $allowedSources, true)
                ? $settings['data_source']
                : 'gravity_forms'; // default fallback
        }
        if (isset($settings['json_url'])) {
            $candidateUrl = esc_url_raw((string) $settings['json_url']);
            // Defence-in-depth: drop to empty string if the URL fails the SSRF guard.
            // Admin UI will surface the rejection in the preview step; this is the
            // server-side belt-and-braces in case the UI is bypassed.
            if ($candidateUrl !== '' && class_exists('TC_JSON_Source_Service')
                && !TC_JSON_Source_Service::is_safe_url($candidateUrl)) {
                $candidateUrl = '';
            }
            $sanitized['json_url'] = $candidateUrl;
        }
        if (isset($settings['json_headers']) && is_array($settings['json_headers'])) {
            $headers = [];
            foreach ($settings['json_headers'] as $rawKey => $rawValue) {
                $key = sanitize_text_field((string) $rawKey);
                $value = sanitize_text_field((string) $rawValue);
                if ($key === '') {
                    continue;
                }
                $headers[$key] = $value;
            }
            $sanitized['json_headers'] = $headers;
        }
        if (isset($settings['json_dot_path'])) {
            $sanitized['json_dot_path'] = sanitize_text_field((string) $settings['json_dot_path']);
        }
        if (isset($settings['json_refresh_minutes'])) {
            // Clamp to [5, 1440] minutes. 5-min floor prevents accidental DDoS
            // of the remote endpoint; 1440-min ceiling (24h) keeps data fresh.
            $sanitized['json_refresh_minutes'] = max(5, min(1440, (int) $settings['json_refresh_minutes']));
        }

        // #992 v4.172.0 — Airtable connection wizard (phase B of #517).
        // PAT is encrypted-at-rest via TC_Airtable_Credential_Service::encrypt.
        // Empty submitted PAT means "keep existing" so edits don't accidentally
        // wipe credentials when the user just changes the table name.
        if (isset($settings['airtable_pat'])) {
            $pat = (string) $settings['airtable_pat'];
            if ($pat !== '') {
                if (class_exists('TC_Airtable_Credential_Service')) {
                    $sanitized['airtable_pat'] = TC_Airtable_Credential_Service::encrypt($pat);
                    $sanitized['airtable_pat_set'] = true; // sentinel so the view can show the masked placeholder
                }
            }
            // Empty submission: do NOT clobber. Caller is responsible for merging
            // sanitized into the existing settings dict.
        }
        if (isset($settings['airtable_base_id'])) {
            $sanitized['airtable_base_id'] = sanitize_text_field((string) $settings['airtable_base_id']);
        }
        if (isset($settings['airtable_table_id'])) {
            $sanitized['airtable_table_id'] = sanitize_text_field((string) $settings['airtable_table_id']);
        }
        if (isset($settings['airtable_view'])) {
            $sanitized['airtable_view'] = sanitize_text_field((string) $settings['airtable_view']);
        }

        // #998 v4.175.0 — Notion data source storage (phase 1 of #592).
        // Reuses the same encrypt/decrypt utility as Airtable for the token —
        // TC_Airtable_Credential_Service is generic; a future refactor will
        // extract it to a TC_Credential_Service shared base.
        if (isset($settings['notion_token'])) {
            $tok = (string) $settings['notion_token'];
            if ($tok !== '') {
                if (class_exists('TC_Airtable_Credential_Service')) {
                    $sanitized['notion_token'] = TC_Airtable_Credential_Service::encrypt($tok);
                    $sanitized['notion_token_set'] = true;
                }
            }
            // Empty submission: do NOT clobber. Caller merges with existing.
        }
        if (isset($settings['notion_database_id'])) {
            $sanitized['notion_database_id'] = sanitize_text_field((string) $settings['notion_database_id']);
        }

        // #1010 v4.181.0 — sync_direction contract (phase 1 of #613 two-way sync).
        // Accepts BOTH the new canonical names (pull / push / two_way) AND the
        // legacy Airtable-specific names (pull_only / push_only / bidirectional)
        // so the existing Airtable pushback code in class-tc-ajax.php keeps
        // working until a future iter unifies the consumers.
        if (isset($settings['sync_direction'])) {
            $aliases = [
                'pull_only'     => 'pull',
                'push_only'     => 'push',
                'bidirectional' => 'two_way',
            ];
            $value = (string) $settings['sync_direction'];
            // Legacy values keep their raw form so existing consumers still work;
            // the alias map is only used for the test/contract assertion below.
            $canonical = $aliases[$value] ?? $value;
            $allowed_canonical = ['pull', 'push', 'two_way'];
            if (in_array($canonical, $allowed_canonical, true)) {
                $sanitized['sync_direction'] = $value; // preserve whichever name was submitted
            } else {
                $sanitized['sync_direction'] = 'pull';
            }
        }

        return $sanitized;
    }
    
    /**
     * Sanitize field labels
     *
     * @param array<string, string> $labels Field labels to sanitize
     * @return array<string, string> Sanitized labels
     */
    public function sanitizeFieldLabels(array $labels): array {
        $sanitized = [];
        
        foreach ($labels as $fieldId => $label) {
            $fieldId = $this->sanitizeInteger($fieldId);
            if ($fieldId > 0) {
                $sanitized[$fieldId] = $this->sanitizeText($label);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize lookup field configurations
     *
     * @param array<string, array<string, mixed>> $lookupFields Lookup fields to sanitize
     * @return array<string, array<string, mixed>> Sanitized lookup fields
     */
    public function sanitizeLookupFields(array $lookupFields): array {
        $sanitized = [];
        
        foreach ($lookupFields as $fieldId => $config) {
            $fieldId = $this->sanitizeInteger($fieldId);
            if ($fieldId > 0 && is_array($config)) {
                $sanitized[$fieldId] = $this->sanitizeLookupConfig($config);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize individual lookup configuration
     *
     * @param array<string, mixed> $config Lookup configuration
     * @return array<string, mixed> Sanitized configuration
     */
    private function sanitizeLookupConfig(array $config): array {
        $sanitized = [];
        
        $allowedTypes = ['user', 'post', 'custom'];
        if (isset($config['type']) && in_array($config['type'], $allowedTypes)) {
            $sanitized['type'] = $config['type'];
        }
        
        $stringFields = ['user_field', 'post_field', 'table', 'id_column', 'display_column'];
        foreach ($stringFields as $field) {
            if (isset($config[$field])) {
                $sanitized[$field] = $this->sanitizeText($config[$field]);
            }
        }
        
        if (isset($config['user_roles']) && is_array($config['user_roles'])) {
            $sanitized['user_roles'] = array_map([$this, 'sanitizeText'], $config['user_roles']);
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize conditional formatting rules
     *
     * @param array<string, array<array<string, mixed>>> $formatting Conditional formatting rules
     * @return array<string, array<array<string, mixed>>> Sanitized rules
     */
    public function sanitizeConditionalFormatting(array $formatting): array {
        $sanitized = [];
        
        foreach ($formatting as $fieldId => $rules) {
            $fieldId = $this->sanitizeInteger($fieldId);
            if ($fieldId > 0 && is_array($rules)) {
                $sanitized[$fieldId] = $this->sanitizeConditionalRules($rules);
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize conditional formatting rules for a field
     *
     * @param array<array<string, mixed>> $rules Rules to sanitize
     * @return array<array<string, mixed>> Sanitized rules
     */
    private function sanitizeConditionalRules(array $rules): array {
        $sanitized = [];
        $allowedOperators = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'greater_than', 'less_than', 'empty', 'not_empty'];
        $allowedActions = ['setCellColor', 'setRowColor', 'setTextColor', 'setCellContent', 'setCellClass', 'setRowClass'];
        
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            
            $sanitizedRule = [];
            
            if (isset($rule['ifClause']) && in_array($rule['ifClause'], $allowedOperators)) {
                $sanitizedRule['ifClause'] = $rule['ifClause'];
            }
            
            if (isset($rule['cellVal'])) {
                $sanitizedRule['cellVal'] = $this->sanitizeText($rule['cellVal']);
            }
            
            if (isset($rule['action']) && in_array($rule['action'], $allowedActions)) {
                $sanitizedRule['action'] = $rule['action'];
            }
            
            if (isset($rule['setVal'])) {
                $sanitizedRule['setVal'] = $this->sanitizeConditionalValue($rule['setVal'], $rule['action'] ?? '');
            }
            
            // Only include complete rules
            if (count($sanitizedRule) >= 3) {
                $sanitized[] = $sanitizedRule;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize conditional formatting value based on action
     *
     * @param mixed $value Value to sanitize
     * @param string $action Action type
     * @return string Sanitized value
     */
    private function sanitizeConditionalValue($value, string $action): string {
        return match($action) {
            'setCellColor', 'setRowColor', 'setTextColor' => $this->sanitizeColor($value),
            'setCellContent' => $this->sanitizeText($value),
            'setCellClass', 'setRowClass' => $this->sanitizeCssClass($value),
            default => $this->sanitizeText($value)
        };
    }
    
    /**
     * Sanitize color value (hex, rgb, rgba)
     *
     * @param mixed $value Color value
     * @return string Sanitized color
     */
    private function sanitizeColor($value): string {
        $color = (string) $value;
        
        // Validate hex colors
        if (preg_match('/^#[a-f0-9]{6}$/i', $color)) {
            return $color;
        }
        
        // Validate rgb/rgba colors
        if (preg_match('/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+)?\s*\)$/i', $color)) {
            return $color;
        }
        
        return '#000000'; // Default to black if invalid
    }
    
    /**
     * Sanitize CSS class name
     *
     * @param mixed $value CSS class name
     * @return string Sanitized class name
     */
    private function sanitizeCssClass($value): string {
        $class = (string) $value;
        // Only allow valid CSS class characters
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $class) ?? '';
    }
    
    /**
     * Sanitize HTML content (for rich text fields)
     *
     * @param mixed $value HTML content
     * @param string[] $allowedTags Allowed HTML tags
     * @return string Sanitized HTML
     */
    public function sanitizeHtml($value, array $allowedTags = []): string {
        if (empty($allowedTags)) {
            $allowedTags = [
                'strong', 'em', 'u', 'b', 'i', 's', 'del', 'ins',
                'br', 'p', 'a', 'span',
                'ul', 'ol', 'li',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'blockquote', 'pre', 'code',
                'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
            ];
        }

        $allowed = '<' . implode('><', $allowedTags) . '>';
        return strip_tags((string) $value, $allowed);
    }

    /**
     * Sanitize HTML content stored in a table cell.
     *
     * Uses wp_kses() with an explicit allowlist that preserves <ul> and <ol>
     * as distinct tags — they are NEVER converted to each other. This is the
     * canonical sanitizer for cell content and must be used on every save path
     * to prevent list-type mutations across plugin updates.
     *
     * @param mixed $value Raw cell HTML content.
     * @return string Sanitized HTML safe for database storage and frontend output.
     */
    public function sanitize_cell_html($value): string {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        if ($value === '') {
            return '';
        }

        $allowed_html = [
            'a'          => ['href' => true, 'rel' => true, 'target' => true, 'title' => true],
            'br'         => [],
            'strong'     => [],
            'b'          => [],
            'em'         => [],
            'i'          => [],
            'u'          => [],
            's'          => [],
            'del'        => [],
            'ins'        => [],
            'p'          => ['class' => true, 'style' => true],
            'span'       => ['class' => true, 'style' => true],
            // Both <ul> and <ol> are listed separately so wp_kses() preserves
            // the exact list type — unordered lists NEVER become ordered lists.
            'ul'         => ['class' => true, 'style' => true],
            'ol'         => ['class' => true, 'style' => true, 'start' => true, 'type' => true],
            'li'         => ['class' => true, 'style' => true, 'value' => true],
            'h1'         => ['class' => true],
            'h2'         => ['class' => true],
            'h3'         => ['class' => true],
            'h4'         => ['class' => true],
            'h5'         => ['class' => true],
            'h6'         => ['class' => true],
            'blockquote' => ['class' => true, 'cite' => true],
            'pre'        => ['class' => true],
            'code'       => ['class' => true],
            'img'        => ['src' => true, 'alt' => true, 'class' => true, 'style' => true, 'width' => true, 'height' => true, 'loading' => true],
        ];

        return wp_kses($value, $allowed_html);
    }

    /**
     * #1649 — sanitize a field value for safe display in a table cell.
     *
     * The JS cell renderers pass any value whose first character is '<'
     * straight into innerHTML so server-rendered HTML (images, star-rating
     * SVG) survives. Plain text (not starting with '<') is escaped
     * client-side, so it must be returned byte-for-byte here to avoid double
     * encoding. Only HTML-looking values are routed through the canonical
     * sanitize_cell_html() allowlist (keeps <img>/<a>, strips <script> and
     * on* event handlers) — neutralising stored XSS in submitted field
     * values without affecting plain-text cells.
     *
     * @param mixed $value Raw field value bound for the client.
     * @return mixed Sanitized value for HTML-looking strings; others unchanged.
     */
    public static function sanitize_display_html($value)
    {
        if (!is_string($value) || $value === '' || $value[0] !== '<') {
            return $value;
        }
        return (new self())->sanitize_cell_html($value);
    }
}