<?php
/**
 * TC_Bulk_Migration_Bundle_Service
 *
 * Issue #522 — slice 1 of 3. Foundational pure helper for bulk
 * site-to-site migration of all tables. Defines the bundle envelope
 * schema, version field, builder, validator, and conflict-resolution
 * policy logic.
 *
 * Bundle envelope:
 *   {
 *     "version": "1.0",
 *     "exported_at": "2026-04-29T14:30:45+00:00",
 *     "table_count": 12,
 *     "tables": [ ...table records, passed through verbatim... ]
 *   }
 *
 * Slice 2 builds on this with the Tools page UI ('Export all tables'
 * action that calls `build()` and emits a downloadable zip/json;
 * 'Import all tables' upload + read).
 *
 * Slice 3 adds dry-run preview, per-table conflict prompt, missing
 * GF form_id warning, per-table failure isolation.
 *
 * @since 4.7.39
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Bulk_Migration_Bundle_Service {

    private const VERSION = '1.0';

    public static function bundle_version(): string {
        return self::VERSION;
    }

    public static function build(array $tables, ?DateTimeImmutable $now = null): array {
        if ($now === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        return [
            'version'     => self::VERSION,
            'exported_at' => $now->format('c'),
            'table_count' => count($tables),
            'tables'      => array_values($tables),
        ];
    }

    public static function validate(array $bundle): ?string {
        if (!array_key_exists('version', $bundle)) {
            return 'Bundle is missing the version field.';
        }
        if (!is_string($bundle['version'])) {
            return 'Bundle version must be a string.';
        }
        if (!array_key_exists('exported_at', $bundle)) {
            return 'Bundle is missing the exported_at timestamp.';
        }
        if (!is_string($bundle['exported_at'])) {
            // @codeCoverageIgnoreStart
            return 'Bundle exported_at must be a string.';
            // @codeCoverageIgnoreEnd
        }
        if (!array_key_exists('tables', $bundle)) {
            return 'Bundle is missing the tables array.';
        }
        if (!is_array($bundle['tables'])) {
            return 'Bundle tables must be an array.';
        }
        foreach ($bundle['tables'] as $idx => $table) {
            if (!is_array($table)) {
                return "Bundle table at index {$idx} is not an array.";
            }
            if (!array_key_exists('title', $table)) {
                return "Bundle table at index {$idx} is missing the title field.";
            }
        }
        return null;
    }

    public static function is_compatible(string $version): bool {
        if (!preg_match('/^(\d+)\./', $version, $m)) {
            return false;
        }
        $major = (int) $m[1];
        return $major === 1;
    }

    public static function resolve_conflict(string $policy, bool $exists): string {
        switch ($policy) {
            case 'skip':
                return $exists ? 'skip' : 'create';
            case 'overwrite':
                return $exists ? 'overwrite' : 'create';
            case 'create_as_new':
                return 'create';
            default:
                return 'skip';
        }
    }
}
