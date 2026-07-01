<?php
/**
 * Plugin Name: TableCrafter
 * Plugin URI: https://github.com/TableCrafter/tablecrafter
 * Description: TableCrafter — beautiful, responsive data tables for WordPress. Free: 3 tables, 8 columns, 500 entries. Pro: unlimited everything + frontend editing, bulk operations, advanced filters.
 * Version: 8.0.35
 * Author: Fahad Murtaza @ iSuperCoder.com
 * Author URI: https://isupercoder.com/contact
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tc-data-tables
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * Network: false
 *
 * @fs_premium_only /includes/services/class-tc-notion-sync-engine.php, /includes/services/class-tc-notion-payload-normalizer.php, /includes/services/class-tc-notion-push-engine.php, /includes/services/class-tc-airtable-push-engine.php, /includes/class-tc-external-db.php, /includes/class-tc-xml-source.php, /admin/views/db-connections.php, /admin/js/db-connections.js
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TC_VERSION', '8.0.35');
define('TC_PHP_COMPAT_VERSION', '8.1');
define('TC_ELEMENTOR_MIN_VERSION', '3.5.0');
define('TC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('TC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Free plan limitations
// #2025 (convergence epic #2006, D1) — size caps removed. The product gates by
// FEATURE (editing, sync, pro sources, advanced filters), not by size. These
// constants are kept for back-compat but set to an effectively-unlimited
// sentinel so every existing "$count >= TC_FREE_MAX_*" comparison no-ops and the
// render-path "Table Limit Exceeded" notice never appears. Premium FEATURE gates
// are unaffected (see #2026).
define('TC_FREE_MAX_TABLES', PHP_INT_MAX);
define('TC_FREE_MAX_COLUMNS', PHP_INT_MAX);
define('TC_FREE_MAX_ENTRIES', PHP_INT_MAX);

// #976 v4.163.0 — Trash retention window (phase 1c-3 of #593).
// Items in Trash older than this are visually flagged as "purges on next
// run" in the admin UI, and (once phase 1d ships) will be removed by the
// WP-cron auto-purge job. Override via the `gravity_tables_trash_retention_days`
// filter — e.g. `add_filter('gravity_tables_trash_retention_days', fn() => 14);`
if (!defined('TC_TRASH_RETENTION_DAYS')) {
    define('TC_TRASH_RETENTION_DAYS', 30);
}

if (!function_exists('wgt_fs')) {
    // Create a helper function for easy SDK access.
    function wgt_fs()
    {
        // #667 slice 15 — PHPUnit-shim test seam. Tests cannot stub wgt_fs()
        // via function_exists-guarded redeclaration because bootstrap.php
        // loads this file BEFORE any test-issue file gets a chance to install
        // a stub. The seam consults $GLOBALS['gt_test_fs_override'] only when
        // TC_PHPUNIT_SHIM is defined — production callers (where the shim
        // constant is never defined) follow the byte-identical pre-slice
        // code path and never read the override.
        if (defined('TC_PHPUNIT_SHIM') && isset($GLOBALS['gt_test_fs_override'])) {
            return $GLOBALS['gt_test_fs_override'];
        }

        global $wgt_fs;

        if (!isset($wgt_fs)) {
            // Load Freemius SDK. #2151 — guard the require so a build that is
            // somehow missing vendor/ degrades gracefully (free tier keeps
            // working) instead of fataling on every request.
            $fs_sdk = TC_PLUGIN_PATH . 'vendor/freemius/wordpress-sdk/start.php';
            if (!file_exists($fs_sdk)) {
                if (function_exists('add_action')) {
                    add_action('admin_notices', function () {
                        echo '<div class="notice notice-error"><p>'
                            . esc_html__('TableCrafter: the licensing SDK is missing from this build. Please reinstall the plugin.', 'tc-data-tables')
                            . '</p></div>';
                    });
                }
                $wgt_fs = null;
                return $wgt_fs;
            }
            require_once $fs_sdk;

            $wgt_fs = fs_dynamic_init(array(
                'id' => '20996',
                'slug' => 'data-tables-for-gravity-forms',
                'premium_slug' => 'wp-gravity-tables-premium',
                'type' => 'plugin',
                'public_key' => 'pk_5f1e1f262ea811c39ec9706ae1613',
                'is_premium' => false,
                'is_premium_only' => false,
                'has_free_plan' => true,
                'has_addons' => false,
                'has_paid_plans' => true,
                // WordPress.org-hosted free version. Without this flag Freemius
                // treats a free install as license-requiring and the opt-in fails
                // with "Invalid license key" instead of offering skip/anonymous.
                'is_org_compliant' => true,
                'trial' => array(
                    'days' => 7,
                    'is_require_payment' => false,
                ),
                'menu' => array(
                    'slug' => 'gravity-tables',
                    'first-path' => 'admin.php?page=gravity-tables',
                    'account' => true,
                    'pricing' => true,
                    'contact' => false,
                    'support' => false,
                ),
            ));

            // Add a "where do I find my license key?" helper to the Freemius
            // license-activation screen — the SDK's default message only says
            // "enter your license key" with no pointer to where to get it.
            $wgt_fs->add_filter('connect-message_on-premium', 'tc_fs_license_help_message', 10, 1);
        }

        return $wgt_fs;
    }

    /**
     * Append a helpful pointer (where to find / how to buy a license key) to the
     * Freemius license-activation prompt. Hooked on 'connect-message_on-premium'.
     */
    function tc_fs_license_help_message($message)
    {
        $help = '<br><br><span style="display:block;font-size:12px;line-height:1.5;opacity:.85">'
            . sprintf(
                /* translators: 1: open account link, 2: close link, 3: open pricing link, 4: close link */
                __('Your license key is in your purchase confirmation email and your %1$sFreemius account%2$s. Don\'t have one yet? %3$sGet TableCrafter Pro%4$s.', 'tc-data-tables'),
                '<a href="https://users.freemius.com/" target="_blank" rel="noopener noreferrer">',
                '</a>',
                '<a href="https://tablecrafter.com/#pricing" target="_blank" rel="noopener noreferrer">',
                '</a>'
            )
            . '</span>';

        return $message . $help;
    }

    // Init Freemius.
    wgt_fs();
    // Signal that SDK was initiated.
    do_action('wgt_fs_loaded');

    // #2146 — clean up plugin data on uninstall via Freemius's after_uninstall
    // hook. Freemius forbids a raw uninstall.php (it tracks the uninstall event
    // itself), so the cleanup is registered here for both free and premium.
    $wgt_fs_instance = wgt_fs();
    if ($wgt_fs_instance) {
        require_once __DIR__ . '/includes/uninstall-cleanup.php';
        $wgt_fs_instance->add_action('after_uninstall', 'tc_run_uninstall_cleanup');
    }
}

// gt_is_premium() / gt_is_free_plan() live in includes/helpers-license.php
// so they can be require'd in isolation by unit tests (see #481).
require_once __DIR__ . '/includes/helpers-license.php';
require_once __DIR__ . '/includes/helpers-db.php';
require_once __DIR__ . '/includes/class-aliases.php';

// #2017 — conflict guard. The legacy standalone free plugin (wp-data-tables,
// "TableCrafter – Data to Beautiful Tables") also uses the TC_* class namespace
// and a tablecrafter.php main file. If it is active, bail BEFORE our class
// requires to avoid a fatal "Cannot redeclare class TC_*", and tell the admin to
// deactivate it — this converged plugin supersedes it. (TC_HTTP_Request is
// defined only by the old free plugin, never here, so it is a safe detector.)
if (class_exists('TC_HTTP_Request')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>TableCrafter:</strong> '
            . esc_html__('Please deactivate the older "TableCrafter – Data to Beautiful Tables" plugin. This version supersedes it; running both at once causes a class conflict.', 'tc-data-tables')
            . '</p></div>';
    });
    return;
}

