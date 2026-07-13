<?php
/**
 * Read-only view over the Freemius license state.
 *
 * Centralises status classification so the rest of the plugin can ask one
 * deterministic question - "what state is the license in?" - instead of
 * combining `is_premium()`, `is_paying()`, `is_trial()` and expiration
 * checks ad hoc at every call site. Required for #481 (graceful
 * degradation on downgrade / expiry).
 *
 * @package GravityTables
 * @since 4.6.4
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_License_State
{
    public const ACTIVE    = 'active';
    public const TRIAL     = 'trial';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';
    public const FREE      = 'free';

    /**
     * Classify the current license into one of the constants above.
     *
     * @param object|null $fs Freemius SDK instance (or test double).
     * @return string One of: active, trial, expired, cancelled, free.
     */
    public static function status($fs = null): string
    {
        if ($fs === null) {
            return self::FREE;
        }

        $is_trial   = self::call_bool($fs, 'is_trial');
        $is_premium = self::call_bool($fs, 'is_premium')
                   || self::call_bool($fs, 'is_paying')
                   || self::call_bool($fs, 'can_use_premium_code');

        if ($is_premium && $is_trial) {
            return self::TRIAL;
        }
        if ($is_premium) {
            return self::ACTIVE;
        }

        // No active premium - distinguish "expired" (we knew about a paid
        // license, the date is in the past) from a plain free user.
        if (method_exists($fs, 'get_license')) {
            $license = $fs->get_license();
            if ($license && method_exists($license, 'get_expiration')) {
                $exp = $license->get_expiration();
                if ($exp && strtotime($exp) !== false && strtotime($exp) < time()) {
                    return self::EXPIRED;
                }
            }
        }

        return self::FREE;
    }

    /**
     * True when the license is in any state that grants premium feature access.
     */
    public static function is_active($fs = null): bool
    {
        $s = self::status($fs);
        return $s === self::ACTIVE || $s === self::TRIAL;
    }

    /**
     * Render the standard "this control requires <plan>" admin notice.
     *
     * Used wherever a previously-enabled premium control becomes gated
     * after expiry or downgrade - the customer's saved configuration is
     * left intact, but the control is shown read-only with this notice
     * so they always know which plan unlocks it.
     */
    private static function call_bool($fs, string $method): bool
    {
        return method_exists($fs, $method) ? (bool) $fs->{$method}() : false;
    }

    public static function feature_required_notice(string $feature, string $required_plan = 'Pro'): string
    {
        $feature_safe = function_exists('esc_html')
            ? esc_html($feature)
            // @codeCoverageIgnoreStart
            : htmlspecialchars($feature, ENT_QUOTES, 'UTF-8');
            // @codeCoverageIgnoreEnd
        $plan_safe = function_exists('esc_html')
            ? esc_html($required_plan)
            // @codeCoverageIgnoreStart
            : htmlspecialchars($required_plan, ENT_QUOTES, 'UTF-8');
            // @codeCoverageIgnoreEnd

        return '<div class="notice notice-warning inline gt-feature-gated">'
             . '<p><strong>' . $feature_safe . '</strong> requires the <em>' . $plan_safe . '</em> plan.</p>'
             . '<p>Your saved configuration for this feature is preserved - re-activate a ' . $plan_safe . ' license to use it again.</p>'
             . '</div>';
    }
}
