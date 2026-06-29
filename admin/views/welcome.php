<?php
/**
 * Welcome / onboarding view (#2064).
 *
 * @package TableCrafter
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap gt-welcome">
    <h1><?php esc_html_e('Welcome to TableCrafter', 'tc-data-tables'); ?></h1>

    <p style="font-size:15px;max-width:760px;">
        <?php esc_html_e('TableCrafter turns any data source into a beautiful, responsive WordPress table — Gravity Forms entries, JSON / REST APIs, CSV files, Google Sheets, Airtable, Notion, WooCommerce products and external databases. Add a shortcode (or the TableCrafter block / Elementor widget) and you are done.', 'tc-data-tables'); ?>
    </p>

    <h2><?php esc_html_e('See it in action — load a demo in one click', 'tc-data-tables'); ?></h2>
    <p>
        <?php
        if (class_exists('TC_Demo_Data')) {
            foreach (TC_Demo_Data::datasets() as $gt_demo_key => $gt_demo) {
                printf(
                    '<button type="button" class="button button-primary gt-load-demo" data-demo="%s">%s</button> ',
                    esc_attr($gt_demo_key),
                    esc_html($gt_demo['label'])
                );
            }
        }
        ?>
        <span class="gt-load-demo-result" style="margin-left:8px;"></span>
    </p>

    <?php if (class_exists('TC_Templates')) : ?>
        <h2><?php esc_html_e('Start from a template', 'tc-data-tables'); ?></h2>
        <p><?php esc_html_e('Prebuilt starting points — one click creates a ready-to-edit table with sample data and columns set up.', 'tc-data-tables'); ?></p>
        <div class="gt-templates-gallery" style="display:flex;flex-wrap:wrap;gap:12px;max-width:900px;">
            <?php foreach (TC_Templates::all() as $gt_tpl_key => $gt_tpl) : ?>
                <div class="gt-template-card" style="flex:1 1 260px;border:1px solid #e5e7eb;border-radius:8px;padding:14px;">
                    <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;letter-spacing:.03em;"><?php echo esc_html($gt_tpl['category']); ?></div>
                    <strong style="display:block;margin:4px 0;"><?php echo esc_html($gt_tpl['label']); ?></strong>
                    <p style="margin:0 0 10px;color:#4b5563;font-size:13px;"><?php echo esc_html($gt_tpl['description']); ?></p>
                    <button type="button" class="button button-primary gt-load-demo" data-demo="<?php echo esc_attr($gt_tpl_key); ?>">
                        <?php esc_html_e('Create this table', 'tc-data-tables'); ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <p><span class="gt-load-demo-result" style="margin-left:8px;"></span></p>
    <?php endif; ?>

    <h2><?php esc_html_e('Or build your own', 'tc-data-tables'); ?></h2>
    <p>
        <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=gravity-tables-new')); ?>">
            <?php esc_html_e('Open the table builder', 'tc-data-tables'); ?>
        </a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=gravity-tables')); ?>">
            <?php esc_html_e('View your tables', 'tc-data-tables'); ?>
        </a>
    </p>
</div>
