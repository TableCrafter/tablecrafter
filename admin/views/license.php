<?php
/**
 * License & Account Admin View
 *
 * @package GravityTables
 * @since 3.3.2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle license activation
if (isset($_POST['action']) && $_POST['action'] === 'activate_license') {
    if (wp_verify_nonce($_POST['nonce'], 'gt_license_activation')) {
        if (!class_exists('TC_License_Activator')) {
            require_once TC_PLUGIN_PATH . 'includes/class-tc-license-activator.php';
        }
        $license_key = sanitize_text_field($_POST['license_key'] ?? '');
        $fs          = function_exists('wgt_fs') ? wgt_fs() : null;
        $result      = TC_License_Activator::activate($license_key, $fs);
        echo TC_License_Activator::render_notice($result);

        if (($result['status'] ?? '') === 'success') {
            echo '<script>setTimeout(function() { window.location.href = "' . admin_url('admin.php?page=gravity-tables') . '"; }, 3000);</script>';
        }
    }
}

$fs = function_exists('wgt_fs') ? wgt_fs() : null;

// Use gt_is_premium() as the canonical check (consistent with the rest of the plugin),
// with a Freemius SDK fallback in case the helper isn't available yet.
$is_premium = ( function_exists('gt_is_premium') && gt_is_premium() )
              || ( $fs && method_exists($fs, 'is_premium') && $fs->is_premium() );

$is_trial  = $fs && method_exists($fs, 'is_trial')             && $fs->is_trial();
$can_trial = $fs && method_exists($fs, 'is_trial_utilized')
             && !$fs->is_trial()
             && !$fs->is_trial_utilized();
?>
<div class="wrap gt-license-wrap">
<!-- h1 must be the first element so WP admin notices appear before the hero, not inside it -->
<h1 class="gt-license-page-title"><?php esc_html_e('TableCrafter — License &amp; Account', 'tc-data-tables'); ?></h1>

<?php if ($is_premium): ?>

    <!-- ── Pro Active ── -->
    <div class="gt-license-hero gt-license-hero--pro">
        <span class="dashicons dashicons-yes-alt gt-license-hero-icon"></span>
        <div>
            <h2><?php esc_html_e('Pro License — Active', 'tc-data-tables'); ?></h2>
            <p><?php esc_html_e('You have full access to all TableCrafter Pro features.', 'tc-data-tables'); ?></p>
        </div>
    </div>

    <div class="gt-license-pro-info">
        <table class="gt-license-table">
            <tr>
                <th><?php esc_html_e('Status', 'tc-data-tables'); ?></th>
                <td><span class="gt-badge gt-badge--active"><?php esc_html_e('Active', 'tc-data-tables'); ?></span></td>
            </tr>
            <?php if (method_exists($fs, 'get_plan_title')): ?>
            <tr>
                <th><?php esc_html_e('Plan', 'tc-data-tables'); ?></th>
                <td><?php echo esc_html($fs->get_plan_title()); ?></td>
            </tr>
            <?php endif; ?>
            <?php
            $_lic = method_exists($fs, 'get_license') ? $fs->get_license() : null;
            $_exp = $_lic && method_exists($_lic, 'get_expiration') ? $_lic->get_expiration() : null;
            if ($_exp):
            ?>
            <tr>
                <th><?php esc_html_e('Renews', 'tc-data-tables'); ?></th>
                <td><?php echo esc_html(date('F j, Y', strtotime($_exp))); ?></td>
            </tr>
            <?php endif; ?>
        </table>
        <div class="gt-license-actions">
            <?php if (method_exists($fs, 'get_account_url')): ?>
            <a href="<?php echo esc_url($fs->get_account_url()); ?>" class="gt-btn gt-btn--outline"><?php esc_html_e('Manage License', 'tc-data-tables'); ?></a>
            <?php endif; ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=gravity-tables')); ?>" class="gt-btn gt-btn--primary"><?php esc_html_e('Create Tables', 'tc-data-tables'); ?></a>
        </div>
    </div>

<?php elseif ($is_trial): ?>

    <!-- ── Trial Active ── -->
    <div class="gt-license-hero gt-license-hero--trial">
        <span class="dashicons dashicons-clock gt-license-hero-icon"></span>
        <div>
            <h2><?php esc_html_e('Free Trial Active', 'tc-data-tables'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %d = days remaining */
                    esc_html__('%d days remaining — lock in Pro before your trial ends.', 'tc-data-tables'),
                    (int) (method_exists($fs, 'get_trial_days_left') ? $fs->get_trial_days_left() : 0)
                );
                ?>
            </p>
        </div>
    </div>

    <div class="gt-license-upsell-row">
        <div class="gt-license-card gt-license-card--featured">
            <div class="gt-license-card-badge"><?php esc_html_e('Recommended', 'tc-data-tables'); ?></div>
            <h2><?php esc_html_e('Upgrade to Pro', 'tc-data-tables'); ?></h2>
            <p><?php esc_html_e('Keep everything you\'ve built — frontend editing, advanced filters &amp; priority support.', 'tc-data-tables'); ?></p>
            <a href="<?php echo esc_url(method_exists($fs, 'get_upgrade_url') ? $fs->get_upgrade_url() : 'https://tablecrafter.com/#pricing'); ?>" class="gt-btn gt-btn--primary gt-btn--lg">
                <?php esc_html_e('Upgrade Now', 'tc-data-tables'); ?>
            </a>
        </div>
        <div class="gt-license-card">
            <h2><?php esc_html_e('Have a License Key?', 'tc-data-tables'); ?></h2>
            <p><?php esc_html_e('Already purchased? Enter your key to activate instantly.', 'tc-data-tables'); ?></p>
            <button type="button" class="gt-btn gt-btn--outline" onclick="document.getElementById('gt-license-form').style.display='block'; this.style.display='none';">
                <?php esc_html_e('Enter License Key', 'tc-data-tables'); ?>
            </button>
        </div>
    </div>

