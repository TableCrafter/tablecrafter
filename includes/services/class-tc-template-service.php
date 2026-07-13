<?php
/**
 * Pre-built table templates library.
 *
 * Ships 8 starter templates covering common use-cases. Templates are stored
 * locally - no external HTTP request is ever made. Users can also save any
 * existing table as a personal template via save_as_template().
 */

// @codeCoverageIgnoreStart
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Template_Service {

    const USER_TEMPLATES_OPTION = 'gt_user_templates';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return all available templates: built-in first, then user-defined.
     *
     * @return array[]
     */
    public static function get_templates(): array {
        return array_merge( self::builtin_templates(), self::get_user_templates() );
    }

    /**
     * Return a single template by slug, or null if not found.
     *
     * @param string $slug
     * @return array|null
     */
    public static function get_template( string $slug ): ?array {
        foreach ( self::get_templates() as $tpl ) {
            if ( ( $tpl['slug'] ?? '' ) === $slug ) {
                return $tpl;
            }
        }
        return null;
    }

    /**
     * Build a new table config array pre-populated from the given template slug.
     *
     * @param string $slug
     * @return array Table config array, or [] if the slug is unknown.
     */
    public static function apply_template( string $slug ): array {
        $tpl = self::get_template( $slug );
        if ( $tpl === null ) {
            return [];
        }

        return [
            'title'       => $tpl['name'],
            'columns'     => $tpl['columns'],
            'sample_data' => $tpl['sample_data'],
            'styling'     => $tpl['styling'] ?? [],
            'settings'    => $tpl['settings'] ?? [],
            'from_template' => $slug,
        ];
    }

    /**
     * Save an existing table as a user-defined personal template.
     *
     * @param int    $table_id  ID in wp_gravity_tables.
     * @param string $name      Human-readable template name.
     * @return array The saved template entry.
     */
    public static function save_as_template( int $table_id, string $name ): array {
        // @codeCoverageIgnoreStart -- Xdebug does not record the global declaration; method body is covered.
        global $wpdb;
        // @codeCoverageIgnoreEnd

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT settings FROM {$wpdb->prefix}gravity_tables WHERE id = %d",
                $table_id
            )
        );

        $settings = $row ? ( json_decode( $row->settings, true ) ?: [] ) : [];

        $template = [
            'slug'        => 'user-' . $table_id . '-' . sanitize_title( $name ),
            'name'        => sanitize_text_field( $name ),
            'columns'     => $settings['columns'] ?? [],
            'sample_data' => [],
            'styling'     => $settings['styling'] ?? [],
            'settings'    => $settings,
            'user_defined' => true,
        ];

        $existing = self::get_user_templates();
        // Replace if slug already exists.
        foreach ( $existing as $i => $t ) {
            if ( $t['slug'] === $template['slug'] ) {
                $existing[ $i ] = $template;
                update_option( self::USER_TEMPLATES_OPTION, $existing );
                return $template;
            }
        }

        $existing[] = $template;
        update_option( self::USER_TEMPLATES_OPTION, $existing );
        return $template;
    }

    /**
     * Return user-defined templates saved via save_as_template().
     *
     * @return array[]
     */
    public static function get_user_templates(): array {
        $stored = get_option( self::USER_TEMPLATES_OPTION, [] );
        return is_array( $stored ) ? $stored : [];
    }

    // -------------------------------------------------------------------------
    // Built-in templates
    // -------------------------------------------------------------------------

    private static function builtin_templates(): array {
        return [
            [
                'slug'  => 'pricing',
                'name'  => __( 'Pricing / Comparison Table', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'feature', 'label' => 'Feature',      'type' => 'text' ],
                    [ 'id' => 'basic',   'label' => 'Basic',         'type' => 'text' ],
                    [ 'id' => 'pro',     'label' => 'Pro',           'type' => 'text' ],
                    [ 'id' => 'enterprise', 'label' => 'Enterprise', 'type' => 'text' ],
                ],
                'sample_data' => [
                    [ 'feature' => 'Storage',      'basic' => '1 GB',       'pro' => '50 GB',   'enterprise' => 'Unlimited' ],
                    [ 'feature' => 'Users',        'basic' => '1',          'pro' => '10',      'enterprise' => 'Unlimited' ],
                    [ 'feature' => 'Support',      'basic' => 'Email',      'pro' => 'Priority','enterprise' => 'Dedicated' ],
                    [ 'feature' => 'API Access',   'basic' => '✗',          'pro' => '✓',       'enterprise' => '✓' ],
                    [ 'feature' => 'Custom Domain','basic' => '✗',          'pro' => '✓',       'enterprise' => '✓' ],
                ],
                'styling' => [ 'preset' => 'striped', 'highlight_header' => true ],
                'settings' => [],
            ],
            [
                'slug'  => 'feature-matrix',
                'name'  => __( 'Product Feature Matrix', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'product',  'label' => 'Product',  'type' => 'text' ],
                    [ 'id' => 'category', 'label' => 'Category', 'type' => 'text' ],
                    [ 'id' => 'rating',   'label' => 'Rating',   'type' => 'number' ],
                    [ 'id' => 'price',    'label' => 'Price',    'type' => 'number' ],
                    [ 'id' => 'in_stock', 'label' => 'In Stock', 'type' => 'text' ],
                ],
                'sample_data' => [
                    [ 'product' => 'Widget A', 'category' => 'Widgets', 'rating' => 4.5, 'price' => 29.99, 'in_stock' => 'Yes' ],
                    [ 'product' => 'Widget B', 'category' => 'Widgets', 'rating' => 3.8, 'price' => 19.99, 'in_stock' => 'No' ],
                    [ 'product' => 'Gadget X', 'category' => 'Gadgets', 'rating' => 4.9, 'price' => 99.00, 'in_stock' => 'Yes' ],
                ],
                'styling' => [ 'preset' => 'bordered' ],
                'settings' => [],
            ],
            [
                'slug'  => 'team-directory',
                'name'  => __( 'Team / Contact Directory', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'name',       'label' => 'Name',       'type' => 'text' ],
                    [ 'id' => 'department', 'label' => 'Department', 'type' => 'text' ],
                    [ 'id' => 'role',       'label' => 'Role',       'type' => 'text' ],
                    [ 'id' => 'email',      'label' => 'Email',      'type' => 'email' ],
                    [ 'id' => 'phone',      'label' => 'Phone',      'type' => 'text' ],
                ],
                'sample_data' => [
                    [ 'name' => 'Alice Johnson', 'department' => 'Engineering', 'role' => 'Lead Dev',    'email' => 'alice@example.com', 'phone' => '555-0101' ],
                    [ 'name' => 'Bob Smith',     'department' => 'Marketing',   'role' => 'Manager',     'email' => 'bob@example.com',   'phone' => '555-0102' ],
                    [ 'name' => 'Carol White',   'department' => 'Design',      'role' => 'UI Designer', 'email' => 'carol@example.com', 'phone' => '555-0103' ],
                ],
                'styling' => [ 'preset' => 'clean' ],
                'settings' => [ 'enable_search' => true ],
            ],
            [
                'slug'  => 'event-schedule',
                'name'  => __( 'Event Schedule', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'date',     'label' => 'Date',     'type' => 'date' ],
                    [ 'id' => 'time',     'label' => 'Time',     'type' => 'text' ],
                    [ 'id' => 'event',    'label' => 'Event',    'type' => 'text' ],
                    [ 'id' => 'location', 'label' => 'Location', 'type' => 'text' ],
                    [ 'id' => 'speaker',  'label' => 'Speaker',  'type' => 'text' ],
                ],
                'sample_data' => [
                    [ 'date' => '2026-05-01', 'time' => '09:00 AM', 'event' => 'Keynote',     'location' => 'Main Hall',     'speaker' => 'Jane Doe' ],
                    [ 'date' => '2026-05-01', 'time' => '11:00 AM', 'event' => 'Workshop A',  'location' => 'Room 101',      'speaker' => 'John Smith' ],
                    [ 'date' => '2026-05-02', 'time' => '02:00 PM', 'event' => 'Panel Q&A',   'location' => 'Conference Hall','speaker' => 'Various' ],
                ],
                'styling' => [ 'preset' => 'striped' ],
                'settings' => [ 'default_sort_column' => 'date', 'default_sort_direction' => 'asc' ],
            ],
            [
                'slug'  => 'league-standings',
                'name'  => __( 'Sports League Standings', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'pos',    'label' => '#',    'type' => 'number' ],
                    [ 'id' => 'team',   'label' => 'Team', 'type' => 'text' ],
                    [ 'id' => 'played', 'label' => 'P',    'type' => 'number' ],
                    [ 'id' => 'won',    'label' => 'W',    'type' => 'number' ],
                    [ 'id' => 'drawn',  'label' => 'D',    'type' => 'number' ],
                    [ 'id' => 'lost',   'label' => 'L',    'type' => 'number' ],
                    [ 'id' => 'gf',     'label' => 'GF',   'type' => 'number' ],
                    [ 'id' => 'ga',     'label' => 'GA',   'type' => 'number' ],
                    [ 'id' => 'gd',     'label' => 'GD',   'type' => 'number' ],
                    [ 'id' => 'points', 'label' => 'Pts',  'type' => 'number' ],
                ],
                'sample_data' => [
                    [ 'pos' => 1, 'team' => 'Eagles',  'played' => 10, 'won' => 8, 'drawn' => 0, 'lost' => 2, 'gf' => 24, 'ga' => 10, 'gd' => 14, 'points' => 24 ],
                    [ 'pos' => 2, 'team' => 'Lions',   'played' => 10, 'won' => 7, 'drawn' => 0, 'lost' => 3, 'gf' => 20, 'ga' => 12, 'gd' =>  8, 'points' => 21 ],
                    [ 'pos' => 3, 'team' => 'Tigers',  'played' => 10, 'won' => 5, 'drawn' => 0, 'lost' => 5, 'gf' => 15, 'ga' => 15, 'gd' =>  0, 'points' => 15 ],
                    [ 'pos' => 4, 'team' => 'Wolves',  'played' => 10, 'won' => 3, 'drawn' => 2, 'lost' => 5, 'gf' => 12, 'ga' => 18, 'gd' => -6, 'points' => 11 ],
                    [ 'pos' => 5, 'team' => 'Bears',   'played' => 10, 'won' => 2, 'drawn' => 1, 'lost' => 7, 'gf' =>  8, 'ga' => 22, 'gd' => -14,'points' =>  7 ],
                ],
                'styling' => [ 'preset' => 'compact' ],
                'settings' => [
                    'default_sort_column' => 'points',
                    'default_sort_direction' => 'desc',
                    'sort_tiebreakers' => [ 'gd', 'gf' ],
                ],
            ],
            [
                'slug'  => 'budget-tracker',
                'name'  => __( 'Financial Data / Budget Tracker', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'category',    'label' => 'Category',    'type' => 'text' ],
                    [ 'id' => 'budgeted',    'label' => 'Budgeted',    'type' => 'number' ],
                    [ 'id' => 'actual',      'label' => 'Actual',      'type' => 'number' ],
                    [ 'id' => 'variance',    'label' => 'Variance',    'type' => 'number' ],
                    [ 'id' => 'notes',       'label' => 'Notes',       'type' => 'text' ],
                ],
                'sample_data' => [
                    [ 'category' => 'Salaries',   'budgeted' => 50000, 'actual' => 48000, 'variance' => 2000,  'notes' => 'Under budget' ],
                    [ 'category' => 'Marketing',  'budgeted' => 10000, 'actual' => 11500, 'variance' => -1500, 'notes' => 'Over budget' ],
                    [ 'category' => 'Equipment',  'budgeted' => 5000,  'actual' => 4800,  'variance' => 200,   'notes' => '' ],
                ],
                'styling' => [ 'preset' => 'bordered' ],
                'settings' => [],
            ],
            [
                'slug'  => 'faq',
                'name'  => __( 'Simple FAQ / Two-Column', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'question', 'label' => 'Question', 'type' => 'text' ],
                    [ 'id' => 'answer',   'label' => 'Answer',   'type' => 'text' ],
                ],
                'sample_data' => [
                    [ 'question' => 'What is the refund policy?', 'answer' => 'Full refund within 30 days.' ],
                    [ 'question' => 'How do I reset my password?', 'answer' => 'Click "Forgot password" on the login page.' ],
                    [ 'question' => 'Is there a free trial?', 'answer' => 'Yes, 14 days free, no credit card required.' ],
                ],
                'styling' => [ 'preset' => 'clean' ],
                'settings' => [ 'enable_search' => true ],
            ],
            [
                'slug'  => 'employee-list',
                'name'  => __( 'Employee / Inventory List', 'tc-data-tables' ),
                'columns' => [
                    [ 'id' => 'id',         'label' => 'ID',         'type' => 'number' ],
                    [ 'id' => 'name',       'label' => 'Name',       'type' => 'text' ],
                    [ 'id' => 'department', 'label' => 'Department', 'type' => 'text' ],
                    [ 'id' => 'start_date', 'label' => 'Start Date', 'type' => 'date' ],
                    [ 'id' => 'status',     'label' => 'Status',     'type' => 'text' ],
                ],
                'sample_data' => [
                    [ 'id' => 1001, 'name' => 'Alice Johnson', 'department' => 'Engineering', 'start_date' => '2021-03-15', 'status' => 'Active' ],
                    [ 'id' => 1002, 'name' => 'Bob Smith',     'department' => 'Marketing',   'start_date' => '2019-07-01', 'status' => 'Active' ],
                    [ 'id' => 1003, 'name' => 'Carol White',   'department' => 'Design',      'start_date' => '2023-01-10', 'status' => 'On Leave' ],
                ],
                'styling' => [ 'preset' => 'striped' ],
                'settings' => [ 'enable_search' => true, 'enable_pagination' => true ],
            ],
        ];
    }
}
