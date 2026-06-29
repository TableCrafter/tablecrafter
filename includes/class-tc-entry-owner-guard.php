<?php
/**
 * Per-entry ownership guard for the frontend inline-editing grant.
 *
 * The "enable_frontend_editing" grant lets any logged-in user who can view
 * an editing-enabled table edit entries on that table's form. For a
 * multi-user form (one row per driver) that is too broad — every driver
 * could edit every other driver's row. When a table sets owner_field_id,
 * a non-admin editor must additionally OWN the target entry: the entry's
 * owner field must hold their user id.
 *
 * Defined as a dependency-free class (mirrors TC_SQL_Guard) so the rule
 * lives in one auditable place and is unit-testable without the WP stack.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd

class TC_Entry_Owner_Guard
{
    /**
     * True when $entry may be edited under the frontend-editing grant.
     *
     * - Empty / '0' owner_field_id: no ownership restriction is configured,
     *   so the grant is unchanged (returns true). Keeps existing tables that
     *   never set the field behaving exactly as before.
     * - Otherwise the entry's owner field must equal the (positive) user id.
     *
     * @param array  $entry          GF entry array (field id => value).
     * @param mixed  $owner_field_id Configured owner field id (e.g. "28").
     * @param int    $user_id        Current user id.
     */
    public static function entry_owner_matches(array $entry, $owner_field_id, int $user_id): bool
    {
        $owner_field_id = trim((string) $owner_field_id);
        if ($owner_field_id === '' || $owner_field_id === '0') {
            return true;
        }
        if ($user_id <= 0) {
            return false;
        }
        if (!array_key_exists($owner_field_id, $entry)) {
            return false;
        }
        return intval($entry[$owner_field_id]) === $user_id;
    }
}
