<?php
/**
 * Records per-day AI provider call usage so the operator can see what's
 * being spent and so a daily ceiling can be enforced later.
 *
 * Storage: gt_ai_usage option, shape:
 *   [
 *     'YYYY-MM-DD' => [
 *       'openai' => ['calls' => N, 'tokens_in' => I, 'tokens_out' => O],
 *       ...
 *     ],
 *     ...
 *   ]
 *
 * @since 4.6.9
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_AI_Usage_Tracker {

    const OPTION_KEY = 'gt_ai_usage';

    public static function record(string $provider_id, int $tokens_in, int $tokens_out): void {
        $today = self::today();
        $usage = get_option(self::OPTION_KEY, []);
        if (!is_array($usage)) {
            // @codeCoverageIgnoreStart
            $usage = [];
            // @codeCoverageIgnoreEnd
        }
        if (!isset($usage[$today]) || !is_array($usage[$today])) {
            $usage[$today] = [];
        }
        if (!isset($usage[$today][$provider_id]) || !is_array($usage[$today][$provider_id])) {
            $usage[$today][$provider_id] = ['calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0];
        }

        $usage[$today][$provider_id]['calls']      += 1;
        $usage[$today][$provider_id]['tokens_in']  += max(0, $tokens_in);
        $usage[$today][$provider_id]['tokens_out'] += max(0, $tokens_out);

        update_option(self::OPTION_KEY, $usage, false);
    }

    public static function get_today_count(string $provider_id): int {
        $today = self::today();
        $usage = get_option(self::OPTION_KEY, []);
        if (!is_array($usage) || !isset($usage[$today][$provider_id]['calls'])) {
            return 0;
        }
        return (int) $usage[$today][$provider_id]['calls'];
    }

    private static function today(): string {
        return gmdate('Y-m-d');
    }
}