<?php else: ?>

    <!-- ── Free Plan — Main Sales Page ── -->
    <div class="gt-license-hero">
        <div class="gt-license-hero-inner">
            <span class="gt-license-hero-logo">TC</span>
            <div>
                <h2><?php esc_html_e('Unlock TableCrafter Pro', 'tc-data-tables'); ?></h2>
                <p><?php esc_html_e('Powerful, interactive WordPress data tables — without touching code.', 'tc-data-tables'); ?></p>
            </div>
        </div>
    </div>

    <div class="gt-license-upsell-row">

        <!-- Purchase (primary CTA) -->
        <div class="gt-license-card gt-license-card--featured">
            <div class="gt-license-card-badge"><?php esc_html_e('Most Popular', 'tc-data-tables'); ?></div>
            <h2><?php esc_html_e('Get TableCrafter Pro', 'tc-data-tables'); ?></h2>
            <div class="gt-price">
                <span class="gt-price-amt">$7.99</span><span class="gt-price-per"><?php esc_html_e('/mo', 'tc-data-tables'); ?></span>
                <span class="gt-price-note"><?php esc_html_e('billed annually · or a one-time lifetime license', 'tc-data-tables'); ?></span>
            </div>
            <p><?php esc_html_e('Everything in Free, plus the features your clients actually need:', 'tc-data-tables'); ?></p>
            <ul class="gt-license-features">
                <li><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Inline frontend editing (click to edit, Enter to save)', 'tc-data-tables'); ?></li>
                <li><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Bulk delete, bulk export &amp; bulk edit', 'tc-data-tables'); ?></li>
                <li><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Advanced filters + URL-shareable filter state', 'tc-data-tables'); ?></li>
                <li><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Add new entries from the frontend', 'tc-data-tables'); ?></li>
                <li><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Gravity Forms, WooCommerce, Airtable &amp; Notion sources', 'tc-data-tables'); ?></li>
                <li><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Conditional formatting, data bars &amp; role permissions', 'tc-data-tables'); ?></li>
                <li><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Priority email support', 'tc-data-tables'); ?></li>
            </ul>
            <a href="<?php echo esc_url($fs && method_exists($fs, 'get_upgrade_url') ? $fs->get_upgrade_url() : 'https://tablecrafter.com/#pricing'); ?>" class="gt-btn gt-btn--primary gt-btn--lg">
                <?php esc_html_e('Start Pro — 7-day free trial', 'tc-data-tables'); ?>
            </a>
            <p class="gt-license-small"><?php esc_html_e('No card required for the trial · Cancel anytime · Instant activation', 'tc-data-tables'); ?></p>
        </div>

        <div class="gt-license-sidebar">

            <?php if ($can_trial): ?>
            <!-- Free Trial -->
            <div class="gt-license-card gt-license-card--trial">
                <h3><?php esc_html_e('Try Pro Free for 7 Days', 'tc-data-tables'); ?></h3>
                <p><?php esc_html_e('No credit card required. Full Pro access, cancel any time.', 'tc-data-tables'); ?></p>
                <a href="<?php echo esc_url(method_exists($fs, 'get_trial_url') ? $fs->get_trial_url() : 'https://tablecrafter.com/#pricing'); ?>" class="gt-btn gt-btn--outline gt-btn--sm">
                    <?php esc_html_e('Start Free Trial', 'tc-data-tables'); ?>
                </a>
            </div>
            <?php endif; ?>

            <!-- Enter License Key -->
            <div class="gt-license-card gt-license-card--key">
                <h3><?php esc_html_e('Already Purchased?', 'tc-data-tables'); ?></h3>
                <p><?php esc_html_e('Enter your license key to activate Pro features instantly.', 'tc-data-tables'); ?></p>
                <button type="button" class="gt-btn gt-btn--outline gt-btn--sm" onclick="document.getElementById('gt-license-form').style.display='block'; this.parentElement.style.display='none';">
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php esc_html_e('Enter License Key', 'tc-data-tables'); ?>
                </button>
            </div>

        </div><!-- .gt-license-sidebar -->
    </div><!-- .gt-license-upsell-row -->