// #1075 — gt_validate_outbound_url() is the shared SSRF gate for every
// wp_remote_*() call site (auto-import, xml-source, webhook, json-source).
// Required early so the gate is in scope before any service class loads.
require_once __DIR__ . '/includes/helpers-url.php';

// #1076 — gt_encrypt_secret() / gt_decrypt_secret() are the shared AES-256-CBC
// helpers for at-rest secret encryption (cloud-storage OAuth tokens, AI
// provider api_keys). gt_rest_filter_safe_settings() is the REST get_table
// allowlist. gt_bundle_strip_secrets() strips credentials from the bulk-
// migration export. Required early so any class loading after this point
// can call into the helpers.
require_once __DIR__ . '/includes/helpers-secrets.php';

// #1073 — gt_request_server_text() is the shared $_SERVER read helper
// (sanitize_text_field + wp_unslash + missing-key default) used by
// submit_new_entry() to populate the GF entry's ip / source_url /
// user_agent fields without leaking raw superglobals into storage.
require_once __DIR__ . '/includes/helpers-request.php';

/**
 * Return the URL to a plugin asset, always using TC_PLUGIN_URL so the
 * correct scheme (http or https) is inherited from WordPress site_url().
 *
 * @param string $path Relative path within the plugin directory (e.g. 'assets/js/frontend.js').
 * @return string Absolute URL.
 */
function gt_asset_url(string $path): string {
    return TC_PLUGIN_URL . ltrim($path, '/');
}

