<?php
/**
 * Popup functionality for Gravity Tables
 *
 * Implements the admin popup functionality for adding new entries using ThickBox.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Popup
{

    private static ?TC_Popup $instance = null;

    public static function get_instance(): TC_Popup
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_popup_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_popup_scripts'));
        add_action('wp_ajax_gt_get_form_for_popup', array($this, 'get_form_for_popup'));
        add_action('wp_ajax_nopriv_gt_get_form_for_popup', array($this, 'get_form_for_popup'));
    }

    /**
     * Enqueue scripts and styles for popup functionality
     */
    public function enqueue_popup_scripts($hook = null): void
    {
        if (is_admin() && $hook && strpos($hook, 'gravity-tables') === false) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        // #546 - frontend gate: don't ship the popup bundle on shortcode-less
        // pages. Admin path is unchanged (the gravity-tables admin pages always
        // need the popup). The `gt_always_enqueue_assets` filter inside the gate
        // bypasses this for cache-plugin scenarios.
        if (!is_admin() && !TC_Asset_Enqueue_Gate::page_has_table()) {
            return;
        }

        // Load ThickBox (built into WP)
        if (!is_admin()) {
            wp_enqueue_script('jquery');
        }
        add_thickbox();

        wp_enqueue_script('gt-popup', TC_PLUGIN_URL . 'assets/js/popup.js', array('jquery', 'thickbox'), TC_VERSION, true);

        wp_localize_script('gt-popup', 'gtPopup', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gt_popup_nonce'),
            'strings' => array(
                'add_new_entry' => __('Add New Entry', 'tc-data-tables'),
                'loading' => __('Loading...', 'tc-data-tables')
            )
        ));
    }

    /**
     * Get form HTML for popup display
     */
    public function get_form_for_popup(): void
    {
        // Allow public access if specifically enabled, otherwise check nonce
        if (isset($_REQUEST['nonce'])) {
            check_ajax_referer('gt_popup_nonce', 'nonce');
        }

        // Define allowed roles/capabilities
        $can_edit = current_user_can('edit_posts') || current_user_can('publish_posts') || current_user_can('driver') || current_user_can('administrator');

        if (!$can_edit) {
            wp_send_json_error(__('Insufficient permissions to create entries', 'tc-data-tables'));
        }

        $form_id = intval($_POST['form_id'] ?? 0);

        if (!$form_id) {
            wp_send_json_error(__('Invalid form ID', 'tc-data-tables'));
        }

        if (!class_exists('GFForms') || !class_exists('GFFormDisplay')) {
            wp_send_json_error(__('Gravity Forms is not available', 'tc-data-tables'));
        }

        $form = GFAPI::get_form($form_id);

        if (!$form || is_wp_error($form)) {
            wp_send_json_error(__('Form not found', 'tc-data-tables'));
        }

        ob_start();

        // Enqueue GF scripts for the form
        GFFormDisplay::enqueue_form_scripts($form, true);

        // Get form HTML
        $form_html = GFFormDisplay::get_form($form_id, true, true, false, null, true, 1);

        if (empty($form_html)) {
            $form_html = '<div class="notice notice-error"><p>' . __('Unable to generate form for popup.', 'tc-data-tables') . '</p></div>';
        }

        echo '<div class="gt-popup-form-container">';
        echo $form_html;
        echo '</div>';

        $form_content = ob_get_clean();

        wp_send_json_success(array(
            'form_content' => $form_content,
            'form_id' => $form_id
        ));
    }

    /**
     * Render the popup trigger link
     */
    public function render_popup_link(int $form_id): string
    {
        $link = sprintf(
            '<a href="#TB_inline?width=700&height=700&inlineId=gt-add-entry-popup" class="thickbox page-title-action gt-add-entry-popup-link" id="gt-add-entry-trigger" data-form-id="%d">%s</a>',
            $form_id,
            __('Add New Entry', 'tc-data-tables')
        );

        $popup_html = '<div id="gt-add-entry-popup" style="display:none;">';
        $popup_html .= '<div class="gt-popup-content">';
        $popup_html .= '<h2>' . __('Add New Entry', 'tc-data-tables') . '</h2>';
        $popup_html .= '<div class="gt-popup-form-placeholder">';
        $popup_html .= '<p class="gt-loading-message">' . __('Loading form...', 'tc-data-tables') . '</p>';
        $popup_html .= '</div>';
        $popup_html .= '</div>';
        $popup_html .= '</div>';

        return $link . $popup_html;
    }
}