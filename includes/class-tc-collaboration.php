<?php
/**
 * TableCrafter Real-time Collaboration Handler
 * 
 * Manages real-time collaboration sessions using WordPress REST API
 * and transient caching for session state management.
 * 
 * @package TableCrafter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TC_Collaboration {
    
    /**
     * Session timeout in seconds (15 minutes)
     */
    const SESSION_TIMEOUT = 900;
    
    /**
     * Maximum events per sync request
     */
    const MAX_EVENTS_PER_SYNC = 50;
    
    /**
     * Maximum users per table
     */
    const MAX_USERS_PER_TABLE = 25;

    /**
     * Initialize the collaboration system
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_endpoints'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_collaboration_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'localize_collaboration_data'));
        
        // Clean up expired sessions periodically
        add_action('tablecrafter_cleanup_collaboration_sessions', array($this, 'cleanup_expired_sessions'));
        if (!wp_next_scheduled('tablecrafter_cleanup_collaboration_sessions')) {
            wp_schedule_event(time(), 'hourly', 'tablecrafter_cleanup_collaboration_sessions');
        }
    }

    /**
     * Register REST API endpoints for collaboration
     */
    public function register_rest_endpoints() {
        register_rest_route('tablecrafter/v1', '/collaboration/join', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_join_session'),
            'permission_callback' => array($this, 'check_collaboration_permission'),
        ));
        
        register_rest_route('tablecrafter/v1', '/collaboration/leave', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_leave_session'),
            'permission_callback' => array($this, 'check_collaboration_permission'),
        ));
        
        register_rest_route('tablecrafter/v1', '/collaboration/broadcast', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_broadcast_event'),
            'permission_callback' => array($this, 'check_collaboration_permission'),
        ));
        
        register_rest_route('tablecrafter/v1', '/collaboration/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_sync_request'),
            'permission_callback' => array($this, 'check_collaboration_permission'),
        ));
    }

    /**
     * Handle user joining a collaboration session
     */
    public function handle_join_session($request) {
        $params = $request->get_json_params();
        $table_id = sanitize_text_field($params['table_id']);
        $session_id = sanitize_text_field($params['session_id']);
        $user_id = get_current_user_id();
        
        if (empty($table_id) || empty($session_id)) {
            return new WP_Error('invalid_params', 'Missing required parameters', array('status' => 400));
        }

        // Check if table already has too many users
        $session_key = "tc_collab_session_{$table_id}";
        $session_data = get_transient($session_key);
        if (!$session_data) {
            $session_data = array(
                'table_id' => $table_id,
                'users' => array(),
                'events' => array(),
                'created' => time()
            );
        }

        if (count($session_data['users']) >= self::MAX_USERS_PER_TABLE) {
            return new WP_Error('session_full', 'Collaboration session is full', array('status' => 429));
        }

        // Add user to session
        $user_data = get_userdata($user_id);
        $session_data['users'][$session_id] = array(
            'user_id' => $user_id,
            'session_id' => $session_id,
            'name' => $user_data ? $user_data->display_name : 'Anonymous User',
            'avatar' => get_avatar_url($user_id, array('size' => 32)),
            'joined' => time(),
            'last_seen' => time()
        );

        // Store table configuration for new users
        if (isset($params['table_config'])) {
            $session_data['table_config'] = $params['table_config'];
        }

        // Save session data
        set_transient($session_key, $session_data, self::SESSION_TIMEOUT);

        // Log collaboration event
        $this->log_collaboration_event($table_id, $user_id, 'join_session', array(
            'session_id' => $session_id,
            'user_count' => count($session_data['users'])
        ));

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'table_id' => $table_id,
                'session_id' => $session_id,
                'users' => array_values($session_data['users']),
                'table_config' => $session_data['table_config'] ?? null
            )
        ));
    }

    /**
     * Handle user leaving a collaboration session
     */
    public function handle_leave_session($request) {
        $params = $request->get_json_params();
        $table_id = sanitize_text_field($params['table_id']);
        $session_id = sanitize_text_field($params['session_id']);
        
        if (empty($table_id) || empty($session_id)) {
            return new WP_Error('invalid_params', 'Missing required parameters', array('status' => 400));
        }

        $session_key = "tc_collab_session_{$table_id}";
        $session_data = get_transient($session_key);
        
        if ($session_data && isset($session_data['users'][$session_id])) {
            $user_id = $session_data['users'][$session_id]['user_id'];
            
            // Remove user from session
            unset($session_data['users'][$session_id]);
            
            // Add leave event for other users
            $session_data['events'][] = array(
                'id' => uniqid('event_'),
                'event_type' => 'user_left',
                'user_id' => $user_id,
                'session_id' => $session_id,
                'event_data' => array(
                    'user_count' => count($session_data['users'])
                ),
                'timestamp' => time()
            );
            
            // Clean up old events
            $session_data['events'] = $this->cleanup_old_events($session_data['events']);
            
            // Update or delete session
            if (empty($session_data['users'])) {
                delete_transient($session_key);
            } else {
                set_transient($session_key, $session_data, self::SESSION_TIMEOUT);
            }

            // Log collaboration event
            $this->log_collaboration_event($table_id, $user_id, 'leave_session', array(
                'session_id' => $session_id,
                'remaining_users' => count($session_data['users'])
            ));
        }

        return rest_ensure_response(array(
            'success' => true
        ));
    }

    /**
     * Handle broadcasting events to other users
     */
    public function handle_broadcast_event($request) {
        $params = $request->get_json_params();
        $table_id = sanitize_text_field($params['table_id']);
        $session_id = sanitize_text_field($params['session_id']);
        $event_type = sanitize_text_field($params['event_type']);
        $event_data = $params['event_data'];
        
        if (empty($table_id) || empty($session_id) || empty($event_type)) {
            return new WP_Error('invalid_params', 'Missing required parameters', array('status' => 400));
        }

        // Validate event type
        $allowed_events = array('sort', 'filter', 'paginate', 'cursor', 'comment');
        if (!in_array($event_type, $allowed_events)) {
            return new WP_Error('invalid_event_type', 'Invalid event type', array('status' => 400));
        }

        $session_key = "tc_collab_session_{$table_id}";
        $session_data = get_transient($session_key);
        
        if (!$session_data || !isset($session_data['users'][$session_id])) {
            return new WP_Error('session_not_found', 'Collaboration session not found', array('status' => 404));
        }

        // Update user's last seen time
        $session_data['users'][$session_id]['last_seen'] = time();
        
        // Add event to session
        $event = array(
            'id' => uniqid('event_'),
            'event_type' => $event_type,
            'user_id' => $session_data['users'][$session_id]['user_id'],
            'session_id' => $session_id,
            'event_data' => $this->sanitize_event_data($event_data),
            'timestamp' => time()
        );
        
        $session_data['events'][] = $event;
        
        // Clean up old events to prevent memory bloat
        $session_data['events'] = $this->cleanup_old_events($session_data['events']);
        
        // Save updated session
        set_transient($session_key, $session_data, self::SESSION_TIMEOUT);

        // Log collaboration event
        $this->log_collaboration_event($table_id, $session_data['users'][$session_id]['user_id'], 'broadcast_event', array(
            'event_type' => $event_type,
            'session_id' => $session_id
        ));

        return rest_ensure_response(array(
            'success' => true,
            'event_id' => $event['id']
        ));
    }

    /**
     * Handle sync requests to get new events
     */
    public function handle_sync_request($request) {
        $params = $request->get_json_params();
        $table_id = sanitize_text_field($params['table_id']);
        $session_id = sanitize_text_field($params['session_id']);
        $last_sync = intval($params['last_sync'] ?? 0);
        
        if (empty($table_id) || empty($session_id)) {
            return new WP_Error('invalid_params', 'Missing required parameters', array('status' => 400));
        }

        $session_key = "tc_collab_session_{$table_id}";
        $session_data = get_transient($session_key);
        
        if (!$session_data || !isset($session_data['users'][$session_id])) {
            return new WP_Error('session_not_found', 'Collaboration session not found', array('status' => 404));
        }

        // Update user's last seen time
        $session_data['users'][$session_id]['last_seen'] = time();
        
        // Remove inactive users (haven't been seen for 5 minutes)
        $cutoff_time = time() - 300;
        foreach ($session_data['users'] as $sid => $user) {
            if ($user['last_seen'] < $cutoff_time) {
                unset($session_data['users'][$sid]);
            }
        }
        
        // Get events since last sync (convert to milliseconds)
        $last_sync_seconds = floor($last_sync / 1000);
        $new_events = array_filter($session_data['events'], function($event) use ($last_sync_seconds) {
            return $event['timestamp'] > $last_sync_seconds;
        });
        
        // Limit number of events to prevent overwhelming the client
        $new_events = array_slice($new_events, 0, self::MAX_EVENTS_PER_SYNC);
        
        // Save updated session (to reflect removed inactive users)
        set_transient($session_key, $session_data, self::SESSION_TIMEOUT);

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'events' => array_values($new_events),
                'users' => array_values($session_data['users']),
                'server_time' => time()
            )
        ));
    }

    /**
     * Check if user has permission for collaboration
     */
    public function check_collaboration_permission($request) {
        // Must be logged in to collaborate
        if (!is_user_logged_in()) {
            return false;
        }
        
        // Check nonce for security
        $nonce = $request->get_header('X-WP-Nonce');
        if (!wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }
        
        // Allow collaboration for users who can read (customize as needed)
        return current_user_can('read');
    }

    /**
     * Enqueue collaboration JavaScript files
     */
    public function enqueue_collaboration_scripts() {
        wp_enqueue_script(
            'tablecrafter-collaboration',
            plugins_url('assets/js/collaboration.js', dirname(__FILE__)),
            array('tablecrafter-main'),
            TABLECRAFTER_VERSION,
            true
        );
    }

    /**
     * Localize collaboration data for JavaScript
     */
    public function localize_collaboration_data() {
        $user_id = get_current_user_id();
        $user_data = get_userdata($user_id);
        
        $collaboration_data = array(
            'user_id' => $user_id,
            'user_name' => $user_data ? $user_data->display_name : 'Anonymous',
            'user_avatar' => get_avatar_url($user_id, array('size' => 32)),
            'rest_url' => rest_url('tablecrafter/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'can_collaborate' => is_user_logged_in() && current_user_can('read'),
            'collaboration_enabled' => get_option('tablecrafter_collaboration_enabled', false)
        );
        
        wp_localize_script('tablecrafter-collaboration', 'tablecrafterCollabData', $collaboration_data);
    }

    /**
     * Sanitize event data to prevent XSS and other security issues
     */
    private function sanitize_event_data($data) {
        if (is_array($data)) {
            return array_map(array($this, 'sanitize_event_data'), $data);
        }
        
        if (is_string($data)) {
            return sanitize_text_field($data);
        }
        
        if (is_numeric($data)) {
            return floatval($data);
        }
        
        return $data;
    }

    /**
     * Clean up old events to prevent memory bloat
     */
    private function cleanup_old_events($events) {
        // Keep only events from the last 5 minutes
        $cutoff_time = time() - 300;
        
        $recent_events = array_filter($events, function($event) use ($cutoff_time) {
            return $event['timestamp'] > $cutoff_time;
        });
        
        // Keep only the most recent MAX_EVENTS_PER_SYNC events
        return array_slice($recent_events, -self::MAX_EVENTS_PER_SYNC);
    }

    /**
     * Log collaboration events for analytics and debugging
     */
    private function log_collaboration_event($table_id, $user_id, $event_type, $data = array()) {
        if (!get_option('tablecrafter_collaboration_logging', false)) {
            return;
        }
        
        $log_entry = array(
            'table_id' => $table_id,
            'user_id' => $user_id,
            'event_type' => $event_type,
            'data' => $data,
            'timestamp' => time(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        );
        
        // Store in WordPress options table (for now)
        $logs = get_option('tablecrafter_collaboration_logs', array());
        $logs[] = $log_entry;
        
        // Keep only last 1000 log entries
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        update_option('tablecrafter_collaboration_logs', $logs);
    }

    /**
     * Clean up expired collaboration sessions
     */
    public function cleanup_expired_sessions() {
        global $wpdb;
        
        // Get all transients that match our collaboration session pattern
        $pattern = 'tc_collab_session_%';
        $transients = $wpdb->get_col($wpdb->prepare("
            SELECT option_name 
            FROM {$wpdb->options} 
            WHERE option_name LIKE %s
        ", '_transient_' . $pattern));
        
        $cleaned = 0;
        foreach ($transients as $transient_name) {
            $key = str_replace('_transient_', '', $transient_name);
            $data = get_transient($key);
            
            // If transient is empty or expired, it will be cleaned automatically
            // But we can also check for very old sessions manually
            if ($data && isset($data['created']) && $data['created'] < (time() - self::SESSION_TIMEOUT * 2)) {
                delete_transient($key);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            error_log("TableCrafter: Cleaned up {$cleaned} expired collaboration sessions");
        }
    }

    /**
     * Get collaboration statistics for admin dashboard
     */
    public function get_collaboration_stats() {
        global $wpdb;
        
        // Get active sessions count
        $pattern = 'tc_collab_session_%';
        $active_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE %s
        ", '_transient_' . $pattern));
        
        // Get collaboration logs if enabled
        $logs = get_option('tablecrafter_collaboration_logs', array());
        $recent_events = array_filter($logs, function($log) {
            return $log['timestamp'] > (time() - 86400); // Last 24 hours
        });
        
        return array(
            'active_sessions' => intval($active_sessions),
            'total_events_24h' => count($recent_events),
            'unique_users_24h' => count(array_unique(array_column($recent_events, 'user_id'))),
            'popular_events' => array_count_values(array_column($recent_events, 'event_type'))
        );
    }
}

// Initialize collaboration system
new TC_Collaboration();