// Include Composer autoloader for PhpSpreadsheet
if (file_exists(TC_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once TC_PLUGIN_PATH . 'vendor/autoload.php';
}

// Note: Freemius SDK is loaded above via freemius/start.php

// Include exception classes
require_once TC_PLUGIN_PATH . 'includes/exceptions/class-tc-validation-exception.php';
require_once TC_PLUGIN_PATH . 'includes/exceptions/class-tc-permission-exception.php';
require_once TC_PLUGIN_PATH . 'includes/exceptions/class-tc-database-exception.php';

// Include service classes
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-validation-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-auto-format.php'; // #2132 rich auto-formatting engine
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-embed.php'; // #2133 embeddable public tables
if (function_exists('add_action')) {
    add_action('template_redirect', 'tc_embed_template_redirect', 1);
}
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-templates.php'; // #2134 prebuilt templates gallery
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-support.php'; // #2159 AI-first support (phase 1, Pro)
if (function_exists('add_action')) {
    add_action('init', array('TC_Support', 'init'));
}
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-placeholder-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-typography-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-wrap-mode-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-save-limit-diagnostics.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-asset-enqueue-gate.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-cache-invalidator.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-seo-rows-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-waf-safe-payload.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-data-integrity-guard.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-excel-date-decoder.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-ai-cleanup-suggester.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-ai-table-summarizer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-row-expiry-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-data-bars-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-table-duplicate-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-badge-service.php';
// TC_Abilities_Registry — slice 3 (v4.63.0) wires the registry so the
// require is back in. boot() arms `init` (register the six abilities
// when wp_register_ability is available) and `admin_init` (WP <7.0
// dismissible notice when the API is absent).
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-abilities-registry.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-activation-funnel.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-review-prompt.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-inline-shortcode-compat.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-api-key-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-json-source-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-source-registry.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-csv-source.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-xlsx-source.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-demo-data.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-welcome.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-shortcode-content-migrator.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-parity-detector.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-swr-cache.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-block.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-tsv-parser-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-airtable-field-mapper.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-airtable-request-builder.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-airtable-sync-engine.php';
// #2027 — strip-safe require: load a premium-only file only if it's present, so
// the Freemius free build (which strips @fs_premium_only files at build time)
// boots without a missing-file fatal. The premium build ships the files.
if (!function_exists('gt_require_premium_file')) {
    function gt_require_premium_file(string $rel): void {
        $path = TC_PLUGIN_PATH . $rel;
        if (file_exists($path)) {
            require_once $path;
        }
    }
}
gt_require_premium_file('includes/services/class-tc-notion-sync-engine.php');
// #1595 — the push engines + Notion normalizer were never loaded in
// production, so the AJAX push path's class_exists guards always failed
// ("push engine not available") despite the feature shipping since 4.209.0.
gt_require_premium_file('includes/services/class-tc-notion-payload-normalizer.php');
gt_require_premium_file('includes/services/class-tc-notion-push-engine.php');
gt_require_premium_file('includes/services/class-tc-airtable-push-engine.php');
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-json-push-engine.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-airtable-credential-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-airtable-audit-log-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-airtable-conflict-detector.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-airtable-rate-limiter.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-rowspan-merge-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-export-filename-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-scheduled-export-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-clipboard-paste-handler.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-list-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-address-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-checkbox-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-multiselect-filter-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-creditcard-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-post-category-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-post-image-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-product-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-post-content-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-name-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-time-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-consent-field-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-fileupload-edit-guard.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-pagination-rest.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-toolbar-visibility-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-bulk-migration-bundle-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-media-folder-adapter.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-print-settings-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-revision-snapshot-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-find-replace-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-per-row-action-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-cascading-filter-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-table-usage-indexer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-sticky-rows-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-schema-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-vertical-align-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-detail-rows-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-pagination-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-pivot-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-row-link-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-drilldown-filter-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-sanitization-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-error-handler.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-configuration-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-date-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-date-format-service.php';
// TC_Entry_Service — 780-line aborted-refactor service from the v2.0.0 era.
// `grep -rn 'TC_Entry_Service|new TC_Entry_Service|TC_Entry_Service::'` across
// includes/, admin/, templates/, tablecrafter.php, tests/ returns only the
// class definition itself — no live caller, no test contract. Entry CRUD lives
// inline in includes/class-tc-ajax.php instead. The file stays in the repo as
// an archaeology breadcrumb in case someone resumes the extraction; the
// require is dropped to skip an 18-method / 780-line parse on every request.
// (Verified per the v4.7.140 lesson: tests/ greps are part of the orphan check.)
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-query-builder.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-rtl-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-accessibility-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-scroll-buttons-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-border-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-conditional-formatting-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-formula-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-column-filter-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-default-sort-service.php';
// TC_Multi_Sort_Service: file is required even though no production code
// instantiates it yet. test-issue-148-multi-column-sort.php asserts this
// include must be present (the #148 contract), and the service ships full
// build_order_by() / get_datatables_order() implementations. The shift-click
// hookup in assets/js/frontend.js + the AJAX/template wiring on the PHP side
// hasn't landed yet — see v4.7.155 changelog for the docs correction. When
// multi-sort is enabled in the table builder UI, replace this comment with
// the actual instantiation callers.
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-multi-sort-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-row-height-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-capabilities-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-url-filter-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-template-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-pagination-label-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-config-port-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-skeleton-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-star-rating-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-row-grouping-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-excel-float-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-custom-css-writer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-chart-refresh-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-cell-merge-service.php';
// TC_Writeback_Service: file is required even though no production code
// instantiates it yet. test-issue-176-writeback.php asserts the include
// must be present (slice-1-of-#86 contract). When the per-table write-back
// option is exposed in the table builder UI, the actual instantiation
// callers will land alongside.
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-writeback-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-collapsible-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-global-search-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-combined-filter-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-inline-edit-sanitizer.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-date-filter-service.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-relative-date-filter.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-relative-date-filter-integration.php';
require_once TC_PLUGIN_PATH . 'includes/services/class-tc-fuzzy-search-service.php';

// Include admin helper classes
require_once TC_PLUGIN_PATH . 'includes/admin/class-tc-frontend-editing-panel.php';

// Include repository classes
require_once TC_PLUGIN_PATH . 'includes/repositories/class-tc-entry-repository.php';

// Include renderer classes
require_once TC_PLUGIN_PATH . 'includes/renderers/class-tc-form-renderer.php';
require_once TC_PLUGIN_PATH . 'includes/renderers/class-tc-cell-renderer.php';

// Include AI provider stack (#495 — foundation only; feature code lands later)
require_once TC_PLUGIN_PATH . 'includes/services/ai/interface-gt-ai-provider.php';
require_once TC_PLUGIN_PATH . 'includes/services/ai/class-tc-ai-provider-openai.php';
require_once TC_PLUGIN_PATH . 'includes/services/ai/class-tc-ai-provider-anthropic.php';
require_once TC_PLUGIN_PATH . 'includes/services/ai/class-tc-ai-provider-gemini.php';
require_once TC_PLUGIN_PATH . 'includes/services/ai/class-tc-ai-provider-registry.php';
require_once TC_PLUGIN_PATH . 'includes/services/ai/class-tc-ai-usage-tracker.php';
require_once TC_PLUGIN_PATH . 'includes/services/ai/class-tc-ai-column-type-detector.php';
require_once TC_PLUGIN_PATH . 'includes/services/ai/class-tc-ai-ajax.php';

// Include model classes
require_once TC_PLUGIN_PATH . 'includes/models/class-tc-table-configuration.php';

// Include core classes
require_once TC_PLUGIN_PATH . 'includes/class-tc-debug.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-sql-guard.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-entry-owner-guard.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-path-guard.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-logger.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-row-visibility.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-column-visibility.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-license-state.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-license-cleanup.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-license-activator.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-license-persistence.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-email-alerts.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-admin.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-shortcode.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-ajax.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-table-builder.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-lookup.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-rest-api.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-import.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-chart.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-map.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-woocommerce.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-blocks.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-elementor.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-divi-compat.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-divi.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-merged-table.php';
gt_require_premium_file('includes/class-tc-external-db.php');
gt_require_premium_file('includes/class-tc-xml-source.php');
require_once TC_PLUGIN_PATH . 'includes/class-tc-auto-import.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-cloud-storage.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-amp-compat.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-dirty-state.php';
require_once TC_PLUGIN_PATH . 'includes/class-tc-google-sheets.php';

// Initialize the plugin
class Gravity_Tables_Plugin
{

    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        if (class_exists('TC_License_Persistence')) {
            TC_License_Persistence::set_plugin_basename(plugin_basename(__FILE__));
            TC_License_Persistence::register();
        }
    }

    public function init()
    {
        // #554 — capability migration on upgrade. The activation hook does
        // NOT fire on plugin updates, so users upgrading from a version that
        // pre-dates the capability registration would be stuck without caps.
        // ensure_capabilities_for_version() compares the stored gt_version
        // option against TC_VERSION and self-heals when the stored version
        // is empty or older. Idempotent — no-op once the stored option
        // matches TC_VERSION.
        $stored_version = (string) get_option('gt_version', '');
        $needs_version_bump = false;
        if (TC_Capabilities_Service::ensure_capabilities_for_version($stored_version, TC_VERSION)) {
            $needs_version_bump = true;
        }

        // #968 v4.159.0 — soft-delete schema migration (phase 1a of #593).
        // Activation hook does NOT fire on plugin updates, so customers upgrading
        // from a pre-4.159.0 version need their wp_gravity_tables table altered
        // to add the new `deleted_at` column. dbDelta is idempotent — safe to
        // run on every gated upgrade path. Gate on stored version < 4.159.0.
        if ($stored_version === '' || version_compare($stored_version, '4.159.0', '<')) {
            $this->create_tables();
            $needs_version_bump = true;
        }

        // #978 v4.164.0 — Schedule the trash auto-purge cron on upgrade from
        // a pre-4.164.0 version (activation hook does not fire on update).
        // Idempotent: wp_next_scheduled gate avoids double-scheduling.
        if ($stored_version === '' || version_compare($stored_version, '4.164.0', '<')) {
            if (!wp_next_scheduled('gravity_tables_purge_expired_trash')) {
                wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'gravity_tables_purge_expired_trash');
            }
            $needs_version_bump = true;
        }

        // #1728 v6.3.4 — restore show_advanced_filters for tables corrupted by
        // the May-9 v4.60.0 refactor. The refactor removed the builder checkbox
        // while save-table.js still read it, so every subsequent table save wrote
        // show_advanced_filters: false. Any stored version between 4.60.0 and
        // 6.3.4 may have affected records; update them back to true.
        if ($stored_version !== '' && version_compare($stored_version, '4.60.0', '>=') && version_compare($stored_version, '6.3.4', '<')) {
            $this->migrate_restore_show_advanced_filters();
            $needs_version_bump = true;
        }

        // v7.6.3 — migrate stored shortcodes from deprecated [gravity_table] /
        // [gravity_tables] to the canonical [tablecrafter] name. Re-runs the
        // existing maybe_update_shortcodes() mechanism by clearing the old flag
        // so the migration executes once on upgrade from any pre-7.6.3 version.
        if ($stored_version === '' || version_compare($stored_version, '7.6.3', '<')) {
            delete_option('gt_shortcode_migration_done');
            $needs_version_bump = true;
        }

        if ($needs_version_bump) {
            update_option('gt_version', TC_VERSION);
        }

        // #2030 — record that this site has been premium so a later lapse
        // degrades pro-source tables to read-only instead of blanking them.
        if (function_exists('gt_mark_premium_seen')) {
            gt_mark_premium_seen();
        }

        // #2007 — boot the GF-independent core first so external data-source
        // tables (JSON/CSV/Airtable/etc.) render even when Gravity Forms is
        // inactive. GF-coupled wiring is deferred to init_gravity_forms().
        $this->init_core();

        if (class_exists('GFForms')) {
            $this->init_gravity_forms();
        } else {
            // Informational only — external data sources keep working.
            add_action('admin_notices', array($this, 'gravity_forms_notice'));
        }
    }

    /**
     * #2007 — Boot all Gravity-Forms-independent components and hooks.
     * Always runs (even when Gravity Forms is inactive) so external
     * data-source tables render without GF.
     */
    private function init_core()
    {
        // Initialize components
        TC_Debug::get_instance();
        TC_Logger::get_instance();
        $admin = TC_Admin::get_instance();
        TC_Shortcode::get_instance();
        TC_Ajax::get_instance();
        TC_Table_Builder::get_instance();
        TC_REST_API::get_instance();
        TC_Chart::get_instance();
        TC_Map::get_instance();
        // #2027 — External DB is a premium-only source; its file is stripped from
        // the free build, so guard the boot instantiation against a missing class.
        if (class_exists('TC_External_DB')) {
            TC_External_DB::get_instance();
        }
        TC_Cloud_Storage::get_instance();
        TC_AMP_Compat::get_instance();
        TC_Dirty_State::get_instance();
        TC_Google_Sheets::get_instance();
        TC_Global_Search_Service::get_instance();

        // #2012 — stale-while-revalidate cache for external data sources.
        if (class_exists('TC_SWR_Cache')) {
            TC_SWR_Cache::register_cron();
        }

        // #2013 — Gutenberg block (server-rendered, delegates to the shortcode).
        if (class_exists('TC_Block')) {
            TC_Block::boot();
        }

        // #2064 — first-activation welcome / onboarding screen.
        if (class_exists('TC_Welcome')) {
            TC_Welcome::boot();
        }

        // #2117 — ask for a WordPress.org review after a success moment.
        if (class_exists('TC_Review_Prompt')) {
            (new TC_Review_Prompt())->register();
        }

        // #2028 — one-time v3.5.6 takeover parity reassurance. When the converged
        // build first runs over an existing install, confirm that existing tables
        // and their data sources keep working (free tier is a superset of v3.5.6).
        add_action('admin_notices', function () {
            if (!current_user_can('manage_options') || !class_exists('TC_Parity_Detector')) {
                return;
            }
            if (!TC_Parity_Detector::needs_check()) {
                return;
            }
            global $wpdb;
            $table = function_exists('gt_tables_table_name') ? gt_tables_table_name() : ($wpdb->prefix . 'gravity_tables');
            $rows  = $wpdb->get_results("SELECT settings FROM `{$table}` WHERE status = 'active'", ARRAY_A);
            $tables = array();
            foreach ((array) $rows as $r) {
                $s = json_decode((string) ($r['settings'] ?? ''), true);
                $tables[] = array('data_source_type' => is_array($s) && isset($s['data_source_type']) ? $s['data_source_type'] : '');
            }
            TC_Parity_Detector::mark_checked();
            $report = TC_Parity_Detector::analyze($tables);
            if ($report['total'] === 0) {
                return; // fresh install — nothing to reassure about
            }
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html(sprintf(
                    /* translators: 1: total tables, 2: free-tier tables */
                    __('Welcome to TableCrafter. All %1$d of your existing tables continue to work — %2$d use free-tier data sources and keep rendering exactly as before.', 'tc-data-tables'),
                    $report['total'],
                    $report['free']
                ))
                . '</p></div>';
        });

        // #2021 — post-upgrade migration prompt. Dismissible; NEVER auto-runs the
        // rebrand migration (the admin must click Run). Shown only when the DB
        // table or options migration is still pending and the notice isn't
        // dismissed for the current admin.
        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            $db_pending  = function_exists('gt_db_table_migrated') && !gt_db_table_migrated();
            $opt_pending = !get_option('gt_options_migrated', false);
            if (!$db_pending && !$opt_pending) {
                return;
            }
            if (get_user_meta(get_current_user_id(), 'gt_migration_notice_dismissed', true)) {
                return;
            }
            $nonce = wp_create_nonce('gt_admin_nonce');
            echo '<div class="notice notice-info is-dismissible gt-migration-notice" data-nonce="' . esc_attr($nonce) . '"><p>'
                . esc_html__('TableCrafter can migrate your data to the new brand names (recommended). This is a one-time, safe operation that keeps your existing tables working.', 'tc-data-tables')
                . ' <button type="button" class="button button-primary gt-run-migration">' . esc_html__('Run migration', 'tc-data-tables') . '</button>'
                . ' <span class="gt-run-migration-result" style="margin-left:8px;"></span></p></div>';
        });

        // #503 slice 3 — WP 7.0 Abilities API integration.
        // boot() hooks register() on `init` (so wp_register_ability is
        // already loaded when we call it) and arms the WP <7.0
        // dismissible admin notice on admin_init.
        if (class_exists('TC_Abilities_Registry')) {
            TC_Abilities_Registry::boot();
        }

        // #519 slice 2 — Scheduled outbound export runner.
        // boot() registers the cron callback against the
        // `gt_run_scheduled_export` hook and adds the gt_every_6h
        // cron interval (idempotent with TC_Auto_Import which also
        // registers the same interval). schedule_for_table /
        // clear_schedule_for_table are admin-flow calls; slice 3's
        // settings UI will invoke them when a table opts in.
        if (class_exists('TC_Scheduled_Export_Service')) {
            TC_Scheduled_Export_Service::boot();
        }

        // #516 slice 2 — clipboard-paste admin handler.
        if (class_exists('TC_Clipboard_Paste_Handler')) {
            TC_Clipboard_Paste_Handler::boot();
        }

        // #560 slice 2 — server-side pagination REST endpoint.
        // Hooks rest_api_init to register /gt/v1/tables/{id}/rows.
        if (class_exists('TC_Pagination_REST')) {
            TC_Pagination_REST::boot();
        }

        // #526 slice 2 — media-folder JS adapter enqueue + localize.
        if (class_exists('TC_Media_Folder_Adapter')) {
            TC_Media_Folder_Adapter::boot();
        }

        // Enqueue RTL styles when applicable
        add_action('wp_enqueue_scripts', array('TC_RTL_Service', 'enqueue_rtl_styles'));

        // Enqueue accessibility assets on every table page
        add_action('wp_enqueue_scripts', array('TC_Accessibility_Service', 'enqueue_a11y_assets'));

        // Enqueue scroll-buttons JS (rendered per-table when show_scroll_buttons is enabled)
        add_action('wp_enqueue_scripts', array('TC_Scroll_Buttons_Service', 'enqueue_assets'));

        // #550 — Page-cache invalidation. When table data changes, purge any
        // cached page-HTML embedding the affected table from WP Rocket /
        // W3TC / LiteSpeed Cache / WordPress core object cache.
        add_action('gravity_tables_after_save_table', function ($table_id) {
            TC_Cache_Invalidator::invalidate_for_table((int) $table_id);
        });

        // #978 v4.164.0 — WP-cron callback for the daily trash auto-purge
        // (phase 1d of #593). Registered on every WP load (not just admin)
        // so wp-cron.php / DOING_CRON requests can fire it. The scheduling
        // happens in activate() + the version-gated upgrade migration.
        add_action('gravity_tables_purge_expired_trash', function () {
            TC_Admin::get_instance()->purge_expired_trash();
        });
        add_action('gravity_tables_after_import', function ($table_id) {
            TC_Cache_Invalidator::invalidate_for_table((int) $table_id);
        });
        add_action('gravity_tables_after_delete_table', function ($table_id) {
            TC_Cache_Invalidator::invalidate_for_table((int) $table_id);
        });

        // TC_Bulk_Migration_Bundle_Service slice 2b (v4.9.17) — Import-bundle
        // admin-post handler. Validates schema + version compatibility via
        // the service, loops the tables[] array, applies the customer-chosen
        // conflict-resolution policy via service::resolve_conflict, routes
        // each table to insert / update / skip. Result transient is read by
        // tables-list.php for a post-redirect notice (matches the pattern in
        // TC_Import).
        add_action('admin_post_gt_bundle_import', function () {
            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions.', 'tc-data-tables'), '', 403);
            }
            check_admin_referer('gt_bundle_import', 'gt_bundle_import_nonce');
            if (!class_exists('TC_Bulk_Migration_Bundle_Service')) {
                wp_die(__('Bulk migration service is not loaded.', 'tc-data-tables'));
            }
            $key = wp_generate_password(20, false, false);
            $set_result = function (array $result) use ($key) {
                set_transient('gt_bundle_import_result_' . $key, $result, 5 * MINUTE_IN_SECONDS);
                wp_safe_redirect(add_query_arg(
                    array('page' => 'gravity-tables', 'gt_bundle_import_result' => $key),
                    admin_url('admin.php')
                ));
                exit;
            };
            // File-upload sanity.
            if (empty($_FILES['bundle_file']['tmp_name']) || (int) ($_FILES['bundle_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $set_result(array('error' => true, 'message' => __('Upload failed. Please try again.', 'tc-data-tables')));
            }
            $size = (int) ($_FILES['bundle_file']['size'] ?? 0);
            if ($size > 10 * 1024 * 1024) {
                $set_result(array('error' => true, 'message' => __('Bundle too large (10 MB max).', 'tc-data-tables')));
            }
            $contents = file_get_contents($_FILES['bundle_file']['tmp_name']);
            if ($contents === false || $contents === '') {
                $set_result(array('error' => true, 'message' => __('Could not read uploaded bundle.', 'tc-data-tables')));
            }
            $bundle = json_decode($contents, true);
            if (!is_array($bundle)) {
                $set_result(array('error' => true, 'message' => __('Bundle is not valid JSON.', 'tc-data-tables')));
            }
            // Service-level validation.
            $err = TC_Bulk_Migration_Bundle_Service::validate($bundle);
            if ($err !== null) {
                $set_result(array('error' => true, 'message' => sprintf(__('Bundle validation failed: %s', 'tc-data-tables'), $err)));
            }
            if (!TC_Bulk_Migration_Bundle_Service::is_compatible((string) $bundle['version'])) {
                $set_result(array('error' => true, 'message' => sprintf(__('Bundle version %s is not compatible with this plugin.', 'tc-data-tables'), $bundle['version'])));
            }
            // Policy whitelist.
            $policy = isset($_POST['conflict_policy']) ? sanitize_key((string) $_POST['conflict_policy']) : 'skip';
            if (!in_array($policy, array('skip', 'overwrite', 'create_as_new'), true)) {
                $policy = 'skip';
            }
            global $wpdb;
            $tablename = $wpdb->prefix . 'gravity_tables';
            $summary = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0);
            foreach ((array) $bundle['tables'] as $tbl) {
                if (!is_array($tbl)) { $summary['failed']++; continue; }
                $candidate_id = isset($tbl['id']) ? (int) $tbl['id'] : 0;
                $exists = false;
                if ($candidate_id > 0) {
                    $exists = (bool) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$tablename} WHERE id = %d",
                        $candidate_id
                    ));
                }
                $action = TC_Bulk_Migration_Bundle_Service::resolve_conflict($policy, $exists);
                $row = array(
                    'title'      => isset($tbl['title']) ? (string) $tbl['title'] : '',
                    'form_id'    => isset($tbl['form_id']) ? (int) $tbl['form_id'] : 0,
                    'settings'   => isset($tbl['settings']) ? (string) $tbl['settings'] : '',
                    'shortcode'  => isset($tbl['shortcode']) ? (string) $tbl['shortcode'] : '',
                    'status'     => 'active',
                    'updated_at' => current_time('mysql'),
                );
                if ($action === 'skip') {
                    $summary['skipped']++;
                } elseif ($action === 'overwrite' && $candidate_id > 0) {
                    $r = $wpdb->update($tablename, $row, array('id' => $candidate_id), null, array('%d'));
                    $summary[$r === false ? 'failed' : 'updated']++;
                } else {
                    // create or create_as_new
                    $row['created_at'] = current_time('mysql');
                    $r = $wpdb->insert($tablename, $row);
                    if ($r === false) { $summary['failed']++; }
                    else {
                        $new_id = (int) $wpdb->insert_id;
                        // Update the shortcode placeholder with the actual new id.
                        $wpdb->update($tablename, array('shortcode' => '[gravity_table id="' . $new_id . '"]'), array('id' => $new_id), array('%s'), array('%d'));
                        $summary['created']++;
                    }
                }
            }
            $set_result(array(
                'error'   => false,
                'message' => __('Bundle imported.', 'tc-data-tables'),
                'summary' => $summary,
            ));
        });

        // TC_Bulk_Migration_Bundle_Service slice 2a — Export-all admin-post
        // handler. The "Export all to JSON" button on the tables-list page
        // POSTs here. Builds the bundle envelope via the service and streams
        // it as a JSON download.
        add_action('admin_post_gt_bundle_export', function () {
            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions.', 'tc-data-tables'), '', 403);
            }
            check_admin_referer('gt_bundle_export', 'gt_bundle_export_nonce');
            if (!class_exists('TC_Bulk_Migration_Bundle_Service')) {
                wp_die(__('Bulk migration service is not loaded.', 'tc-data-tables'));
            }
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}gravity_tables WHERE status = 'active' ORDER BY id ASC",
                ARRAY_A
            );
            $tables = array();
            foreach ((array) $rows as $row) {
                if (isset($row['settings']) && is_string($row['settings'])) {
                    $decoded = json_decode($row['settings'], true);
                    $row['settings_decoded'] = is_array($decoded) ? $decoded : array();
                }
                // #1076 finding #4 — strip credentials from the bundle row
                // before it enters the export. Importing on a fresh site is
                // expected to re-enter credentials (the audit calls this out
                // explicitly; the helper adds a _stripped_secret_keys sentinel
                // so the importer can warn "re-enter credentials for: X, Y").
                if (function_exists('gt_bundle_strip_secrets')) {
                    $row = gt_bundle_strip_secrets($row);
                }
                $tables[] = $row;
            }
            $bundle = TC_Bulk_Migration_Bundle_Service::build($tables);
            $filename = 'gravity-tables-bundle-' . date('Y-m-d-His') . '.json';
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        });

        // Admin-ajax handler for dismissing the "What's new in v8.x" highlights
        // notice. Uses a v8-specific meta key (gt_v8_highlights_dismissed) so
        // users who dismissed the earlier v7.x notice see this refreshed one.
        add_action('wp_ajax_gt_dismiss_v8_highlights', function () {
            check_ajax_referer('gt_dismiss_v8_highlights', 'nonce');
            $user_id = get_current_user_id();
            if (!$user_id || !current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
            }
            update_user_meta($user_id, 'gt_v8_highlights_dismissed', 1);
            wp_send_json_success();
        });

        // #1621 — inline formula validation for the computed-columns
        // builder repeater. Thin wrapper over the unit-tested
        // TC_Formula_Service::validate_formula(); admin-only.
        add_action('wp_ajax_gt_cc_validate_formula', function () {
            check_ajax_referer('gt_cc_validate_formula');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
            }
            $formula = isset($_POST['formula']) ? wp_unslash((string) $_POST['formula']) : '';
            if (!class_exists('TC_Formula_Service')) {
                wp_send_json_error(array('message' => 'Formula service unavailable'), 503);
            }
            wp_send_json_success(TC_Formula_Service::validate_formula($formula));
        });

        // #1615 — list a table's revision snapshots for the History
        // modal. Thin wrapper over the unit-tested
        // TC_Revision_Snapshot_Service::summaries_for_admin().
        add_action('wp_ajax_gt_list_revisions', function () {
            check_ajax_referer('gt_list_revisions', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
            }
            $table_id = isset($_POST['table_id']) ? absint($_POST['table_id']) : 0;
            if ($table_id <= 0 || !class_exists('TC_Revision_Snapshot_Service')) {
                wp_send_json_error(array('message' => 'Invalid request'), 400);
            }
            $revisions = TC_Revision_Snapshot_Service::load($table_id, 'get_option');
            wp_send_json_success(array(
                'revisions' => TC_Revision_Snapshot_Service::summaries_for_admin(is_array($revisions) ? $revisions : array()),
            ));
        });

        // Update existing shortcodes to new format (one-time migration)
        $this->maybe_update_shortcodes();
    }

    /**
     * #2007 — Boot Gravity-Forms-coupled components. Called only when the
     * GFForms class is loaded. Constructors are GF-safe; this method exists
     * to keep GF-only wiring (entry-event cache invalidation, GF imports,
     * WooCommerce-from-entries) out of the GF-free boot path.
     */
    private function init_gravity_forms()
    {
        TC_Import::get_instance();
        TC_WooCommerce::get_instance();
        TC_Auto_Import::get_instance();

        // Entry-level events fire with form_id but not table_id — we resolve
        // every table whose form_id matches and invalidate each in turn.
        // Only Gravity Forms fires these actions.
        $entry_event_listener = function ($entry_id, $form_id) {
            global $wpdb;
            if (!$wpdb || (int) $form_id <= 0) return;
            $table_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}gravity_tables WHERE form_id = %d AND status = 'active'",
                (int) $form_id
            ));
            foreach ((array) $table_ids as $tid) {
                TC_Cache_Invalidator::invalidate_for_table((int) $tid);
            }
        };
        add_action('gravity_tables_entry_updated', $entry_event_listener, 10, 2);
        add_action('gravity_tables_entry_created', $entry_event_listener, 10, 2);
        add_action('gravity_tables_entry_deleted', $entry_event_listener, 10, 2);
    }

    public function activate()
    {
        // Create database tables if needed
        $this->create_tables();

        // #2116 — first funnel milestone: the plugin is active.
        if (class_exists('TC_Activation_Funnel')) {
            TC_Activation_Funnel::record('plugin_activated');
        }

        // Register custom capabilities on activation
        TC_Capabilities_Service::register_capabilities();

        // Clean up any legacy analytics data
        delete_option('gt_analytics_events');
        delete_option('gt_analytics_enabled');

        // Set default options
        if (!get_option('gt_version')) {
            add_option('gt_version', TC_VERSION);
            add_option('gt_settings', array(
                'default_per_page' => 25,
                'enable_frontend_editing' => true,
                'enable_bulk_actions' => true,
                'enable_advanced_filters' => true,
                'date_format' => 'm/d/Y',
                'time_format' => 'g:i A',
                'user_roles_can_edit' => array('administrator', 'editor'),
                'css_framework' => 'default'
            ));
        }

        // #978 v4.164.0 — Schedule the daily trash auto-purge cron (phase 1d of #593).
        // Idempotent: wp_next_scheduled gate avoids double-scheduling on re-activation.
        if (!wp_next_scheduled('gravity_tables_purge_expired_trash')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'gravity_tables_purge_expired_trash');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function deactivate()
    {
        // Clear every cron schedule the plugin owns so a deactivation /
        // license-uninstall leaves no orphaned WP-cron entries (#481 AC #3).
        if (class_exists('TC_License_Cleanup')) {
            TC_License_Cleanup::on_deactivate();
        }
        // #978 v4.164.0 — Clear the trash auto-purge cron on deactivation.
        wp_clear_scheduled_hook('gravity_tables_purge_expired_trash');
        flush_rewrite_rules();
    }

    private function maybe_update_shortcodes()
    {
        // Only run this migration once
        $migration_done = get_option('gt_shortcode_migration_done', false);

        if (!$migration_done) {
            $admin = TC_Admin::get_instance();
            $updated_count = $admin->update_shortcodes_to_new_format();

            // Mark migration as complete
            update_option('gt_shortcode_migration_done', true);

            // Log the migration if any tables were updated
            if ($updated_count > 0) {
                // error_log("Gravity Tables: Updated {$updated_count} table shortcodes to new format");
            }
        }
    }

    /**
     * #1728 — set show_advanced_filters back to true for every table where
     * it was written as false by the broken May-9 v4.60.0 save path.
     */
    private function migrate_restore_show_advanced_filters()
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, settings FROM {$wpdb->prefix}gravity_tables WHERE status != 'deleted' OR status IS NULL"
        );
        foreach ($rows as $row) {
            $s = json_decode($row->settings, true);
            if (!is_array($s)) {
                continue;
            }
            $changed = false;
            // Flat structure (most tables)
            if (array_key_exists('show_advanced_filters', $s) && $s['show_advanced_filters'] === false) {
                $s['show_advanced_filters'] = true;
                $changed = true;
            }
            // Nested structure (tables with a settings.settings envelope)
            if (isset($s['settings']) && is_array($s['settings'])
                && array_key_exists('show_advanced_filters', $s['settings'])
                && $s['settings']['show_advanced_filters'] === false) {
                $s['settings']['show_advanced_filters'] = true;
                $changed = true;
            }
            if ($changed) {
                $wpdb->update(
                    $wpdb->prefix . 'gravity_tables',
                    array('settings' => wp_json_encode($s)),
                    array('id' => (int) $row->id),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    private function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Table for storing custom table configurations
        $table_name = $wpdb->prefix . 'gravity_tables';

        // #968 v4.159.0 — `deleted_at` for soft-delete trash bin (phase 1a of #593).
        // NULL = live record. NON-NULL = soft-deleted; row is filtered out of
        // listing queries and is visible only in the Trash admin tab (phase 1c,
        // not yet shipped).
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            form_id mediumint(9) NOT NULL,
            settings longtext NOT NULL,
            shortcode varchar(500) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'active',
            deleted_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY deleted_at (deleted_at)
        ) $charset_collate;";

        $audit_table = $wpdb->prefix . 'gravity_tables_audit_log';
        $audit_sql = "CREATE TABLE $audit_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id bigint(20) UNSIGNED NOT NULL,
            form_id mediumint(9) NOT NULL,
            field_id varchar(64) NOT NULL,
            old_value longtext NULL,
            new_value longtext NULL,
            user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entry_id (entry_id),
            KEY form_id (form_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($audit_sql);
    }

    public function gravity_forms_notice()
    {
        echo '<div class="notice notice-warning"><p>';
        echo __('Gravity Forms is not active. TableCrafter\'s external data sources (JSON, CSV, Google Sheets, and Excel) work normally. Gravity Forms entry tables are a Pro feature and also need Gravity Forms active.', 'tc-data-tables');
        echo '</p></div>';
    }
}

/**
 * Load the plugin text domain on the 'init' hook to avoid the
 * _load_textdomain_just_in_time notice introduced in WordPress 6.7.
 */
function gt_load_textdomain()
{
    load_plugin_textdomain('tc-data-tables', false, dirname(TC_PLUGIN_BASENAME) . '/languages');
}
add_action('init', 'gt_load_textdomain');

// Initialize the plugin
if (!defined('WP_INT_TEST')) {
    Gravity_Tables_Plugin::get_instance();
}

/**
 * #618 slice 3 — Built-in send_email per-row action.
 *
 * Opt-in via the gt_send_email_enabled filter (default off). When
 * opted-in, TC_Per_Row_Action_Service::register_builtin_send_email
 * is auto-registered alongside any other developer-registered
 * actions, and clicking the resulting button on a row dispatches to
 * gt_handle_send_email_action via admin-post.php.
 *
 * Slice 4 (future) ships a per-table admin UI to configure the
 * email field / subject / body templates so customers don't need
 * filter callbacks.
 */
add_filter('gt_per_row_actions', function ($actions) {
    if (!is_array($actions)) $actions = [];
    if (apply_filters('gt_send_email_enabled', false) && class_exists('TC_Per_Row_Action_Service')) {
        $actions[] = TC_Per_Row_Action_Service::register_builtin_send_email();
    }
    if (apply_filters('gt_post_webhook_enabled', false) && class_exists('TC_Per_Row_Action_Service')) {
        $actions[] = TC_Per_Row_Action_Service::register_builtin_post_webhook();
    }
    return $actions;
});

/**
 * #618 slice 5 — bridge per-table admin settings to the slice-3 / slice-4
 * filter callbacks. When a customer configures send_email_recipient_field
 * or per_row_webhook_url in the table builder, the relevant filters
 * default to those values so the customer doesn't need to write filter
 * callbacks of their own. Filters with priority > 10 still take precedence.
 */
add_filter('gt_send_email_recipient_field', function ($current, $form_id, $entry, $table_id = 0) {
    if ($current !== '' || !class_exists('TC_Admin')) return $current;
    $admin = TC_Admin::get_instance();
    $table = ($table_id > 0 && method_exists($admin, 'get_table')) ? $admin->get_table((int) $table_id) : null;
    if (!$table || empty($table->settings)) return $current;
    $settings = json_decode($table->settings, true);
    if (!is_array($settings) || empty($settings['send_email_recipient_field'])) return $current;
    return (string) $settings['send_email_recipient_field'];
}, 10, 4);

add_filter('gt_post_webhook_url', function ($current, $table_id, $entry) {
    if ($current !== '' || !class_exists('TC_Admin')) return $current;
    $admin = TC_Admin::get_instance();
    $table = method_exists($admin, 'get_table') ? $admin->get_table((int) $table_id) : null;
    if (!$table || empty($table->settings)) return $current;
    $settings = json_decode($table->settings, true);
    if (!is_array($settings) || empty($settings['per_row_webhook_url'])) return $current;
    return (string) $settings['per_row_webhook_url'];
}, 10, 3);

add_action('admin_post_gt_action_send_email', 'gt_handle_send_email_action');

function gt_handle_send_email_action()
{
    // Auth gate: nonce + capability.
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gt_action_send_email')) {
        wp_die(__('Security check failed. Please try again.', 'tc-data-tables'), '', ['response' => 403]);
    }
    if (!current_user_can('edit_posts')) {
        wp_die(__('You do not have permission to send emails from this row.', 'tc-data-tables'), '', ['response' => 403]);
    }

    $table_id = isset($_GET['table']) ? (int) $_GET['table'] : 0;
    $row_id   = isset($_GET['row']) ? (int) $_GET['row'] : 0;
    if ($table_id <= 0 || $row_id <= 0) {
        wp_die(__('Invalid request: missing table or row.', 'tc-data-tables'), '', ['response' => 400]);
    }

    if (!class_exists('TC_Admin') || !class_exists('GFAPI')) {
        wp_die(__('Required dependency unavailable.', 'tc-data-tables'), '', ['response' => 500]);
    }

    $admin = TC_Admin::get_instance();
    $table = method_exists($admin, 'get_table') ? $admin->get_table($table_id) : null;
    if (!$table || empty($table->form_id)) {
        wp_die(__('Table not found.', 'tc-data-tables'), '', ['response' => 404]);
    }

    $entry = GFAPI::get_entry($row_id);
    if (is_wp_error($entry) || !is_array($entry)) {
        wp_die(__('Row not found.', 'tc-data-tables'), '', ['response' => 404]);
    }

    // Pick the recipient field via filter; default to the first field
    // that looks like an email address in the entry.
    $recipient_field = apply_filters('gt_send_email_recipient_field', '', (int) $table->form_id, $entry, $table_id);
    $recipient = '';
    if ($recipient_field !== '' && isset($entry[$recipient_field])) {
        $recipient = (string) $entry[$recipient_field];
    } else {
        foreach ($entry as $val) {
            if (is_string($val) && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $recipient = $val;
                break;
            }
        }
    }
    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        wp_die(__('No recipient email address found in this row.', 'tc-data-tables'), '', ['response' => 400]);
    }

    // Subject + body via filters so customers can customize.
    $default_subject = sprintf(
        /* translators: %d = entry id */
        __('Notification regarding entry #%d', 'tc-data-tables'),
        $row_id
    );
    $subject = (string) apply_filters('gt_send_email_subject', $default_subject, $entry, $table_id);
    $default_body = sprintf(
        /* translators: %d = entry id */
        __("Hello,\n\nThis is a notification regarding entry #%d.\n\nThank you.", 'tc-data-tables'),
        $row_id
    );
    $body = (string) apply_filters('gt_send_email_body', $default_body, $entry, $table_id);

    $sent = wp_mail($recipient, $subject, $body);

    // Redirect back to the referrer with a status flag.
    $back = wp_get_referer() ?: home_url('/');
    $back = add_query_arg('gt_email_sent', $sent ? '1' : '0', $back);
    wp_safe_redirect($back);
    exit;
}

