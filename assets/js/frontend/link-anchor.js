/**
 * TableCrafter — frontend/link-anchor.js
 *
 * Column link-settings anchor builder (#109). #832 slice 5 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - buildLinkAnchorHtml(linkSettings, displayValue)
 *       Pure helper. Given `column_link_settings` config (or null)
 *       and a `displayValue`, returns the `<a>` HTML or null when
 *       no link should be emitted. Output:
 *         <a href="<escaped>"[ target="X"[ rel="..."]][ style="..."]>
 *           <escaped displayValue>
 *         </a>
 *
 *       Mapping:
 *         link_target: '_self' (default) → no target attr.
 *                      'new_tab' → target="_blank" rel="noopener noreferrer".
 *                      Any other → target="<value>".
 *         link_color: '<css color>' → style="color:..."
 *         link_underline: false → style adds "text-decoration:none"
 *                          (default/undefined/true keeps browser underline).
 *
 *       Pre-requisite: util.js must load first (escapeHtml).
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        buildLinkAnchorHtml: function (linkSettings, displayValue) {
            var self = this;
            if (!linkSettings || !displayValue) return null;

            var linkTarget = linkSettings.link_target || '_self';
            var linkColor = linkSettings.link_color || '';
            var linkUnderline = (linkSettings.link_underline !== false);

            var styleParts = [];
            if (linkColor) { styleParts.push('color:' + linkColor); }
            if (!linkUnderline) { styleParts.push('text-decoration:none'); }
            var styleAttr = styleParts.length ? ' style="' + styleParts.join(';') + '"' : '';

            var targetAttr = linkTarget && linkTarget !== '_self' ? ' target="' + linkTarget + '"' : '';
            if (linkTarget === 'new_tab') {
                targetAttr = ' target="_blank" rel="noopener noreferrer"';
            }

            return '<a href="' + self.escapeHtml(displayValue) + '"' + targetAttr + styleAttr + '>'
                + self.escapeHtml(displayValue) + '</a>';
        }

    });

})(window);
