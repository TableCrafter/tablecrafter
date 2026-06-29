<?php
/**
 * Centralised guard for SQL identifier validation.
 *
 * Used wherever a table or column name has to be interpolated into a SQL
 * statement (which $wpdb->prepare() cannot parameterise). Defining the
 * allowlist in one place makes the rule auditable and prevents drift.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_SQL_Guard
{
    /**
     * Maximum length of a MySQL identifier — see
     * https://dev.mysql.com/doc/refman/8.0/en/identifier-length.html
     */
    const MAX_IDENTIFIER_LENGTH = 64;

    /**
     * True when $name is safe to interpolate into a SQL identifier position
     * (table name, column name, alias). Rejects everything outside
     * [A-Za-z0-9_], empty strings, and names longer than the MySQL limit.
     */
    public static function is_safe_identifier($name): bool
    {
        if (!is_string($name) || $name === '') {
            return false;
        }

        if (strlen($name) > self::MAX_IDENTIFIER_LENGTH) {
            return false;
        }

        return (bool) preg_match('/\A[A-Za-z0-9_]+\z/', $name);
    }

    /**
     * Fixed pseudo-columns that map to gf_entry table columns rather than
     * to a Gravity Forms field meta_key. Safe to interpolate because the
     * set is closed.
     */
    const SPECIAL_COLUMNS = ['entry_id', 'date_created', 'created_by', 'ip'];

    /**
     * True when $id is a valid Gravity Forms field ID: a positive integer,
     * optionally with a single dotted sub-input (e.g. "1", "42", "1.3").
     *
     * GF meta_key values are always of this shape. Restricting to it makes
     * it impossible to smuggle a quote/parenthesis into a SELECT pivot's
     * meta_key literal or field alias.
     */
    public static function is_safe_field_id($id): bool
    {
        if (!is_string($id) && !is_int($id)) {
            return false;
        }

        return (bool) preg_match('/\A[0-9]+(\.[0-9]+)?\z/', (string) $id);
    }

    /**
     * Reduce a requested column list to only values that are safe to
     * interpolate into the entry-listing SELECT pivot: the fixed special
     * columns plus valid Gravity Forms field IDs. Scalars are coerced to
     * strings; everything else (arrays, objects, injection payloads) is
     * dropped. The result is re-indexed.
     */
    public static function filter_columns($columns): array
    {
        if (!is_array($columns)) {
            return [];
        }

        $safe = [];
        foreach ($columns as $col) {
            if (!is_scalar($col)) {
                continue;
            }
            $col = (string) $col;
            if (in_array($col, self::SPECIAL_COLUMNS, true) || self::is_safe_field_id($col)) {
                $safe[] = $col;
            }
        }

        return $safe;
    }
}