/**
 * #618 slice 4 — admin-post handler for the built-in post_webhook
 * action. Verifies nonce + capability, loads the entry, POSTs it as
 * JSON to the URL configured by the gt_post_webhook_url filter.
 *
 * Slice 5 (future) ships a per-table admin field so the URL can be
 * configured per-table without filter callbacks.
 */
add_action('admin_post_gt_action_post_webhook', 'gt_handle_post_webhook_action');

function gt_handle_post_webhook_action()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gt_action_post_webhook')) {
        wp_die(__('Security check failed. Please try again.', 'tc-data-tables'), '', ['response' => 403]);
    }
    if (!current_user_can('edit_posts')) {
        wp_die(__('You do not have permission to dispatch webhooks from this row.', 'tc-data-tables'), '', ['response' => 403]);
    }

    $table_id = isset($_GET['table']) ? (int) $_GET['table'] : 0;
    $row_id   = isset($_GET['row']) ? (int) $_GET['row'] : 0;
    if ($table_id <= 0 || $row_id <= 0) {
        wp_die(__('Invalid request: missing table or row.', 'tc-data-tables'), '', ['response' => 400]);
    }

    if (!class_exists('TC_Admin') || !class_exists('GFAPI')) {
        wp_die(__('Required dependency unavailable.', 'tc-data-tables'), '', ['response' => 500]);
    }

    $admin = TC_Admin::get_instance();
    $table = method_exists($admin, 'get_table') ? $admin->get_table($table_id) : null;
    if (!$table || empty($table->form_id)) {
        wp_die(__('Table not found.', 'tc-data-tables'), '', ['response' => 404]);
    }

    $entry = GFAPI::get_entry($row_id);
    if (is_wp_error($entry) || !is_array($entry)) {
        wp_die(__('Row not found.', 'tc-data-tables'), '', ['response' => 404]);
    }

    // Webhook URL: filter-driven (slice 4). Slice 5 will read from a
    // per-table admin field stored in $table->settings.
    $webhook_url = (string) apply_filters('gt_post_webhook_url', '', $table_id, $entry);
    if ($webhook_url === '' || !filter_var($webhook_url, FILTER_VALIDATE_URL)) {
        wp_die(__('Webhook URL is not configured. Hook gt_post_webhook_url to provide one.', 'tc-data-tables'), '', ['response' => 500]);
    }

    $payload = apply_filters('gt_post_webhook_payload', [
        'event'    => 'gt_per_row_action',
        'action'   => 'post_webhook',
        'table_id' => $table_id,
        'row_id'   => $row_id,
        'entry'    => $entry,
    ], $entry, $table_id);

    $response = wp_remote_post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload),
        'timeout' => 15,
    ]);

    $sent = !is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 400;

    $back = wp_get_referer() ?: home_url('/');
    $back = add_query_arg('gt_webhook_sent', $sent ? '1' : '0', $back);
    wp_safe_redirect($back);
    exit;
}

