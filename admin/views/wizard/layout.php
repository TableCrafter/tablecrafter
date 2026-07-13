<?php
/**
 * Table Creation Wizard - main layout (#1978)
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$is_free = function_exists( 'gt_is_free_plan' ) && gt_is_free_plan();

// Pre-load Gravity Forms list for Step 2 (avoids an extra AJAX round-trip).
$gf_forms = array();
if ( class_exists( 'GFAPI' ) ) {
    $raw = GFAPI::get_forms( true );
    foreach ( (array) $raw as $f ) {
        $gf_forms[] = array( 'id' => (int) $f['id'], 'title' => $f['title'] );
    }
}
?>
<div class="wrap gt-wizard-wrap">
    <!-- Hidden h1 satisfies WP admin-notices injection requirement -->
    <h1 class="gt-wizard-page-h1"><?php esc_html_e( 'Create Table - Wizard', 'tc-data-tables' ); ?></h1>

    <!-- Hero -->
    <div class="gt-wizard-hero">
        <span class="dashicons dashicons-table-col-after gt-wizard-hero-icon"></span>
        <div>
            <h2><?php esc_html_e( 'Create a Table', 'tc-data-tables' ); ?></h2>
            <p><?php esc_html_e( 'Answer a few quick questions and we\'ll build your first table in under 2 minutes.', 'tc-data-tables' ); ?></p>
        </div>
        <span class="gt-wizard-beta-badge"><?php esc_html_e( 'Beta', 'tc-data-tables' ); ?></span>
    </div>

    <!-- Step indicator -->
    <div class="gt-wizard-indicator" role="navigation" aria-label="<?php esc_attr_e( 'Wizard steps', 'tc-data-tables' ); ?>">
        <?php
        $steps = array(
            1 => __( 'Data Source', 'tc-data-tables' ),
            2 => __( 'Connect',     'tc-data-tables' ),
            3 => __( 'Columns',     'tc-data-tables' ),
            4 => __( 'Display',     'tc-data-tables' ),
            5 => __( 'Create',      'tc-data-tables' ),
        );
        foreach ( $steps as $n => $label ) :
        ?>
        <div class="gt-wizard-step<?php echo $n === 1 ? ' gt-wizard-step--active' : ''; ?>" data-step="<?php echo esc_attr( $n ); ?>">
            <span class="gt-wizard-step-num"><?php echo esc_html( $n ); ?></span>
            <span class="gt-wizard-step-label"><?php echo esc_html( $label ); ?></span>
        </div>
        <?php if ( $n < 5 ) : ?>
        <div class="gt-wizard-step-connector"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Panels -->
    <div class="gt-wizard-panels">

        <!-- Step 1 - Data Source -->
        <div class="gt-wizard-panel gt-wizard-panel--active" data-panel="1">
            <?php include __DIR__ . '/step-1.php'; ?>
        </div>

        <!-- Step 2 - Connect -->
        <div class="gt-wizard-panel" data-panel="2">
            <?php include __DIR__ . '/step-2.php'; ?>
        </div>

        <!-- Step 3 - Columns -->
        <div class="gt-wizard-panel" data-panel="3">
            <?php include __DIR__ . '/step-3.php'; ?>
        </div>

        <!-- Step 4 - Display Options -->
        <div class="gt-wizard-panel" data-panel="4">
            <?php include __DIR__ . '/step-4.php'; ?>
        </div>

        <!-- Step 5 - Review & Create -->
        <div class="gt-wizard-panel" data-panel="5">
            <?php include __DIR__ . '/step-5.php'; ?>
        </div>

    </div><!-- .gt-wizard-panels -->

    <!-- Navigation bar -->
    <div class="gt-wizard-nav">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables' ) ); ?>" class="gt-wizard-cancel">
            <?php esc_html_e( 'Cancel', 'tc-data-tables' ); ?>
        </a>
        <div class="gt-wizard-nav-right">
            <button type="button" class="button gt-wizard-btn-back" style="display:none">
                ← <?php esc_html_e( 'Back', 'tc-data-tables' ); ?>
            </button>
            <button type="button" class="button button-primary gt-wizard-btn-next">
                <?php esc_html_e( 'Next', 'tc-data-tables' ); ?> →
            </button>
            <button type="button" class="button button-primary gt-wizard-btn-create" style="display:none">
                <span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Create Table', 'tc-data-tables' ); ?>
            </button>
        </div>
    </div>

    <!-- Hidden data for JS -->
    <script type="application/json" id="gt-wizard-forms-data">
        <?php echo wp_json_encode( $gf_forms ); ?>
    </script>

</div><!-- .gt-wizard-wrap -->
