<?php
/**
 * Query Builder Service for Gravity Tables
 *
 * Provides a fluent interface for building complex database queries
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
 * QueryBuilder class
 *
 * Fluent query builder for Gravity Forms entries with type safety
 */
class TC_Query_Builder {
    
    /**
     * WordPress database instance
     *
     * @var wpdb
     */
    private $wpdb;
    
    /**
     * SELECT clause components
     *
     * @var array<string>
     */
    private array $select = [];
    
    /**
     * FROM clause
     *
     * @var string
     */
    private string $from = '';
    
    /**
     * JOIN clauses
     *
     * @var array<string>
     */
    private array $joins = [];
    
    /**
     * WHERE conditions
     *
     * @var array<string>
     */
    private array $where = [];
    
    /**
     * GROUP BY clause
     *
     * @var string
     */
    private string $groupBy = '';
    
    /**
     * HAVING conditions
     *
     * @var array<string>
     */
    private array $having = [];
    
    /**
     * ORDER BY clause
     *
     * @var string
     */
    private string $orderBy = '';
    
    /**
     * LIMIT clause
     *
     * @var string
     */
    private string $limit = '';
    
    /**
     * Query parameters for prepared statements
     *
     * @var array<mixed>
     */
    private array $parameters = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }
    
    /**
     * Start a new query
     *
     * @return static New query builder instance
     */
    public static function create(): static {
        return new static();
    }
    
    /**
     * Add SELECT fields
     *
     * @param array<string>|string $fields Fields to select
     * @return static
     */
    public function select($fields): static {
        if (is_string($fields)) {
            $this->select[] = $fields;
        } elseif (is_array($fields)) {
            $this->select = array_merge($this->select, $fields);
        }
        
        return $this;
    }
    
    /**
     * Set FROM table
     *
     * @param string $table Table name
     * @param string $alias Optional table alias
     * @return static
     */
    public function from(string $table, string $alias = ''): static {
        $this->from = $table . ($alias ? " AS {$alias}" : '');
        return $this;
    }
    
    /**
     * Add LEFT JOIN
     *
     * @param string $table Table to join
     * @param string $condition Join condition
     * @param string $alias Optional table alias
     * @return static
     */
    public function leftJoin(string $table, string $condition, string $alias = ''): static {
        $table_with_alias = $table . ($alias ? " AS {$alias}" : '');
        $this->joins[] = "LEFT JOIN {$table_with_alias} ON {$condition}";
        return $this;
    }
    
    /**
     * Add INNER JOIN
     *
     * @param string $table Table to join
     * @param string $condition Join condition
     * @param string $alias Optional table alias
     * @return static
     */
    public function innerJoin(string $table, string $condition, string $alias = ''): static {
        $table_with_alias = $table . ($alias ? " AS {$alias}" : '');
        $this->joins[] = "INNER JOIN {$table_with_alias} ON {$condition}";
        return $this;
    }
    
    /**
     * Add WHERE condition
     *
     * @param string $condition WHERE condition with placeholders
     * @param mixed ...$parameters Parameters for the condition
     * @return static
     */
    public function where(string $condition, ...$parameters): static {
        $this->where[] = $condition;
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }
    
    /**
     * Add WHERE IN condition
     *
     * @param string $column Column name
     * @param array<mixed> $values Values for IN clause
     * @return static
     */
    public function whereIn(string $column, array $values): static {
        if (empty($values)) {
            return $this;
        }
        
        $placeholders = implode(', ', array_fill(0, count($values), '%s'));
        $this->where[] = "{$column} IN ({$placeholders})";
        $this->parameters = array_merge($this->parameters, $values);
        return $this;
    }
    
    /**
     * Add WHERE NOT IN condition
     *
     * @param string $column Column name
     * @param array<mixed> $values Values for NOT IN clause
     * @return static
     */
    public function whereNotIn(string $column, array $values): static {
        if (empty($values)) {
            return $this;
        }
        
        $placeholders = implode(', ', array_fill(0, count($values), '%s'));
        $this->where[] = "{$column} NOT IN ({$placeholders})";
        $this->parameters = array_merge($this->parameters, $values);
        return $this;
    }
    
    /**
     * Add WHERE LIKE condition
     *
     * @param string $column Column name
     * @param string $value Value to search for
     * @param bool $wildcard_start Add wildcard at start
     * @param bool $wildcard_end Add wildcard at end
     * @return static
     */
    public function whereLike(string $column, string $value, bool $wildcard_start = true, bool $wildcard_end = true): static {
        $escaped_value = $this->wpdb->esc_like($value);
        
        if ($wildcard_start) {
            $escaped_value = '%' . $escaped_value;
        }
        if ($wildcard_end) {
            $escaped_value = $escaped_value . '%';
        }
        
        $this->where[] = "{$column} LIKE %s";
        $this->parameters[] = $escaped_value;
        return $this;
    }
    
    /**
     * Add WHERE between condition
     *
     * @param string $column Column name
     * @param mixed $start Start value
     * @param mixed $end End value
     * @return static
     */
    public function whereBetween(string $column, $start, $end): static {
        $this->where[] = "{$column} BETWEEN %s AND %s";
        $this->parameters[] = $start;
        $this->parameters[] = $end;
        return $this;
    }
    
    /**
     * Add WHERE EXISTS condition
     *
     * @param string $subquery Subquery
     * @param mixed ...$parameters Parameters for the subquery
     * @return static
     */
    public function whereExists(string $subquery, ...$parameters): static {
        $this->where[] = "EXISTS ({$subquery})";
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }
    
    /**
     * Add WHERE NOT EXISTS condition
     *
     * @param string $subquery Subquery
     * @param mixed ...$parameters Parameters for the subquery
     * @return static
     */
    public function whereNotExists(string $subquery, ...$parameters): static {
        $this->where[] = "NOT EXISTS ({$subquery})";
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }
    
    /**
     * Set GROUP BY clause
     *
     * @param string $column Column to group by
     * @return static
     */
    public function groupBy(string $column): static {
        $this->groupBy = $column;
        return $this;
    }
    
    /**
     * Add HAVING condition
     *
     * @param string $condition HAVING condition
     * @param mixed ...$parameters Parameters for the condition
     * @return static
     */
    public function having(string $condition, ...$parameters): static {
        $this->having[] = $condition;
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }
    
    /**
     * Set ORDER BY clause
     *
     * @param string $column Column to order by
     * @param string $direction Order direction (ASC|DESC)
     * @return static
     */
    public function orderBy(string $column, string $direction = 'ASC'): static {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy = "{$column} {$direction}";
        return $this;
    }

    /**
     * Set ORDER BY clause using numeric cast so values sort as numbers, not strings.
     * Use for columns whose GF field type is 'number', 'currency', etc.
     *
     * @param string $column Column alias (e.g. field_5)
     * @param string $direction ASC|DESC
     * @return static
     */
    public function orderByNumeric(string $column, string $direction = 'ASC'): static {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBy = "CAST({$column} AS DECIMAL(20,6)) {$direction}";
        return $this;
    }

    /**
     * Set LIMIT clause
     *
     * @param int $limit Number of records to limit
     * @param int $offset Optional offset
     * @return static
     */
    public function limit(int $limit, int $offset = 0): static {
        if ($offset > 0) {
            $this->limit = "LIMIT {$offset}, {$limit}";
        } else {
            $this->limit = "LIMIT {$limit}";
        }
        return $this;
    }
    
    /**
     * Build the SQL query
     *
     * @return string Built SQL query
     */
    public function toSql(): string {
        $sql = '';
        
        // SELECT
        if (!empty($this->select)) {
            $sql .= 'SELECT ' . implode(', ', $this->select);
        } else {
            $sql .= 'SELECT *';
        }
        
        // FROM
        if (!empty($this->from)) {
            $sql .= ' FROM ' . $this->from;
        }
        
        // JOINs
        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        
        // WHERE
        if (!empty($this->where)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }
        
        // GROUP BY
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . $this->groupBy;
        }
        
        // HAVING
        if (!empty($this->having)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }
        
        // ORDER BY
        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . $this->orderBy;
        }
        
        // LIMIT
        if (!empty($this->limit)) {
            $sql .= ' ' . $this->limit;
        }
        
        return trim($sql);
    }
    
    /**
     * Get prepared statement parameters
     *
     * @return array<mixed> Parameters
     */
    public function getParameters(): array {
        return $this->parameters;
    }
    
    /**
     * Execute the query and get results
     *
     * @return array<object>|null Query results
     * @throws TC_Database_Exception
     */
    public function get(): ?array {
        $sql = $this->toSql();
        
        if (empty($this->parameters)) {
            $results = $this->wpdb->get_results($sql);
        } else {
            $prepared_sql = $this->wpdb->prepare($sql, $this->parameters);
            $results = $this->wpdb->get_results($prepared_sql);
        }
        
        if ($this->wpdb->last_error) {
            throw TC_Database_Exception::fromWpdb($this->wpdb, $sql);
        }
        
        return $results;
    }
    
    /**
     * Execute the query and get first result
     *
     * @return object|null First result
     * @throws TC_Database_Exception
     */
    public function first(): ?object {
        $this->limit(1);
        $results = $this->get();
        
        return !empty($results) ? $results[0] : null;
    }
    
    /**
     * Execute the query and get count
     *
     * @return int Count
     * @throws TC_Database_Exception
     */
    public function count(): int {
        // Save current select and replace with COUNT(*)
        $original_select = $this->select;
        $this->select = ['COUNT(*) as count'];
        
        $sql = $this->toSql();
        
        if (empty($this->parameters)) {
            $result = $this->wpdb->get_var($sql);
        } else {
            $prepared_sql = $this->wpdb->prepare($sql, $this->parameters);
            $result = $this->wpdb->get_var($prepared_sql);
        }
        
        if ($this->wpdb->last_error) {
            throw TC_Database_Exception::fromWpdb($this->wpdb, $sql);
        }
        
        // Restore original select
        $this->select = $original_select;
        
        return intval($result);
    }
    
    /**
     * Execute the query and check if any results exist
     *
     * @return bool True if results exist
     * @throws TC_Database_Exception
     */
    public function exists(): bool {
        return $this->count() > 0;
    }
    
    /**
     * Build a query for Gravity Forms entries
     *
     * @param int $form_id Form ID
     * @return static
     */
    public function forGravityForm(int $form_id): static {
        return $this
            ->from("{$this->wpdb->prefix}gf_entry", 'e')
            ->where('e.form_id = %d', $form_id)
            ->where("e.status = %s", 'active');
    }
    
    /**
     * Join with entry meta table
     *
     * @param string $alias Optional alias for meta table
     * @return static
     */
    public function withEntryMeta(string $alias = 'em'): static {
        return $this->leftJoin(
            "{$this->wpdb->prefix}gf_entry_meta",
            "e.id = {$alias}.entry_id",
            $alias
        );
    }
    
    /**
     * Add meta field condition
     *
     * @param string $field_id Field ID
     * @param mixed $value Field value
     * @param string $operator Comparison operator
     * @param string $meta_alias Meta table alias
     * @return static
     */
    public function whereMetaField(string $field_id, $value, string $operator = '=', string $meta_alias = 'em'): static {
        $subquery = "SELECT 1 FROM {$this->wpdb->prefix}gf_entry_meta {$meta_alias}_sub 
                     WHERE {$meta_alias}_sub.entry_id = e.id 
                     AND {$meta_alias}_sub.meta_key = %s 
                     AND {$meta_alias}_sub.meta_value {$operator} %s";
        
        return $this->whereExists($subquery, $field_id, $value);
    }
    
    /**
     * Add meta field LIKE condition
     *
     * @param string $field_id Field ID
     * @param string $value Value to search for
     * @param string $meta_alias Meta table alias
     * @return static
     */
    public function whereMetaFieldLike(string $field_id, string $value, string $meta_alias = 'em'): static {
        $escaped_value = '%' . $this->wpdb->esc_like($value) . '%';
        return $this->whereMetaField($field_id, $escaped_value, 'LIKE', $meta_alias);
    }
    
    /**
     * Add meta field IN condition
     *
     * @param string $field_id Field ID
     * @param array<mixed> $values Values for IN clause
     * @param string $meta_alias Meta table alias
     * @return static
     */
    public function whereMetaFieldIn(string $field_id, array $values, string $meta_alias = 'em'): static {
        if (empty($values)) {
            return $this;
        }
        
        $placeholders = implode(', ', array_fill(0, count($values), '%s'));
        $subquery = "SELECT 1 FROM {$this->wpdb->prefix}gf_entry_meta {$meta_alias}_sub 
                     WHERE {$meta_alias}_sub.entry_id = e.id 
                     AND {$meta_alias}_sub.meta_key = %s 
                     AND {$meta_alias}_sub.meta_value IN ({$placeholders})";
        
        $params = array_merge([$field_id], $values);
        return $this->whereExists($subquery, ...$params);
    }
    
    /**
     * Add date range condition
     *
     * @param string $start_date Start date
     * @param string $end_date End date
     * @return static
     */
    public function whereDateRange(string $start_date, string $end_date): static {
        if (!empty($start_date)) {
            $this->where('e.date_created >= %s', $start_date . ' 00:00:00');
        }
        
        if (!empty($end_date)) {
            $this->where('e.date_created <= %s', $end_date . ' 23:59:59');
        }
        
        return $this;
    }
    
    /**
     * Add search condition across all entry meta
     *
     * @param string $search_term Search term
     * @return static
     */
    public function whereSearch(string $search_term): static {
        if (empty($search_term)) {
            return $this;
        }
        
        $escaped_term = '%' . $this->wpdb->esc_like($search_term) . '%';
        
        $search_conditions = [
            "EXISTS (SELECT 1 FROM {$this->wpdb->prefix}gf_entry_meta em_search 
             WHERE em_search.entry_id = e.id AND em_search.meta_value LIKE %s)",
            "e.date_created LIKE %s",
            "CAST(e.id AS CHAR) LIKE %s"
        ];
        
        $condition = '(' . implode(' OR ', $search_conditions) . ')';
        $this->where[] = $condition;
        $this->parameters[] = $escaped_term;
        $this->parameters[] = $escaped_term;
        $this->parameters[] = $escaped_term;
        
        return $this;
    }
    
    /**
     * Add dynamic field selection for entry meta
     *
     * @param array<string> $field_ids Field IDs to select
     * @return static
     */
    public function selectEntryFields(array $field_ids): static {
        $this->select(['e.id as entry_id', 'e.date_created']);
        
        foreach ($field_ids as $field_id) {
            if (!in_array($field_id, ['entry_id', 'date_created'])) {
                $this->select("MAX(CASE WHEN em.meta_key = '{$field_id}' THEN em.meta_value END) as field_{$field_id}");
            }
        }
        
        return $this;
    }
    
    /**
     * Debug: Print the built query with parameters
     *
     * @return static
     */
    public function debug(): static {
        $sql = $this->toSql();
        $params = $this->getParameters();
        
        // error_log('GT Query Builder Debug:');
        // error_log('SQL: ' . $sql);
        // error_log('Parameters: ' . print_r($params, true));
        
        if (!empty($params)) {
            $prepared = $this->wpdb->prepare($sql, $params);
            // error_log('Prepared SQL: ' . $prepared);
        }
        
        return $this;
    }
}