<?php endif; ?>

<!-- License Activation Form (hidden by default) -->
<div id="gt-license-form" class="gt-license-form-wrap" style="display:none;">
    <h3><?php esc_html_e('Activate License Key', 'tc-data-tables'); ?></h3>
    <form method="post">
        <?php wp_nonce_field('gt_license_activation', 'nonce'); ?>
        <input type="hidden" name="action" value="activate_license">
        <div class="gt-license-key-row">
            <input type="text"
                   id="license_key"
                   name="license_key"
                   class="gt-license-key-input"
                   placeholder="<?php esc_attr_e('Paste your license key here…', 'tc-data-tables'); ?>"
                   required>
            <button type="submit" class="gt-btn gt-btn--primary"><?php esc_html_e('Activate', 'tc-data-tables'); ?></button>
            <button type="button" class="gt-btn gt-btn--ghost" onclick="document.getElementById('gt-license-form').style.display='none';"><?php esc_html_e('Cancel', 'tc-data-tables'); ?></button>
        </div>
        <p class="gt-license-small"><?php esc_html_e('Your key was emailed after purchase. Check spam if you can\'t find it.', 'tc-data-tables'); ?></p>
    </form>
</div>

<!-- Account Information -->
<?php
$_fs_user = $fs && method_exists($fs, 'is_registered') && $fs->is_registered() && method_exists($fs, 'get_user')
    ? $fs->get_user()
    : null;
