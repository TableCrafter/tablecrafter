<?php
/**
 * TableCrafter Dashboard view — admin/views/dashboard.php
 *
 * Renders widgets for issues #1924-#1931.
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// ── Stats ──────────────────────────────────────────────────────────────────
// #2229 — "Total" is every table the user actually has, i.e. excluding
// soft-deleted (Trash) rows. #2257 — all counts now come from
// TC_Data_Integrity_Guard so the cards, the tables list, and the Trash tab
// share one definition of each population.
$table_count  = TC_Data_Integrity_Guard::count_active_tables( $wpdb );
$all_tables   = TC_Data_Integrity_Guard::count_all_tables( $wpdb );
$trash_count  = TC_Data_Integrity_Guard::count_trashed_tables( $wpdb );
// "In use" = live tables actually embedded on a post/page (where-used scan,
// #542). More telling than Active, which mirrors Total on healthy data.
$in_use_count = TC_Data_Integrity_Guard::count_tables_in_use( $wpdb );

// #2257 — active tables grouped by data_source_type (settings JSON; absent
// key defaults to gravity_forms). Feeds the per-source stat cards AND the
// Data Sources widget below.
$_src_counts   = TC_Data_Integrity_Guard::count_active_by_source( $wpdb );
$_src_labels   = TC_Data_Integrity_Guard::source_labels();
$gf_count       = $_src_counts['gravity_forms'] ?? 0;
$json_count     = $_src_counts['json']           ?? 0;
$airtable_count = $_src_counts['airtable']       ?? 0;
$notion_count   = $_src_counts['notion']         ?? 0;

$gf_active = class_exists( 'GFAPI' );

// ── License ────────────────────────────────────────────────────────────────
$is_pro = function_exists( 'gt_is_premium' ) && gt_is_premium();

// ── System info ────────────────────────────────────────────────────────────
global $wp_version;
$php_version = PHP_VERSION;
$gf_version  = $gf_active && class_exists( 'GFCommon' )
    ? GFCommon::$version
    : ( defined( 'GF_PLUGIN_VERSION' ) ? GF_PLUGIN_VERSION : __( 'n/a', 'tc-data-tables' ) );

// ── Recent changelog entries ─────────────────────────────────────────────
// Keep the newest entry's version in step with the current release — the
// #2232 guard (tests/test-issue-2232-dashboard-whats-new.php) fails if this
// falls a whole minor version behind TC_VERSION. Update on every release
// (per the docs-per-release policy), newest first.
$changelog = array(
    array(
        'version' => '8.0.40',
        'summary' => __( 'The All Tables list now shows each table\'s data source (Gravity Forms, JSON, Google Sheets, External Database, and more) instead of a Gravity-Forms-only column that read "Form ID: 0" for other sources.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.39',
        'summary' => __( 'External Database tables now support a per-table public viewing opt-in; field-picker chips in the builder show readable labels for JSON, CSV, XML, and Google Sheets sources.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.38',
        'summary' => __( 'Dashboard stats redesigned: Total, In use, per-source counts, and Trash (hidden when empty); orphaned pre-Trash-system deletes now surface in the Trash tab.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.37',
        'summary' => __( 'Airtable, Notion, and External Database tables now load columns + a live preview right in the builder; fixed External Database tables never rendering (unregistered capability).', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.36',
        'summary' => __( 'External-source table headers now show readable labels (e.g. "Product Name") instead of raw keys, in the frontend and the builder preview.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.35',
        'summary' => __( 'Cleaner table builder: removed the redundant per-step Save buttons.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.34',
        'summary' => __( 'Dashboard "Total" tables count no longer includes trashed tables.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.33',
        'summary' => __( 'Pro installs are no longer offered the WordPress.org free version as an update.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.32',
        'summary' => __( 'Server-side processing tables render their rows again.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.31',
        'summary' => __( 'WooCommerce table builder: product column picker + live preview.', 'tc-data-tables' ),
    ),
    array(
        'version' => '8.0.30',
        'summary' => __( 'WooCommerce product tables render (links, prices, stock, add-to-cart).', 'tc-data-tables' ),
    ),
);
?>
<div class="wrap gt-dashboard">
    <h1><?php esc_html_e( 'TableCrafter Dashboard', 'tc-data-tables' ); ?></h1>

    <div class="gt-dashboard-layout">

        <?php /* ── #1924 Hero ── */ ?>
        <div class="gt-hero">
            <div class="gt-hero-text">
                <h2><?php esc_html_e( 'Welcome to TableCrafter', 'tc-data-tables' ); ?></h2>
                <p><?php esc_html_e( 'Beautiful, responsive WordPress data tables powered by Gravity Forms and more.', 'tc-data-tables' ); ?></p>
                <span class="gt-hero-version">v<?php echo esc_html( TC_VERSION ); ?></span>
            </div>
            <div class="gt-hero-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables&action=new' ) ); ?>" class="gt-hero-btn">
                    <?php esc_html_e( '+ New Table', 'tc-data-tables' ); ?>
                </a>
                <a href="https://tablecrafter.com/docs" target="_blank" rel="noopener" class="gt-hero-btn gt-hero-btn-outline">
                    <?php esc_html_e( 'Docs', 'tc-data-tables' ); ?>
                </a>
            </div>
        </div>

        <div class="gt-dashboard-grid">

            <?php /* ── #1925 Tables stats ── */ ?>
            <div class="gt-widget">
                <h3><?php esc_html_e( 'Tables', 'tc-data-tables' ); ?></h3>
                <?php /* #2257 — Total, Active, Trash, then a card per data
                       source in use. The old third card ("GF Tables") echoed
                       $table_count — the Active count — instead of the
                       per-source tally. */ ?>
                <div class="gt-stats-grid">
                    <div class="gt-stat-item">
                        <span class="gt-stat-number"><?php echo esc_html( $all_tables ); ?></span>
                        <span class="gt-stat-label"><?php esc_html_e( 'Total', 'tc-data-tables' ); ?></span>
                    </div>
                    <div class="gt-stat-item">
                        <span class="gt-stat-number"><?php echo esc_html( $in_use_count ); ?></span>
                        <span class="gt-stat-label"><?php esc_html_e( 'In use', 'tc-data-tables' ); ?></span>
                    </div>
                    <?php foreach ( $_src_counts as $_src => $_n ) :
                        if ( $_n < 1 ) {
                            continue;
                        }
                        $_label = $_src_labels[ $_src ] ?? ucwords( str_replace( '_', ' ', (string) $_src ) );
                        ?>
                    <div class="gt-stat-item gt-stat-source">
                        <span class="gt-stat-number"><?php echo esc_html( $_n ); ?></span>
                        <span class="gt-stat-label"><?php echo esc_html( $_label ); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php // #2259 — an empty Trash card tells the admin nothing; hide at zero
                    if ( $trash_count > 0 ) : ?>
                    <div class="gt-stat-item">
                        <span class="gt-stat-number"><?php echo esc_html( $trash_count ); ?></span>
                        <span class="gt-stat-label"><?php esc_html_e( 'Trash', 'tc-data-tables' ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <p style="margin:14px 0 0;font-size:12px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables' ) ); ?>">
                        <?php esc_html_e( 'Manage tables →', 'tc-data-tables' ); ?>
                    </a>
                </p>
            </div>

            <?php /* ── #2116 Activation funnel ── */ ?>
            <?php if ( class_exists( 'TC_Activation_Funnel' ) ) :
                $gt_funnel = TC_Activation_Funnel::get_funnel();
                $gt_labels = array(
                    'plugin_activated'        => __( 'Plugin activated', 'tc-data-tables' ),
                    'builder_opened'          => __( 'Opened the table builder', 'tc-data-tables' ),
                    'table_created'           => __( 'Created a table', 'tc-data-tables' ),
                    'table_published'         => __( 'Published a table (rendered live)', 'tc-data-tables' ),
                    'first_inline_edit_saved' => __( 'Saved a frontend inline edit', 'tc-data-tables' ),
                    'first_export'            => __( 'Exported data', 'tc-data-tables' ),
                );
                ?>
            <div class="gt-widget gt-activation-funnel">
                <h3><?php esc_html_e( 'Activation funnel', 'tc-data-tables' ); ?></h3>
                <p style="margin:0 0 10px;font-size:12px;color:#666;">
                    <?php esc_html_e( 'How far this install has progressed through onboarding. Stored locally only.', 'tc-data-tables' ); ?>
                </p>
                <ol style="margin:0;padding-left:0;list-style:none;">
                    <?php foreach ( $gt_labels as $gt_step => $gt_label ) :
                        $gt_reached = ! empty( $gt_funnel[ $gt_step ]['reached'] );
                        $gt_when    = $gt_reached && ! empty( $gt_funnel[ $gt_step ]['first'] )
                            ? date_i18n( get_option( 'date_format', 'Y-m-d' ), (int) $gt_funnel[ $gt_step ]['first'] )
                            : '';
                        ?>
                        <li style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;<?php echo $gt_reached ? '' : 'opacity:.55;'; ?>">
                            <span style="font-weight:bold;color:<?php echo $gt_reached ? '#1a7f37' : '#999'; ?>;"><?php echo $gt_reached ? '✓' : '○'; ?></span>
                            <span style="flex:1;"><?php echo esc_html( $gt_label ); ?></span>
                            <?php if ( $gt_when ) : ?>
                                <span style="color:#888;font-size:11px;"><?php echo esc_html( $gt_when ); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <?php endif; ?>

            <?php /* ── #1926 Data sources ── */ ?>
            <div class="gt-widget gt-sources">
                <h3><?php esc_html_e( 'Data Sources', 'tc-data-tables' ); ?></h3>
                <ul class="gt-sources-list">
                    <li class="gt-source-item">
                        <span class="gt-source-dot <?php echo $gf_active ? 'gt-source-dot-active' : 'gt-source-dot-inactive'; ?>"></span>
                        <span class="gt-source-name"><?php esc_html_e( 'Gravity Forms', 'tc-data-tables' ); ?></span>
                        <span class="gt-source-status">
                            <?php
                            if ( ! $gf_active ) {
                                esc_html_e( 'Not installed', 'tc-data-tables' );
                            } else {
                                echo esc_html(
                                    /* translators: %d = number of active tables */
                                    sprintf( _n( '%d table', '%d tables', $gf_count, 'tc-data-tables' ), $gf_count )
                                );
                            }
                            ?>
                        </span>
                    </li>
                    <li class="gt-source-item">
                        <span class="gt-source-dot <?php echo $json_count > 0 ? 'gt-source-dot-active' : 'gt-source-dot-inactive'; ?>"></span>
                        <span class="gt-source-name"><?php esc_html_e( 'JSON / REST', 'tc-data-tables' ); ?></span>
                        <span class="gt-source-status">
                            <?php
                            echo esc_html(
                                $json_count > 0
                                    ? sprintf( _n( '%d table', '%d tables', $json_count, 'tc-data-tables' ), $json_count )
                                    : __( 'Not in use', 'tc-data-tables' )
                            );
                            ?>
                        </span>
                    </li>
                    <li class="gt-source-item">
                        <span class="gt-source-dot <?php echo $airtable_count > 0 ? 'gt-source-dot-active' : 'gt-source-dot-inactive'; ?>"></span>
                        <span class="gt-source-name"><?php esc_html_e( 'Airtable', 'tc-data-tables' ); ?></span>
                        <span class="gt-source-status">
                            <?php
                            echo esc_html(
                                $airtable_count > 0
                                    ? sprintf( _n( '%d table', '%d tables', $airtable_count, 'tc-data-tables' ), $airtable_count )
                                    : __( 'Not in use', 'tc-data-tables' )
                            );
                            ?>
                        </span>
                    </li>
                    <li class="gt-source-item">
                        <span class="gt-source-dot <?php echo $notion_count > 0 ? 'gt-source-dot-active' : 'gt-source-dot-inactive'; ?>"></span>
                        <span class="gt-source-name"><?php esc_html_e( 'Notion', 'tc-data-tables' ); ?></span>
                        <span class="gt-source-status">
                            <?php
                            echo esc_html(
                                $notion_count > 0
                                    ? sprintf( _n( '%d table', '%d tables', $notion_count, 'tc-data-tables' ), $notion_count )
                                    : __( 'Not in use', 'tc-data-tables' )
                            );
                            ?>
                        </span>
                    </li>
                </ul>
            </div>

            <?php /* ── #1927 License ── */ ?>
            <div class="gt-widget gt-license">
                <h3><?php esc_html_e( 'License', 'tc-data-tables' ); ?></h3>
                <?php if ( $is_pro ) : ?>
                    <div class="gt-license-status active">
                        <span class="gt-license-icon">✅</span>
                        <div class="gt-license-text">
                            <strong><?php esc_html_e( 'Pro — Active', 'tc-data-tables' ); ?></strong>
                            <span><?php esc_html_e( 'All Pro features unlocked', 'tc-data-tables' ); ?></span>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="gt-license-status free">
                        <span class="gt-license-icon">🆓</span>
                        <div class="gt-license-text">
                            <strong><?php esc_html_e( 'Free Plan', 'tc-data-tables' ); ?></strong>
                            <span><?php esc_html_e( 'Upgrade to unlock inline editing, bulk actions, and more', 'tc-data-tables' ); ?></span>
                        </div>
                    </div>
                    <a href="https://tablecrafter.com/#pricing" target="_blank" rel="noopener" class="gt-license-cta">
                        <?php esc_html_e( 'Upgrade to Pro', 'tc-data-tables' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php /* ── #1928 System info ── */ ?>
            <div class="gt-widget gt-system">
                <h3><?php esc_html_e( 'System Info', 'tc-data-tables' ); ?></h3>
                <table class="gt-system-table">
                    <tr>
                        <td><?php esc_html_e( 'TableCrafter', 'tc-data-tables' ); ?></td>
                        <td><?php echo esc_html( TC_VERSION ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'WordPress', 'tc-data-tables' ); ?></td>
                        <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'PHP', 'tc-data-tables' ); ?></td>
                        <td><?php echo esc_html( PHP_VERSION ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Gravity Forms', 'tc-data-tables' ); ?></td>
                        <td><?php echo esc_html( $gf_version ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'MySQL', 'tc-data-tables' ); ?></td>
                        <td><?php echo esc_html( $wpdb->db_server_info() ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Active Tables', 'tc-data-tables' ); ?></td>
                        <td><?php echo esc_html( $table_count ); ?></td>
                    </tr>
                </table>
            </div>

            <?php /* ── #1929 Changelog ── */ ?>
            <div class="gt-widget gt-changelog">
                <h3><?php esc_html_e( "What's New", 'tc-data-tables' ); ?></h3>
                <ul class="gt-changelog-list">
                    <?php // #2263 — the array grows every release; show only the 5 newest.
                    foreach ( array_slice( $changelog, 0, 5 ) as $entry ) : ?>
                        <li class="gt-changelog-item">
                            <div class="gt-changelog-version">v<?php echo esc_html( $entry['version'] ); ?></div>
                            <p class="gt-changelog-desc"><?php echo esc_html( $entry['summary'] ); ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin:14px 0 0;font-size:12px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables-docs#whats-new' ) ); ?>">
                        <?php esc_html_e( 'Full changelog →', 'tc-data-tables' ); ?>
                    </a>
                </p>
            </div>

            <?php /* ── #1930 Quick links ── */ ?>
            <div class="gt-widget gt-quick-links-widget">
                <h3><?php esc_html_e( 'Getting Started', 'tc-data-tables' ); ?></h3>
                <div class="gt-quick-links">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables&action=new' ) ); ?>" class="gt-quick-link">
                        <span class="gt-quick-link-icon">📋</span>
                        <?php esc_html_e( 'Create table', 'tc-data-tables' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tablecrafter-wizard' ) ); ?>" class="gt-quick-link">
                        <span class="gt-quick-link-icon">✦</span>
                        <?php esc_html_e( 'New (Wizard) Beta', 'tc-data-tables' ); ?>
                    </a>
                    <a href="https://tablecrafter.com/docs" target="_blank" rel="noopener" class="gt-quick-link">
                        <span class="gt-quick-link-icon">📖</span>
                        <?php esc_html_e( 'Documentation', 'tc-data-tables' ); ?>
                    </a>
                    <a href="https://wordpress.org/support/plugin/tablecrafter-wp-data-tables/" target="_blank" rel="noopener" class="gt-quick-link">
                        <span class="gt-quick-link-icon">💬</span>
                        <?php esc_html_e( 'Support forum', 'tc-data-tables' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables-docs' ) ); ?>" class="gt-quick-link">
                        <span class="gt-quick-link-icon">📋</span>
                        <?php esc_html_e( 'In-plugin docs', 'tc-data-tables' ); ?>
                    </a>
                    <?php if ( ! $is_pro ) : ?>
                    <a href="https://tablecrafter.com/#pricing" target="_blank" rel="noopener" class="gt-quick-link">
                        <span class="gt-quick-link-icon">⭐</span>
                        <?php esc_html_e( 'Upgrade to Pro', 'tc-data-tables' ); ?>
                    </a>
                    <?php endif; ?>
                    <a href="https://wordpress.org/support/plugin/tablecrafter-wp-data-tables/reviews/#new-post" target="_blank" rel="noopener" class="gt-quick-link">
                        <span class="gt-quick-link-icon">⭐</span>
                        <?php esc_html_e( 'Rate plugin', 'tc-data-tables' ); ?>
                    </a>
                </div>
            </div>

        </div><!-- .gt-dashboard-grid -->
    </div><!-- .gt-dashboard-layout -->
</div><!-- .gt-dashboard -->
