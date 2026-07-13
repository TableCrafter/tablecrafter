<?php
/**
 * TC_Sticky_Rows_Service
 *
 * Issue #544 - slice 1 of 3. Pure config helper for the multi-row
 * frozen-header feature. Existing `sticky_header` boolean already
 * ships end-to-end (single-row sticky); this service adds the
 * `frozen_top_rows` count knob (default 1, max 10) so multi-row
 * headers can be frozen too.
 *
 * Slice 2 wires the new key into the admin UI + validation +
 * sanitization save chain (mirroring how `freeze_first_column`
 * was added in #534). Slice 3 ships the per-N CSS:
 * `.gt-table thead tr:nth-child(<= N)` gets `position: sticky`
 * with accumulated `top` offsets and a z-index ladder so the
 * freeze pane combines correctly with `gt-freeze-first-column`
 * (#534) for an Excel-style top-left freeze.
 *
 * @since 4.7.46
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Sticky_Rows_Service {

    private const MIN_ROWS = 1;
    private const MAX_ROWS = 10;
    private const DEFAULT_ROWS = 1;

    public static function defaults(): array {
        return [
            'sticky_header'   => false,
            'frozen_top_rows' => self::DEFAULT_ROWS,
        ];
    }

    public static function normalize(array $settings): array {
        $defaults = self::defaults();
        $out = $defaults;

        if (array_key_exists('sticky_header', $settings)) {
            $out['sticky_header'] = (bool) $settings['sticky_header'];
        }

        if (array_key_exists('frozen_top_rows', $settings)) {
            $raw = $settings['frozen_top_rows'];
            // Floats floor to int (matches PHP's (int) cast). Strings /
            // null / arrays / objects fall back to default.
            if (is_int($raw) || is_float($raw)) {
                $n = (int) $raw;
                $out['frozen_top_rows'] = ($n >= self::MIN_ROWS && $n <= self::MAX_ROWS)
                    ? $n
                    : self::DEFAULT_ROWS;
            } else {
                $out['frozen_top_rows'] = self::DEFAULT_ROWS;
            }
        }

        return $out;
    }

    public static function is_enabled(array $settings): bool {
        return !empty($settings['sticky_header']);
    }

    public static function frozen_count(array $settings): int {
        if (!self::is_enabled($settings)) {
            return 0;
        }
        $n = isset($settings['frozen_top_rows']) ? (int) $settings['frozen_top_rows'] : self::DEFAULT_ROWS;
        if ($n < self::MIN_ROWS || $n > self::MAX_ROWS) {
            // @codeCoverageIgnoreStart
            return self::DEFAULT_ROWS;
            // @codeCoverageIgnoreEnd
        }
        return $n;
    }
}
