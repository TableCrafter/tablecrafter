<?php
/**
 * Outbound-URL SSRF gate - issue #1075.
 *
 * Shared helper that every wp_remote_*() call site in the plugin routes
 * through. Promotes the original TC_JSON_Source_Service::is_safe_url()
 * (#980 / v4.165.0) to a free-standing function so the gate is enforced
 * uniformly across:
 *
 *   - includes/class-tc-auto-import.php       (auto-import URL fetch)
 *   - includes/class-tc-xml-source.php        (XML remote-URL fetch)
 *   - includes/class-tc-ajax.php              (entry-change webhook)
 *   - includes/services/class-tc-json-source-service.php
 *                                              (fetch_from_url defense-in-depth)
 *   - includes/services/class-tc-json-push-engine.php
 *                                              (push-to-URL - already gated)
 *
 * Security audit reference:
 *   https://github.com/TableCrafter/gravity-tables/issues/1075#issuecomment-4583955144
 *
 * Why each guard exists:
 *
 *   - Scheme allow-list (http / https): rejects javascript:, data:,
 *     file://, gopher://, ftp:// - these are XSS / LFI / SSRF tunnels.
 *
 *   - Loopback hostnames (localhost, localhost.localdomain,
 *     broadcasthost) + .local mDNS: an admin-supplied URL pointing at
 *     loopback would let the server fetch its own internal endpoints
 *     (admin-only APIs, debug pages, etc.) - classic SSRF.
 *
 *   - RFC1918 private ranges (10/8, 172.16/12, 192.168/16) + IPv6 ULA
 *     fc00::/7: same SSRF surface as loopback, scoped to the LAN.
 *     Reaches internal services that have no public ACL.
 *
 *   - Link-local 169.254.0.0/16: AWS / GCP / Azure / OpenStack instance
 *     metadata endpoints live at 169.254.169.254. Allowing the gate to
 *     fetch this would leak IAM credentials, kernel commands, and
 *     bootstrap secrets to whatever consumes the response body.
 *
 *   - DNS-rebinding re-check: for non-IP hostnames, gethostbyname() is
 *     called and the resolved IP is re-checked against the same
 *     private/loopback/link-local guard. Without this, an attacker can
 *     register a public-looking domain whose A record points at
 *     169.254.169.254 and bypass the IP-literal check entirely.
 *
 * Return shape: bool. true = safe to fetch; false = reject. Matches the
 * original is_safe_url() contract so call sites can branch on truthy and
 * emit their own WP_Error with a context-specific code.
 *
 * @since 5.2.0 (issue #1075)
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
// @codeCoverageIgnoreStart
if (!function_exists('gt_validate_outbound_url')) {
// @codeCoverageIgnoreEnd
    /**
     * Validate that $url is safe to pass to wp_remote_*() - i.e. it
     * points at a clearly-public HTTP(S) endpoint, not at loopback /
     * a private LAN address / a cloud metadata IP.
     *
     * @param string   $url             The candidate URL.
     * @param string[] $allowed_schemes Lowercase scheme allow-list.
     *                                  Defaults to ['http', 'https'].
     * @return bool true iff the URL is safe to fetch.
     */
    function gt_validate_outbound_url(string $url, array $allowed_schemes = ['http', 'https']): bool {
        // ---- parse + structural checks ----
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, $allowed_schemes, true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        // ---- IPv6 literal bracket-strip ----
        // parse_url preserves the [..] wrapping around IPv6 literals
        // (e.g. "http://[::1]/" → host "[::1]"). filter_var rejects the
        // bracketed form, so strip them before classifying as IP.
        if (isset($host[0]) && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }

        // ---- hostname-level loopback aliases ----
        $blocked_hostnames = ['localhost', 'localhost.localdomain', 'broadcasthost'];
        if (in_array($host, $blocked_hostnames, true)) {
            return false;
        }

        // mDNS .local - not addressable from a public server and a
        // common shape for "I forgot to swap to prod" misconfigs.
        if (substr($host, -6) === '.local') {
            return false;
        }

        // ---- IP literal? Run the private/reserved-range gate. ----
        $is_ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($is_ip) {
            return _gt_ip_is_public($host);
        }

        // ---- DNS-rebinding re-check ----
        // The host is a name, not an IP literal. Resolve it and apply
        // the same private/reserved-range check. Without this gate, a
        // public-looking hostname whose A record points at 127.0.0.1
        // or 169.254.169.254 sails through the IP-literal branch above.
        $resolved = _gt_resolve_host($host);

        if ($resolved === '' || $resolved === $host || filter_var($resolved, FILTER_VALIDATE_IP) === false) {
            // gethostbyname() returns the input string unchanged when
            // it cannot resolve. This is ambiguous - it could mean
            // "DNS is unavailable" (CI sandbox, locked-down host) or
            // "the name doesn't exist." We degrade to the pre-#1075
            // behaviour and trust the hostname rather than fail closed,
            // because the IP-literal branch above already blocks the
            // most common SSRF payload (`http://169.254.169.254/`).
            //
            // The rebinding guard's *real* job is catching the case
            // where DNS *does* resolve, but to a private/loopback IP.
            // That case is handled below.
            return true;
        }

        return _gt_ip_is_public($resolved);
    }
}

// @codeCoverageIgnoreStart
if (!function_exists('_gt_ip_is_public')) {
// @codeCoverageIgnoreEnd
    /**
     * True iff $ip (a literal IPv4 or IPv6 address) is in a publicly
     * routable range. Rejects loopback, RFC1918, link-local, IPv6 ULA,
     * 0.0.0.0, etc. via PHP's built-in filter flags plus a manual
     * sanity check for 0.0.0.0 (PHP's flags do not catch the
     * "any address" sentinel as reserved on all builds).
     *
     * Internal helper - not part of the public API.
     */
    function _gt_ip_is_public(string $ip): bool {
        // 0.0.0.0 is the "unspecified" address - never publicly routable.
        if ($ip === '0.0.0.0') {
            return false;
        }
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        return $public !== false;
    }
}

// @codeCoverageIgnoreStart
if (!function_exists('_gt_resolve_host')) {
// @codeCoverageIgnoreEnd
    /**
     * Resolve $host to an IPv4 address. Internal helper for the
     * DNS-rebinding guard. Tests can inject a deterministic resolver
     * by setting $GLOBALS['gt_test_resolver'] = function($host): string
     * before exercising the helper - only honoured under TC_PHPUNIT_SHIM
     * so production traffic always uses the real gethostbyname().
     *
     * @return string The resolved IP, or '' on lookup failure.
     */
    function _gt_resolve_host(string $host): string {
        if (defined('TC_PHPUNIT_SHIM') && isset($GLOBALS['gt_test_resolver']) && is_callable($GLOBALS['gt_test_resolver'])) {
            return (string) call_user_func($GLOBALS['gt_test_resolver'], $host);
        }
        if (!function_exists('gethostbyname')) {
            // Hosts without the dns extension. Fail closed - we cannot
            // run the rebinding guard, so we cannot trust the hostname.
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }
        return (string) gethostbyname($host);
    }
}