if ($_fs_user):
?>
<div class="gt-license-account">
    <h3><?php esc_html_e('Account', 'tc-data-tables'); ?></h3>
    <table class="gt-license-table">
        <tr>
            <th><?php esc_html_e('Email', 'tc-data-tables'); ?></th>
            <td><?php echo esc_html($_fs_user->email ?? ''); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('User ID', 'tc-data-tables'); ?></th>
            <td><?php echo esc_html($_fs_user->id ?? ''); ?></td>
        </tr>
    </table>
    <?php if (method_exists($fs, 'get_account_url')): ?>
    <a href="<?php echo esc_url($fs->get_account_url()); ?>" class="gt-btn gt-btn--outline gt-btn--sm"><?php esc_html_e('Manage Account', 'tc-data-tables'); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- .gt-license-wrap -->

<style>
:root {
    --tc: #0d9488;
    --tc-dark: #0f766e;
    --tc-light: #f0fdfa;
    --tc-border: #99f6e4;
}

/* ── Layout ── */
.gt-license-wrap {
    max-width: 900px;
}
.gt-license-upsell-row {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 20px;
    margin-top: 24px;
    align-items: start;
}
.gt-license-sidebar {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ── Hero ── */
.gt-license-hero {
    background: linear-gradient(135deg, var(--tc) 0%, var(--tc-dark) 100%);
    border-radius: 10px;
    padding: 26px 30px;
    margin-top: 8px;
    color: #fff;
}
.gt-license-hero h1,
.gt-license-hero h2 {
    color: #fff;
    font-size: 22px;
    margin: 0 0 5px;
}

/* Page-level title — visible for accessibility & WP notice placement, styled to blend */
.gt-license-page-title {
    font-size: 14px;
    font-weight: 400;
    color: #50575e;
    margin: 8px 0 0;
    padding: 0;
}
.gt-license-hero p {
    color: rgba(255,255,255,.88);
    margin: 0;
    font-size: 14px;
}
.gt-license-hero-inner {
    display: flex;
    align-items: center;
    gap: 18px;
}
.gt-license-hero-logo {
    background: rgba(255,255,255,.18);
    border-radius: 10px;
    width: 50px;
    height: 50px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    font-weight: 800;
    letter-spacing: -1px;
    flex-shrink: 0;
}
.gt-license-hero--pro,
.gt-license-hero--trial {
    display: flex;
    align-items: center;
    gap: 18px;
}
.gt-license-hero--pro .gt-license-hero-icon,
.gt-license-hero--trial .gt-license-hero-icon {
    font-size: 34px;
    width: 34px;
    height: 34px;
    color: rgba(255,255,255,.9);
    flex-shrink: 0;
}

/* ── Cards ── */
.gt-license-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 22px 20px;
    position: relative;
}
.gt-license-card h2 {
    font-size: 17px;
    margin: 0 0 9px;
    color: #111827;
}
.gt-license-card h3 {
    font-size: 14px;
    margin: 0 0 7px;
    color: #111827;
}
.gt-license-card p {
    color: #6b7280;
    font-size: 13px;
    margin: 0 0 14px;
    line-height: 1.55;
}
.gt-license-card--featured {
    border-color: var(--tc);
    box-shadow: 0 0 0 2px rgba(13,148,136,.12), 0 4px 16px rgba(13,148,136,.08);
    padding-top: 34px;
}
.gt-license-card--trial {
    background: var(--tc-light);
    border-color: var(--tc-border);
}
.gt-license-card--key {
    background: #fafafa;
}
.gt-license-card-badge {
    position: absolute;
    top: -1px;
    left: 18px;
    background: var(--tc);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 3px 10px;
    border-radius: 0 0 6px 6px;
}

/* ── Feature list ── */
.gt-license-features {
    list-style: none;
    margin: 0 0 20px;
    padding: 0;
}
.gt-license-features li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 13px;
    color: #374151;
    margin: 6px 0;
    line-height: 1.5;
}
.gt-license-features .dashicons {
    color: var(--tc);
    font-size: 17px;
    width: 17px;
    height: 17px;
    flex-shrink: 0;
    margin-top: 1px;
}

