<?php
/**
 * TC_Column_Visibility — server-side enforcement of per-column role visibility.
 *
 * Per-column role visibility (#1746, Pro) was originally enforced only in the
 * browser (assets/js/frontend/column-role-visibility.js sets display:none),
 * which leaked restricted column values, the visibility map, and the user's
 * roles to every authorized viewer. #1763 moves enforcement server-side: this
 * helper computes which columns the current user may not see and strips them
 * from both the AJAX entries payload and the server-rendered column config.
 *
 * Semantics match the JS verbatim: a column is hidden iff its allowed-roles
 * list is non-empty AND the user holds none of those roles. An empty list
 * means "visible to everyone". There is no administrator exemption.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart -- ABSPATH/SAPI guard; conditions are always false under the test shim and run pre-instrumentation.
if ( ! defined( 'ABSPATH' ) && ! defined( 'TC_PHPUNIT_SHIM' ) && ! defined( 'TC_TESTING' ) ) {
    // Allow CLI test harness to load this file standalone.
    if ( PHP_SAPI !== 'cli' ) {
        exit;
    }
}
// @codeCoverageIgnoreEnd

class TC_Column_Visibility {

    /**
     * Compute the field IDs that must be hidden from a user holding $user_roles.
     *
     * Pro-gated: on the free tier this always returns an empty array, so a
     * stale Pro-era visibility map left in the DB after a downgrade can never
     * hide (or, worse, partially reveal) columns.
     *
     * @param array $settings   Table settings (expects 'column_role_visibility').
     * @param array $user_roles Role slugs the current user holds.
     * @return string[] Field IDs to strip for this user.
     */
    public static function hidden_field_ids( array $settings, array $user_roles ): array {
        if ( function_exists( 'gt_is_premium' ) && ! gt_is_premium() ) {
            return [];
        }

        $map = $settings['column_role_visibility'] ?? null;
        if ( ! is_array( $map ) ) {
            return [];
        }

        $user_roles = array_map( 'strval', $user_roles );
        $hidden     = [];

        foreach ( $map as $field_id => $allowed_roles ) {
            if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
                // Empty / malformed allowed-list = visible to everyone.
                continue;
            }
            $allowed_roles = array_map( 'strval', $allowed_roles );
            if ( empty( array_intersect( $allowed_roles, $user_roles ) ) ) {
                $hidden[] = (string) $field_id;
            }
        }

        return $hidden;
    }

    /**
     * Strip hidden columns' values (and any composite sub-input keys like
     * "3.1") from every entry row before it ships to the client.
     *
     * @param array    $entries          List of entry rows (field_id => value).
     * @param string[] $hidden_field_ids Output of hidden_field_ids().
     * @return array The entries with restricted keys removed.
     */
    public static function strip_entry_columns( array $entries, array $hidden_field_ids ): array {
        if ( empty( $hidden_field_ids ) ) {
            return $entries;
        }

        foreach ( $entries as &$entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            foreach ( $hidden_field_ids as $fid ) {
                $fid = (string) $fid;
                unset( $entry[ $fid ] );
                // Composite fields (address/name/checkbox/etc.) store sub-inputs
                // under "{id}.{n}" keys — strip those too so none leak.
                $prefix = $fid . '.';
                foreach ( array_keys( $entry ) as $key ) {
                    if ( strpos( (string) $key, $prefix ) === 0 ) {
                        unset( $entry[ $key ] );
                    }
                }
            }
        }
        unset( $entry );

        return $entries;
    }

    /**
     * Remove hidden columns from a column_config map so the server renders no
     * <th>/<td> for them and the localized JS config never learns of them.
     *
     * @param array    $column_config    field_id => column config.
     * @param string[] $hidden_field_ids Output of hidden_field_ids().
     * @return array The column_config without restricted columns.
     */
    public static function strip_columns_config( array $column_config, array $hidden_field_ids ): array {
        foreach ( $hidden_field_ids as $fid ) {
            unset( $column_config[ (string) $fid ] );
        }
        return $column_config;
    }
}
