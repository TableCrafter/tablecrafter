<?php
/**
 * Shows a one-time, dismissible "leave a review" admin notice AFTER a genuine
 * success moment - a table went live, an inline edit saved, or data was
 * exported (read from {@see TC_Activation_Funnel}). Never on a timer.
 *
 * WordPress.org ranking is heavily review-weighted, so asking at the moment a
 * user just got value is the highest-yield, least-annoying ask. The user can
 * leave a review, snooze ("maybe later"), or dismiss forever - and we never
 * nag again after dismiss/review.
 *
 * State: a single local gt_review_prompt option:
 *   [ 'status' => 'pending'|'dismissed'|'reviewed', 'snooze_until' => int ]
 *
 * @since 8.0.6
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Review_Prompt {

    const OPTION_KEY = 'gt_review_prompt';
    const REVIEW_URL = 'https://wordpress.org/support/plugin/tablecrafter-wp-data-tables/reviews/#new-post';
    const SNOOZE_DAYS = 14;

    /** Funnel milestones that count as "the user got real value". */
    const SUCCESS_STEPS = array('table_published', 'first_inline_edit_saved', 'first_export');

    /**
     * Pure decision: should the notice show?
     *
     * @param array $state  The stored prompt state.
     * @param array $funnel A TC_Activation_Funnel::get_funnel() snapshot.
     * @param int   $now    Current Unix time.
     */
    public static function should_show(array $state, array $funnel, int $now): bool {
        $status = $state['status'] ?? 'pending';
        if ($status === 'dismissed' || $status === 'reviewed') {
            return false;
        }
        if (($state['snooze_until'] ?? 0) > $now) {
            return false;
        }
        foreach (self::SUCCESS_STEPS as $step) {
            if (!empty($funnel[$step]['reached'])) {
                return true;
            }
        }
        return false;
    }

    /** Pure transition for a user action; returns the new state. */
    public static function apply_action(array $state, string $action, int $now): array {
        switch ($action) {
            case 'reviewed':
                $state['status'] = 'reviewed';
                break;
            case 'dismiss':
                $state['status'] = 'dismissed';
                break;
            case 'later':
                $state['status']       = 'pending';
                $state['snooze_until'] = $now + (self::SNOOZE_DAYS * DAY_IN_SECONDS);
                break;
        }
        return $state;
    }

    // --- WordPress wiring ----------------------------------------------------

    public function register(): void {
        add_action('admin_notices', array($this, 'maybe_render'));
        add_action('admin_post_tc_review_prompt', array($this, 'handle_action'));
    }

    public function maybe_render(): void {
        if (!current_user_can('install_plugins')) {
            return;
        }
        if (!class_exists('TC_Activation_Funnel')) {
            // @codeCoverageIgnoreStart -- free-build-only fallback; TC_Activation_Funnel is autoloaded in this build.
            return;
            // @codeCoverageIgnoreEnd
        }
        $state  = $this->read();
        $funnel = TC_Activation_Funnel::get_funnel();
        if (!self::should_show($state, $funnel, time())) {
            return;
        }

        $act = function (string $a): string {
            return wp_nonce_url(
                admin_url('admin-post.php?action=tc_review_prompt&do=' . $a),
                'tc_review_prompt'
            );
        };
        ?>
        <div class="notice notice-info is-dismissible tc-review-prompt">
            <p style="font-size:13px;">
                <?php esc_html_e('Nice - TableCrafter just did its job. If it helped, a quick review really helps other WordPress users find it.', 'tc-data-tables'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url(self::REVIEW_URL); ?>"
                   class="button button-primary"
                   target="_blank" rel="noopener"
                   onclick="window.location.href='<?php echo esc_url($act('reviewed')); ?>';">
                    <?php esc_html_e('Leave a review', 'tc-data-tables'); ?>
                </a>
                <a href="<?php echo esc_url($act('later')); ?>" class="button">
                    <?php esc_html_e('Maybe later', 'tc-data-tables'); ?>
                </a>
                <a href="<?php echo esc_url($act('dismiss')); ?>" class="button-link" style="margin-left:8px;">
                    <?php esc_html_e('Don\'t show again', 'tc-data-tables'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function handle_action(): void {
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('Insufficient permissions', 'tc-data-tables'));
        }
        check_admin_referer('tc_review_prompt');

        $do    = isset($_GET['do']) ? sanitize_key(wp_unslash($_GET['do'])) : '';
        $state = self::apply_action($this->read(), $do, time());
        update_option(self::OPTION_KEY, $state, false);

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=tablecrafter-dashboard'));
        // @codeCoverageIgnoreStart
        exit;
        // @codeCoverageIgnoreEnd
    }

    private function read(): array {
        $state = get_option(self::OPTION_KEY, array());
        return is_array($state) ? $state : array();
    }
}
