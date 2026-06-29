<?php
/**
 * Centralised Freemius license activation handler.
 *
 * Translates the loose contract of {@see Freemius::opt_in()} into a small,
 * deterministic status + message tuple so the admin view can render a
 * specific, actionable notice for every failure mode rather than the
 * generic "Activation failed" wpDataTables thread (#480) complained about.
 *
 * @package GravityTables
 * @since 4.6.3
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_License_Activator
{
    /** @var string[] Freemius error codes that mean "seat already used". */
    private const SEAT_LIMIT_CODES = [
        'license_in_use',
        'no_available_licenses',
        'license_activation_limit_reached',
        'license_activation_quota_exhausted',
    ];

    /** @var string[] Freemius error codes that mean "key is wrong / unknown". */
    private const INVALID_KEY_CODES = [
        'invalid_license_key',
        'license_not_found',
        'invalid_key',
        'invalid_secret_key',
    ];

    /**
     * Activate a Freemius license against the supplied SDK instance.
     *
     * @param string      $key Raw license key (will be trimmed).
     * @param object|null $fs  Freemius SDK instance (or test double).
     * @return array{status:string,message:string,retryable:bool}
     */
    public static function activate(string $key, $fs = null): array
    {
        $key = trim($key);

        if ($key === '') {
            return self::result('empty_key', 'Please enter a license key.');
        }

        if ($fs === null) {
            return self::result('unknown', 'Licensing SDK is not available on this site.');
        }

        if (self::is_already_premium($fs)) {
            return self::result('already_active', 'This site already has an active premium license.');
        }

        try {
            $response = $fs->opt_in(false, false, false, $key);
        } catch (\Throwable $e) {
            return self::result(
                'network_failure',
                'Could not reach the licensing server (' . $e->getMessage() . '). Please retry in a moment.',
                true
            );
        }

        return self::classify($response, $key);
    }

    /**
     * Render an admin notice for the result returned from activate().
     *
     * @param array{status:string,message:string,retryable?:bool} $result
     * @return string Sanitised HTML for echoing inside a wp-admin page.
     */
    public static function render_notice(array $result): string
    {
        $status   = $result['status']  ?? 'unknown';
        $message  = $result['message'] ?? '';
        $retry    = !empty($result['retryable']);

        $class = 'notice-error';
        $title = 'License could not be activated';

        switch ($status) {
            case 'success':
                $class = 'notice-success';
                $title = 'License activated';
                break;
            case 'already_active':
                $class = 'notice-info';
                $title = 'License already active';
                break;
            case 'empty_key':
                $title = 'Missing license key';
                break;
            case 'invalid_key':
                $title = 'License key not recognized';
                break;
            case 'seat_limit':
                $title = 'License is in use on another site';
                break;
            case 'network_failure':
                $title = 'Could not reach licensing server';
                break;
        }

        $html  = '<div class="notice ' . esc_attr_compat($class) . '">';
        $html .= '<p><strong>' . esc_html($title) . '</strong></p>';
        $html .= '<p>' . esc_html($message) . '</p>';
        if ($retry) {
            $html .= '<p>Please <em>try again</em> in a moment, or <a href="">retry now</a>.</p>';
        }
        $html .= '</div>';
        return $html;
    }

    // ---------------------------------------------------------------------
    // internals

    private static function is_already_premium($fs): bool
    {
        $checks = ['is_premium', 'is_paying', 'can_use_premium_code'];
        foreach ($checks as $m) {
            if (method_exists($fs, $m) && $fs->{$m}()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Translate the raw $fs->opt_in() return value into our status tuple.
     *
     * @param mixed  $response
     * @param string $key
     * @return array{status:string,message:string,retryable:bool}
     */
    private static function classify($response, string $key): array
    {
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $msg = method_exists($response, 'get_error_message')
                ? $response->get_error_message()
                // @codeCoverageIgnoreStart
                : 'unknown error';
                // @codeCoverageIgnoreEnd
            return self::result(
                'network_failure',
                'Could not reach the licensing server (' . $msg . '). Please retry in a moment.',
                true
            );
        }

        if ($response === true || (is_object($response) && !empty($response->is_valid))) {
            return self::result('success', 'License activated successfully.');
        }

        if (is_object($response) && isset($response->error)) {
            return self::map_error($response->error, $key);
        }

        if ($response === false || $response === null) {
            return self::result(
                'unknown',
                'The licensing server returned no result. Please double-check the key (' . $key . ') and try again.'
            );
        }

        return self::result(
            'unknown',
            'The licensing server returned an unexpected response. Please contact support if this persists.'
        );
    }

    private static function map_error($error, string $key): array
    {
        $code    = is_object($error) ? ($error->code ?? '') : (is_array($error) ? ($error['code'] ?? '') : '');
        $message = is_object($error) ? ($error->message ?? '') : (is_array($error) ? ($error['message'] ?? '') : '');

        if (in_array($code, self::SEAT_LIMIT_CODES, true)) {
            return self::result(
                'seat_limit',
                'This license key is already activated on another site. Deactivate it there first, or move the license from your Freemius account.'
            );
        }

        if (in_array($code, self::INVALID_KEY_CODES, true)) {
            return self::result(
                'invalid_key',
                'License key "' . $key . '" is not recognized. Double-check the key in your purchase email.'
            );
        }

        if ($code === 'cant_resolve_user' || $code === 'user_blocked') {
            return self::result(
                'invalid_key',
                'License key "' . $key . '" is not associated with a valid account. Please contact support.'
            );
        }

        if ($code === '' && $message === '') {
            return self::result('unknown', 'The licensing server returned an unspecified error.');
        }

        return self::result('unknown', $message !== '' ? $message : 'Unspecified licensing error (' . $code . ').');
    }

    private static function result(string $status, string $message, bool $retryable = false): array
    {
        return [
            'status'    => $status,
            'message'   => $message,
            'retryable' => $retryable,
        ];
    }
}

// @codeCoverageIgnoreStart
if (!function_exists('esc_attr_compat')) {
// @codeCoverageIgnoreEnd
    function esc_attr_compat($s) {
        return function_exists('esc_attr')
            ? esc_attr($s)
            : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
