/**
 * TableCrafter — frontend/column-role-visibility.js
 *
 * Pro-gated per-column role visibility. Hides th + td elements for
 * columns whose allowed-roles list does not include any role the current
 * user holds. Called once from init() after the DOM is rendered.
 *
 * Closes #1746.
 *
 * Surface (GravityTable.prototype):
 *   - applyColumnRoleVisibility() — reads config.column_role_visibility
 *     (field_id => roles[]) and config.user_roles, hides/shows columns.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    GravityTable.prototype.applyColumnRoleVisibility = function () {
        var config = this.config || {};
        if (!config.is_pro) { return; }

        var visMap   = config.column_role_visibility || {};
        var userRoles = config.user_roles || [];
        var wrapper  = document.getElementById(this.wrapperId);
        if (!wrapper) { return; }

        Object.keys(visMap).forEach(function (fieldId) {
            var allowedRoles = visMap[fieldId];
            if (!allowedRoles || !allowedRoles.length) { return; }

            var hasRole = allowedRoles.some(function (r) {
                return userRoles.indexOf(r) !== -1;
            });
            var display = hasRole ? '' : 'none';

            var cells = wrapper.querySelectorAll('[data-field-id="' + fieldId + '"]');
            for (var i = 0; i < cells.length; i++) {
                cells[i].style.display = display;
            }
        });
    };

}(window));
