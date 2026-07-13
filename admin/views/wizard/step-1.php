<?php
/**
 * Wizard Step 1 - Data source selector (#1979)
 *
 * @package GravityTables
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// #2011 - the source cards are data-driven from TC_Source_Registry::for_wizard()
// so wizard-eligible sources come from one source of truth (convergence #2006).
if ( ! class_exists( 'TC_Source_Registry' ) ) {
    require_once dirname( __DIR__, 2 ) . '/includes/services/class-tc-source-registry.php';
}
$gt_wizard_sources = TC_Source_Registry::for_wizard();
$gt_first_available = true;
?>
<div class="gt-wizard-step-inner">
    <h3 class="gt-wizard-step-title">
        <span class="dashicons dashicons-cloud gt-wizard-step-icon"></span>
        <?php esc_html_e( 'Where is your data?', 'tc-data-tables' ); ?>
    </h3>
    <p class="gt-wizard-step-desc"><?php esc_html_e( 'Pick the source that holds the data you want to display as a table.', 'tc-data-tables' ); ?></p>

    <div class="gt-wizard-source-grid">

        <?php foreach ( $gt_wizard_sources as $gt_src_key => $gt_src ) :
            $requires_class = isset( $gt_src['requires_class'] ) ? (string) $gt_src['requires_class'] : '';
            $available      = ( $requires_class === '' ) || class_exists( $requires_class );
            $recommended    = $available && $gt_first_available;
            $icon           = isset( $gt_src['wizard_icon'] ) ? (string) $gt_src['wizard_icon'] : 'dashicons-database';
            $card_classes   = 'gt-wizard-source-card';
            if ( ! $available ) {
                $card_classes .= ' gt-wizard-source-card--unavailable';
            } elseif ( $recommended ) {
                $card_classes .= ' gt-wizard-source-card--recommended';
            }
            ?>
        <label class="<?php echo esc_attr( $card_classes ); ?>" data-source="<?php echo esc_attr( $gt_src_key ); ?>">
            <input type="radio" name="data_source_type" value="<?php echo esc_attr( $gt_src_key ); ?>"
                <?php disabled( ! $available ); ?>
                <?php checked( $recommended ); ?>>
            <span class="gt-wizard-source-icon dashicons <?php echo esc_attr( $icon ); ?>"></span>
            <span class="gt-wizard-source-name"><?php echo esc_html( $gt_src['label'] ); ?></span>
            <span class="gt-wizard-source-desc">
                <?php
                if ( $available ) {
                    echo esc_html( isset( $gt_src['description'] ) ? $gt_src['description'] : '' );
                } else {
                    /* translators: %s: required plugin/source name */
                    echo esc_html( sprintf( __( '%s not detected.', 'tc-data-tables' ), $gt_src['label'] ) );
                }
                ?>
            </span>
            <?php if ( $available && ! empty( $gt_src['wizard_badge'] ) ) : ?>
            <span class="gt-wizard-source-badge"><?php echo esc_html( $gt_src['wizard_badge'] ); ?></span>
            <?php endif; ?>
        </label>
        <?php
            if ( $available ) {
                $gt_first_available = false;
            }
        endforeach;
        ?>

        <!-- Advanced / Full builder -->
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=gravity-tables-new' ) ); ?>"
           class="gt-wizard-source-card gt-wizard-source-card--advanced" data-source="advanced">
            <span class="gt-wizard-source-icon dashicons dashicons-admin-settings"></span>
            <span class="gt-wizard-source-name"><?php esc_html_e( 'Other / Advanced', 'tc-data-tables' ); ?></span>
            <span class="gt-wizard-source-desc">
                <?php esc_html_e( 'Airtable, Notion, or full configuration options in the advanced builder.', 'tc-data-tables' ); ?>
            </span>
            <span class="gt-wizard-source-badge gt-wizard-source-badge--neutral"><?php esc_html_e( 'Opens builder', 'tc-data-tables' ); ?></span>
        </a>

    </div>

    <p class="gt-wizard-validation-msg" style="display:none">
        <?php esc_html_e( 'Please select a data source to continue.', 'tc-data-tables' ); ?>
    </p>
</div>
