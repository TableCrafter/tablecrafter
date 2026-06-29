/**
 * TableCrafter — frontend/search-highlight.js
 *
 * #1606 — search-term highlighting in matched cells. When a global
 * search term is active, matched substrings in cell text are wrapped
 * in <mark class="gt-search-hit"> so users can see WHERE the match
 * occurred instead of scanning every column.
 *
 * XSS discipline: escape-then-highlight. Both the cell text and the
 * term go through escapeHtml before matching, so the only raw HTML
 * this module ever introduces is the <mark> wrapper itself.
 *
 * Scope (v1, per the issue): literal case-insensitive matches from
 * the global search box, applied to plain-text cell content. Cells
 * whose server-rendered HTML contains markup (links, images) are left
 * untouched; fuzzy-only matches (no literal substring) simply don't
 * highlight. Card view + per-column filters are follow-ups.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - highlightInEscapedHtml(escapedText, term)
 *       Wrap case-insensitive literal occurrences of `term` (escaped
 *       through this.escapeHtml so entity forms match) in already-
 *       escaped text. Returns the input unchanged for empty terms,
 *       no matches, or non-string input ('' for null/undefined text).
 *
 *   - renderHighlightedText(rawText, term)
 *       this.escapeHtml(rawText) then highlightInEscapedHtml.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    function escapeRegExp(s) {
        return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    Object.assign(window.GravityTable.prototype, {

        highlightInEscapedHtml: function (escapedText, term) {
            if (escapedText === null || escapedText === undefined) {
                return '';
            }
            escapedText = String(escapedText);
            if (typeof term !== 'string' || term.trim() === '') {
                return escapedText;
            }
            var needle = this.escapeHtml(term.trim());
            if (needle === '') {
                return escapedText;
            }
            var re = new RegExp(escapeRegExp(needle), 'gi');
            return escapedText.replace(re, '<mark class="gt-search-hit">$&</mark>');
        },

        renderHighlightedText: function (rawText, term) {
            return this.highlightInEscapedHtml(this.escapeHtml(rawText), term);
        }

    });

})(window);
