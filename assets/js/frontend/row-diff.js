/* #1749 — Inline cell diff badge + bulk fill diff preview. */

/**
 * Show a brief "was: {oldValue}" badge on a cell after an inline edit
 * changes its value. Badge auto-removes after 3 seconds.
 *
 * Free feature — no Pro gate needed (purely cosmetic feedback).
 *
 * @param {Element} cell       The td/th element that was just saved.
 * @param {string}  oldValue   The value before the edit.
 * @param {string}  newValue   The value after the edit.
 */
GravityTable.prototype.showDiffBadge = function (cell, oldValue, newValue) {
    if (!cell || oldValue === newValue) { return; }

    // Remove any existing badge on this cell.
    var existing = cell.querySelector('.gt-diff-badge');
    if (existing) { existing.remove(); }

    var badge = document.createElement('span');
    badge.className = 'gt-diff-badge';
    badge.textContent = '← was: ' + oldValue;
    badge.style.cssText = [
        'display:inline-block',
        'margin-left:6px',
        'font-size:11px',
        'color:#6b7280',
        'background:rgba(0,0,0,0.06)',
        'border-radius:4px',
        'padding:1px 6px',
        'transition:opacity 600ms ease',
        'opacity:1',
        'pointer-events:none',
        'vertical-align:middle',
    ].join(';');

    cell.appendChild(badge);

    // Fade out after 2.4s, remove after 3s.
    var timer = setTimeout(function () {
        badge.style.opacity = '0';
        setTimeout(function () { badge.remove(); }, 600);
    }, 2400);

    // Store timer so tests can clear if needed.
    badge._timer = timer;
};

/**
 * Update the bulk fill modal's Apply button to preview what will happen (Pro).
 * Shows "Apply to {N} rows → '{field}': '{value}'" so the user can confirm
 * before committing. Degrades to plain "Apply" on free or when value is empty.
 *
 * @param {number} rowCount   Number of rows that will be affected.
 * @param {string} fieldLabel Human-readable field label.
 * @param {string} value      The value that will be written.
 */
GravityTable.prototype.updateBulkFillPreview = function (rowCount, fieldLabel, value) {
    var config = this.config || {};
    // Prefer the stored reference injected by bulk-column-fill.js;
    // fall back to a DOM query so tests don't need to mock it.
    var btn = this._bulkFillApplyBtn || document.querySelector('.gt-bulk-fill-apply') || document.querySelector('.gt-bulk-fill-confirm');
    if (!btn) { return; }

    if (!config.is_pro || !value) {
        btn.textContent = 'Apply';
        return;
    }

    btn.textContent = 'Apply to ' + rowCount + ' row' + (rowCount !== 1 ? 's' : '') + ' → “' + value + '”';
};
