<?php
/**
 * Frontend row visibility rules for Gravity Tables (#456).
 *
 * Rules are stored as a map keyed by row id (or row index) → rule string:
 *
 *   'everyone' - default, always visible (also matched by empty string)
 *   'logged-in' - visible only to authenticated users
 *   'logged-out' - visible only to guests
 *   '<role-slug>' - visible only to users that hold that WP role
 *
 * Rules are evaluated server-side before render so hidden rows never reach
 * the browser. The class is intentionally framework-light: every public
 * method takes the user context as plain arrays so it can be unit-tested
 * without booting WordPress.
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Row_Visibility {

    /**
     * Evaluate a single visibility rule against a user context.
     *
     * @param string $rule          One of '' / 'everyone' / 'logged-in' / 'logged-out' / a WP role slug.
     * @param array  $user_roles    Role slugs the current user holds.
     * @param bool   $is_logged_in  Whether the current request is authenticated.
     */
    public static function passes( string $rule, array $user_roles, bool $is_logged_in ): bool {
        $rule = trim( $rule );

        if ( $rule === '' || $rule === 'everyone' ) {
            return true;
        }

        if ( $rule === 'logged-in' ) {
            return $is_logged_in;
        }

        if ( $rule === 'logged-out' ) {
            return ! $is_logged_in;
        }

        // Otherwise treat as a role slug.
        return in_array( $rule, $user_roles, true );
    }

    /**
     * Drop rows whose configured rule fails for the given user context.
     *
     * Rows without a mapped rule default to visible. Rows are matched by
     * their `id` key, falling back to numeric index if `id` is absent.
     *
     * @param array $rows           List of associative row arrays.
     * @param array $visibility_map [row_id => rule string]
     * @param array $context        ['roles' => [...], 'is_logged_in' => bool]
     */
    public static function filter_rows( array $rows, array $visibility_map, array $context ): array {
        if ( empty( $visibility_map ) ) {
            return $rows;
        }

        $roles        = isset( $context['roles'] ) && is_array( $context['roles'] ) ? $context['roles'] : array();
        $is_logged_in = ! empty( $context['is_logged_in'] );

        $out = array();
        foreach ( $rows as $idx => $row ) {
            $row_id = $row['id'] ?? $idx;
            $rule   = $visibility_map[ $row_id ] ?? '';
            if ( self::passes( $rule, $roles, $is_logged_in ) ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Snapshot of the current WP user for rule evaluation.
     *
     * Falls back to a logged-out guest when no user is available so the
     * caller does not have to handle nulls.
     */
    public static function current_user_context(): array {
        $is_logged_in = function_exists( 'is_user_logged_in' ) ? (bool) is_user_logged_in() : false;
        $roles        = array();

        if ( $is_logged_in && function_exists( 'wp_get_current_user' ) ) {
            $user = wp_get_current_user();
            if ( $user && isset( $user->roles ) && is_array( $user->roles ) ) {
                $roles = array_values( $user->roles );
            }
        }

        return array(
            'roles'        => $roles,
            'is_logged_in' => $is_logged_in,
        );
    }
}
