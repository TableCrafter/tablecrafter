<?php
/**
 * Map view shortcode for Gravity Tables (#36 MVP).
 *
 * Shortcode:
 *   [gravity_map table_id="1" lat_field="35" lng_field="36" label_field="2"
 *                height="420" zoom="auto" tiles="osm"]
 *
 * Required attributes:
 *   table_id    Active gravity_tables row id (drives form_id)
 *   lat_field   GF field id holding latitude as a decimal
 *   lng_field   GF field id holding longitude as a decimal
 *
 * Optional:
 *   label_field GF field id whose value is shown in each marker popup
 *   height      Map height in px (default 420)
 *   zoom        "auto" (fit-bounds) or an integer 1..18 (default "auto")
 *   tiles       Currently only "osm" (OpenStreetMap) is supported
 *
 * Renders a Leaflet map (loaded from a CDN, only on pages that use the
 * shortcode) populated server-side with the lat/lng/label triples for
 * every active entry of the linked form.
 *
 * Deferred follow-ups: automatic address-field detection + geocoding
 * with cached lat/lng meta, Google Maps provider with API key, marker
 * clustering for large datasets, live filter sync with the table
 * shortcode on the same page.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Map
{
    private static ?TC_Map $instance = null;
    private bool $assets_registered = false;

    public static function get_instance(): TC_Map
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('gravity_map', array($this, 'render_shortcode'));
    }

    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts(array(
            'table_id' => 0,
            'lat_field' => '',
            'lng_field' => '',
            'label_field' => '',
            'height' => 420,
            'zoom' => 'auto',
            'tiles' => 'osm',
        ), is_array($atts) ? $atts : array(), 'gravity_map');

        $table_id = intval($atts['table_id']);
        $lat_field = trim((string) $atts['lat_field']);
        $lng_field = trim((string) $atts['lng_field']);

        if (!$table_id || $lat_field === '' || $lng_field === '') {
            return '<p class="gt-map-error">' . esc_html__('gravity_map: table_id, lat_field, and lng_field are required.', 'tc-data-tables') . '</p>';
        }

        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd
        $table = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        if (!$table) {
            return '<p class="gt-map-error">' . esc_html__('gravity_map: table not found.', 'tc-data-tables') . '</p>';
        }

        $form_id = (int) $table->form_id;
        $label_field = trim((string) $atts['label_field']);

        $points = $this->load_points($form_id, $lat_field, $lng_field, $label_field);

        $map_id = 'gt-map-' . wp_generate_password(8, false, false);
        $height = max(180, intval($atts['height']));
        $zoom = $atts['zoom'] === 'auto' ? 'auto' : max(1, min(18, intval($atts['zoom'])));

        $this->register_assets();
        wp_enqueue_style('gt-leaflet');
        wp_enqueue_script('gt-leaflet');

        $payload = array(
            'mapId' => $map_id,
            'points' => array_values($points),
            'zoom' => $zoom,
        );

        $init_js = '(function () {
            var data = ' . wp_json_encode($payload) . ';
            function init() {
                if (typeof L === "undefined") { setTimeout(init, 80); return; }
                var el = document.getElementById(data.mapId);
                if (!el || el.dataset.gtInited) return;
                el.dataset.gtInited = "1";

                var map = L.map(el);
                L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                    maxZoom: 18,
                    attribution: "© OpenStreetMap contributors"
                }).addTo(map);

                if (!data.points.length) {
                    map.setView([0, 0], 2);
                    return;
                }

                var bounds = [];
                data.points.forEach(function (p) {
                    var marker = L.marker([p.lat, p.lng]).addTo(map);
                    if (p.label) marker.bindPopup(String(p.label));
                    bounds.push([p.lat, p.lng]);
                });

                if (data.zoom === "auto") {
                    map.fitBounds(bounds, { padding: [24, 24], maxZoom: 16 });
                    if (data.points.length === 1) map.setZoom(13);
                } else {
                    map.setView(bounds[0], data.zoom);
                }
            }
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", init);
            } else {
                init();
            }
        })();';

        wp_add_inline_script('gt-leaflet', $init_js);

        $empty_msg = empty($points)
            ? '<p class="gt-map-empty">' . esc_html__('No entries with valid coordinates.', 'tc-data-tables') . '</p>'
            : '';

        return '<div class="gt-map-wrapper">'
            . '<div id="' . esc_attr($map_id) . '" class="gt-map" style="height: ' . (int) $height . 'px;"></div>'
            . $empty_msg
            . '</div>';
    }

    private function register_assets(): void
    {
        if ($this->assets_registered) return;
        $this->assets_registered = true;

        // #600 slice 2: per-source CDN opt-out + self-host hook.
        //
        // Disable Leaflet outright:
        //   add_filter('gt_disable_third_party_cdn',         '__return_true'); // global kill-switch
        //   add_filter('gt_disable_third_party_cdn_leaflet', '__return_true'); // leaflet only
        // When disabled, Map cells render the lat/lng text-only fallback
        // because the leaflet script never loads.
        //
        // Self-host: replace the unpkg.com base with your own mirror or a
        // bundled copy in /wp-content/uploads/, e.g.:
        //   add_filter('gt_leaflet_url_base', fn() => '/wp-content/uploads/gt-vendor/leaflet/1.9.4');
        if (!function_exists('gt_third_party_cdn_source_disabled')) {
            // @codeCoverageIgnoreStart
            require_once __DIR__ . '/helpers-cdn.php';
            // @codeCoverageIgnoreEnd
        }
        if (gt_third_party_cdn_source_disabled('leaflet')) {
            return;
        }

        $leaflet_base = (string) apply_filters('gt_leaflet_url_base', 'https://unpkg.com/leaflet@1.9.4/dist');
        $leaflet_base = rtrim($leaflet_base, '/');

        wp_register_style(
            'gt-leaflet',
            $leaflet_base . '/leaflet.css',
            array(),
            '1.9.4'
        );
        wp_register_script(
            'gt-leaflet',
            $leaflet_base . '/leaflet.js',
            array(),
            '1.9.4',
            true
        );
    }

    /**
     * Pull lat/lng (and optional label) for every active entry of $form_id.
     * Drops rows whose lat or lng don't parse as finite numbers.
     */
    private function load_points(int $form_id, string $lat_field, string $lng_field, string $label_field): array
    {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $lat_field = preg_replace('/[^0-9._a-z]/i', '', $lat_field);
        $lng_field = preg_replace('/[^0-9._a-z]/i', '', $lng_field);
        $label_field = preg_replace('/[^0-9._a-z]/i', '', $label_field);

        $sql = "SELECT e.id AS entry_id,
                       em_lat.meta_value AS lat,
                       em_lng.meta_value AS lng";
        if ($label_field !== '') {
            $sql .= ", em_lbl.meta_value AS label";
        }
        $sql .= " FROM {$wpdb->prefix}gf_entry e
                  INNER JOIN {$wpdb->prefix}gf_entry_meta em_lat
                      ON em_lat.entry_id = e.id AND em_lat.meta_key = %s
                  INNER JOIN {$wpdb->prefix}gf_entry_meta em_lng
                      ON em_lng.entry_id = e.id AND em_lng.meta_key = %s";
        if ($label_field !== '') {
            $sql .= " LEFT JOIN {$wpdb->prefix}gf_entry_meta em_lbl
                      ON em_lbl.entry_id = e.id AND em_lbl.meta_key = %s";
        }
        $sql .= " WHERE e.form_id = %d AND e.status = 'active'";

        $params = array($lat_field, $lng_field);
        if ($label_field !== '') $params[] = $label_field;
        $params[] = $form_id;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (empty($rows)) return array();

        $points = array();
        foreach ($rows as $r) {
            if (!is_numeric($r['lat']) || !is_numeric($r['lng'])) continue;
            $lat = (float) $r['lat'];
            $lng = (float) $r['lng'];
            if (!is_finite($lat) || !is_finite($lng)) continue;
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) continue;
            $points[] = array(
                'entryId' => (int) $r['entry_id'],
                'lat' => $lat,
                'lng' => $lng,
                'label' => isset($r['label']) ? (string) $r['label'] : '',
            );
        }
        return $points;
    }
}
