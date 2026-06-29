<?php
/**
 * Charting / data-visualization shortcode for Gravity Tables (#34 MVP).
 *
 * Shortcode:
 *   [gravity_chart table_id="1" type="bar" field="20" group_by="20" agg="count"]
 *
 * Attributes:
 *   table_id  (required) ID of an active gravity_tables row (drives form_id)
 *   type      bar | donut          default: bar
 *   field     GF field id          numeric source for sum/avg/min/max
 *                                  (ignored when agg=count)
 *   group_by  GF field id          categorical bucket; falls back to `field`
 *   agg       count|sum|avg|min|max   default: count
 *   limit     int                  cap on number of buckets after sorting
 *                                  (default 12; max 50)
 *   title     string               optional chart heading
 *   width     int (px)             default 540
 *   height    int (px)             default 280
 *
 * Renders pure inline SVG — no JS dependency, no external chart library.
 *
 * Deferred follow-ups: pie, line, multi-series, color theme picker, lazy
 * Chart.js for richer interactivity, "show alongside table" coordination,
 * CSV/PNG download of the chart.
 *
 * @package GravityTables
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Chart
{
    private static ?TC_Chart $instance = null;
    private const PALETTE = array(
        '#2271b1', '#46b450', '#f0b849', '#d63638', '#6b46c1',
        '#0891b2', '#db2777', '#65a30d', '#ea580c', '#0ea5e9',
        '#7c3aed', '#16a34a',
    );

    public static function get_instance(): TC_Chart
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('gravity_chart', array($this, 'render_shortcode'));
    }

    public function render_shortcode($atts): string
    {
        $atts = shortcode_atts(array(
            'table_id' => 0,
            'type' => 'bar',
            'field' => '',
            'group_by' => '',
            'agg' => 'count',
            'limit' => 12,
            'title' => '',
            'width' => 540,
            'height' => 280,
        ), is_array($atts) ? $atts : array(), 'gravity_chart');

        $table_id = intval($atts['table_id']);
        if (!$table_id) {
            return '<p class="gt-chart-error">' . esc_html__('gravity_chart: table_id is required.', 'tc-data-tables') . '</p>';
        }

        global $wpdb;
        $table = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE id = %d AND status = 'active'",
            $table_id
        ));
        if (!$table) {
            return '<p class="gt-chart-error">' . esc_html__('gravity_chart: table not found.', 'tc-data-tables') . '</p>';
        }

        if (!class_exists('GFAPI')) {
            return '<p class="gt-chart-error">' . esc_html__('gravity_chart: Gravity Forms is not active.', 'tc-data-tables') . '</p>';
        }

        $form_id = (int) $table->form_id;
        $agg = strtolower((string) $atts['agg']);
        if (!in_array($agg, array('count', 'sum', 'avg', 'min', 'max'), true)) {
            $agg = 'count';
        }
        $type = strtolower((string) $atts['type']);
        if (!in_array($type, array('bar', 'donut'), true)) {
            $type = 'bar';
        }
        $group_by = (string) ($atts['group_by'] !== '' ? $atts['group_by'] : $atts['field']);
        $field = (string) $atts['field'];
        if ($group_by === '') {
            return '<p class="gt-chart-error">' . esc_html__('gravity_chart: provide either field or group_by.', 'tc-data-tables') . '</p>';
        }

        $limit = max(1, min(50, intval($atts['limit'])));

        $buckets = $this->aggregate($form_id, $group_by, $field, $agg);
        if (empty($buckets)) {
            return '<p class="gt-chart-empty">' . esc_html__('No data to chart.', 'tc-data-tables') . '</p>';
        }

        // Sort by value desc, cap to limit
        uasort($buckets, function ($a, $b) { return $b <=> $a; });
        $buckets = array_slice($buckets, 0, $limit, true);

        $width = max(280, intval($atts['width']));
        $height = max(180, intval($atts['height']));
        $title = (string) $atts['title'];

        if ($type === 'donut') {
            return $this->render_donut($buckets, $title, $width, $height);
        }
        return $this->render_bar($buckets, $title, $width, $height, $agg);
    }

    /**
     * Group entries by `group_field` and aggregate `value_field` according
     * to $agg. Returns an associative array of [bucketLabel => numericValue].
     */
    private function aggregate(int $form_id, string $group_field, string $value_field, string $agg): array
    {
        global $wpdb;

        $group_field = preg_replace('/[^0-9._a-z]/i', '', $group_field);
        if ($group_field === '') return array();

        // Pull bucket label + (for non-count aggs) the value-field value per entry.
        // Using meta self-joins keeps this efficient on tables with many fields.
        $sql = "SELECT em_g.meta_value AS bucket";
        if ($agg !== 'count' && $value_field !== '' && $value_field !== $group_field) {
            $value_field = preg_replace('/[^0-9._a-z]/i', '', $value_field);
            $sql .= ", em_v.meta_value AS val";
        } elseif ($agg !== 'count' && $value_field === $group_field) {
            $sql .= ", em_g.meta_value AS val";
        }
        $sql .= " FROM {$wpdb->prefix}gf_entry e";
        $sql .= " INNER JOIN {$wpdb->prefix}gf_entry_meta em_g ON em_g.entry_id = e.id AND em_g.meta_key = %s";
        if ($agg !== 'count' && $value_field !== '' && $value_field !== $group_field) {
            $sql .= " LEFT JOIN {$wpdb->prefix}gf_entry_meta em_v ON em_v.entry_id = e.id AND em_v.meta_key = %s";
        }
        $sql .= " WHERE e.form_id = %d AND e.status = 'active'";

        $params = array($group_field);
        if ($agg !== 'count' && $value_field !== '' && $value_field !== $group_field) {
            $params[] = $value_field;
        }
        $params[] = $form_id;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (empty($rows)) return array();

        $buckets = array();
        $sums = array();
        $mins = array();
        $maxs = array();
        $counts = array();

        foreach ($rows as $r) {
            $key = (string) ($r['bucket'] ?? '');
            if ($key === '') continue;

            if (!isset($counts[$key])) {
                $counts[$key] = 0;
                $sums[$key] = 0.0;
                $mins[$key] = null;
                $maxs[$key] = null;
            }
            $counts[$key]++;

            if ($agg !== 'count') {
                $val_raw = $r['val'] ?? null;
                if (!is_numeric($val_raw)) continue;
                $val = (float) $val_raw;
                $sums[$key] += $val;
                $mins[$key] = $mins[$key] === null ? $val : min($mins[$key], $val);
                $maxs[$key] = $maxs[$key] === null ? $val : max($maxs[$key], $val);
            }
        }

        switch ($agg) {
            case 'sum':
                $buckets = $sums;
                break;
            case 'avg':
                foreach ($counts as $k => $c) {
                    $buckets[$k] = $c > 0 ? ($sums[$k] / $c) : 0.0;
                }
                break;
            case 'min':
                $buckets = $mins;
                break;
            case 'max':
                $buckets = $maxs;
                break;
            case 'count':
            default:
                $buckets = $counts;
                break;
        }

        // Drop nulls
        $buckets = array_filter($buckets, function ($v) { return $v !== null; });
        return $buckets;
    }

    private function render_bar(array $buckets, string $title, int $w, int $h, string $agg): string
    {
        $padding_left = 60;
        $padding_top = $title !== '' ? 36 : 16;
        $padding_right = 16;
        $padding_bottom = 60;

        $plot_w = $w - $padding_left - $padding_right;
        $plot_h = $h - $padding_top - $padding_bottom;

        $max = max($buckets);
        if ($max <= 0) $max = 1;

        $count = count($buckets);
        $bar_gap = 6;
        $bar_w = max(8, intval(($plot_w - ($count + 1) * $bar_gap) / max(1, $count)));

        $svg = sprintf('<svg class="gt-chart gt-chart-bar" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="%s">',
            $w, $h, esc_attr($title !== '' ? $title : 'Bar chart'));

        if ($title !== '') {
            $svg .= sprintf('<text x="%d" y="20" font-size="14" font-weight="600" fill="#1d2327">%s</text>',
                $padding_left, esc_html($title));
        }

        // Axis baseline
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#d1d5db" stroke-width="1" />',
            $padding_left, $padding_top + $plot_h,
            $padding_left + $plot_w, $padding_top + $plot_h);

        // Y-axis max label
        $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#6b7280" text-anchor="end">%s</text>',
            $padding_left - 6, $padding_top + 4, esc_html($this->format_number($max)));

        $i = 0;
        $x = $padding_left + $bar_gap;
        foreach ($buckets as $label => $value) {
            $bar_h = $value > 0 ? intval(($value / $max) * ($plot_h - 4)) : 1;
            $bar_y = $padding_top + $plot_h - $bar_h;
            $color = self::PALETTE[$i % count(self::PALETTE)];

            // Bar
            $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="%s" rx="3"><title>%s: %s</title></rect>',
                $x, $bar_y, $bar_w, $bar_h, $color,
                esc_html((string) $label), esc_html($this->format_number($value)));

            // Value label above bar
            $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#374151" text-anchor="middle">%s</text>',
                $x + intval($bar_w / 2), $bar_y - 4, esc_html($this->format_number($value)));

            // Category label below axis (truncated)
            $cat = (string) $label;
            if (strlen($cat) > 14) $cat = substr($cat, 0, 12) . '…';
            $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#6b7280" text-anchor="end" transform="rotate(-45 %d %d)">%s</text>',
                $x + intval($bar_w / 2),
                $padding_top + $plot_h + 14,
                $x + intval($bar_w / 2),
                $padding_top + $plot_h + 14,
                esc_html($cat));

            $x += $bar_w + $bar_gap;
            $i++;
        }

        // Aggregation footer
        $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#9ca3af">%s</text>',
            $padding_left, $h - 6, esc_html(strtoupper($agg)));

        $svg .= '</svg>';
        return '<figure class="gt-chart-wrap">' . $svg . '</figure>';
    }

    private function render_donut(array $buckets, string $title, int $w, int $h): string
    {
        $cx = intval($w / 2);
        $cy = intval($h / 2) + ($title !== '' ? 10 : 0);
        $outer = min($cx, $cy) - 30;
        $inner = intval($outer * 0.55);

        $total = array_sum($buckets);
        if ($total <= 0) $total = 1;

        $svg = sprintf('<svg class="gt-chart gt-chart-donut" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="%s">',
            $w, $h, esc_attr($title !== '' ? $title : 'Donut chart'));

        if ($title !== '') {
            $svg .= sprintf('<text x="%d" y="20" font-size="14" font-weight="600" fill="#1d2327" text-anchor="middle">%s</text>',
                $cx, esc_html($title));
        }

        $angle = -M_PI / 2; // start at top
        $i = 0;
        foreach ($buckets as $label => $value) {
            $slice_angle = ($value / $total) * 2 * M_PI;
            $end_angle = $angle + $slice_angle;
            $color = self::PALETTE[$i % count(self::PALETTE)];

            $x1 = $cx + $outer * cos($angle);
            $y1 = $cy + $outer * sin($angle);
            $x2 = $cx + $outer * cos($end_angle);
            $y2 = $cy + $outer * sin($end_angle);
            $x3 = $cx + $inner * cos($end_angle);
            $y3 = $cy + $inner * sin($end_angle);
            $x4 = $cx + $inner * cos($angle);
            $y4 = $cy + $inner * sin($angle);
            $large_arc = $slice_angle > M_PI ? 1 : 0;

            $d = sprintf(
                'M %.2f %.2f A %d %d 0 %d 1 %.2f %.2f L %.2f %.2f A %d %d 0 %d 0 %.2f %.2f Z',
                $x1, $y1, $outer, $outer, $large_arc, $x2, $y2,
                $x3, $y3, $inner, $inner, $large_arc, $x4, $y4
            );

            $pct = round(($value / $total) * 100, 1);
            $svg .= sprintf('<path d="%s" fill="%s"><title>%s: %s (%s%%)</title></path>',
                esc_attr($d), $color,
                esc_html((string) $label), esc_html($this->format_number($value)), esc_html((string) $pct));

            $angle = $end_angle;
            $i++;
        }

        // Center total
        $svg .= sprintf('<text x="%d" y="%d" font-size="14" font-weight="600" fill="#1d2327" text-anchor="middle">%s</text>',
            $cx, $cy + 5, esc_html($this->format_number($total)));
        $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#6b7280" text-anchor="middle">total</text>',
            $cx, $cy + 20);

        // Legend
        $legend_x = 10;
        $legend_y = $h - 14 * count($buckets) - 4;
        if ($legend_y < ($title !== '' ? 30 : 4)) $legend_y = ($title !== '' ? 30 : 4);
        $i = 0;
        foreach ($buckets as $label => $value) {
            $color = self::PALETTE[$i % count(self::PALETTE)];
            $cat = (string) $label;
            if (strlen($cat) > 18) $cat = substr($cat, 0, 16) . '…';
            $svg .= sprintf('<rect x="%d" y="%d" width="10" height="10" fill="%s" />',
                $legend_x, $legend_y + ($i * 14), $color);
            $svg .= sprintf('<text x="%d" y="%d" font-size="10" fill="#374151">%s</text>',
                $legend_x + 14, $legend_y + ($i * 14) + 9, esc_html($cat));
            $i++;
        }

        $svg .= '</svg>';
        return '<figure class="gt-chart-wrap">' . $svg . '</figure>';
    }

    private function format_number(float $n): string
    {
        if (floor($n) === $n) {
            return number_format($n, 0);
        }
        return number_format($n, 2);
    }
}
