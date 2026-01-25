<?php
/**
 * TableCrafter Real-time Collaboration Tests
 * 
 * Comprehensive test suite for the collaboration features including
 * session management, event broadcasting, and user presence.
 * 
 * @package TableCrafter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TC_Collaboration_Tests {
    
    private $test_results = [];
    private $collaboration;
    private $test_user_id;
    private $test_table_id;
    
    public function __construct() {
        $this->test_table_id = 'test_table_' . time();
        $this->test_user_id = get_current_user_id() ?: 1;
    }
    
    /**
     * Run all collaboration tests
     */
    public function run_all_tests() {
        echo "<h2>🤝 TableCrafter Collaboration Tests</h2>\n";
        echo "<div style='font-family: monospace; background: #f5f5f5; padding: 20px; border-radius: 5px;'>\n";
        
        // Initialize collaboration system
        if (!class_exists('TC_Collaboration')) {
            $this->test_results[] = "❌ TC_Collaboration class not found - collaboration system not loaded";
            $this->print_results();
            return;
        }
        
        $this->collaboration = new TC_Collaboration();
        
        // Run test suite
        $this->test_session_management();
        $this->test_event_broadcasting();
        $this->test_user_presence();
        $this->test_permission_checks();
        $this->test_session_cleanup();
        $this->test_rest_api_endpoints();
        $this->test_security_validation();
        $this->test_performance_limits();
        
        $this->print_results();
    }
    
    /**
     * Test session join/leave functionality
     */
    private function test_session_management() {
        echo "Testing session management...\n";
        
        try {
            // Test session join
            $join_result = $this->simulate_rest_request('join', [
                'table_id' => $this->test_table_id,
                'session_id' => 'test_session_1',
                'user_id' => $this->test_user_id,
                'table_config' => [
                    'columns' => [
                        ['field' => 'name', 'title' => 'Name'],
                        ['field' => 'email', 'title' => 'Email']
                    ]
                ]
            ]);
            
            if ($join_result && isset($join_result['success']) && $join_result['success']) {
                $this->test_results[] = "✅ Session join successful";
                
                // Verify session data stored
                $session_key = "tc_collab_session_{$this->test_table_id}";
                $session_data = get_transient($session_key);
                
                if ($session_data && isset($session_data['users']['test_session_1'])) {
                    $this->test_results[] = "✅ Session data stored correctly";
                } else {
                    $this->test_results[] = "❌ Session data not found in transient storage";
                }
                
                // Test session leave
                $leave_result = $this->simulate_rest_request('leave', [
                    'table_id' => $this->test_table_id,
                    'session_id' => 'test_session_1'
                ]);
                
                if ($leave_result && isset($leave_result['success']) && $leave_result['success']) {
                    $this->test_results[] = "✅ Session leave successful";
                } else {
                    $this->test_results[] = "❌ Session leave failed";
                }
            } else {
                $this->test_results[] = "❌ Session join failed";
            }
            
        } catch (Exception $e) {
            $this->test_results[] = "❌ Session management test error: " . $e->getMessage();
        }
    }
    
    /**
     * Test event broadcasting functionality
     */
    private function test_event_broadcasting() {
        echo "Testing event broadcasting...\n";
        
        try {
            // Join session first
            $this->simulate_rest_request('join', [
                'table_id' => $this->test_table_id,
                'session_id' => 'test_session_broadcast',
                'user_id' => $this->test_user_id
            ]);
            
            // Test broadcasting different event types
            $events_to_test = [
                ['event_type' => 'sort', 'event_data' => ['field' => 'name', 'direction' => 'asc']],
                ['event_type' => 'filter', 'event_data' => ['filters' => ['name' => 'John']]],
                ['event_type' => 'cursor', 'event_data' => ['x' => 50, 'y' => 75]]
            ];
            
            foreach ($events_to_test as $event) {
                $broadcast_result = $this->simulate_rest_request('broadcast', [
                    'table_id' => $this->test_table_id,
                    'session_id' => 'test_session_broadcast',
                    'event_type' => $event['event_type'],
                    'event_data' => $event['event_data']
                ]);
                
                if ($broadcast_result && isset($broadcast_result['success']) && $broadcast_result['success']) {
                    $this->test_results[] = "✅ {$event['event_type']} event broadcast successful";
                } else {
                    $this->test_results[] = "❌ {$event['event_type']} event broadcast failed";
                }
            }
            
            // Test invalid event type
            $invalid_event = $this->simulate_rest_request('broadcast', [
                'table_id' => $this->test_table_id,
                'session_id' => 'test_session_broadcast',
                'event_type' => 'invalid_event',
                'event_data' => []
            ]);
            
            if (isset($invalid_event['code']) && $invalid_event['code'] === 'invalid_event_type') {
                $this->test_results[] = "✅ Invalid event type properly rejected";
            } else {
                $this->test_results[] = "❌ Invalid event type not properly validated";
            }
            
        } catch (Exception $e) {
            $this->test_results[] = "❌ Event broadcasting test error: " . $e->getMessage();
        }
    }
    
    /**
     * Test user presence functionality
     */
    private function test_user_presence() {
        echo "Testing user presence...\n";
        
        try {
            // Create multiple sessions
            $sessions = ['session_1', 'session_2', 'session_3'];
            
            foreach ($sessions as $session_id) {
                $this->simulate_rest_request('join', [
                    'table_id' => $this->test_table_id . '_presence',
                    'session_id' => $session_id,
                    'user_id' => $this->test_user_id
                ]);
            }
            
            // Test sync to get user list
            $sync_result = $this->simulate_rest_request('sync', [
                'table_id' => $this->test_table_id . '_presence',
                'session_id' => 'session_1',
                'last_sync' => 0
            ]);
            
            if ($sync_result && isset($sync_result['data']['users'])) {
                $user_count = count($sync_result['data']['users']);
                if ($user_count === 3) {
                    $this->test_results[] = "✅ User presence tracking correct ($user_count users)";
                } else {
                    $this->test_results[] = "❌ User presence count incorrect (expected 3, got $user_count)";
                }
            } else {
                $this->test_results[] = "❌ Sync request failed or invalid response";
            }
            
            // Test inactive user cleanup
            $session_key = "tc_collab_session_{$this->test_table_id}_presence";
            $session_data = get_transient($session_key);
            
            if ($session_data) {
                // Manually set one user as inactive
                $session_data['users']['session_2']['last_seen'] = time() - 400; // 6+ minutes ago
                set_transient($session_key, $session_data, 900);
                
                // Run sync again to trigger cleanup
                $sync_result2 = $this->simulate_rest_request('sync', [
                    'table_id' => $this->test_table_id . '_presence',
                    'session_id' => 'session_1',
                    'last_sync' => 0
                ]);
                
                if ($sync_result2 && isset($sync_result2['data']['users'])) {
                    $active_count = count($sync_result2['data']['users']);
                    if ($active_count === 2) {
                        $this->test_results[] = "✅ Inactive user cleanup working correctly";
                    } else {
                        $this->test_results[] = "❌ Inactive user cleanup failed (expected 2, got $active_count)";
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->test_results[] = "❌ User presence test error: " . $e->getMessage();
        }
    }
    
    /**
     * Test permission checks
     */
    private function test_permission_checks() {
        echo "Testing permission checks...\n";
        
        try {
            // Test with valid user
            $valid_request = new WP_REST_Request('POST', '/tablecrafter/v1/collaboration/join');
            $valid_request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
            
            $permission_result = $this->collaboration->check_collaboration_permission($valid_request);
            
            if ($permission_result) {
                $this->test_results[] = "✅ Valid user permission check passed";
            } else {
                $this->test_results[] = "❌ Valid user permission check failed";
            }
            
            // Test with invalid nonce
            $invalid_request = new WP_REST_Request('POST', '/tablecrafter/v1/collaboration/join');
            $invalid_request->set_header('X-WP-Nonce', 'invalid_nonce');
            
            $invalid_permission = $this->collaboration->check_collaboration_permission($invalid_request);
            
            if (!$invalid_permission) {
                $this->test_results[] = "✅ Invalid nonce properly rejected";
            } else {
                $this->test_results[] = "❌ Invalid nonce not properly validated";
            }
            
        } catch (Exception $e) {
            $this->test_results[] = "❌ Permission test error: " . $e->getMessage();
        }
    }
    
    /**
     * Test session cleanup functionality
     */
    private function test_session_cleanup() {
        echo "Testing session cleanup...\n";
        
        try {
            // Create a test session
            $old_table_id = 'old_test_table_' . (time() - 2000);
            set_transient("tc_collab_session_{$old_table_id}", [
                'table_id' => $old_table_id,
                'users' => ['test_session' => ['user_id' => 1, 'joined' => time() - 2000]],
                'events' => [],
                'created' => time() - 2000
            ], 900);
            
            // Run cleanup
            $this->collaboration->cleanup_expired_sessions();
            
            // Check if session was cleaned up
            $cleaned_session = get_transient("tc_collab_session_{$old_table_id}");
            
            if (!$cleaned_session) {
                $this->test_results[] = "✅ Expired session cleanup working";
            } else {
                $this->test_results[] = "❌ Expired session not cleaned up";
            }
            
        } catch (Exception $e) {
            $this->test_results[] = "❌ Session cleanup test error: " . $e->getMessage();
        }
    }
    
    /**
     * Test REST API endpoints
     */
    private function test_rest_api_endpoints() {
        echo "Testing REST API endpoints...\n";
        
        $endpoints_to_test = [
            '/tablecrafter/v1/collaboration/join',
            '/tablecrafter/v1/collaboration/leave', 
            '/tablecrafter/v1/collaboration/broadcast',
            '/tablecrafter/v1/collaboration/sync'
        ];
        
        foreach ($endpoints_to_test as $endpoint) {
            $routes = rest_get_server()->get_routes();
            
            if (isset($routes[$endpoint])) {
                $this->test_results[] = "✅ REST endpoint registered: $endpoint";
            } else {
                $this->test_results[] = "❌ REST endpoint missing: $endpoint";
            }
        }
    }
    
    /**
     * Test security validation
     */
    private function test_security_validation() {
        echo "Testing security validation...\n";
        
        try {
            // Test XSS prevention in event data
            $xss_test = $this->simulate_rest_request('join', [
                'table_id' => $this->test_table_id . '_security',
                'session_id' => 'security_test',
                'user_id' => $this->test_user_id
            ]);
            
            if ($xss_test) {
                $malicious_event = $this->simulate_rest_request('broadcast', [
                    'table_id' => $this->test_table_id . '_security',
                    'session_id' => 'security_test',
                    'event_type' => 'sort',
                    'event_data' => [
                        'field' => '<script>alert("xss")</script>',
                        'direction' => 'asc'
                    ]
                ]);
                
                if ($malicious_event && isset($malicious_event['success'])) {
                    // Check if the data was sanitized
                    $session_data = get_transient("tc_collab_session_{$this->test_table_id}_security");
                    $last_event = end($session_data['events']);
                    
                    if (strpos($last_event['event_data']['field'], '<script>') === false) {
                        $this->test_results[] = "✅ XSS prevention working - malicious script sanitized";
                    } else {
                        $this->test_results[] = "❌ XSS vulnerability - malicious script not sanitized";
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->test_results[] = "❌ Security test error: " . $e->getMessage();
        }
    }
    
    /**
     * Test performance limits
     */
    private function test_performance_limits() {
        echo "Testing performance limits...\n";
        
        try {
            // Test maximum users per table
            $max_users_table = $this->test_table_id . '_max_users';
            
            // Try to add more than the maximum allowed users
            for ($i = 1; $i <= 30; $i++) {
                $result = $this->simulate_rest_request('join', [
                    'table_id' => $max_users_table,
                    'session_id' => "user_$i",
                    'user_id' => $this->test_user_id
                ]);
                
                // Should start failing after 25 users
                if ($i > 25) {
                    if (isset($result['code']) && $result['code'] === 'session_full') {
                        $this->test_results[] = "✅ Maximum users limit enforced (failed at user $i)";
                        break;
                    } else if ($i === 30) {
                        $this->test_results[] = "❌ Maximum users limit not enforced";
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->test_results[] = "❌ Performance limits test error: " . $e->getMessage();
        }
    }
    
    /**
     * Simulate REST API request
     */
    private function simulate_rest_request($action, $data) {
        try {
            $request = new WP_REST_Request('POST', "/tablecrafter/v1/collaboration/$action");
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
            $request->set_json_params($data);
            
            switch ($action) {
                case 'join':
                    return $this->collaboration->handle_join_session($request);
                case 'leave':
                    return $this->collaboration->handle_leave_session($request);
                case 'broadcast':
                    return $this->collaboration->handle_broadcast_event($request);
                case 'sync':
                    return $this->collaboration->handle_sync_request($request);
                default:
                    return new WP_Error('invalid_action', 'Invalid action');
            }
        } catch (Exception $e) {
            return new WP_Error('request_failed', $e->getMessage());
        }
    }
    
    /**
     * Print test results
     */
    private function print_results() {
        $passed = 0;
        $total = count($this->test_results);
        
        foreach ($this->test_results as $result) {
            echo "$result\n";
            if (strpos($result, '✅') === 0) {
                $passed++;
            }
        }
        
        echo "\n<strong>📊 Test Results: $passed/$total passed</strong>\n";
        
        if ($passed === $total) {
            echo "<strong style='color: green;'>🎉 All collaboration tests passed! Real-time collaboration is working correctly.</strong>\n";
        } else {
            echo "<strong style='color: orange;'>⚠️ Some tests failed. Please review the implementation.</strong>\n";
        }
        
        echo "</div>\n";
        
        // Cleanup test data
        $this->cleanup_test_data();
    }
    
    /**
     * Clean up test data
     */
    private function cleanup_test_data() {
        $test_keys = [
            "tc_collab_session_{$this->test_table_id}",
            "tc_collab_session_{$this->test_table_id}_presence",
            "tc_collab_session_{$this->test_table_id}_security",
            "tc_collab_session_{$this->test_table_id}_max_users"
        ];
        
        foreach ($test_keys as $key) {
            delete_transient($key);
        }
    }
}

// Run tests if accessed directly or via admin
if (is_admin() && isset($_GET['run_collaboration_tests'])) {
    add_action('admin_init', function() {
        $tests = new TC_Collaboration_Tests();
        $tests->run_all_tests();
        exit;
    });
}

// Function to run tests programmatically
function run_tablecrafter_collaboration_tests() {
    $tests = new TC_Collaboration_Tests();
    return $tests->run_all_tests();
}