/**
 * #536 slice 2a — Capture a revision snapshot on every save_table.
 *
 * Hooks `gravity_tables_after_save_table` and persists the post-save
 * table state into the per-table option `gt_revisions_table_<id>` via
 * TC_Revision_Snapshot_Service. Retention: last 5 revisions by
 * created_at desc.
 *
 * Slice 2b (future) ships the admin UI: per-table "Revisions" link
 * lists snapshots, Restore button writes a snapshot back as the
 * current state.
 */
add_action('gravity_tables_after_save_table', 'gt_capture_revision_snapshot', 10, 1);

function gt_capture_revision_snapshot($table_id)
{
    $tid = (int) $table_id;
    if ($tid <= 0 || !class_exists('TC_Revision_Snapshot_Service') || !class_exists('TC_Admin')) {
        return;
    }
    $admin = TC_Admin::get_instance();
    $table = method_exists($admin, 'get_table') ? $admin->get_table($tid) : null;
    if (!$table) {
        return;
    }
    // Convert the table object to an array (the service signature).
    $table_array = (array) $table;
    $table_array['id'] = $tid;
    $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

    $snapshot = TC_Revision_Snapshot_Service::make_snapshot($table_array, $user_id);

    TC_Revision_Snapshot_Service::persist(
        $tid,
        $snapshot,
        function ($key) {
            return function_exists('get_option') ? get_option($key, []) : [];
        },
        function ($key, $value) {
            return function_exists('update_option') ? update_option($key, $value, false) : false;
        },
        5 // keep last 5 revisions
    );
}

