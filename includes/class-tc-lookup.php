<?php
/**
 * Lookup functionality for Gravity Tables
 * 
 * Handles field value lookups and transformations for display.
 * Supports user lookups, entry lookups, and custom field mappings.
 * 
 * Provides three-tier fallback system for reliable data retrieval
 * and role-based filtering for security.
 *
 * @package GravityTables
 * @author Fahad Murtaza <business@isupercoder.com>
 * @since 1.0.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Lookup
{

    private static ?TC_Lookup $instance = null;

    public static function get_instance(): TC_Lookup
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Lookup processor is initialized when needed
    }

    /**
     * Process lookup values for a field
     */
    public function process_lookup_value($value, array $lookup_config): string
    {
        if (empty($value) || empty($lookup_config) || !isset($lookup_config['type'])) {
            return $value;
        }

        switch ($lookup_config['type']) {
            case 'user':
                return $this->lookup_user($value, $lookup_config);

            case 'post':
                return $this->lookup_post($value, $lookup_config);

            case 'custom':
                return $this->lookup_custom($value, $lookup_config);

            default:
                return $value;
        }
    }

    /**
     * Lookup WordPress user
     */
    private function lookup_user($user_id, array $lookup_config): string
    {
        $user_id = intval($user_id);
        if (!$user_id) {
            // @codeCoverageIgnoreStart
            return $user_id;
            // @codeCoverageIgnoreEnd
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return $user_id; // Return original value if user not found
        }

        $field = isset($lookup_config['user_field']) ? $lookup_config['user_field'] : 'display_name';

        switch ($field) {
            case 'display_name':
                return $user->display_name;

            case 'user_login':
                return $user->user_login;

            case 'user_email':
                return $user->user_email;

            case 'first_name':
                return get_user_meta($user_id, 'first_name', true);

            case 'last_name':
                return get_user_meta($user_id, 'last_name', true);

            case 'user_nicename':
                return $user->user_nicename;

            default:
                return $user->display_name;
        }
    }

    /**
     * Lookup WordPress post
     */
    private function lookup_post($post_id, array $lookup_config): string
    {
        $post_id = intval($post_id);
        if (!$post_id) {
            // @codeCoverageIgnoreStart
            return $post_id;
            // @codeCoverageIgnoreEnd
        }

        $post = get_post($post_id);
        if (!$post) {
            return $post_id; // Return original value if post not found
        }

        $field = isset($lookup_config['post_field']) ? $lookup_config['post_field'] : 'post_title';

        switch ($field) {
            case 'post_title':
                return $post->post_title;

            case 'post_excerpt':
                return $post->post_excerpt;

            case 'post_status':
                return $post->post_status;

            case 'post_type':
                return $post->post_type;

            default:
                return $post->post_title;
        }
    }

    /**
     * Lookup custom table
     */
    private function lookup_custom($id, array $lookup_config): string
    {
        global $wpdb;

        if (
            empty($lookup_config['table']) ||
            empty($lookup_config['id_column']) ||
            empty($lookup_config['display_column'])
        ) {
            return $id;
        }

        $table = sanitize_text_field($lookup_config['table']);
        $id_column = sanitize_text_field($lookup_config['id_column']);
        $display_column = sanitize_text_field($lookup_config['display_column']);

        // Validate table name (basic security check)
        if (
            !preg_match('/^[a-zA-Z0-9_]+$/', $table) ||
            !preg_match('/^[a-zA-Z0-9_]+$/', $id_column) ||
            !preg_match('/^[a-zA-Z0-9_]+$/', $display_column)
        ) {
            return $id;
        }

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT `{$display_column}` FROM `{$table}` WHERE `{$id_column}` = %s LIMIT 1",
            $id
        ));

        return $result ? $result : $id;
    }

    /**
     * Process multiple lookup values at once (for performance)
     */
    public function process_lookup_values_batch(array $values, array $lookup_config): array
    {
        if (empty($values) || empty($lookup_config) || !isset($lookup_config['type'])) {
            return $values;
        }

        $processed = array();

        switch ($lookup_config['type']) {
            case 'user':
                $processed = $this->lookup_users_batch($values, $lookup_config);
                break;

            case 'post':
                $processed = $this->lookup_posts_batch($values, $lookup_config);
                break;

            case 'custom':
                $processed = $this->lookup_custom_batch($values, $lookup_config);
                break;

            default:
                return $values;
        }

        return $processed;
    }

    /**
     * Batch lookup WordPress users
     */
    private function lookup_users_batch(array $user_ids, array $lookup_config): array
    {
        $user_ids = array_map('intval', array_filter($user_ids));
        if (empty($user_ids)) {
            return array();
        }

        $field = isset($lookup_config['user_field']) ? $lookup_config['user_field'] : 'display_name';
        $users = get_users(array('include' => $user_ids));

        // #1667 — prime the user meta cache once so the per-user
        // get_user_meta() calls below (first/last name) are cache hits, not
        // N DB queries. get_users() does not prime usermeta.
        if (in_array($field, array('first_name', 'last_name'), true) && function_exists('update_meta_cache')) {
            update_meta_cache('user', $user_ids);
        }

        $result = array();
        foreach ($users as $user) {
            switch ($field) {
                case 'display_name':
                    $result[$user->ID] = $user->display_name;
                    break;
                case 'user_login':
                    $result[$user->ID] = $user->user_login;
                    break;
                case 'user_email':
                    $result[$user->ID] = $user->user_email;
                    break;
                case 'first_name':
                    $result[$user->ID] = get_user_meta($user->ID, 'first_name', true);
                    break;
                case 'last_name':
                    $result[$user->ID] = get_user_meta($user->ID, 'last_name', true);
                    break;
                case 'user_nicename':
                    $result[$user->ID] = $user->user_nicename;
                    break;
                default:
                    $result[$user->ID] = $user->display_name;
            }
        }

        return $result;
    }

    /**
     * Batch lookup WordPress posts
     */
    private function lookup_posts_batch(array $post_ids, array $lookup_config): array
    {
        $post_ids = array_map('intval', array_filter($post_ids));
        if (empty($post_ids)) {
            return array();
        }

        $field = isset($lookup_config['post_field']) ? $lookup_config['post_field'] : 'post_title';
        $posts = get_posts(array(
            'post__in' => $post_ids,
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => -1
        ));

        $result = array();
        foreach ($posts as $post) {
            switch ($field) {
                case 'post_title':
                    $result[$post->ID] = $post->post_title;
                    break;
                case 'post_excerpt':
                    $result[$post->ID] = $post->post_excerpt;
                    break;
                case 'post_status':
                    $result[$post->ID] = $post->post_status;
                    break;
                case 'post_type':
                    $result[$post->ID] = $post->post_type;
                    break;
                default:
                    $result[$post->ID] = $post->post_title;
            }
        }

        return $result;
    }

    /**
     * Batch lookup custom table
     */
    private function lookup_custom_batch(array $ids, array $lookup_config): array
    {
        global $wpdb;

        if (
            empty($lookup_config['table']) ||
            empty($lookup_config['id_column']) ||
            empty($lookup_config['display_column'])
        ) {
            return array();
        }

        $table = sanitize_text_field($lookup_config['table']);
        $id_column = sanitize_text_field($lookup_config['id_column']);
        $display_column = sanitize_text_field($lookup_config['display_column']);

        // Validate identifiers against the centralised allowlist (issue #478).
        if (
            !TC_SQL_Guard::is_safe_identifier($table) ||
            !TC_SQL_Guard::is_safe_identifier($id_column) ||
            !TC_SQL_Guard::is_safe_identifier($display_column)
        ) {
            return array();
        }

        $ids = array_map('sanitize_text_field', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT `{$id_column}` as id, `{$display_column}` as value FROM `{$table}` WHERE `{$id_column}` IN ({$placeholders})",
            $ids
        ));

        $result = array();
        foreach ($results as $row) {
            $result[$row->id] = $row->value;
        }

        return $result;
    }

    /**
     * Get all available options for a lookup field (for dropdown filters)
     */
    public function get_lookup_options(array $lookup_config, ?int $form_id = null): array
    {
        if (empty($lookup_config) || !isset($lookup_config['type'])) {
            return array();
        }

        // #1660 — cache the resolved option list. Building it runs a
        // gf_entry_meta JOIN + REGEXP plus a users/posts/custom query; without
        // caching it ran on every render and every AJAX open (per visitor x
        // lookup column). A short TTL bounds staleness (a new option appears
        // within the window); force_refresh bypasses the cache.
        $force_refresh = !empty($lookup_config['force_refresh']);
        $cache_config = $lookup_config;
        unset($cache_config['force_refresh']);
        $cache_key = 'gt_lookup_opts_' . md5((string) ($form_id ?? 0) . '|' . serialize($cache_config));

        if (!$force_refresh && function_exists('get_transient')) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $options = $this->resolve_lookup_options($lookup_config, $form_id);

        if (function_exists('set_transient')) {
            $ttl = defined('MINUTE_IN_SECONDS') ? 5 * MINUTE_IN_SECONDS : 300;
            set_transient($cache_key, $options, $ttl);
        }

        return $options;
    }

    private function resolve_lookup_options(array $lookup_config, ?int $form_id = null): array
    {
        switch ($lookup_config['type']) {
            case 'user':
                return $this->get_user_options($lookup_config, $form_id);

            case 'post':
                return $this->get_post_options($lookup_config, $form_id);

            case 'custom':
                return $this->get_custom_options($lookup_config, $form_id);

            default:
                return array();
        }
    }

    /**
     * Get user options for dropdown
     */
    private function get_user_options(array $lookup_config, ?int $form_id = null): array
    {
        global $wpdb;

        $field = isset($lookup_config['user_field']) ? $lookup_config['user_field'] : 'display_name';
        $user_roles = array();
        if (isset($lookup_config['user_roles']) && is_array($lookup_config['user_roles'])) {
            $user_roles = $lookup_config['user_roles'];
        } elseif (isset($lookup_config['lookup_user_roles']) && is_array($lookup_config['lookup_user_roles'])) {
            $user_roles = $lookup_config['lookup_user_roles'];
        }
        $options = array();

        // Get unique user IDs that are actually used in this form
        $used_user_ids = array();
        if ($form_id) {
            $used_user_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT em.meta_value 
                FROM {$wpdb->prefix}gf_entry e 
                JOIN {$wpdb->prefix}gf_entry_meta em ON e.id = em.entry_id 
                WHERE e.form_id = %d AND e.status = 'active' 
                AND em.meta_value != '' AND em.meta_value REGEXP '^[0-9]+$'",
                $form_id
            ));
        }

        // Build args and apply role filter when provided
        $args = array(
            'orderby' => 'display_name',
            'order' => 'ASC'
        );
        if (!empty($user_roles)) {
            $args['role__in'] = array_values(array_filter(array_map('strval', $user_roles)));
        }

        if (empty($used_user_ids)) {
            // Fallback: get all users with a reasonable limit
            $args['number'] = 100;
            $users = get_users($args);
        } else {
            // Get only the users that are actually referenced
            $args['include'] = array_map('intval', $used_user_ids);
            $users = get_users($args);
        }

        // #1667 — prime the user meta cache once for first/last name fields so
        // the per-user get_user_meta() calls below are cache hits, not N DB
        // queries (get_users() does not prime usermeta).
        if (in_array($field, array('first_name', 'last_name'), true) && function_exists('update_meta_cache')) {
            $gt_user_ids = array_map(function ($u) { return (int) $u->ID; }, $users);
            if (!empty($gt_user_ids)) {
                update_meta_cache('user', $gt_user_ids);
            }
        }

        foreach ($users as $user) {
            switch ($field) {
                case 'display_name':
                    $label = $user->display_name;
                    break;
                case 'user_login':
                    $label = $user->user_login;
                    break;
                case 'user_email':
                    $label = $user->user_email;
                    break;
                case 'first_name':
                    $label = get_user_meta($user->ID, 'first_name', true);
                    break;
                case 'last_name':
                    $label = get_user_meta($user->ID, 'last_name', true);
                    break;
                case 'user_nicename':
                    $label = $user->user_nicename;
                    break;
                default:
                    $label = $user->display_name;
            }

            if (!empty($label)) {
                $options[] = array(
                    'value' => $user->ID,
                    'label' => $label
                );
            }
        }

        return $options;
    }

    /**
     * Get post options for dropdown
     */
    private function get_post_options(array $lookup_config, ?int $form_id = null): array
    {
        global $wpdb;

        $field = isset($lookup_config['post_field']) ? $lookup_config['post_field'] : 'post_title';
        $post_type = isset($lookup_config['post_type']) ? $lookup_config['post_type'] : 'post';
        $options = array();

        // Get unique post IDs that are actually used in this form
        $used_post_ids = array();
        if ($form_id) {
            $used_post_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT em.meta_value 
                FROM {$wpdb->prefix}gf_entry e 
                JOIN {$wpdb->prefix}gf_entry_meta em ON e.id = em.entry_id 
                WHERE e.form_id = %d AND e.status = 'active' 
                AND em.meta_value != '' AND em.meta_value REGEXP '^[0-9]+$'",
                $form_id
            ));
        }

        $query_args = array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => empty($used_post_ids) ? 100 : -1,
            'orderby' => 'title',
            'order' => 'ASC'
        );

        if (!empty($used_post_ids)) {
            $query_args['post__in'] = $used_post_ids;
        }

        $posts = get_posts($query_args);

        foreach ($posts as $post) {
            switch ($field) {
                case 'post_title':
                    $label = $post->post_title;
                    break;
                case 'post_excerpt':
                    $label = $post->post_excerpt;
                    break;
                case 'post_status':
                    $label = $post->post_status;
                    break;
                case 'post_type':
                    $label = $post->post_type;
                    break;
                default:
                    $label = $post->post_title;
            }

            if (!empty($label)) {
                $options[] = array(
                    'value' => $post->ID,
                    'label' => $label
                );
            }
        }

        return $options;
    }

    /**
     * Get custom table options for dropdown
     */
    private function get_custom_options(array $lookup_config, ?int $form_id = null): array
    {
        global $wpdb;

        if (
            empty($lookup_config['table']) ||
            empty($lookup_config['id_column']) ||
            empty($lookup_config['display_column'])
        ) {
            return array();
        }

        $table = sanitize_text_field($lookup_config['table']);
        $id_column = sanitize_text_field($lookup_config['id_column']);
        $display_column = sanitize_text_field($lookup_config['display_column']);

        // Validate identifiers against the centralised allowlist (issue #478).
        if (
            !TC_SQL_Guard::is_safe_identifier($table) ||
            !TC_SQL_Guard::is_safe_identifier($id_column) ||
            !TC_SQL_Guard::is_safe_identifier($display_column)
        ) {
            return array();
        }

        $options = array();

        // Get unique values that are actually used in this form
        if ($form_id) {
            $used_values = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT em.meta_value 
                FROM {$wpdb->prefix}gf_entry e 
                JOIN {$wpdb->prefix}gf_entry_meta em ON e.id = em.entry_id 
                WHERE e.form_id = %d AND e.status = 'active' 
                AND em.meta_value != ''",
                $form_id
            ));

            if (!empty($used_values)) {
                $placeholders = implode(',', array_fill(0, count($used_values), '%s'));
                $query = "SELECT `{$id_column}`, `{$display_column}` FROM `{$table}` WHERE `{$id_column}` IN ({$placeholders}) ORDER BY `{$display_column}`";
                $results = $wpdb->get_results($wpdb->prepare($query, $used_values));
            } else {
                $results = array();
            }
        } else {
            // Fallback: get all values with a reasonable limit
            $results = $wpdb->get_results("SELECT `{$id_column}`, `{$display_column}` FROM `{$table}` ORDER BY `{$display_column}` LIMIT 100");
        }

        foreach ($results as $row) {
            $options[] = array(
                'value' => $row->$id_column,
                'label' => $row->$display_column
            );
        }

        return $options;
    }
}