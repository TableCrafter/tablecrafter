<?php
/**
 * WordPress Function Mocks for Standalone Testing
 *
 * These mock functions allow unit tests to run without a full WordPress installation.
 *
 * @package TableCrafter\Tests
 */

// i18n function mocks
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default')
    {
        echo $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default')
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_http_validate_url')) {
    function wp_http_validate_url($url)
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }

        $host = $parsed['host'];

        // Block localhost
        if (in_array(strtolower($host), array('localhost', '127.0.0.1', '[::1]'))) {
            return false;
        }

        // Block private IPs
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $is_private = !filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($is_private) {
                return false;
            }
        }

        return $url;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return stripslashes($value);
    }
}

if (!function_exists('wp_hash')) {
    function wp_hash($data, $scheme = 'auth')
    {
        return md5($data);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return 0;
    }
}

if (!function_exists('get_transient')) {
    $GLOBALS['_tc_transients'] = array();

    function get_transient($transient)
    {
        return isset($GLOBALS['_tc_transients'][$transient])
            ? $GLOBALS['_tc_transients'][$transient]['value']
            : false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0)
    {
        $GLOBALS['_tc_transients'][$transient] = array(
            'value' => $value,
            'expiration' => $expiration,
            'time' => time()
        );
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient)
    {
        if (isset($GLOBALS['_tc_transients'][$transient])) {
            unset($GLOBALS['_tc_transients'][$transient]);
            return true;
        }
        return false;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        // For testing, accept a specific test nonce
        return $nonce === 'test_nonce_' . $action;
    }
}

/**
 * Reset all mocked transients (useful between tests)
 */
function tc_reset_mock_transients()
{
    $GLOBALS['_tc_transients'] = array();
}

// WP_Error class mock
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('site_url')) {
    function site_url($path = '')
    {
        return 'http://example.com' . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'http://example.com' . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array())
    {
        return array('body' => '[]', 'response' => array('code' => 200));
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return isset($response['body']) ? $response['body'] : '';
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return isset($GLOBALS['_tc_options'][$option]) ? $GLOBALS['_tc_options'][$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null)
    {
        $GLOBALS['_tc_options'][$option] = $value;
        return true;
    }
}

$GLOBALS['_tc_options'] = array();

// --- Export handler support mocks ---------------------------------------

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false)
    {
        $base = sys_get_temp_dir() . '/tc-test-uploads';
        return array(
            'basedir' => $base,
            'baseurl' => 'http://example.com/wp-content/uploads',
            'path'    => $base,
            'url'     => 'http://example.com/wp-content/uploads',
            'error'   => false,
        );
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string)
    {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target)
    {
        if (is_dir($target)) {
            return true;
        }
        return mkdir($target, 0777, true);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array())
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        } elseif (!is_array($args)) {
            parse_str((string) $args, $args);
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename)
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) $filename);
        return trim($filename, '-') ?: 'export';
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        if ($type === 'timestamp' || $type === 'U') {
            return time();
        }
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false)
    {
        $string = strip_tags((string) $string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }
        return trim($string);
    }
}

// Mock $wpdb for standalone tests
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public $options = 'wp_options';

        public function get_col($query)
        {
            // Return matching transients from our mock storage
            $results = array();
            foreach (array_keys($GLOBALS['_tc_transients']) as $key) {
                if (strpos($key, 'tc_') === 0) {
                    $results[] = '_transient_' . $key;
                }
            }
            return $results;
        }

        public function prepare($query, ...$args)
        {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function esc_like($text)
        {
            return addcslashes($text, '_%\\');
        }
    };
}