/**
 * #536 slice 2b — Admin-post handler that restores a chosen
 * revision snapshot back as the current table state.
 *
 * Verifies nonce + manage_options. Loads the per-table revisions
 * via TC_Revision_Snapshot_Service::load. Decodes the chosen
 * snapshot's payload (full table row) and writes title + settings
 * back via $wpdb->update.
 *
 * Closes #536 end-to-end.
 */
add_action('admin_post_gt_action_restore_revision', 'gt_handle_restore_revision_action');

function gt_handle_restore_revision_action()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gt_action_restore_revision')) {
        wp_die(__('Security check failed.', 'tc-data-tables'), '', ['response' => 403]);
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to restore revisions.', 'tc-data-tables'), '', ['response' => 403]);
    }

    $table_id = isset($_GET['table']) ? (int) $_GET['table'] : 0;
    $index    = isset($_GET['index']) ? (int) $_GET['index'] : -1;
    if ($table_id <= 0 || $index < 0) {
        wp_die(__('Invalid request: missing table or index.', 'tc-data-tables'), '', ['response' => 400]);
    }

    if (!class_exists('TC_Revision_Snapshot_Service')) {
        wp_die(__('Revision service unavailable.', 'tc-data-tables'), '', ['response' => 500]);
    }

    $revisions = TC_Revision_Snapshot_Service::load($table_id, function ($key) {
        return function_exists('get_option') ? get_option($key, []) : [];
    });
    if (!isset($revisions[$index])) {
        wp_die(__('Revision not found.', 'tc-data-tables'), '', ['response' => 404]);
    }

    $payload_json = (string) ($revisions[$index]['payload'] ?? '');
    $payload = json_decode($payload_json, true);
    if (!is_array($payload)) {
        wp_die(__('Revision payload is malformed.', 'tc-data-tables'), '', ['response' => 500]);
    }

    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) {
        wp_die(__('Database unavailable.', 'tc-data-tables'), '', ['response' => 500]);
    }

    $table_name = $wpdb->prefix . 'gravity_tables';
    $update = [];
    if (isset($payload['title'])) {
        $update['title'] = (string) $payload['title'];
    }
    // Settings can be either an array or a JSON string in the snapshot.
    $settings_value = $payload['settings'] ?? null;
    if (is_array($settings_value)) {
        $update['settings'] = wp_json_encode($settings_value);
    } elseif (is_string($settings_value)) {
        $update['settings'] = $settings_value;
    }

    $restored = !empty($update)
        ? (bool) $wpdb->update($table_name, $update, ['id' => $table_id])
        : false;

    $back = wp_get_referer() ?: home_url('/');
    $back = add_query_arg('gt_restored', $restored ? '1' : '0', $back);
    wp_safe_redirect($back);
    exit;
}

