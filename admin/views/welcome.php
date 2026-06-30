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
    <!-- h1 must be the first element so WP admin notices render before the hero, not inside it. -->
    <h1 class="screen-reader-text"><?php esc_html_e('Welcome to TableCrafter', 'tc-data-tables'); ?></h1>
    <div class="gt-welcome-hero">
        <span class="gt-welcome-logo">TC</span>
        <div>
            <h2 class="gt-welcome-hero-title"><?php esc_html_e('Welcome to TableCrafter', 'tc-data-tables'); ?></h2>
            <p><?php esc_html_e('Turn any data source into a beautiful, responsive WordPress table — JSON / REST APIs, CSV, Google Sheets and Excel for free, plus Gravity Forms, WooCommerce, Airtable, Notion and external databases on Pro. Add a shortcode (or the TableCrafter block / Elementor widget) and you are done.', 'tc-data-tables'); ?></p>
        </div>
    </div>

    <h2><?php esc_html_e('See it in action — load a demo in one click', 'tc-data-tables'); ?></h2>
    <p class="gt-welcome-demos">
        <?php
        if (class_exists('TC_Demo_Data')) {
            foreach (TC_Demo_Data::datasets() as $gt_demo_key => $gt_demo) {
                printf(
                    '<button type="button" class="button gt-btn-tc gt-load-demo" data-demo="%s">%s</button> ',
                    esc_attr($gt_demo_key),
                    esc_html($gt_demo['label'])
                );
            }
        }
        ?>
        <span class="gt-load-demo-result"></span>
    </p>

    <?php if (class_exists('TC_Templates')) : ?>
        <h2><?php esc_html_e('Start from a template', 'tc-data-tables'); ?></h2>
        <p class="gt-welcome-lead"><?php esc_html_e('Prebuilt starting points — one click creates a ready-to-edit table with sample data and columns set up.', 'tc-data-tables'); ?></p>
        <div class="gt-templates-gallery">
            <?php foreach (TC_Templates::all() as $gt_tpl_key => $gt_tpl) : ?>
                <div class="gt-template-card">
                    <div class="gt-template-cat"><?php echo esc_html($gt_tpl['category']); ?></div>
                    <strong class="gt-template-name"><?php echo esc_html($gt_tpl['label']); ?></strong>
                    <p class="gt-template-desc"><?php echo esc_html($gt_tpl['description']); ?></p>
                    <button type="button" class="button gt-btn-tc gt-load-demo" data-demo="<?php echo esc_attr($gt_tpl_key); ?>">
                        <?php esc_html_e('Create this table', 'tc-data-tables'); ?>
                    </button>
                    <span class="gt-load-demo-result"></span>
                </div>
            <?php endforeach; ?>
        </div>
        <p><span class="gt-load-demo-result"></span></p>
    <?php endif; ?>

    <h2><?php esc_html_e('Or build your own', 'tc-data-tables'); ?></h2>
    <p>
        <a class="button gt-btn-tc-outline" href="<?php echo esc_url(admin_url('admin.php?page=gravity-tables-new')); ?>">
            <?php esc_html_e('Open the table builder', 'tc-data-tables'); ?>
        </a>
        <a class="button gt-btn-tc-ghost" href="<?php echo esc_url(admin_url('admin.php?page=gravity-tables')); ?>">
            <?php esc_html_e('View your tables', 'tc-data-tables'); ?>
        </a>
    </p>
</div>

<style>
.gt-welcome { max-width: 1000px; }
.gt-welcome-hero {
    display: flex; align-items: center; gap: 18px;
    background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%);
    color: #fff; border-radius: 14px; padding: 26px 30px; margin: 14px 0 8px;
}
.gt-welcome-logo {
    flex-shrink: 0; width: 52px; height: 52px; border-radius: 12px;
    background: rgba(255,255,255,.18); display: flex; align-items: center;
    justify-content: center; font-weight: 800; font-size: 20px; letter-spacing: -1px;
}
.gt-welcome-hero-title { color: #fff; margin: 0 0 4px; font-size: 23px; }
.gt-welcome-hero p { color: rgba(255,255,255,.9); margin: 0; font-size: 14px; line-height: 1.55; max-width: 760px; }
.gt-welcome h2 { font-size: 17px; color: #141312; margin: 30px 0 6px; }
.gt-welcome-lead { color: #4b5563; }
/* Brand the action buttons (keep the functional gt-load-demo hook intact). */
.gt-welcome .gt-btn-tc {
    box-sizing: border-box;
    background: linear-gradient(135deg, #14b8a6, #0d9488) !important;
    border: none !important; color: #fff !important; border-radius: 7px !important;
    height: auto !important; padding: 7px 16px !important; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: 0 1px 4px rgba(13,148,136,.3) !important;
    line-height: 1.6 !important; white-space: normal;
}
.gt-welcome .gt-btn-tc:hover { filter: brightness(1.06); color: #fff !important; }
.gt-welcome .gt-btn-tc-outline, .gt-welcome .gt-btn-tc-ghost {
    box-sizing: border-box; border-radius: 7px !important; height: auto !important;
    padding: 7px 15px !important; font-weight: 600 !important;
}
.gt-welcome .gt-btn-tc-outline { border: 1.5px solid #0d9488 !important; color: #0d9488 !important; background: #fff !important; }
.gt-welcome .gt-btn-tc-ghost  { border: 1px solid #d1d5db !important; color: #374151 !important; background: #fff !important; }
.gt-welcome .gt-btn-tc-outline:hover { background: #f0fdfa !important; }
.gt-templates-gallery { display: flex; flex-wrap: wrap; gap: 14px; max-width: 980px; margin-top: 8px; }
.gt-template-card {
    box-sizing: border-box; flex: 1 1 280px; background: #fff;
    border: 1px solid #e5e3dd; border-radius: 12px; padding: 18px;
    transition: border-color .15s, box-shadow .15s, transform .12s;
    position: relative; overflow: hidden;
}
.gt-template-card::before {
    content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
    background: linear-gradient(180deg, #2dd4bf, #0d9488);
}
.gt-template-card:hover { border-color: #14b8a6; box-shadow: 0 6px 20px rgba(13,148,136,.12); transform: translateY(-1px); }
.gt-template-cat { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #0d9488; }
.gt-template-name { display: block; margin: 5px 0; color: #141312; font-size: 15px; }
.gt-template-desc { margin: 0 0 14px; color: #6b6560; font-size: 13px; line-height: 1.5; }
.gt-load-demo-result { display: inline-block; margin-left: 8px; font-size: 13px; color: #0d9488; }
.gt-template-card .gt-load-demo-result { display: block; margin: 10px 0 0; }
.gt-load-demo-result .button {
    box-sizing: border-box; border-radius: 6px !important; height: auto !important;
    padding: 4px 12px !important; font-weight: 600 !important; margin-left: 6px;
    border: 1.5px solid #0d9488 !important; color: #0d9488 !important; background: #fff !important;
}
.gt-load-demo-result .button:hover { background: #f0fdfa !important; }
@media (max-width: 782px) {
    .gt-welcome-hero { flex-direction: column; text-align: center; }
    .gt-template-card { flex-basis: 100%; }
}
</style>
