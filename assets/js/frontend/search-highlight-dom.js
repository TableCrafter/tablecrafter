/**
 * TableCrafter — frontend/search-highlight-dom.js
 *
 * #1612 — search-highlight v2: DOM text-node walker.
 *
 * v6.1.4 (#1606) highlighted plain-text cells at the string level and
 * deliberately skipped cells with server-rendered markup so the
 * wrapper could never land inside a tag or attribute. This module
 * closes the remaining surfaces with a structurally-safe DOM walk:
 * only TEXT NODES are split and wrapped, so tags and attributes are
 * untouchable by construction. Covers HTML cells, the responsive
 * card view (.gt-cards-container), and per-column text-filter terms
 * (scoped to their own column's cells).
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *   - highlightTextNodes(rootEl, term)   — returns wrap count
 *   - applyDomSearchHighlight($wrapper)  — post-render pass
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, MARK: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1 };

    function collectTextNodes(root) {
        var out = [];
        (function walk(node) {
            for (var child = node.firstChild; child; child = child.nextSibling) {
                if (child.nodeType === 3) {
                    out.push(child);
                } else if (child.nodeType === 1 && !SKIP_TAGS[child.tagName]
                    && !(child.tagName === 'MARK' || (child.classList && child.classList.contains('gt-search-hit')))) {
                    walk(child);
                }
            }
        }(root));
        return out;
    }

    Object.assign(window.GravityTable.prototype, {

        highlightTextNodes: function (rootEl, term) {
            if (!rootEl || typeof term !== 'string' || term.trim() === '') {
                return 0;
            }
            var needle = term.trim().toLowerCase();
            var doc = rootEl.ownerDocument || window.document;
            var count = 0;
            var nodes = collectTextNodes(rootEl);
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                // A node may be split as we wrap; loop within it until
                // no further match remains in the trailing remainder.
                while (node) {
                    var idx = node.nodeValue.toLowerCase().indexOf(needle);
                    if (idx === -1) { break; }
                    var matchNode = node.splitText(idx);
                    var rest = matchNode.splitText(term.trim().length);
                    var mark = doc.createElement('mark');
                    mark.className = 'gt-search-hit';
                    matchNode.parentNode.insertBefore(mark, matchNode);
                    mark.appendChild(matchNode);
                    count++;
                    node = rest;
                }
            }
            return count;
        },

        /**
         * Post-render highlight pass: the global search term over the
         * table body AND the responsive card view, then each active
         * per-column TEXT filter scoped to its own column's cells.
         */
        applyDomSearchHighlight: function ($wrapper) {
            var self = this;
            var globalTerm = typeof this.searchTerm === 'string' ? this.searchTerm.trim() : '';
            if (globalTerm) {
                $wrapper.find('tbody').each(function () {
                    self.highlightTextNodes(this, globalTerm);
                });
                $wrapper.find('.gt-cards-container').each(function () {
                    self.highlightTextNodes(this, globalTerm);
                });
            }
            var filters = this.filters && typeof this.filters === 'object' ? this.filters : {};
            for (var key in filters) {
                if (!Object.prototype.hasOwnProperty.call(filters, key)) { continue; }
                var m = /^filter_(.+)$/.exec(key);
                var f = filters[key];
                if (!m || !f || f.type !== 'text' || typeof f.value !== 'string' || f.value.trim() === '') {
                    continue;
                }
                var fieldId = m[1];
                var term = f.value.trim();
                $wrapper.find('td[data-field-id="' + fieldId + '"]').each(function () {
                    self.highlightTextNodes(this, term);
                });
            }
        }

    });

})(window);
