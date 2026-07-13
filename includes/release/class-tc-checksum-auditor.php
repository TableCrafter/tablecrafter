<?php
/**
 * Release-time auditor that catches checksum-API drift before users
 * see false-positive integrity warnings from Wordfence / Jetpack
 * Scan / MainWP.
 *
 * Issue #485: when a release removes a previously-shipped file, the
 * WordPress.org plugin checksum API can keep listing the old path
 * until a manual re-sync is filed. Integrity scanners then flag the
 * install as tampered. This class provides the diff + parity logic
 * the release pipeline calls before pushing to WordPress.org.
 *
 * Pure functions - every entry point takes data, returns data. The
 * git / HTTP wrapping lives in bin/release-checksum-audit.php so
 * the audit logic stays unit-testable.
 */
// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    // Allow CLI use without WordPress bootstrap.
    if (PHP_SAPI !== 'cli') { exit; }
// @codeCoverageIgnoreEnd
}

class TC_Checksum_Auditor
{
    /**
     * Diff two file lists.
     *
     * @param string[] $previous Files in the previous tag's tree.
     * @param string[] $current  Files in the current tag's tree.
     * @return array{added: string[], removed: string[]}
     */
    public static function diff_file_lists(array $previous, array $current): array
    {
        $prev_set = array_fill_keys($previous, true);
        $curr_set = array_fill_keys($current, true);

        $added   = array_keys(array_diff_key($curr_set, $prev_set));
        $removed = array_keys(array_diff_key($prev_set, $curr_set));

        sort($added);
        sort($removed);

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Compare a WP.org checksums-API response against the shipped zip.
     *
     * @param array<string,string> $api_files path => sha256 (or md5).
     * @param array<string,string> $zip_files path => sha256 (or md5).
     * @return array{
     *   missing_from_zip: string[],
     *   missing_from_api: string[],
     *   hash_mismatch:    string[],
     *   has_drift:        bool
     * }
     */
    public static function parity_report(array $api_files, array $zip_files): array
    {
        $missing_from_zip = array_keys(array_diff_key($api_files, $zip_files));
        $missing_from_api = array_keys(array_diff_key($zip_files, $api_files));

        $hash_mismatch = [];
        foreach ($api_files as $path => $hash) {
            if (isset($zip_files[$path]) && $zip_files[$path] !== $hash) {
                $hash_mismatch[] = $path;
            }
        }

        sort($missing_from_zip);
        sort($missing_from_api);
        sort($hash_mismatch);

        return [
            'missing_from_zip' => $missing_from_zip,
            'missing_from_api' => $missing_from_api,
            'hash_mismatch'    => $hash_mismatch,
            'has_drift'        => !empty($missing_from_zip)
                                 || !empty($missing_from_api)
                                 || !empty($hash_mismatch),
        ];
    }

    /**
     * Filter a list of removed files to those NOT mentioned in the
     * changelog entry for the target version.
     *
     * @param string[] $removed_files Files removed since previous tag.
     * @param string   $readme_text   Full readme.txt contents.
     * @param string   $version       Target version, e.g. "4.7.0".
     * @return string[] Subset that is undocumented.
     */
    public static function undocumented_removals(array $removed_files, string $readme_text, string $version): array
    {
        $section = self::extract_changelog_section($readme_text, $version);
        if ($section === '') {
            return $removed_files;
        }
        $undocumented = [];
        foreach ($removed_files as $file) {
            if (strpos($section, $file) === false) {
                $undocumented[] = $file;
            }
        }
        return $undocumented;
    }

    /**
     * Extract the changelog section for a single version from a
     * readme.txt body. Returns '' if no section is found.
     */
    private static function extract_changelog_section(string $readme_text, string $version): string
    {
        $pattern = '/^=\s*' . preg_quote($version, '/') . '\s*=\s*$/m';
        if (!preg_match($pattern, $readme_text, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = $m[0][1] + strlen($m[0][0]);
        // End is the next "= x.y.z =" header or end-of-string.
        if (preg_match('/^=\s*[0-9][^=]*=\s*$/m', $readme_text, $next, PREG_OFFSET_CAPTURE, $start)) {
            return substr($readme_text, $start, $next[0][1] - $start);
        }
        return substr($readme_text, $start);
    }

    /**
     * Hash every file under a directory tree, returning [relative-path => sha256].
     * Skips dot-files / vcs / vendor / node_modules / tests by default so the
     * result matches what WordPress.org distributes.
     *
     * @param string   $root           Directory root (will be a checked-out tag).
     * @param string[] $exclude_globs  Relative-path globs to skip.
     * @return array<string,string>
     */
    public static function hash_tree(string $root, array $exclude_globs = []): array
    {
        $defaults = ['.git', '.github', 'node_modules', 'vendor', 'tests', '.claude'];
        $exclude  = array_merge($defaults, $exclude_globs);
        $out      = [];
        if (!is_dir($root)) { return $out; }
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        $root_real = realpath($root);
        foreach ($rii as $f) {
            if (!$f->isFile()) { continue; }
            $rel = ltrim(substr($f->getPathname(), strlen($root_real) + 1), DIRECTORY_SEPARATOR);
            $skip = false;
            foreach ($exclude as $g) {
                if (str_starts_with($rel, $g . DIRECTORY_SEPARATOR) || $rel === $g) { $skip = true; break; }
            }
            if ($skip) { continue; }
            $out[$rel] = hash_file('sha256', $f->getPathname());
        }
        return $out;
    }
}
