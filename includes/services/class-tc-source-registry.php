<?php
/**
 * TC_Source_Registry — single source of truth for TableCrafter data-source types.
 *
 * Issue #2009 (convergence epic #2006, Phase 1). Replaces the hardcoded source
 * lists previously duplicated in the builder select (admin/views/table-builder.php)
 * and the wizard radios (admin/views/wizard/step-1.php). Every consumer — builder,
 * wizard, shortcode dispatch — reads from here, and new sources register once via
 * the `gravity_tables_source_types` filter and surface everywhere.
 *
 * Each source definition:
 *   - label       (string) human-readable name
 *   - requires_gf (bool)   true if the source needs Gravity Forms active
 *   - pro         (bool)   true if gated to premium (enforced separately, #2026)
 *   - in_wizard   (bool)   true to offer it in the quick-start wizard
 *   - description (string) optional builder helper text
 */

class TC_Source_Registry
{
    /** Translate without hard-depending on WP being loaded (tests/CLI). */
    private static function t(string $text): string
    {
        return function_exists('__') ? __($text, 'tc-data-tables') : $text;
    }

    /**
     * The full, filterable map of source type => definition.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $sources = array(
            'manual' => array(
                'label'       => self::t('Manual / Static table'),
                'requires_gf' => false,
                'pro'         => false, // #2366 — manual tables are a free-tier feature (TablePress parity P1-1)
                'in_wizard'   => true,
                'wizard_icon' => 'dashicons-editor-table',
                'wizard_badge' => self::t('New'),
                'description' => self::t('Enter data directly — no external source needed. Edit rows in the grid editor (coming soon).'),
            ),
            'gravity_forms' => array(
                'label'          => self::t('Gravity Forms entries'),
                'requires_gf'    => true,
                'pro'            => true, // Gravity Forms is the premium product's core source.
                'in_wizard'      => true,
                'wizard_icon'    => 'dashicons-feedback',
                'wizard_badge'   => self::t('Most popular'),
                'requires_class' => 'GFAPI',
                'description'    => self::t('Build a table from a Gravity Forms form\'s entries.'),
            ),
            'woocommerce_products' => array(
                'label'          => self::t('WooCommerce Products'),
                'requires_gf'    => false,
                'pro'            => true, // WooCommerce integration is Pro.
                'in_wizard'      => true,
                'wizard_icon'    => 'dashicons-cart',
                'requires_class' => 'WooCommerce',
                'description'    => self::t('Query your WooCommerce product catalog live.'),
            ),
            'json' => array(
                'label'       => self::t('JSON file or REST API URL'),
                'requires_gf' => false,
                'pro'         => false,
                'in_wizard'   => true,
                'wizard_icon' => 'dashicons-rest-api',
                'description' => self::t('Fetch rows from a remote JSON endpoint with optional auth headers.'),
            ),
            'airtable' => array(
                'label'       => self::t('Airtable'),
                'requires_gf' => false,
                'pro'         => true, // Airtable integration is Pro.
                'in_wizard'   => false,
                'description' => self::t('Pull records from an Airtable base.'),
            ),
            'notion' => array(
                'label'       => self::t('Notion'),
                'requires_gf' => false,
                'pro'         => true, // #2026 (D1) — Notion is a Pro source.
                'in_wizard'   => false,
                'description' => self::t('Pull rows from a Notion database.'),
            ),
            'google_sheets' => array(
                'label'       => self::t('Google Sheets'),
                'requires_gf' => false,
                'pro'         => false,
                'in_wizard'   => true, // #2039 — URL-based, good wizard fit.
                'wizard_icon' => 'dashicons-media-spreadsheet',
                'description' => self::t('Display a public Google Sheet (published to the web) as a table.'),
            ),
            'xml' => array(
                'label'       => self::t('XML feed or file'),
                'requires_gf' => false,
                'pro'         => true, // #2026 (D1) — XML is a Pro source.
                'in_wizard'   => false,
                'description' => self::t('Fetch rows from an XML feed or file via a repeating-element path.'),
            ),
            'csv' => array(
                'label'       => self::t('CSV file or URL'),
                'requires_gf' => false,
                'pro'         => false,
                'in_wizard'   => true, // #2039 — URL-based, good wizard fit.
                'wizard_icon' => 'dashicons-media-spreadsheet',
                'description' => self::t('Fetch a remote CSV file; cached and auto-refreshed after a configurable interval.'),
            ),
            'xlsx' => array(
                'label'       => self::t('Excel file (.xlsx)'),
                'requires_gf' => false,
                'pro'         => false,
                'in_wizard'   => false,
                'wizard_icon' => 'dashicons-media-spreadsheet',
                'description' => self::t('Fetch a remote Excel (.xlsx) workbook; the first sheet\'s first row is the headers.'),
            ),
            'external_db' => array(
                'label'       => self::t('External database (MySQL / SQL Server)'),
                'requires_gf' => false,
                'pro'         => true,
                'in_wizard'   => false,
                'description' => self::t('Run a read-only SELECT against an external MySQL or SQL Server database.'),
            ),
        );

        if (function_exists('apply_filters')) {
            $sources = apply_filters('gravity_tables_source_types', $sources);
        }

        return is_array($sources) ? $sources : array();
    }

    /** @return string[] source-type identifiers */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Whether a given source-type key is registered. */
    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** Human label for a key; falls back to the key itself (never fatal/empty). */
    public static function label(string $key): string
    {
        $all = self::all();
        if (isset($all[$key]['label']) && $all[$key]['label'] !== '') {
            return (string) $all[$key]['label'];
        }
        return $key;
    }

    /** Full definition for a key, or null. */
    public static function get(string $key): ?array
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /** All sources, for the builder data-source select. */
    public static function for_builder(): array
    {
        return self::all();
    }

    /** Subset flagged in_wizard, for the quick-start wizard. */
    public static function for_wizard(): array
    {
        return array_filter(self::all(), static function ($def) {
            return !empty($def['in_wizard']);
        });
    }
}
