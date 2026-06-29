<?php
/**
 * Centralised guard for local-file path containment.
 *
 * Used wherever the plugin reads a file path that may come from
 * configuration or user input. Guarantees the resolved real path lives
 * inside an explicitly allowed base directory — defeats `../`, backslash,
 * doubled-dot, and null-byte traversal payloads in one place.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Path_Guard
{
    /**
     * Return true iff $path resolves to a real file that lives inside
     * $allowed_base (also resolved). Rejects:
     *   - empty / whitespace-only / non-string input
     *   - paths containing null bytes
     *   - paths whose realpath() is false (does not exist, broken symlink)
     *   - paths whose realpath() does not start with the realpath() of
     *     $allowed_base (traversal escaping the allowed root)
     */
    public static function is_safe_local_path($path, string $allowed_base): bool
    {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }

        // Null-byte injection (`evil.xml\0.jpg`) — refuse outright.
        if (strpos($path, "\0") !== false) {
            return false;
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            return false;
        }

        $base = realpath($allowed_base);
        if ($base === false) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        // Normalise trailing slash on the base so `/foo/uploads-evil` does
        // not slip past a `/foo/uploads` prefix check.
        $base_with_sep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($resolved . DIRECTORY_SEPARATOR, $base_with_sep) === 0;
    }
}
