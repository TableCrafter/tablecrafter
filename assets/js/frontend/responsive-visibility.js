/**
 * TableCrafter - frontend/responsive-visibility.js
 *
 * Pure visibility helpers for responsive card-view rendering.
 * #832 slice 12 of N.
 *
 * Five small helpers attached to GravityTable.prototype via Object.assign.
 * All read `this.config.responsive_settings` keyed by fieldId - when the
 * key is absent or the entire map is missing, default to visible.
 *
 *   - isFieldVisibleInCards(fieldId, isTabletView)
 *       Returns whether a field should render in the card view at the
 *       current breakpoint. Reads `settings.tablet_visible` when
 *       isTabletView is true, otherwise `settings.mobile_visible`.
 *       Both fall through to true unless explicitly === false.
 *
 *   - isFieldVisibleOnMobile(fieldId)
 *       Convenience: settings.mobile_visible !== false.
 *
 *   - isFieldVisibleOnTablet(fieldId)
 *       Convenience: settings.tablet_visible !== false.
 *
 *   - getMobileLabel(fieldId, defaultLabel)
 *       Returns the trimmed mobile label override when set + non-empty,
 *       otherwise falls back to defaultLabel.
 *
 *   - isFieldVisibleOnCurrentDevice(fieldId, isTabletView)
 *       Alias of isFieldVisibleInCards - same branch table, used at
 *       different call sites in card render. Pre-existing duplication.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        isFieldVisibleInCards: function (fieldId, isTabletView) {
            if (!this.config.responsive_settings || !this.config.responsive_settings[fieldId]) {
                return true;
            }
            var settings = this.config.responsive_settings[fieldId];
            if (isTabletView) {
                return settings.tablet_visible !== false;
            }
            return settings.mobile_visible !== false;
        },

        isFieldVisibleOnMobile: function (fieldId) {
            if (!this.config.responsive_settings || !this.config.responsive_settings[fieldId]) {
                return true;
            }
            return this.config.responsive_settings[fieldId].mobile_visible !== false;
        },

        isFieldVisibleOnTablet: function (fieldId) {
            if (!this.config.responsive_settings || !this.config.responsive_settings[fieldId]) {
                return true;
            }
            return this.config.responsive_settings[fieldId].tablet_visible !== false;
        },

        getMobileLabel: function (fieldId, defaultLabel) {
            if (this.config.responsive_settings && this.config.responsive_settings[fieldId]) {
                var mobileLabel = this.config.responsive_settings[fieldId].mobile_label;
                if (mobileLabel && mobileLabel.trim()) {
                    return mobileLabel.trim();
                }
            }
            return defaultLabel;
        },

        isFieldVisibleOnCurrentDevice: function (fieldId, isTabletView) {
            if (!this.config.responsive_settings || !this.config.responsive_settings[fieldId]) {
                return true;
            }
            var settings = this.config.responsive_settings[fieldId];
            if (isTabletView) {
                return settings.tablet_visible !== false;
            }
            return settings.mobile_visible !== false;
        }

    });

})(window);
