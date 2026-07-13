<?php
/**
 * TC_Media_Folder_Adapter
 *
 * Issue #526 - slice 1 of 3. Pure feature-detection adapter for
 * WordPress media-library folder/tree plugins. Tells the JS layer
 * which folder plugin is active so the wp.media frame can be opened
 * with the right folder-UI extension.
 *
 * Supported folder plugins (canonical order - first match wins):
 *   filebird - FileBird (free + pro)
 *   folderpress - FolderPress
 *   wp_media_folder - WP Media Folder
 *   real_media_library - Real Media Library
 *
 * Slice 2 ships the JS adapter (`assets/js/gt-media-folder.js`) that
 * reads this config from a localized var and invokes the wp.media
 * frame with the folder UI hooked. Slice 3 adds the admin UI tooltip
 * + docs link.
 *
 * No-op default: when no folder plugin is detected, `config()`
 * returns `supports_folder_ui = false` so the JS layer falls
 * through to the existing flat-list behaviour.
 *
 * @since 4.7.41
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Media_Folder_Adapter {

    /**
     * Canonical map of plugin_id => { detect, label }.
     * `detect` is the function-or-class-name used as the
     * feature-detection key when no $context override is supplied
     * to `detect_active()`.
     */
    public static function supported_plugins(): array {
        return [
            'filebird' => [
                'detect' => 'FileBird\\Plugin',
                'label'  => 'FileBird',
            ],
            'folderpress' => [
                'detect' => 'FolderPress',
                'label'  => 'FolderPress',
            ],
            'wp_media_folder' => [
                'detect' => 'WpMediaFolder',
                'label'  => 'WP Media Folder',
            ],
            'real_media_library' => [
                'detect' => 'RML_Bootstrap',
                'label'  => 'Real Media Library',
            ],
        ];
    }

    /**
     * Returns the plugin_id of the first supported folder plugin
     * detected as active, or null when none are active.
     *
     * `$context` is an optional override for tests. When supplied,
     * its `active_plugins` key is used as the truth (an array of
     * plugin_ids) instead of probing the real WP environment via
     * `class_exists`/`function_exists`.
     */
    public static function detect_active(?array $context = null): ?string {
        foreach (self::supported_plugins() as $id => $meta) {
            if ($context !== null) {
                $active = $context['active_plugins'] ?? [];
                if (is_array($active) && in_array($id, $active, true)) {
                    return $id;
                }
                continue;
            }
            // Real environment probe: class_exists falls back to
            // function_exists for older plugins that ship as a global
            // bootstrap.
            $detect = (string) ($meta['detect'] ?? '');
            if ($detect !== '' && (class_exists($detect) || function_exists($detect))) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Slice 2 bootstrap. Hooks `admin_enqueue_scripts` to register
     * the JS adapter (`assets/js/gt-media-folder.js`) and localize
     * the runtime config under `window.gtMediaFolder`. Idempotent
     * - invoked from tablecrafter.php on init.
     *
     * @since 4.67.0
     */
    public static function boot(): void {
        if (function_exists('add_action')) {
            add_action('admin_enqueue_scripts', [self::class, 'enqueue_adapter']);
        }
    }

    /**
     * Admin-side enqueue. Registers + localizes the JS adapter so
     * it's available wherever the image-cell-type picker fires.
     */
    public static function enqueue_adapter(): void {
        if (!function_exists('wp_enqueue_script')) { return; }
        $handle = 'gt-media-folder';
        $url    = (defined('TC_PLUGIN_URL') ? TC_PLUGIN_URL : '') . 'assets/js/gt-media-folder.js';
        $ver    = defined('TC_VERSION') ? TC_VERSION : '1.0.0';
        wp_enqueue_script($handle, $url, [], $ver, true);
        wp_localize_script($handle, 'gtMediaFolder', self::config());
    }

    /**
     * JS-localizable config. Keys (always present):
     *   active_plugin       string|null
     *   plugin_label        string|null
     *   supports_folder_ui  bool
     */
    public static function config(?array $context = null): array {
        $id = self::detect_active($context);
        if ($id === null) {
            return [
                'active_plugin'      => null,
                'plugin_label'       => null,
                'supports_folder_ui' => false,
            ];
        }
        $plugins = self::supported_plugins();
        return [
            'active_plugin'      => $id,
            'plugin_label'       => $plugins[$id]['label'] ?? '',
            'supports_folder_ui' => true,
        ];
    }
}
