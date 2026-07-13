<?php
/**
 * Records the activation/onboarding funnel so drop-off between milestones is
 * measurable for an install:
 *
 *   plugin_activated → builder_opened → table_created
 *     → table_published → first_inline_edit_saved → first_export
 *
 * For each step we store the first time it was reached and how many times it
 * happened. This is what lets the operator see "100 installs, 60 opened the
 * builder, 35 created a table, 12 published" - i.e. where users fall off.
 *
 * Privacy: storage is a single local wp_option (gt_activation_funnel). Nothing
 * leaves the site. Mirrors the {@see TC_AI_Usage_Tracker} pattern.
 *
 * @since 8.0.5
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Activation_Funnel {

    const OPTION_KEY = 'gt_activation_funnel';

    /** Canonical funnel steps, in order. Recording an off-list step is a no-op. */
    const STEPS = array(
        'plugin_activated',
        'builder_opened',
        'table_created',
        'table_published',
        'first_inline_edit_saved',
        'first_export',
    );

    /**
     * Record that a funnel step happened. Sets the first-seen timestamp once
     * and increments the count on every call. Unknown steps are ignored.
     */
    public static function record(string $step): void {
        if (!in_array($step, self::STEPS, true)) {
            return;
        }

        $data = self::read();
        if (!isset($data[$step]) || !is_array($data[$step])) {
            $data[$step] = array('first' => 0, 'count' => 0);
        }
        if (empty($data[$step]['first'])) {
            $data[$step]['first'] = time();
        }
        $data[$step]['count'] = (int) ($data[$step]['count'] ?? 0) + 1;

        update_option(self::OPTION_KEY, $data, false);
    }

    /** Has this step ever been reached? */
    public static function has(string $step): bool {
        $data = self::read();
        return !empty($data[$step]['first']);
    }

    /** First-seen Unix timestamp for a step, or null if never reached. */
    public static function first_seen(string $step): ?int {
        $data = self::read();
        return empty($data[$step]['first']) ? null : (int) $data[$step]['first'];
    }

    /** How many times a step has been recorded. */
    public static function count(string $step): int {
        $data = self::read();
        return (int) ($data[$step]['count'] ?? 0);
    }

    /**
     * Full funnel snapshot, every canonical step in order:
     *   [ step => ['reached' => bool, 'first' => int|null, 'count' => int], ... ]
     */
    public static function get_funnel(): array {
        $data = self::read();
        $out  = array();
        foreach (self::STEPS as $step) {
            $first        = empty($data[$step]['first']) ? null : (int) $data[$step]['first'];
            $out[$step]   = array(
                'reached' => $first !== null,
                'first'   => $first,
                'count'   => (int) ($data[$step]['count'] ?? 0),
            );
        }
        return $out;
    }

    private static function read(): array {
        $data = get_option(self::OPTION_KEY, array());
        return is_array($data) ? $data : array();
    }
}
