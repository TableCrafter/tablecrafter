<?php
/**
 * Comparison / Pricing table template (#211).
 *
 * Variables available (set by TC_Shortcode::render_comparison_table or
 * directly when called from the shortcode handler):
 *   $atts            array  — merged shortcode/table attributes
 *   $table_settings  array  — saved builder settings
 *   $table_id        int    — database table ID
 *
 * Expected settings keys (all optional with sensible defaults):
 *   columns[]        array  — per-column: label, recommended (bool), price,
 *                             billing_period ('monthly'|'yearly'), cta_label, cta_url
 *   feature_rows[]   array  — per-row: label, values[] (true|false|string per column)
 *   currency_symbol  string — defaults to '$'
 *   show_billing_toggle bool — show monthly/yearly switch (default true)
 */

if (!defined('ABSPATH')) {
    exit;
}

$columns     = isset($table_settings['comparison_columns'])    ? (array) $table_settings['comparison_columns']    : [];
$features    = isset($table_settings['comparison_features'])   ? (array) $table_settings['comparison_features']   : [];
$currency    = isset($table_settings['currency_symbol'])       ? $table_settings['currency_symbol']               : '$';
$show_toggle = isset($table_settings['show_billing_toggle'])   ? (bool)  $table_settings['show_billing_toggle']   : true;

$table_id_attr = 'gt-comparison-' . absint($table_id ?? 0);
?>
<div class="gt-comparison-table-wrap" id="<?php echo esc_attr($table_id_attr); ?>">

    <?php if ($show_billing_toggle && !empty($columns)): ?>
    <div class="gt-billing-toggle" role="group" aria-label="<?php esc_attr_e('Billing period', 'tc-data-tables'); ?>">
        <button type="button" class="gt-billing-btn gt-billing-monthly active"
                aria-pressed="true"><?php esc_html_e('Monthly', 'tc-data-tables'); ?></button>
        <button type="button" class="gt-billing-btn gt-billing-yearly"
                aria-pressed="false"><?php esc_html_e('Yearly', 'tc-data-tables'); ?></button>
    </div>
    <?php endif; ?>

    <table class="gt-comparison-table">
        <thead>
            <tr>
                <th class="gt-feature-label-col"></th>
                <?php foreach ($columns as $col): ?>
                <th class="gt-comparison-col<?php echo !empty($col['recommended']) ? ' gt-col-recommended' : ''; ?>">
                    <?php if (!empty($col['recommended'])): ?>
                    <span class="gt-recommended-badge"><?php esc_html_e('Recommended', 'tc-data-tables'); ?></span>
                    <?php endif; ?>
                    <?php echo esc_html($col['label'] ?? ''); ?>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>

        <tbody>
            <!-- Pricing row -->
            <tr class="gt-pricing-row">
                <td class="gt-feature-label"><?php esc_html_e('Price', 'tc-data-tables'); ?></td>
                <?php foreach ($columns as $col): ?>
                <td class="gt-price-cell<?php echo !empty($col['recommended']) ? ' gt-col-recommended' : ''; ?>">
                    <?php
                    $monthly = isset($col['price_monthly']) ? floatval($col['price_monthly']) : (isset($col['price']) ? floatval($col['price']) : 0);
                    $yearly  = isset($col['price_yearly'])  ? floatval($col['price_yearly'])  : round($monthly * 10, 2);
                    ?>
                    <span class="gt-price-monthly">
                        <span class="gt-currency"><?php echo esc_html($currency); ?></span><span class="gt-amount"><?php echo esc_html(number_format($monthly, 0)); ?></span>
                        <span class="gt-billing-period"><?php esc_html_e('/mo', 'tc-data-tables'); ?></span>
                    </span>
                    <span class="gt-price-yearly" style="display:none">
                        <span class="gt-currency"><?php echo esc_html($currency); ?></span><span class="gt-amount"><?php echo esc_html(number_format($yearly, 0)); ?></span>
                        <span class="gt-billing-period"><?php esc_html_e('/yr', 'tc-data-tables'); ?></span>
                    </span>
                </td>
                <?php endforeach; ?>
            </tr>

            <!-- Feature rows -->
            <?php foreach ($features as $feature): ?>
            <tr class="gt-feature-row">
                <td class="gt-feature-label"><?php echo esc_html($feature['label'] ?? ''); ?></td>
                <?php foreach ($columns as $ci => $col): ?>
                <td class="<?php echo !empty($col['recommended']) ? 'gt-col-recommended' : ''; ?>">
                    <?php
                    $val = $feature['values'][$ci] ?? null;
                    if ($val === true || $val === 'true' || $val === '1') {
                        echo '<span class="gt-check" aria-label="' . esc_attr__('Included', 'tc-data-tables') . '">&#10003;</span>';
                    } elseif ($val === false || $val === 'false' || $val === '0' || $val === null) {
                        echo '<span class="gt-cross" aria-label="' . esc_attr__('Not included', 'tc-data-tables') . '">&#8211;</span>';
                    } else {
                        echo esc_html($val);
                    }
                    ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>

            <!-- CTA button row -->
            <tr class="gt-cta-row">
                <td></td>
                <?php foreach ($columns as $col): ?>
                <td class="<?php echo !empty($col['recommended']) ? 'gt-col-recommended' : ''; ?>">
                    <?php if (!empty($col['cta_url'])): ?>
                    <a href="<?php echo esc_url($col['cta_url']); ?>"
                       class="gt-cta-btn<?php echo !empty($col['recommended']) ? ' gt-cta-btn-primary' : ''; ?>">
                        <?php echo esc_html($col['cta_label'] ?? __('Get started', 'tc-data-tables')); ?>
                    </a>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>

</div>

<script>
(function () {
    var wrap = document.getElementById(<?php echo wp_json_encode($table_id_attr); ?>);
    if (!wrap) { return; }

    var btnMonthly = wrap.querySelector('.gt-billing-monthly');
    var btnYearly  = wrap.querySelector('.gt-billing-yearly');

    function switchBilling(period) {
        var monthly = period === 'monthly';
        wrap.querySelectorAll('.gt-price-monthly').forEach(function (el) { el.style.display = monthly ? '' : 'none'; });
        wrap.querySelectorAll('.gt-price-yearly').forEach(function  (el) { el.style.display = monthly ? 'none' : ''; });
        if (btnMonthly) { btnMonthly.classList.toggle('active', monthly);  btnMonthly.setAttribute('aria-pressed', monthly ? 'true' : 'false'); }
        if (btnYearly)  { btnYearly.classList.toggle('active',  !monthly); btnYearly.setAttribute('aria-pressed',  monthly ? 'false' : 'true'); }
    }

    if (btnMonthly) { btnMonthly.addEventListener('click', function () { switchBilling('monthly'); }); }
    if (btnYearly)  { btnYearly.addEventListener('click',  function () { switchBilling('yearly');  }); }
}());
</script>
