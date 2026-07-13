<?php
/**
 * TC_Export_Filename_Service
 *
 * Issue #519 - slice 1 of 3. Pure helper that expands filename-pattern
 * tokens (`{table_name}`, `{YYYY-MM-DD}`, `{timestamp}`, etc.) into a
 * filesystem-safe filename. Substrate the future scheduled-export job
 * uses to decide where to write each run.
 *
 * Tokens:
 *   {table_name} - from $context['table_name']
 *   {table_id} - from $context['table_id']
 *   {YYYY} - 4-digit year
 *   {MM} - 2-digit month (01-12)
 *   {DD} - 2-digit day (01-31)
 *   {YYYY-MM-DD} - convenience composite
 *   {HH} - 24-hour hour (00-23)
 *   {mm} - minutes (00-59) - lowercase to disambiguate from MM
 *   {ss} - seconds (00-59)
 *   {HHMMSS} - convenience composite
 *   {timestamp} - Unix timestamp
 *
 * Unknown tokens (e.g. `{nonsense}`) are passed through verbatim.
 *
 * After substitution the result is sanitized: backslashes, forward
 * slashes, `..`, null bytes, and control chars are stripped.
 * Spaces inside the table_name token are preserved (most modern
 * filesystems handle them, and users expect 'My Table-...' to render
 * readably).
 *
 * Empty pattern → default fallback `{table_name}-{YYYY-MM-DD}.csv`.
 *
 * Slice 2 wires this into a `TC_Scheduled_Export_Service` runner;
 * slice 3 adds the admin UI + email destination + manual-run button.
 *
 * @since 4.7.37
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Export_Filename_Service {

    private const DEFAULT_PATTERN = '{table_name}-{YYYY-MM-DD}.csv';

    public static function expand(string $pattern, array $context = [], ?DateTimeImmutable $now = null): string {
        if ($pattern === '') {
            $pattern = self::DEFAULT_PATTERN;
        }
        if ($now === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        $replacements = [
            '{table_name}'    => isset($context['table_name']) ? (string) $context['table_name'] : '',
            '{table_id}'      => isset($context['table_id']) ? (string) $context['table_id'] : '',
            '{YYYY-MM-DD}'    => $now->format('Y-m-d'),
            '{HHMMSS}'        => $now->format('His'),
            '{YYYY}'          => $now->format('Y'),
            '{MM}'            => $now->format('m'),
            '{DD}'            => $now->format('d'),
            '{HH}'            => $now->format('H'),
            '{mm}'            => $now->format('i'),
            '{ss}'            => $now->format('s'),
            '{timestamp}'     => (string) $now->getTimestamp(),
        ];
        // Order matters: replace composite tokens first so the simpler
        // tokens inside them don't match prematurely.
        $rendered = strtr($pattern, $replacements);
        return self::sanitize_filename($rendered);
    }

    /**
     * Strip filesystem-unsafe characters. Preserves alphanumerics,
     * spaces, dots, hyphens, underscores, and the non-ASCII range.
     */
    private static function sanitize_filename(string $name): string {
        // Strip null bytes and control chars (0x00 - 0x1F + 0x7F).
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        // Replace path separators and parent-traversal with empty.
        $name = str_replace(['/', '\\', '..'], '', $name);
        return $name;
    }
}
