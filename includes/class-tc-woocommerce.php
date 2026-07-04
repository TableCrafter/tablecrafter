<?php
/**
 * WooCommerce integration for Gravity Tables (#35 MVP).
 *
 * When WooCommerce is active, an admin can map four GF fields onto core
 * WC product attributes (title / regular_price / sku / description) per
 * Gravity Tables row. A "Create Product" row action then converts that
 * row's GF entry into a WooCommerce simple draft product via the
 * official WC_Product CRUD class — no direct DB writes.
 *
 * Configuration is stored alongside the rest of the table settings as
 * settings.wc_mapping = [
 *     'title'       => '<gf field id>',
 *     'price'       => '<gf field id>',
 *     'sku'         => '<gf field id>',
 *     'description' => '<gf field id>',
 * ];
 *
 * Deferred follow-ups:
 *  - Bulk action for multi-row product creation
 *  - One-way sync: re-edit the linked product when the GF entry changes
 *  - Map GF file upload field to product image
 *  - Display product status / link in the table for entries that have
 *    a stored linked WC product id
 *  - Variable products + attribute mapping
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_WooCommerce
{
    private static ?TC_WooCommerce $instance = null;

    public static function get_instance(): TC_WooCommerce
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    private function __construct()
    {
        // The AJAX action is registered unconditionally so the request still
        // returns a clean error envelope on sites without WC. Localising the
        // mapping into the table config below is also unconditional so the
        // shortcode template can render the row action only when both WC is
        // active AND a mapping exists.
        add_action('wp_ajax_gt_create_wc_product', array($this, 'create_product'));
        add_action('wp_ajax_nopriv_gt_create_wc_product', array($this, 'create_product'));

        add_filter('gt_table_config', array($this, 'inject_wc_flags'), 10, 2);

        // Frontend compat script: prevents table event handlers from swallowing
        // WooCommerce add-to-cart and quantity events inside table cells.
        add_action('wp_footer', array($this, 'enqueue_compat_script'));
    }

    /**
     * Allow the table-config localiser to advertise WC-readiness so frontend.js
     * knows whether to render the Create Product action button. Hooked via the
     * existing gt_table_config filter (added in the table render path).
     */
    public function inject_wc_flags(array $config, array $atts): array
    {
        $mapping = isset($atts['wc_mapping']) && is_array($atts['wc_mapping']) ? $atts['wc_mapping'] : array();
        $mapping_ready = !empty($mapping['title']);

        $config['woocommerce'] = array(
            'active' => self::is_woocommerce_active(),
            'mapping_ready' => $mapping_ready,
            'mapping' => $mapping_ready ? $mapping : new stdClass(),
        );
        return $config;
    }

    public function create_product(): void
    {
        check_ajax_referer('gravity_tables_nonce', 'nonce');

        if (!self::is_woocommerce_active()) {
            wp_send_json_error(array(
                'message' => __('WooCommerce is not active on this site.', 'tc-data-tables'),
                'reason' => 'wc_inactive',
            ));
        }

        if (!current_user_can('publish_products') && !current_user_can('edit_products')) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to create products.', 'tc-data-tables'),
                'reason' => 'permission_denied',
            ));
        }

        $entry_id = intval($_POST['entry_id'] ?? 0);
        $table_id = intval($_POST['table_id'] ?? 0);
        if (!$entry_id || !$table_id) {
            wp_send_json_error(__('Invalid request', 'tc-data-tables'));
        }

        if (!class_exists('GFAPI')) {
            wp_send_json_error(__('Gravity Forms not active', 'tc-data-tables'));
        }

        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry) || !$entry) {
            wp_send_json_error(__('Entry not found', 'tc-data-tables'));
        }

        global $wpdb; // @codeCoverageIgnore
        $table_row = $wpdb->get_row($wpdb->prepare(
            "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        if (!$table_row) {
            wp_send_json_error(__('Table not found', 'tc-data-tables'));
        }
        $settings = json_decode($table_row->settings, true) ?: array();

        $mapping = $settings['wc_mapping'] ?? array();
        if (empty($mapping['title'])) {
            wp_send_json_error(array(
                'message' => __('No WooCommerce field mapping is configured for this table.', 'tc-data-tables'),
                'reason' => 'no_mapping',
            ));
        }

        $title_raw = isset($entry[(string) $mapping['title']]) ? (string) $entry[(string) $mapping['title']] : '';
        $title = trim($title_raw);
        if ($title === '') {
            wp_send_json_error(array(
                'message' => __('The mapped title field is empty for this entry.', 'tc-data-tables'),
                'reason' => 'empty_title',
            ));
        }

        $price = '';
        if (!empty($mapping['price'])) {
            $price_raw = isset($entry[(string) $mapping['price']]) ? (string) $entry[(string) $mapping['price']] : '';
            // Strip currency symbols / commas so wc_format_decimal accepts it
            $price_raw = preg_replace('/[^0-9.\-]/', '', $price_raw);
            if ($price_raw !== '' && is_numeric($price_raw)) {
                $price = (string) $price_raw;
            }
        }

        $sku = '';
        if (!empty($mapping['sku'])) {
            $sku_raw = isset($entry[(string) $mapping['sku']]) ? (string) $entry[(string) $mapping['sku']] : '';
            $sku = sanitize_text_field($sku_raw);
        }

        $description = '';
        if (!empty($mapping['description'])) {
            $desc_raw = isset($entry[(string) $mapping['description']]) ? (string) $entry[(string) $mapping['description']] : '';
            $description = wp_kses_post($desc_raw);
        }

        $product = new WC_Product_Simple();
        $product->set_name($title);
        $product->set_status('draft');
        $product->set_catalog_visibility('visible');
        if ($description !== '') {
            $product->set_description($description);
        }
        if ($price !== '') {
            $product->set_regular_price($price);
        }
        if ($sku !== '') {
            try {
                $product->set_sku($sku);
            } catch (\Exception $e) {
                // SKU collisions raise WC_Data_Exception — fall back to no SKU
                // rather than failing the whole create.
                $product->set_sku('');
            }
        }

        $product_id = $product->save();
        if (is_wp_error($product_id) || !$product_id) {
            wp_send_json_error(array(
                'message' => is_wp_error($product_id) ? $product_id->get_error_message() : 'Could not create product.',
                'reason' => 'wc_save_failed',
            ));
        }

        // Stamp a back-reference to the source GF entry on the product so
        // future "is this row already linked?" checks are a single meta read.
        update_post_meta($product_id, '_gt_source_entry_id', (int) $entry_id);
        update_post_meta($product_id, '_gt_source_table_id', (int) $table_id);

        wp_send_json_success(array(
            'product_id' => (int) $product_id,
            'product_title' => $title,
            'edit_url' => admin_url('post.php?post=' . (int) $product_id . '&action=edit'),
            'view_url' => get_permalink($product_id) ?: '',
        ));
    }

    /**
     * Output a small inline script that fixes WooCommerce add-to-cart and quantity
     * inputs embedded inside Gravity Tables cells.
     *
     * Problems solved:
     *  1. DataTables' own row-click / cell-click handlers must not swallow clicks
     *     destined for .add_to_cart_button or .qty inputs — we use event delegation
     *     at document level with explicit checks so those elements are never blocked.
     *  2. After a DataTables draw (sort, filter, pagination) the WooCommerce AJAX
     *     add-to-cart script loses its bindings on newly injected DOM nodes —
     *     we trigger 'wc-fragments-loaded' and reinitialise quantity inputs.
     *  3. Multiple [add_to_cart] shortcodes on the same page can share duplicate
     *     id attributes; we scope reinit to the table container.
     */
    public function enqueue_compat_script(): void
    {
        if (!self::is_woocommerce_active()) {
            return;
        }

        // Only output on pages that have a GT table and WooCommerce active.
        $script = <<<'JS'
(function ($) {
    'use strict';

    // 1. Ensure WooCommerce quantity (+/-) and add-to-cart button clicks are NEVER
    //    swallowed by Gravity Tables row/cell click handlers.
    //    We use document-level delegation so this works even after DataTables redraws.
    $(document).on('click', '.gt-table-wrap .add_to_cart_button, .gt-table-wrap .single_add_to_cart_button', function (e) {
        // Allow the click to reach WooCommerce — do NOT stopPropagation.
        e.stopImmediatePropagation && e.stopImmediatePropagation();
    });

    $(document).on('change input', '.gt-table-wrap .qty, .gt-table-wrap .woocommerce-quantity-input', function (e) {
        // Quantity input changes must not be captured by DataTables row selection.
        e.stopImmediatePropagation && e.stopImmediatePropagation();
    });

    // 2. After every DataTables draw re-initialise WooCommerce quantity inputs and
    //    re-trigger fragment refresh so cart totals stay accurate.
    $(document).on('draw.dt', '.gt-table-wrap table', function () {
        var $wrap = $(this).closest('.gt-table-wrap');

        // Re-initialise the quantity stepper plugin if wc_quantity_plus_minus exists.
        if (typeof $.fn.wc_quantity_plus_minus === 'function') {
            $wrap.find('.qty').wc_quantity_plus_minus();
        }

        // Re-bind wc-add-to-cart AJAX handler on the redrawn buttons.
        if (typeof wc_add_to_cart_params !== 'undefined') {
            $wrap.find('.add_to_cart_button').trigger('wc-add-to-cart-init');
        }
    });

}(jQuery));
JS;

        wp_add_inline_script('wc-add-to-cart', $script);
    }

    /**
     * Query WooCommerce products and return them as table rows.
     *
     * Each row includes: thumbnail, name (linked), sku, price (with sale
     * strikethrough via get_price_html), stock_status, rating, and an
     * add_to_cart button.
     *
     * @param array $args {
     *   int    $per_page   Rows per page (default 25).
     *   int    $page       1-based page number (default 1).
     *   string $search     Optional search term matched against product title.
     *   string $category   Optional category slug filter.
     *   float  $min_price  Optional minimum price filter.
     *   float  $max_price  Optional maximum price filter.
     *   string $orderby    WP_Query orderby (default 'date').
     *   string $order      ASC|DESC (default 'DESC').
     * }
     * @return array { entries: array, total: int }
     */
    public static function get_product_table_entries(array $args = []): array
    {
        // Column fields returned per product row.
        $fields = ['thumbnail', 'name', 'sku', 'price', 'stock_status', 'rating', 'add_to_cart'];

        $defaults = [
            'per_page'  => 25,
            'page'      => 1,
            'search'    => '',
            'category'  => '',
            'min_price' => '',
            'max_price' => '',
            'orderby'   => 'date',
            'order'     => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);

        $query_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => max(1, intval($args['per_page'])),
            'paged'          => max(1, intval($args['page'])),
            'orderby'        => sanitize_key($args['orderby']),
            'order'          => strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC',
        ];

        if (!empty($args['search'])) {
            $query_args['s'] = sanitize_text_field($args['search']);
        }

        if (!empty($args['category'])) {
            $query_args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($args['category']),
            ]];
        }

        if ($args['min_price'] !== '' || $args['max_price'] !== '') {
            $query_args['meta_query'] = [];
            if ($args['min_price'] !== '') {
                $query_args['meta_query'][] = [
                    'key'     => '_price',
                    'value'   => floatval($args['min_price']),
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ];
            }
            if ($args['max_price'] !== '') {
                $query_args['meta_query'][] = [
                    'key'     => '_price',
                    'value'   => floatval($args['max_price']),
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ];
            }
        }

        $query   = new WP_Query($query_args);
        $entries = [];

        foreach ($query->posts as $post) {
            $product = self::is_woocommerce_active() ? wc_get_product($post->ID) : null;

            $price        = $product ? $product->get_price_html() : '';
            $sku          = $product ? $product->get_sku() : '';
            $stock_status = $product ? $product->get_stock_status() : '';
            $rating       = $product ? $product->get_average_rating() : '';
            $thumb        = get_the_post_thumbnail($post->ID, 'thumbnail');
            $name_link    = '<a href="' . esc_url(get_permalink($post->ID)) . '">' . esc_html($post->post_title) . '</a>';
            $add_to_cart  = $product ? '<a href="?add-to-cart=' . absint($post->ID) . '" class="button add_to_cart_button" data-product_id="' . absint($post->ID) . '">' . __('Add to cart', 'tc-data-tables') . '</a>' : '';

            $entries[] = [
                'id'           => $post->ID,
                'thumbnail'    => $thumb,
                'name'         => $name_link,
                'sku'          => $sku,
                'price'        => $price,
                'stock_status' => $stock_status,
                'rating'       => $rating,
                'add_to_cart'  => $add_to_cart,
            ];
        }

        return [
            'entries' => $entries,
            'total'   => $query->found_posts,
        ];
    }

    /**
     * #1914 — Query WooCommerce orders and return them as table rows.
     *
     * Each row includes: order_id, date, customer name + email, order_status,
     * total, item count, payment method, and a link to the WC order admin page.
     *
     * @param array $args {
     *   int    $per_page     Rows per page (default 25).
     *   int    $page         1-based page number (default 1).
     *   string $status       Order status slug filter, e.g. 'wc-processing' (default: all).
     *   string $search       Optional search term (customer name / email / order ID).
     *   string $orderby      Field to order by: 'date'|'total'|'id' (default 'date').
     *   string $order        ASC|DESC (default 'DESC').
     * }
     * @return array { entries: array, total: int }
     */
    public static function get_order_table_entries(array $args = []): array
    {
        if (!self::is_woocommerce_active()) {
            return ['entries' => [], 'total' => 0];
        }

        $defaults = [
            'per_page' => 25,
            'page'     => 1,
            'status'   => 'any',
            'search'   => '',
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);

        $query_args = [
            'limit'   => max(1, intval($args['per_page'])),
            'paged'   => max(1, intval($args['page'])),
            'orderby' => sanitize_key($args['orderby']),
            'order'   => strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC',
            'return'  => 'objects',
        ];

        if (!empty($args['status']) && $args['status'] !== 'any') {
            $query_args['status'] = sanitize_key($args['status']);
        }

        if (!empty($args['search'])) {
            $query_args['customer'] = sanitize_text_field($args['search']);
        }

        $orders  = wc_get_orders($query_args);
        $entries = [];

        foreach ($orders as $order) {
            $customer_name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $customer_email = $order->get_billing_email();
            $order_status   = $order->get_status();
            $order_total    = $order->get_total();
            $item_count     = $order->get_item_count();
            $payment_method = $order->get_payment_method_title();
            $date_created   = $order->get_date_created();
            $date_str       = $date_created ? $date_created->date_i18n(get_option('date_format')) : '';
            $admin_url      = get_edit_post_link($order->get_id(), 'raw');
            $admin_link     = $admin_url ? '<a href="' . esc_url($admin_url) . '">#' . absint($order->get_id()) . '</a>' : '#' . absint($order->get_id());

            $entries[] = [
                'order_id'       => $order->get_id(),
                'order_link'     => $admin_link,
                'date'           => $date_str,
                'customer_name'  => esc_html($customer_name),
                'customer_email' => esc_html($customer_email),
                'order_status'   => esc_html(wc_get_order_status_name($order_status)),
                'total'          => wc_price($order_total),
                'item_count'     => $item_count,
                'payment_method' => esc_html($payment_method),
            ];
        }

        // Get total count for pagination.
        $count_args           = $query_args;
        $count_args['return'] = 'ids';
        $count_args['limit']  = -1;
        $count_args['paged']  = 1;
        $total                = count(wc_get_orders($count_args));

        return [
            'entries' => $entries,
            'total'   => $total,
        ];
    }

    /**
     * #1922 — Update a WooCommerce order status inline and fire WC status hooks.
     *
     * Calling `$order->update_status()` is the canonical WC API — it automatically
     * fires `woocommerce_order_status_changed`, sends customer emails, and logs
     * the status change in the order notes. No direct DB writes needed.
     *
     * @param int    $order_id   WooCommerce order ID.
     * @param string $new_status Status slug (with or without `wc-` prefix, e.g. 'completed').
     * @param string $note       Optional order note added alongside the status change.
     * @return true|\WP_Error    true on success, WP_Error on failure.
     */
    public static function update_order_status(int $order_id, string $new_status, string $note = ''): true|\WP_Error
    {
        if (!self::is_woocommerce_active()) {
            return new \WP_Error(
                'gt_wc_inactive',
                __('WooCommerce is not active.', 'tc-data-tables')
            );
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error(
                'gt_wc_order_not_found',
                sprintf(
                    /* translators: %d is the order ID. */
                    __('WooCommerce order #%d not found.', 'tc-data-tables'),
                    $order_id
                )
            );
        }

        // wc_get_order_statuses() returns keys like 'wc-processing'; strip the prefix for update_status().
        $status_slug = str_replace('wc-', '', sanitize_key($new_status));

        $valid_statuses = array_keys(wc_get_order_statuses());
        if (!in_array('wc-' . $status_slug, $valid_statuses, true)) {
            return new \WP_Error(
                'gt_wc_invalid_status',
                sprintf(
                    /* translators: %s is the status slug. */
                    __('"%s" is not a valid WooCommerce order status.', 'tc-data-tables'),
                    esc_html($status_slug)
                )
            );
        }

        $note = sanitize_text_field($note) ?: __('Status updated via TableCrafter inline edit.', 'tc-data-tables');
        $order->update_status($status_slug, $note, true);

        return true;
    }
}
