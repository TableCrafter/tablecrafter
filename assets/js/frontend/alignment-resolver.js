/**
 * TableCrafter — frontend/alignment-resolver.js
 *
 * Cell alignment style + class builder (#549). #832 slice 4 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - resolveCellAlignment(config, fieldId, entryId)
 *       Pure helper. Returns { style, valignClass } given the table
 *       config + a (fieldId, entryId) lookup pair. Three layers
 *       stack (later wins) per #549 slice 3:
 *         1. column_alignments[fieldId] — horizontal text-align.
 *         2. column_vertical_alignments[fieldId] — vertical-align base.
 *         3. cell_vertical_alignments[entryId][fieldId] — per-cell
 *            v-align override.
 *
 *       Output shape:
 *         { style: ' style="text-align:X;vertical-align:Y"' | '',
 *           valignClass: ' gt-valign-X' | '' }
 *
 *       Empty `colVAlign` means: emit no vertical-align so the
 *       browser default of `middle` applies — preserves prior
 *       behavior. `middle` explicitly does NOT emit a class (also
 *       browser default).
 *
 *       Pure: no DOM, no jQuery, no `this` reads. Fully testable.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    Object.assign(window.GravityTable.prototype, {

        resolveCellAlignment: function (config, fieldId, entryId) {
            var colAlign = (config && config.column_alignments && config.column_alignments[fieldId])
                ? config.column_alignments[fieldId]
                : '';
            var colVAlign = (config && config.column_vertical_alignments && config.column_vertical_alignments[fieldId])
                ? config.column_vertical_alignments[fieldId]
                : '';
            var cellOverride = (config && config.cell_vertical_alignments && config.cell_vertical_alignments[entryId] && config.cell_vertical_alignments[entryId][fieldId])
                ? config.cell_vertical_alignments[entryId][fieldId]
                : null;
            var finalVAlign = cellOverride || colVAlign || '';

            var styleParts = [];
            if (colAlign) { styleParts.push('text-align:' + colAlign); }
            if (finalVAlign) { styleParts.push('vertical-align:' + finalVAlign); }
            var style = styleParts.length ? ' style="' + styleParts.join(';') + '"' : '';

            var valignClass = (finalVAlign && finalVAlign !== 'middle') ? ' gt-valign-' + finalVAlign : '';

            return { style: style, valignClass: valignClass };
        }

    });

})(window);
