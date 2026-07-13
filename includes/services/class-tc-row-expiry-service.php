<?php
/**
 * TC_Row_Expiry_Service
 *
 * Issue #501 - slice 1 of 3. Pure date-math helper that decides
 * whether a row's expiry date has passed, and which mode the table
 * operator picked (hide / strikethrough / move-to-bottom / upcoming
 * only). Future slices add:
 *
 *   - Slice 2: admin settings UI ('Expiry column' dropdown,
 *     'Behavior' selector, optional 'Grace period' /
 *     'Show only upcoming' toggles), persisted via the existing
 *     settings save chain.
 *   - Slice 3: AJAX / render integration - frontend query path
 *     filters or annotates rows according to the per-table setting.
 *
 * The service is timezone-aware (defaults to UTC), supports a grace
 * period (rows expire N days after the configured date instead of
 * immediately), and dispatches on mode strings:
 *
 *   - 'hide'           → expired rows are not rendered.
 *   - 'strikethrough'  → expired rows still render; caller adds the
 *                        `gt-row-expired` CSS class for visual styling.
 *   - 'move_bottom'    → expired rows still render; caller demotes
 *                        them in the sort order.
 *   - 'upcoming_only'  → INVERSE: only future-dated rows render.
 *                        Useful for sneak-peek / pre-order tables.
 *
 * Unknown modes default to render-true (defensive - better to over-show
 * than silently hide if the per-table setting got garbled).
 *
 * @since 4.7.30
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Row_Expiry_Service {

    /**
     * True iff `$value` parses to a date strictly earlier than
     * `$now - $grace_days days` in the supplied timezone. Empty /
     * null / unparseable values return false (don't expire what we
     * can't read).
     */
    public static function is_expired(
        $value,
        ?DateTimeImmutable $now = null,
        ?DateTimeZone $tz = null,
        int $grace_days = 0
    ): bool {
        if ($value === null || $value === '' || !is_string($value)) {
            return false;
        }
        $tz = $tz ?? new DateTimeZone('UTC');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $now = $now->setTimezone($tz);
        try {
            $expiry = new DateTimeImmutable($value, $tz);
        } catch (Exception $e) {
            return false;
        }
        $expiry = $expiry->setTimezone($tz);
        // Grace: shift the comparison anchor BACK by $grace_days. A row
        // expired 3 days ago with grace 7 is still considered fresh
        // because the comparison anchor is 7 days earlier than now.
        if ($grace_days > 0) {
            $now = $now->modify('-' . $grace_days . ' days');
        }
        // Strict less-than: exactly equal is not expired (today's row
        // remains visible all of today).
        return $expiry < $now;
    }

    /**
     * Whether a row with `$value` in the expiry column should render
     * given the operator's chosen `$mode`.
     */
    public static function should_render(
        $value,
        string $mode,
        ?DateTimeImmutable $now = null,
        ?DateTimeZone $tz = null,
        int $grace_days = 0
    ): bool {
        $expired = self::is_expired($value, $now, $tz, $grace_days);
        switch ($mode) {
            case 'hide':
                return !$expired;
            case 'strikethrough':
            case 'move_bottom':
                return true;
            case 'upcoming_only':
                // Inverse: hide rows whose date is in the past OR equal to now.
                if ($value === null || $value === '' || !is_string($value)) {
                    // @codeCoverageIgnoreStart
                    return true;  // unparseable → render (defensive)
                    // @codeCoverageIgnoreEnd
                }
                $tz = $tz ?? new DateTimeZone('UTC');
                $now = $now ?? new DateTimeImmutable('now', $tz);
                try {
                    $d = (new DateTimeImmutable($value, $tz))->setTimezone($tz);
                // @codeCoverageIgnoreStart
                } catch (Exception $e) {
                    return true;
                // @codeCoverageIgnoreEnd
                }
                return $d > $now->setTimezone($tz);
            default:
                return true;  // unknown mode: defensive show
        }
    }

    /**
     * CSS class to add to an expired row in `strikethrough` mode.
     * Returns null in every other case so the caller can branch
     * cleanly. Slice 3 wires this into the render path.
     */
    public static function row_class_for_mode(string $mode, bool $expired): ?string {
        if ($mode === 'strikethrough' && $expired) {
            return 'gt-row-expired';
        }
        return null;
    }
}