/* ── Price ── */
.gt-price {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 0 14px;
}
.gt-price-amt {
    font-size: 34px;
    font-weight: 800;
    color: #111827;
    line-height: 1;
}
.gt-price-per {
    font-size: 15px;
    color: #6b7280;
    font-weight: 600;
}
.gt-price-note {
    font-size: 12px;
    color: #9ca3af;
    flex-basis: 100%;
    margin-top: 3px;
}

/* ── Buttons ── */
.gt-btn {
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    border: none;
    padding: 9px 18px;
    transition: background .15s, box-shadow .15s, color .15s;
    line-height: 1.2;
    white-space: normal;
    text-align: center;
    max-width: 100%;
}
.gt-btn--primary {
    background: var(--tc);
    color: #fff !important;
}
.gt-btn--primary:hover {
    background: var(--tc-dark);
    color: #fff !important;
    box-shadow: 0 2px 8px rgba(13,148,136,.3);
}
.gt-btn--outline {
    background: transparent;
    color: var(--tc) !important;
    border: 1.5px solid var(--tc);
}
.gt-btn--outline:hover {
    background: var(--tc-light);
    color: var(--tc-dark) !important;
}
.gt-btn--ghost {
    background: transparent;
    color: #6b7280 !important;
    border: 1.5px solid #d1d5db;
}
.gt-btn--ghost:hover {
    background: #f9fafb;
    color: #374151 !important;
}
.gt-btn--lg {
    padding: 13px 20px;
    font-size: 14px;
    width: 100%;
    justify-content: center;
    border-radius: 8px;
}
.gt-btn--sm {
    padding: 7px 13px;
    font-size: 12px;
}

/* ── License key form ── */
.gt-license-form-wrap {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px 22px;
    margin-top: 20px;
    max-width: 700px;
}
.gt-license-form-wrap h3 {
    margin: 0 0 12px;
    font-size: 14px;
}
.gt-license-key-row {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.gt-license-key-input {
    flex: 1;
    min-width: 200px;
    height: 36px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 0 12px;
    font-size: 13px;
    color: #111827;
    background: #fff;
}
.gt-license-key-input:focus {
    outline: none;
    border-color: var(--tc);
    box-shadow: 0 0 0 2px rgba(13,148,136,.12);
}

/* ── Pro info table ── */
.gt-license-pro-info {
    margin-top: 20px;
    max-width: 560px;
}
.gt-license-table {
    border-collapse: collapse;
    width: 100%;
    margin-bottom: 16px;
}
.gt-license-table th,
.gt-license-table td {
    padding: 9px 10px;
    font-size: 13px;
    border-bottom: 1px solid #f3f4f6;
    text-align: left;
}
.gt-license-table th {
    color: #6b7280;
    font-weight: 500;
    width: 120px;
}
.gt-license-table td {
    color: #111827;
}
.gt-license-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

/* ── Badge ── */
.gt-badge {
    display: inline-block;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    padding: 2px 9px;
}
.gt-badge--active {
    background: #dcfce7;
    color: #15803d;
}

/* ── Account ── */
.gt-license-account {
    margin-top: 26px;
    padding-top: 20px;
    border-top: 1px solid #f3f4f6;
    max-width: 560px;
}
.gt-license-account h3 {
    font-size: 12px;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin: 0 0 10px;
}

/* ── Small text ── */
.gt-license-small {
    font-size: 11px;
    color: #9ca3af;
    margin: 10px 0 0;
    line-height: 1.5;
}

/* ── Responsive ── */
@media (max-width: 782px) {
    .gt-license-upsell-row {
        grid-template-columns: 1fr;
    }
    .gt-license-sidebar {
        flex-direction: row;
        flex-wrap: wrap;
    }
    .gt-license-sidebar .gt-license-card {
        flex: 1;
        min-width: 220px;
    }
    .gt-btn--lg {
        width: auto;
    }
}
</style>
