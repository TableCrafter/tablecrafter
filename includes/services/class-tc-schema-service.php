<?php
/**
 * TC_Schema_Service
 *
 * Issue #547 - slice 1 of 3. Pure builder helper for the per-table
 * schema.org JSON-LD output feature. Slice 1 ships the config
 * normalization + the `Table` JSON-LD builder. Slices 2 + 3 wire
 * the render hook, richer types (Dataset / ProductList / EventList),
 * the admin UI (type dropdown + column-to-property mapping), and
 * Google Rich Results validation tooling.
 *
 * @since 4.7.47
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Schema_Service {

    private const TYPES = ['off', 'Table', 'Dataset', 'ProductList', 'EventList'];
    private const DEFAULT_TYPE = 'Table';

    public static function defaults(): array {
        return [
            'schema_type'         => self::DEFAULT_TYPE,
            'schema_property_map' => [],
        ];
    }

    public static function normalize(array $settings): array {
        $out = self::defaults();

        if (array_key_exists('schema_type', $settings)) {
            $type = $settings['schema_type'];
            $out['schema_type'] = (is_string($type) && in_array($type, self::TYPES, true))
                ? $type
                : self::DEFAULT_TYPE;
        }

        if (array_key_exists('schema_property_map', $settings)) {
            $raw = $settings['schema_property_map'];
            if (is_array($raw)) {
                $clean = [];
                foreach ($raw as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $clean[$k] = $v;
                    }
                }
                $out['schema_property_map'] = $clean;
            }
        }

        return $out;
    }

    public static function is_enabled(array $settings): bool {
        $type = isset($settings['schema_type']) ? $settings['schema_type'] : self::DEFAULT_TYPE;
        return $type !== 'off';
    }

    /**
     * Build the JSON-LD payload array for a table. Returns null when
     * disabled. Slice 2 (4.7.66) extends slice 1's `Table` support with
     * `Dataset` (most useful for SEO on pricing / spec / data tables).
     * `ProductList` and `EventList` remain pending slice 3 - they need
     * the column-to-property map UI before they can emit useful output.
     */
    public static function build_jsonld(array $table, array $settings): ?array {
        if (!self::is_enabled($settings)) {
            return null;
        }
        $type = isset($settings['schema_type']) ? $settings['schema_type'] : self::DEFAULT_TYPE;
        $title = isset($table['title']) && is_string($table['title']) ? $table['title'] : '';

        if ($type === 'Table') {
            $payload = [
                '@context' => 'https://schema.org',
                '@type'    => 'Table',
            ];
            if ($title !== '') {
                $payload['name']  = $title;
                $payload['about'] = $title;
            }
            return $payload;
        }

        if ($type === 'Dataset') {
            $payload = [
                '@context' => 'https://schema.org',
                '@type'    => 'Dataset',
            ];
            if ($title !== '') {
                $payload['name']        = $title;
                $payload['description'] = $title;
            }
            return $payload;
        }

        // ProductList / EventList still pending the slice-3 column-mapping UI.
        return null;
    }
}
