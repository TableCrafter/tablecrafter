<?php
/**
 * TC_Time_Field_Renderer
 *
 * Issue #818 (child of #793). GF `time` fields store sub-inputs:
 *   N.1 = hour   (1..12 in 12h forms, 0..23 in 24h forms)
 *   N.2 = minute (0..59)
 *   N.3 = am/pm  ("am" / "pm" — present only when the form is 12h)
 *
 * Without this renderer, the cell shows the empty bare slot.
 *
 * @since 4.84.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Time_Field_Renderer {

    /**
     * Pull the three sub-inputs. Returns null for any missing piece.
     *
     * @return array{hour:?int,minute:?int,meridian:?string}
     */
    public static function sub_input_values(array $entry, string $field_id): array {
        $h_raw = $entry[$field_id . '.1'] ?? '';
        $m_raw = $entry[$field_id . '.2'] ?? '';
        $p_raw = $entry[$field_id . '.3'] ?? '';
        return [
            'hour'     => is_numeric($h_raw) ? (int) $h_raw : null,
            'minute'   => is_numeric($m_raw) ? (int) $m_raw : null,
            'meridian' => is_string($p_raw) ? strtolower(trim((string) $p_raw)) : null,
        ];
    }

    /**
     * Compose a readable time. Default 12-hour with am/pm (or just
     * "HH:MM" when no meridian sub-input present). Filter
     * `gt_time_field_format` accepts `'12h'` or `'24h'`.
     *
     * Empty / unparseable → ''.
     */
    public static function render_text(array $entry, string $field_id, ?string $format = null): string {
        $v = self::sub_input_values($entry, $field_id);
        if ($v['hour'] === null && $v['minute'] === null) {
            return '';
        }

        if ($format === null) {
            $format = '12h';
            if (function_exists('apply_filters')) {
                $filtered = apply_filters('gt_time_field_format', $format);
                if (is_string($filtered) && in_array($filtered, ['12h', '24h'], true)) {
                    $format = $filtered;
                }
            }
        }

        $hour     = $v['hour']     ?? 0;
        $minute   = $v['minute']   ?? 0;
        $meridian = $v['meridian'] ?? '';

        if ($format === '24h') {
            $hour_24 = self::to_24h($hour, $meridian);
            return sprintf('%02d:%02d', $hour_24, $minute);
        }

        // 12h format. Normalise hour to 1..12 range.
        $hour_12 = $hour;
        if ($meridian === '') {
            // 24h-only form → derive meridian for display.
            $meridian = $hour >= 12 ? 'pm' : 'am';
            $hour_12  = ($hour % 12) ?: 12;
        } else {
            $hour_12 = ($hour_12 % 12) ?: 12;
        }
        return sprintf('%d:%02d %s', $hour_12, $minute, strtolower($meridian));
    }

    /**
     * Sort key: minutes-since-midnight. Empty cells get a sentinel
     * (1500) that sorts after any real time (24h × 60 = 1440 → use
     * 1500 to leave headroom).
     */
    public static function sort_key(array $entry, string $field_id): int {
        $v = self::sub_input_values($entry, $field_id);
        if ($v['hour'] === null && $v['minute'] === null) {
            return 1500;
        }
        $hour_24 = self::to_24h($v['hour'] ?? 0, $v['meridian'] ?? '');
        return ($hour_24 * 60) + ($v['minute'] ?? 0);
    }

    /**
     * Convert a (hour, meridian) pair to a 0..23 hour.
     */
    private static function to_24h(int $hour, string $meridian): int {
        $meridian = strtolower($meridian);
        if ($meridian === '') {
            // No meridian → assume the hour is already 24h.
            // @codeCoverageIgnoreStart
            return max(0, min(23, $hour));
            // @codeCoverageIgnoreEnd
        }
        // 12h form. Normalise edge: 12am = 00, 12pm = 12.
        $h12 = $hour % 12;
        if ($meridian === 'pm') {
            return $h12 + 12;
        }
        return $h12;
    }
}
