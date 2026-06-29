<?php
/**
 * TC_Consent_Field_Renderer
 *
 * Issue #820 (child of #793 follow-up). GF `consent` fields store:
 *   N.1 = accepted (1 / empty)
 *   N.2 = the consent label text the user agreed to
 *   N.3 = revision id (snapshot of the wording at submit time)
 *
 * Without this renderer the cell shows the empty bare slot.
 *
 * Render shape (default):
 *   "Accepted: \"Privacy policy\" (rev #3)"   when consent was given
 *   "Not accepted"                           when N.1 is empty
 *   ""                                       when the entire entry has no sub-inputs
 *
 * @since 4.85.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Consent_Field_Renderer {

    /**
     * Pull the three sub-inputs. Returns null for missing pieces.
     *
     * @return array{accepted:?bool,label:?string,revision:?string}
     */
    public static function sub_input_values(array $entry, string $field_id): array {
        $a_raw = $entry[$field_id . '.1'] ?? '';
        $l_raw = $entry[$field_id . '.2'] ?? '';
        $r_raw = $entry[$field_id . '.3'] ?? '';
        $accepted = null;
        if ($a_raw !== '') {
            // GF stores '1' for accepted; anything else (including '0', empty) is not-accepted.
            $accepted = (string) $a_raw === '1';
        }
        return [
            'accepted' => $accepted,
            'label'    => is_string($l_raw) && trim($l_raw) !== '' ? trim((string) $l_raw) : null,
            'revision' => is_string($r_raw) && trim($r_raw) !== '' ? trim((string) $r_raw) : null,
        ];
    }

    /**
     * Compose a readable summary. Filter `gt_consent_field_format`
     * lets themes pick alternates (e.g. terser "✓ Privacy policy"
     * for compact tables). Output is plain text — caller esc_htmls.
     */
    public static function render_text(array $entry, string $field_id): string {
        $v = self::sub_input_values($entry, $field_id);

        // No sub-inputs at all → empty (caller emits dash).
        if ($v['accepted'] === null && $v['label'] === null && $v['revision'] === null) {
            return '';
        }

        // Explicitly not accepted.
        if ($v['accepted'] === false) {
            return 'Not accepted';
        }

        // Accepted (or unknown-accepted with a label) — compose.
        $parts = ['Accepted'];
        if ($v['label'] !== null) {
            $parts[0] = 'Accepted: "' . $v['label'] . '"';
        }
        if ($v['revision'] !== null) {
            $parts[] = '(rev #' . $v['revision'] . ')';
        }
        $default = implode(' ', $parts);

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('gt_consent_field_format', $default, $v);
            if (is_string($filtered) && $filtered !== '') {
                return $filtered;
            }
        }
        // @codeCoverageIgnoreStart
        return $default;
        // @codeCoverageIgnoreEnd
    }
}
