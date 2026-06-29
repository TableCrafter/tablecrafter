/**
 * GT Media Folder — JS adapter for media-library folder plugins.
 *
 * Issue #526 slice 2. Wraps wp.media() so the rest of the admin
 * (image-type cells, anywhere an image picker is needed) can call
 * a single entrypoint without caring which folder plugin is active.
 *
 * Folder plugins (FileBird / FolderPress / WP Media Folder / Real
 * Media Library) hook wp.media via their own JS bundles when
 * loaded, so this adapter only has to open the frame — the folder
 * UI then appears automatically via those plugins' filters.
 *
 * Config is read from `window.gtMediaFolder` which the PHP-side
 * TC_Media_Folder_Adapter::boot() localizes during admin_enqueue.
 *
 * Public API:
 *   window.GTMediaFolder.openFrame(opts) -> wp.media.Frame
 *
 * @since 4.67.0
 */
(function (window) {
    'use strict';

    var GTMediaFolder = {
        /**
         * Open a wp.media frame configured for image selection.
         * Returns the frame for callers that want to bind their own
         * 'select' event listener.
         *
         * opts:
         *   onSelect(attachment) — called with the chosen attachment.
         *   title — frame title (default 'Select image').
         *   library — overrides for wp.media library spec.
         */
        openFrame: function (opts) {
            opts = opts || {};
            if (typeof window.wp === 'undefined' || !window.wp.media) {
                // wp.media isn't on the page — nothing to do.
                return null;
            }
            var cfg = window.gtMediaFolder || { supports_folder_ui: false, active_plugin: null };

            var frame = window.wp.media({
                title:    opts.title || 'Select image',
                button:   { text: opts.buttonText || 'Use image' },
                multiple: false,
                library:  Object.assign({ type: 'image' }, opts.library || {})
            });

            // When a supported folder plugin is detected, surface its
            // id on the frame instance so the plugin's own filters
            // (which look for gt_folder_plugin) can opt into our flow.
            // No-op when supports_folder_ui is false — the plain
            // wp.media frame still opens.
            if (cfg.supports_folder_ui && cfg.active_plugin) {
                frame.gt_folder_plugin = cfg.active_plugin;
            }

            if (typeof opts.onSelect === 'function') {
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first();
                    if (attachment) {
                        opts.onSelect(attachment.toJSON());
                    }
                });
            }

            frame.open();
            return frame;
        },

        /**
         * Expose the active plugin id for callers that want to
         * branch UX (e.g. show a different tooltip).
         */
        activePlugin: function () {
            var cfg = window.gtMediaFolder || {};
            return cfg.supports_folder_ui ? (cfg.active_plugin || null) : null;
        }
    };

    window.GTMediaFolder = GTMediaFolder;
}(typeof window !== 'undefined' ? window : this));