/**
 * #517 slice 3b — Airtable settings page (admin UI for credential storage).
 *
 * Adds a "Airtable" submenu under the GT plugin menu. Three admin-post
 * handlers wire it to the slice-3a credential service and the slice-2
 * sync engine:
 *   - save:  encrypts + persists base / table / token via credential service.
 *   - test:  cheap probe (page_size=1) via sync engine to verify auth.
 *   - clear: wipes the credential option.
 *
 * This slice retires the orphan-with-test-contract invariants from
 * slices 1, 2, and 3a — admin now references all three Airtable
 * services. Slice 4+ tackles two-way sync (#613).
 */

// Priority 20 so the parent 'tc-data-tables' menu (registered by
// TC_Admin::add_admin_menu at default priority 10) exists before the
// submenu attaches. Without this, WP treats the orphan submenu slug
// as a relative wp-admin/ path and the link renders as
// /wp-admin/gravity-tables-airtable — a 404.
// tag: 4.59.0
add_action('admin_menu', 'gt_register_airtable_settings_page', 20);

function gt_register_airtable_settings_page()
{
    add_submenu_page(
        'gravity-tables',
        __('Airtable', 'tc-data-tables'),
        __('Airtable', 'tc-data-tables'),
        'manage_options',
        'gravity-tables-airtable',
        'gt_render_airtable_settings_page'
    );
}

