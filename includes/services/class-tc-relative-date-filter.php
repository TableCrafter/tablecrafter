<?php
/**
 * Relative-date-filter date-math service (slice 1 of #500).
 *
 * Pure, deterministic date arithmetic used by the relative-time row
 * filter (Today, Last 7 days, This quarter, etc.). The UI / AJAX / URL
 * persistence land under a follow-up; this class is the testable seam.
 *
 * All ranges are inclusive [start, end] with wall-clock 00:00:00 /
 * 23:59:59 boundaries in the supplied timezone — never UTC. Day, week,
 * month, quarter and year boundaries follow the calendar (ISO-8601 Mon
 * for week, Q1 = Jan–Mar for quarter).
 *
 * @package GravityTables
 * @since 4.7.2
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Relative_Date_Filter
{
    /**
     * Ordered preset map, keys in spec order.
     *
     * @return array<string, string>
     */
    public static function presets(): array
    {
        return array(
            'today'         => __('Today', 'tc-data-tables'),
            'yesterday'     => __('Yesterday', 'tc-data-tables'),
            'last_7_days'   => __('Last 7 days', 'tc-data-tables'),
            'last_30_days'  => __('Last 30 days', 'tc-data-tables'),
            'this_week'     => __('This week', 'tc-data-tables'),
            'last_week'     => __('Last week', 'tc-data-tables'),
            'this_month'    => __('This month', 'tc-data-tables'),
            'last_month'    => __('Last month', 'tc-data-tables'),
            'this_quarter'  => __('This quarter', 'tc-data-tables'),
            'this_year'     => __('This year', 'tc-data-tables'),
            'year_to_date'  => __('Year to date', 'tc-data-tables'),
            'custom'        => __('Custom range', 'tc-data-tables'),
        );
    }

    /**
     * Resolve a preset key to an inclusive [start, end] window.
     *
     * @param string                  $preset One of the preset keys.
     * @param DateTimeImmutable|null  $now    Reference "now" (defaults to current time in $tz).
     * @param DateTimeZone|null       $tz     Timezone (defaults to UTC). Wall-clock boundaries
     *                                        are computed against this zone.
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     *         null for `custom` and any unknown preset key.
     */
    public static function range_for(string $preset, ?DateTimeImmutable $now = null, ?DateTimeZone $tz = null): ?array
    {
        $tz  = $tz  ?: new DateTimeZone('UTC');
        $now = $now ? $now->setTimezone($tz) : new DateTimeImmutable('now', $tz);

        // Anchor to today's wall-clock midnight in $tz so day arithmetic
        // never crosses a DST boundary by accident.
        $today_start = $now->setTime(0, 0, 0);
        $today_end   = $now->setTime(23, 59, 59);

        switch ($preset) {
            case 'today':
                return array('start' => $today_start, 'end' => $today_end);

            case 'yesterday':
                $y = $today_start->modify('-1 day');
                return array(
                    'start' => $y->setTime(0, 0, 0),
                    'end'   => $y->setTime(23, 59, 59),
                );

            case 'last_7_days':
                return array(
                    'start' => $today_start->modify('-6 days'),
                    'end'   => $today_end,
                );

            case 'last_30_days':
                return array(
                    'start' => $today_start->modify('-29 days'),
                    'end'   => $today_end,
                );

            case 'this_week':
                return self::iso_week_for($today_start);

            case 'last_week':
                $prev = $today_start->modify('-7 days');
                return self::iso_week_for($prev);

            case 'this_month':
                return self::month_range($today_start);

            case 'last_month':
                $prev = $today_start->modify('first day of previous month');
                return self::month_range($prev);

            case 'this_quarter':
                return self::quarter_range($today_start);

            case 'this_year':
                $start = $today_start->setDate((int) $today_start->format('Y'), 1, 1)->setTime(0, 0, 0);
                $end   = $today_start->setDate((int) $today_start->format('Y'), 12, 31)->setTime(23, 59, 59);
                return array('start' => $start, 'end' => $end);

            case 'year_to_date':
                $start = $today_start->setDate((int) $today_start->format('Y'), 1, 1)->setTime(0, 0, 0);
                return array('start' => $start, 'end' => $today_end);

            case 'custom':
                return null;

            default:
                return null;
        }
    }

    /**
     * Mon 00:00:00 .. Sun 23:59:59 (ISO-8601) for the week containing $anchor.
     */
    private static function iso_week_for(DateTimeImmutable $anchor): array
    {
        // PHP 'N' format: 1 = Mon, 7 = Sun.
        $dow = (int) $anchor->format('N');
        $monday = $anchor->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        $sunday = $monday->modify('+6 days')->setTime(23, 59, 59);
        return array('start' => $monday, 'end' => $sunday);
    }

    /**
     * First-of-month 00:00 .. last-of-month 23:59:59 in $anchor's tz.
     */
    private static function month_range(DateTimeImmutable $anchor): array
    {
        $year  = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');
        $start = $anchor->setDate($year, $month, 1)->setTime(0, 0, 0);
        $last  = (int) $start->format('t');
        $end   = $anchor->setDate($year, $month, $last)->setTime(23, 59, 59);
        return array('start' => $start, 'end' => $end);
    }

    /**
     * Calendar quarter (Q1 = Jan–Mar, Q2 = Apr–Jun, Q3 = Jul–Sep, Q4 = Oct–Dec)
     * for the quarter containing $anchor, full wall-clock window.
     */
    private static function quarter_range(DateTimeImmutable $anchor): array
    {
        $year  = (int) $anchor->format('Y');
        $month = (int) $anchor->format('n');
        $first_month_of_q = ((int) floor(($month - 1) / 3)) * 3 + 1;
        $last_month_of_q  = $first_month_of_q + 2;
        $start = $anchor->setDate($year, $first_month_of_q, 1)->setTime(0, 0, 0);
        $last_day = (int) $anchor->setDate($year, $last_month_of_q, 1)->format('t');
        $end = $anchor->setDate($year, $last_month_of_q, $last_day)->setTime(23, 59, 59);
        return array('start' => $start, 'end' => $end);
    }
}
