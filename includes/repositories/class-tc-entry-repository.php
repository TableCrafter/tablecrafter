<?php
/**
 * Entry Repository for Gravity Tables
 *
 * Data access layer for Gravity Forms entries
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
 * EntryRepository class
 *
 * Handles data access for Gravity Forms entries using Repository pattern
 */
class TC_Entry_Repository
{

    /**
     * Query builder instance
     *
     * @var TC_Query_Builder
     */
    private TC_Query_Builder $queryBuilder;

    /**
     * Lookup processor
     *
     * @var TC_Lookup
     */
    private TC_Lookup $lookupProcessor;

    /**
     * Configuration service
     *
     * @var TC_Configuration_Service
     */
    private TC_Configuration_Service $config;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->queryBuilder = new TC_Query_Builder();
        $this->lookupProcessor = TC_Lookup::get_instance();
        $this->config = new TC_Configuration_Service();
    }

    /**
     * Find entries by form ID with optional filters
     *
     * @param int $form_id Form ID
     * @param array $options Query options
     * @return array Entries with pagination data
     */
    public function findByFormId(int $form_id, array $options = []): array
    {
        $defaults = [
            'page' => 1,
            'per_page' => $this->config->getPerPage(),
            'columns' => [],
            'search' => '',
            'filters' => [],
            'sort_field' => 'date_created',
            'sort_order' => 'desc',
            'date_from' => '',
            'date_to' => '',
            'user_filter' => '',
            'lookup_fields' => [],
            'field_types' => [],   // map of field_id => gf_field_type for numeric sort detection
        ];

        $options = array_merge($defaults, $options);

        // Build base query
        $query = TC_Query_Builder::create()
            ->forGravityForm($form_id)
            ->withEntryMeta()
            ->selectEntryFields($options['columns'])
            ->groupBy('e.id');

        // Apply search
        if (!empty($options['search'])) {
            $query->whereSearch($options['search']);
        }

        // Apply date range
        if (!empty($options['date_from']) || !empty($options['date_to'])) {
            $query->whereDateRange($options['date_from'], $options['date_to']);
        }

        // Apply user filter
        if (!empty($options['user_filter'])) {
            $query->whereMetaField('created_by', $options['user_filter']);
        }

        // Apply custom filters
        $this->applyCustomFilters($query, $options['filters']);

        // Apply sorting
        $this->applySorting($query, $options);

        // Get total count for pagination
        $total_count = $this->countByFormId($form_id, $options);

        // Apply pagination
        $offset = ($options['page'] - 1) * $options['per_page'];
        $query->limit($options['per_page'], $offset);

        // Execute query
        $results = $query->get();

        // Process results
        $entries = $this->processEntryResults($results, $options);

        return [
            'entries' => $entries,
            'total' => $total_count,
            'page' => $options['page'],
            'per_page' => $options['per_page'],
            'total_pages' => ceil($total_count / $options['per_page']),
            'columns' => $options['columns']
        ];
    }

    /**
     * Count entries by form ID with filters
     *
     * @param int $form_id Form ID
     * @param array $options Filter options
     * @return int Entry count
     */
    public function countByFormId(int $form_id, array $options = []): int
    {
        $query = TC_Query_Builder::create()
            ->forGravityForm($form_id)
            ->withEntryMeta();

        // Apply search
        if (!empty($options['search'])) {
            $query->whereSearch($options['search']);
        }

        // Apply date range
        if (!empty($options['date_from']) || !empty($options['date_to'])) {
            $query->whereDateRange($options['date_from'], $options['date_to']);
        }

        // Apply user filter
        if (!empty($options['user_filter'])) {
            $query->whereMetaField('created_by', $options['user_filter']);
        }

        // Apply custom filters
        if (!empty($options['filters'])) {
            $this->applyCustomFilters($query, $options['filters']);
        }

        // Group by entry ID for accurate count with JOINs
        $query->select('DISTINCT e.id');

        return $query->count();
    }

    /**
     * Find entry by ID
     *
     * @param int $entry_id Entry ID
     * @return object|null Entry data
     */
    public function findById(int $entry_id): ?object
    {
        return TC_Query_Builder::create()
            ->select('*')
            ->from("{$GLOBALS['wpdb']->prefix}gf_entry")
            ->where('id = %d', $entry_id)
            ->where("status = %s", 'active')
            ->first();
    }

    /**
     * Find entries by IDs
     *
     * @param array $entry_ids Entry IDs
     * @return array Entry data
     */
    public function findByIds(array $entry_ids): array
    {
        if (empty($entry_ids)) {
            return [];
        }

        $entry_ids = array_map('intval', $entry_ids);

        return TC_Query_Builder::create()
            ->select('*')
            ->from("{$GLOBALS['wpdb']->prefix}gf_entry")
            ->whereIn('id', $entry_ids)
            ->where("status = %s", 'active')
            ->get() ?? [];
    }

    /**
     * Get entry meta for an entry
     *
     * @param int $entry_id Entry ID
     * @param array $field_ids Optional specific field IDs
     * @return array Meta data keyed by field ID
     */
    public function getEntryMeta(int $entry_id, array $field_ids = []): array
    {
        $query = TC_Query_Builder::create()
            ->select(['meta_key', 'meta_value'])
            ->from("{$GLOBALS['wpdb']->prefix}gf_entry_meta")
            ->where('entry_id = %d', $entry_id);

        if (!empty($field_ids)) {
            $query->whereIn('meta_key', $field_ids);
        }

        $results = $query->get();

        $meta = [];
        foreach ($results as $row) {
            $meta[$row->meta_key] = $row->meta_value;
        }

        return $meta;
    }

    /**
     * Update entry meta
     *
     * @param int $entry_id Entry ID
     * @param array $meta_data Meta data to update
     * @return bool Success
     * @throws TC_Database_Exception
     */
    public function updateEntryMeta(int $entry_id, array $meta_data): bool
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $success = true;

        foreach ($meta_data as $field_id => $value) {
            $result = $wpdb->update(
                $wpdb->prefix . 'gf_entry_meta',
                ['meta_value' => $value],
                [
                    'entry_id' => $entry_id,
                    'meta_key' => $field_id
                ],
                ['%s'],
                ['%d', '%s']
            );

            if ($result === false) {
                $success = false;
                break;
            }
        }

        if (!$success && $wpdb->last_error) {
            throw TC_Database_Exception::fromWpdb($wpdb);
        }

        // Update entry timestamp
        if ($success) {
            $wpdb->update(
                $wpdb->prefix . 'gf_entry',
                ['date_updated' => current_time('mysql')],
                ['id' => $entry_id],
                ['%s'],
                ['%d']
            );
        }

        return $success;
    }

    /**
     * Bulk update entries
     *
     * @param array $entry_ids Entry IDs
     * @param array $updates Field updates
     * @return array Result with count
     * @throws TC_Database_Exception
     */
    public function bulkUpdate(array $entry_ids, array $updates): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $updated = 0;

        foreach ($entry_ids as $entry_id) {
            if ($this->updateEntryMeta($entry_id, $updates)) {
                $updated++;
            }
        }

        return [
            'updated_count' => $updated,
            'total_entries' => count($entry_ids)
        ];
    }

    /**
     * Soft delete entries (move to trash)
     *
     * @param array $entry_ids Entry IDs
     * @return array Result with count
     * @throws TC_Database_Exception
     */
    public function bulkDelete(array $entry_ids): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $entry_ids = array_map('intval', $entry_ids);
        $placeholders = implode(', ', array_fill(0, count($entry_ids), '%d'));

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}gf_entry SET status = 'trash' WHERE id IN ({$placeholders})",
                ...$entry_ids
            )
        );

        if ($result === false && $wpdb->last_error) {
            throw TC_Database_Exception::fromWpdb($wpdb);
        }

        return [
            'deleted_count' => $result,
            'total_entries' => count($entry_ids)
        ];
    }

    /**
     * Check if user can edit entry
     *
     * @param int $entry_id Entry ID
     * @param int $user_id User ID
     * @return bool Can edit
     */
    public function canUserEditEntry(int $entry_id, int $user_id): bool
    {
        // Admins can edit all entries
        if (user_can($user_id, 'edit_posts')) {
            return true;
        }

        // Check if user created the entry or is associated with it
        $user_meta_exists = TC_Query_Builder::create()
            ->from("{$GLOBALS['wpdb']->prefix}gf_entry_meta")
            ->where('entry_id = %d', $entry_id)
            ->where('meta_value = %s', (string) $user_id)
            ->exists();

        return $user_meta_exists;
    }

    /**
     * Get entries for export
     *
     * @param int $form_id Form ID
     * @param array $entry_ids Entry IDs
     * @return array Export data
     */
    public function getForExport(int $form_id, array $entry_ids): array
    {
        if (!class_exists('GFAPI')) {
            return [];
        }

        $form = GFAPI::get_form($form_id);
        if (!$form) {
            return [];
        }

        $entries = GFAPI::get_entries($form_id, [
            'field_filters' => [
                ['key' => 'id', 'operator' => 'in', 'value' => $entry_ids]
            ]
        ]);

        return [
            'form' => $form,
            'entries' => $entries
        ];
    }

    /**
     * Apply custom filters to query
     *
     * @param TC_Query_Builder $query Query builder
     * @param array $filters Custom filters
     * @return void
     */
    private function applyCustomFilters(TC_Query_Builder $query, array $filters): void
    {
        foreach ($filters as $field_id => $filter_data) {
            if (!is_array($filter_data) || !isset($filter_data['type'])) {
                continue;
            }

            switch ($filter_data['type']) {
                case 'text':
                    if (!empty($filter_data['value'])) {
                        $query->whereMetaFieldLike($field_id, $filter_data['value']);
                    }
                    break;

                case 'dropdown':
                case 'lookup':
                    if (!empty($filter_data['value'])) {
                        $query->whereMetaField($field_id, $filter_data['value']);
                    } elseif (!empty($filter_data['values']) && is_array($filter_data['values'])) {
                        $query->whereMetaFieldIn($field_id, $filter_data['values']);
                    }
                    break;

                case 'checkboxes':
                    if (!empty($filter_data['values']) && is_array($filter_data['values'])) {
                        $query->whereMetaFieldIn($field_id, $filter_data['values']);
                    }
                    break;

                case 'date_range':
                    if (!empty($filter_data['from']) || !empty($filter_data['to'])) {
                        $this->applyDateRangeFilter($query, $field_id, $filter_data);
                    }
                    break;

                case 'number_range':
                    if (!empty($filter_data['min']) || !empty($filter_data['max'])) {
                        $this->applyNumberRangeFilter($query, $field_id, $filter_data);
                    }
                    break;
            }
        }
    }

    /**
     * Apply date range filter
     *
     * @param TC_Query_Builder $query Query builder
     * @param string $field_id Field ID
     * @param array $filter_data Filter data
     * @return void
     */
    private function applyDateRangeFilter(TC_Query_Builder $query, string $field_id, array $filter_data): void
    {
        if (!empty($filter_data['from'])) {
            $normalized_from = $this->normalizeDateFormat($filter_data['from']);
            $subquery = "SELECT 1 FROM {$GLOBALS['wpdb']->prefix}gf_entry_meta em_date 
                        WHERE em_date.entry_id = e.id 
                        AND em_date.meta_key = %s 
                        AND STR_TO_DATE(em_date.meta_value, '%%m/%%d/%%Y') >= STR_TO_DATE(%s, '%%m/%%d/%%Y')";
            $query->whereExists($subquery, $field_id, $normalized_from);
        }

        if (!empty($filter_data['to'])) {
            $normalized_to = $this->normalizeDateFormat($filter_data['to']);
            $subquery = "SELECT 1 FROM {$GLOBALS['wpdb']->prefix}gf_entry_meta em_date 
                        WHERE em_date.entry_id = e.id 
                        AND em_date.meta_key = %s 
                        AND STR_TO_DATE(em_date.meta_value, '%%m/%%d/%%Y') <= STR_TO_DATE(%s, '%%m/%%d/%%Y')";
            $query->whereExists($subquery, $field_id, $normalized_to);
        }
    }

    /**
     * Apply number range filter
     *
     * @param TC_Query_Builder $query Query builder
     * @param string $field_id Field ID
     * @param array $filter_data Filter data
     * @return void
     */
    private function applyNumberRangeFilter(TC_Query_Builder $query, string $field_id, array $filter_data): void
    {
        if (isset($filter_data['min']) && is_numeric($filter_data['min'])) {
            $subquery = "SELECT 1 FROM {$GLOBALS['wpdb']->prefix}gf_entry_meta em_num 
                        WHERE em_num.entry_id = e.id 
                        AND em_num.meta_key = %s 
                        AND CAST(em_num.meta_value AS DECIMAL(10,2)) >= %f";
            $query->whereExists($subquery, $field_id, floatval($filter_data['min']));
        }

        if (isset($filter_data['max']) && is_numeric($filter_data['max'])) {
            $subquery = "SELECT 1 FROM {$GLOBALS['wpdb']->prefix}gf_entry_meta em_num 
                        WHERE em_num.entry_id = e.id 
                        AND em_num.meta_key = %s 
                        AND CAST(em_num.meta_value AS DECIMAL(10,2)) <= %f";
            $query->whereExists($subquery, $field_id, floatval($filter_data['max']));
        }
    }

    /**
     * Apply sorting to query
     *
     * @param TC_Query_Builder $query Query builder
     * @param array $options Sort options
     * @return void
     */
    private function applySorting(TC_Query_Builder $query, array $options): void
    {
        $sort_field = $options['sort_field'];
        $sort_order = $options['sort_order'];

        if ($sort_field === 'entry_id') {
            $query->orderBy('e.id', $sort_order);
        } elseif ($sort_field === 'date_created') {
            $query->orderBy('e.date_created', $sort_order);
        } else {
            if (in_array($sort_field, $options['columns'])) {
                // Check if this is a lookup field that needs special sorting
                if (!empty($options['lookup_fields'][$sort_field])) {
                    $this->applyLookupSorting($query, $sort_field, $options['lookup_fields'][$sort_field], $sort_order);
                } else {
                    // Use numeric CAST for number-type fields so 2 < 10 < 100
                    $field_type = $options['field_types'][$sort_field] ?? '';
                    $numeric_types = ['number', 'currency', 'calculation', 'quantity'];
                    if (in_array($field_type, $numeric_types, true)) {
                        $query->orderByNumeric("field_{$sort_field}", $sort_order);
                    } else {
                        $query->orderBy("field_{$sort_field}", $sort_order);
                    }
                }
            } else {
                // Fallback to date_created
                $query->orderBy('e.date_created', $sort_order);
            }
        }
    }

    /**
     * Apply lookup field sorting
     *
     * @param TC_Query_Builder $query Query builder
     * @param string $field_id Field ID
     * @param array $lookup_config Lookup configuration
     * @param string $sort_order Sort order
     * @return void
     */
    private function applyLookupSorting(TC_Query_Builder $query, string $field_id, array $lookup_config, string $sort_order): void
    {
        // This would need to be implemented based on lookup type
        // For now, fallback to regular field sorting
        $query->orderBy("field_{$field_id}", $sort_order);
    }

    /**
     * Process entry results
     *
     * @param array $results Raw results
     * @param array $options Query options
     * @return array Processed entries
     */
    private function processEntryResults(array $results, array $options): array
    {
        $entries = [];

        // #1664 — pre-resolve each lookup column's values in one batch before
        // the row loop, instead of one process_lookup_value() per row per
        // lookup column. Map shape: [field_id => [raw_value => resolved]].
        $lookup_maps = [];
        if (!empty($options['lookup_fields'])) {
            foreach ($options['lookup_fields'] as $lf_field_id => $lf_config) {
                if (!in_array($lf_field_id, $options['columns'])) {
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
                    $lookup_maps[$lf_field_id] = $this->lookupProcessor->process_lookup_values_batch(
                        array_values(array_unique($lf_values)),
                        $lf_config
                    );
                }
            }
        }

        foreach ($results as $row) {
            $entry = ['entry_id' => $row->entry_id];

            if (in_array('entry_id', $options['columns'])) {
                $entry['entry_id'] = $row->entry_id;
            }

            if (in_array('date_created', $options['columns'])) {
                $entry['date_created'] = $this->formatDateDisplay($row->date_created ?? '');
            }

            // Process dynamic fields
            foreach ($options['columns'] as $field_id) {
                if (in_array($field_id, ['date_created', 'entry_id'])) {
                    continue;
                }

                // Handle field IDs with dots (convert to underscores for SQL aliases)
                $field_key = str_replace('.', '_', $field_id);
                $field_name = "field_{$field_key}";
                $value = $row->$field_name ?? '';

                // Process lookup if configured
                if (!empty($options['lookup_fields'][$field_id])) {
                    // #1664 — use the pre-batched map; fall back to a per-value
                    // resolve only for values not present in the batch result.
                    if (isset($lookup_maps[$field_id]) && array_key_exists($value, $lookup_maps[$field_id])) {
                        $value = $lookup_maps[$field_id][$value];
                    } else {
                        $value = $this->lookupProcessor->process_lookup_value($value, $options['lookup_fields'][$field_id]);
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
    private function formatDateDisplay(string $date_value): string
    {
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

    /**
     * Normalize date format
     *
     * @param string $date_string Date string
     * @return string Normalized date
     */
    private function normalizeDateFormat(string $date_string): string
    {
        if (empty($date_string)) {
            return $date_string;
        }

        $parts = explode('/', $date_string);
        if (count($parts) === 3) {
            $month = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
            $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
            $year = $parts[2];

            return "{$month}/{$day}/{$year}";
        }

        return $date_string;
    }

    /**
     * Get unique values for a specific field
     *
     * @param int $form_id Form ID
     * @param string $field_id Field ID
     * @return array Unique values
     */
    public function getUniqueValues(int $form_id, string $field_id, int $limit = 1000): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        // #1666 — cap the distinct list so a high-cardinality column (load
        // numbers, dates) doesn't emit thousands of <option> rows per render.
        // Free-text discovery beyond the cap uses getUniqueValuesMatching()
        // (the clamped typeahead path).
        $limit = max(1, $limit);

        $sql = "SELECT DISTINCT em.meta_value
                FROM {$wpdb->prefix}gf_entry_meta em
                INNER JOIN {$wpdb->prefix}gf_entry e ON e.id = em.entry_id
                WHERE e.form_id = %d AND em.meta_key = %s AND em.meta_value != '' AND e.status = 'active'
                ORDER BY em.meta_value ASC
                LIMIT %d";

        return $wpdb->get_col($wpdb->prepare($sql, $form_id, $field_id, $limit));
    }

    /**
     * Per-value count + first-seen entry id for a field.
     *
     * Powers the dropdown filter's `sort_options=frequency` and
     * `sort_options=original` modes (#650 second half). The template
     * sorts the returned rows itself — the SQL just needs to expose
     * COUNT(*) and MIN(entry_id) so either ordering is reachable.
     *
     * Returns: [ ['value' => string, 'count' => int, 'first_seen' => int], ... ]
     * Order: ascending alphabetical so callers that don't sort still
     * get a deterministic stable list.
     */
    public function getValueCountsAndFirstSeen(int $form_id, string $field_id): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $sql = "SELECT em.meta_value AS value, COUNT(*) AS cnt, MIN(em.entry_id) AS first_seen
                FROM {$wpdb->prefix}gf_entry_meta em
                INNER JOIN {$wpdb->prefix}gf_entry e ON e.id = em.entry_id
                WHERE e.form_id = %d AND em.meta_key = %s AND em.meta_value != '' AND e.status = 'active'
                GROUP BY em.meta_value
                ORDER BY em.meta_value ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $form_id, $field_id), ARRAY_A);
        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_map( static function ( $row ) {
            return array(
                'value'      => (string) $row['value'],
                'count'      => (int) $row['cnt'],
                'first_seen' => (int) $row['first_seen'],
            );
        }, $rows );
    }

    /**
     * Get unique values for a field, optionally filtered by a search prefix and capped.
     * Powers the text-filter typeahead introduced in 4.7.57.
     *
     * @param int    $form_id  Form ID.
     * @param string $field_id Field ID (matches gf_entry_meta.meta_key).
     * @param string $search   Optional search fragment; matches anywhere in the value (case-insensitive).
     * @param int    $limit    Max results (clamped to 1..200).
     * @return array Sorted alphabetically; values pre-trimmed.
     */
    public function getUniqueValuesMatching(int $form_id, string $field_id, string $search = '', int $limit = 50): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $limit = max(1, min(200, $limit));

        $sql = "SELECT DISTINCT em.meta_value
                FROM {$wpdb->prefix}gf_entry_meta em
                INNER JOIN {$wpdb->prefix}gf_entry e ON e.id = em.entry_id
                WHERE e.form_id = %d
                  AND em.meta_key = %s
                  AND em.meta_value != ''
                  AND e.status = 'active'";

        $params = array($form_id, $field_id);

        if ($search !== '') {
            $sql .= ' AND em.meta_value LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $sql .= ' ORDER BY em.meta_value ASC LIMIT %d';
        $params[] = $limit;

        $values = $wpdb->get_col($wpdb->prepare($sql, $params));
        return is_array($values) ? $values : array();
    }
}