function gt_render_airtable_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'tc-data-tables'), '', ['response' => 403]);
    }
    include TC_PLUGIN_PATH . 'admin/views/airtable-settings.php';
}

add_action('admin_post_gt_airtable_save_credentials', 'gt_handle_airtable_save_credentials');

function gt_handle_airtable_save_credentials()
{
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gt_airtable_save_credentials')) {
        wp_die(__('Security check failed.', 'tc-data-tables'), '', ['response' => 403]);
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to manage Airtable credentials.', 'tc-data-tables'), '', ['response' => 403]);
    }

    $base_id  = isset($_POST['base_id']) ? sanitize_text_field(wp_unslash($_POST['base_id'])) : '';
    $table_id = isset($_POST['table_id']) ? sanitize_text_field(wp_unslash($_POST['table_id'])) : '';
    $token    = isset($_POST['token']) ? trim((string) wp_unslash($_POST['token'])) : '';

    $stored = TC_Airtable_Credential_Service::store($base_id, $table_id, $token);

    $back = admin_url('admin.php?page=gravity-tables-airtable');
    $back = add_query_arg('gt_airtable_saved', $stored ? '1' : '0', $back);
    wp_safe_redirect($back);
    exit;
}

add_action('admin_post_gt_airtable_test_connection', 'gt_handle_airtable_test_connection');

function gt_handle_airtable_test_connection()
{
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gt_airtable_test_connection')) {
        wp_die(__('Security check failed.', 'tc-data-tables'), '', ['response' => 403]);
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to test Airtable credentials.', 'tc-data-tables'), '', ['response' => 403]);
    }

    $creds = TC_Airtable_Credential_Service::load();
    if (!is_array($creds) || $creds['base_id'] === '' || $creds['table_id'] === '' || $creds['token'] === '') {
        $back = admin_url('admin.php?page=gravity-tables-airtable');
        $back = add_query_arg(['gt_airtable_test' => 'unconfigured'], $back);
        wp_safe_redirect($back);
        exit;
    }

    // Cheap probe: page_size=1 — minimum traffic to confirm auth + base/table existence.
    $result = TC_Airtable_Sync_Engine::fetch_records(
        $creds['base_id'],
        $creds['table_id'],
        $creds['token'],
        ['page_size' => 1]
    );

    $back = admin_url('admin.php?page=gravity-tables-airtable');
    if (!empty($result['ok'])) {
        $back = add_query_arg(['gt_airtable_test' => 'ok'], $back);
    } else {
        $back = add_query_arg([
            'gt_airtable_test'  => 'fail',
            'gt_airtable_error' => rawurlencode((string) ($result['error'] ?? 'unknown')),
        ], $back);
    }
    wp_safe_redirect($back);
    exit;
}

add_action('admin_post_gt_airtable_clear_credentials', 'gt_handle_airtable_clear_credentials');

function gt_handle_airtable_clear_credentials()
{
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gt_airtable_clear_credentials')) {
        wp_die(__('Security check failed.', 'tc-data-tables'), '', ['response' => 403]);
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to clear Airtable credentials.', 'tc-data-tables'), '', ['response' => 403]);
    }

    TC_Airtable_Credential_Service::clear();

    $back = admin_url('admin.php?page=gravity-tables-airtable');
    $back = add_query_arg('gt_airtable_cleared', '1', $back);
    wp_safe_redirect($back);
    exit;
}
