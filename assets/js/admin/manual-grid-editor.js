/**
 * TableCrafter — admin/manual-grid-editor.js
 *
 * #2367 — P1-1b: Manual tables spreadsheet grid editor MVP.
 *
 * Engine decision: lean grid built on our existing admin inline-edit stack.
 * Rationale: avoids a vendored dependency (jSpreadsheet ~180 KB minified).
 * The MVP scope (click-to-edit, Tab/Shift-Tab/Enter/Esc nav, editable
 * headers, dirty-state, TSV paste) is achievable in ~350 LOC with vanilla JS
 * + jQuery already on the page. Scaling seam: replace_rows is a full-replace
 * (DELETE + bulk INSERT); acceptable for ≤2 k rows at MVP. When row count
 * grows beyond that, migrate to a delta-patch endpoint that only writes
 * changed row_index values — the AJAX handler is intentionally separate
 * (gt_save_manual_rows) to make that seam easy to replace.
 *
 * This file exposes:
 *   window.TCManualGridModel  — pure model (no DOM; unit-testable)
 *   window.TCManualGridEditor — DOM binding layer (jQuery)
 *
 * Loaded ONLY on the table-builder screen for manual-source tables; see
 * TC_Admin::enqueue_admin_scripts() for the conditional enqueue.
 *
 * @since 8.1.0 (#2367)
 */

/* global jQuery, gtAdmin */
(function ($) {
    'use strict';

    // =========================================================================
    // TCManualGridModel — pure model, no DOM dependency
    // =========================================================================

    /**
     * Grid data model.
     *
     * @param {object} options
     * @param {Array<{key:string, label:string}>} options.columns  column definitions
     * @param {Array<object>}                     options.rows     data rows (key→value maps)
     */
    function TCManualGridModel(options) {
        // Deep-clone so we never mutate the caller's arrays.
        var cols = (options.columns || []).map(function (c) {
            var col = { key: c.key, label: c.label || c.key };
            // #2370 — preserve column visibility flag from server data.
            if (c.hidden === true) { col.hidden = true; }
            return col;
        });
        var rows = (options.rows || []).map(function (r) {
            var copy = {};
            cols.forEach(function (c) {
                copy[c.key] = Object.prototype.hasOwnProperty.call(r, c.key) ? String(r[c.key]) : '';
            });
            // #2370 — preserve row visibility flag from server data.
            if (r._tc_hidden === true) { copy._tc_hidden = true; }
            return copy;
        });

        this._columns = cols;
        this._rows    = rows;
        this._dirty   = false;
    }

    TCManualGridModel.prototype.getRowCount = function () {
        return this._rows.length;
    };

    TCManualGridModel.prototype.getColCount = function () {
        return this._columns.length;
    };

    /** Get the value at (rowIdx, colIdx). Returns '' on out-of-bounds. */
    TCManualGridModel.prototype.getCell = function (rowIdx, colIdx) {
        if (rowIdx < 0 || rowIdx >= this._rows.length) { return ''; }
        if (colIdx < 0 || colIdx >= this._columns.length) { return ''; }
        return this._rows[rowIdx][this._columns[colIdx].key];
    };

    /** Set value at (rowIdx, colIdx). No-op if out-of-bounds. Marks dirty. */
    TCManualGridModel.prototype.setCell = function (rowIdx, colIdx, value) {
        if (rowIdx < 0 || rowIdx >= this._rows.length) { return; }
        if (colIdx < 0 || colIdx >= this._columns.length) { return; }
        this._rows[rowIdx][this._columns[colIdx].key] = String(value);
        this._dirty = true;
    };

    /** Get the label of column at colIdx. */
    TCManualGridModel.prototype.getColumnLabel = function (colIdx) {
        if (colIdx < 0 || colIdx >= this._columns.length) { return ''; }
        return this._columns[colIdx].label;
    };

    /** Set label of column at colIdx. Marks dirty. */
    TCManualGridModel.prototype.setColumnLabel = function (colIdx, label) {
        if (colIdx < 0 || colIdx >= this._columns.length) { return; }
        this._columns[colIdx].label = String(label);
        this._dirty = true;
    };

    /** Returns { key: label } map for all columns. */
    TCManualGridModel.prototype.getColumnLabelsMap = function () {
        var map = {};
        this._columns.forEach(function (c) {
            map[c.key] = c.label;
        });
        return map;
    };

    /** Returns the current rows as an array of key→value objects. */
    TCManualGridModel.prototype.getRows = function () {
        return this._rows.map(function (r) {
            var copy = {};
            Object.keys(r).forEach(function (k) { copy[k] = r[k]; });
            return copy;
        });
    };

    /** Returns the column definitions array (for reference). */
    TCManualGridModel.prototype.getColumns = function () {
        return this._columns.slice();
    };

    TCManualGridModel.prototype.isDirty = function () {
        return this._dirty;
    };

    TCManualGridModel.prototype.resetDirty = function () {
        this._dirty = false;
    };

    // ── Visibility toggles (#2370) ─────────────────────────────────────────

    /** Toggle the hidden state of a row. Marks dirty. */
    TCManualGridModel.prototype.toggleRowHidden = function (rowIdx) {
        if (rowIdx < 0 || rowIdx >= this._rows.length) { return; }
        this._rows[rowIdx]._tc_hidden = !this._rows[rowIdx]._tc_hidden;
        if (!this._rows[rowIdx]._tc_hidden) {
            delete this._rows[rowIdx]._tc_hidden;
        }
        this._dirty = true;
    };

    /** Returns true if the row at rowIdx is hidden. */
    TCManualGridModel.prototype.isRowHidden = function (rowIdx) {
        if (rowIdx < 0 || rowIdx >= this._rows.length) { return false; }
        return this._rows[rowIdx]._tc_hidden === true;
    };

    /** Count of rows not hidden. */
    TCManualGridModel.prototype.visibleRowCount = function () {
        var count = 0;
        this._rows.forEach(function (r) {
            if (r._tc_hidden !== true) { count++; }
        });
        return count;
    };

    /** Toggle the hidden state of a column. Marks dirty. */
    TCManualGridModel.prototype.toggleColumnHidden = function (colIdx) {
        if (colIdx < 0 || colIdx >= this._columns.length) { return; }
        this._columns[colIdx].hidden = !this._columns[colIdx].hidden;
        if (!this._columns[colIdx].hidden) {
            delete this._columns[colIdx].hidden;
        }
        this._dirty = true;
    };

    /** Returns true if the column at colIdx is hidden. */
    TCManualGridModel.prototype.isColumnHidden = function (colIdx) {
        if (colIdx < 0 || colIdx >= this._columns.length) { return false; }
        return this._columns[colIdx].hidden === true;
    };

    /** Count of columns not hidden. */
    TCManualGridModel.prototype.visibleColCount = function () {
        var count = 0;
        this._columns.forEach(function (c) {
            if (c.hidden !== true) { count++; }
        });
        return count;
    };

    // ── Row operations (#2368) ─────────────────────────────────────────────

    /**
     * Insert a blank row above rowIdx.
     *
     * @param {int} rowIdx
     * @returns {{before:{rowCount:int}, after:{rowCount:int}}}
     */
    TCManualGridModel.prototype.insertRowAbove = function (rowIdx) {
        var before = { rowCount: this._rows.length };
        var blank = _blankRow(this._columns);
        this._rows.splice(rowIdx, 0, blank);
        this._dirty = true;
        return { before: before, after: { rowCount: this._rows.length } };
    };

    /**
     * Insert a blank row below rowIdx.
     *
     * @param {int} rowIdx
     * @returns {{before:{rowCount:int}, after:{rowCount:int}}}
     */
    TCManualGridModel.prototype.insertRowBelow = function (rowIdx) {
        var before = { rowCount: this._rows.length };
        var blank = _blankRow(this._columns);
        this._rows.splice(rowIdx + 1, 0, blank);
        this._dirty = true;
        return { before: before, after: { rowCount: this._rows.length } };
    };

    /**
     * Delete the row at rowIdx.
     *
     * Guard: returns null (no-op) if this is the last row, or idx is OOB.
     *
     * @param {int} rowIdx
     * @returns {{before:{rowCount:int}, after:{rowCount:int}}|null}
     */
    TCManualGridModel.prototype.deleteRow = function (rowIdx) {
        if (this._rows.length <= 1) { return null; }
        if (rowIdx < 0 || rowIdx >= this._rows.length) { return null; }
        var before = { rowCount: this._rows.length };
        this._rows.splice(rowIdx, 1);
        this._dirty = true;
        return { before: before, after: { rowCount: this._rows.length } };
    };

    /**
     * Duplicate the row at rowIdx, inserting the copy immediately below it.
     *
     * @param {int} rowIdx
     * @returns {{before:{rowCount:int}, after:{rowCount:int}}}
     */
    TCManualGridModel.prototype.duplicateRow = function (rowIdx) {
        var before = { rowCount: this._rows.length };
        var source = this._rows[rowIdx];
        var copy = {};
        Object.keys(source).forEach(function (k) { copy[k] = source[k]; });
        this._rows.splice(rowIdx + 1, 0, copy);
        this._dirty = true;
        return { before: before, after: { rowCount: this._rows.length } };
    };

    /**
     * Move the row at fromIdx to toIdx.
     *
     * No-op (returns no-op state) when fromIdx === toIdx.
     *
     * @param {int} fromIdx
     * @param {int} toIdx
     * @returns {{before:{rowCount:int}, after:{rowCount:int}}}
     */
    TCManualGridModel.prototype.moveRow = function (fromIdx, toIdx) {
        var state = { rowCount: this._rows.length };
        if (fromIdx === toIdx) { return { before: state, after: state }; }
        var row = this._rows.splice(fromIdx, 1)[0];
        this._rows.splice(toIdx, 0, row);
        this._dirty = true;
        return { before: { rowCount: this._rows.length }, after: { rowCount: this._rows.length } };
    };

    // ── Column operations (#2368) ──────────────────────────────────────────

    /**
     * Insert a blank column to the left of colIdx.
     *
     * @param {int} colIdx
     * @returns {{before:{colCount:int}, after:{colCount:int}}}
     */
    TCManualGridModel.prototype.insertColLeft = function (colIdx) {
        return _insertCol(this, colIdx);
    };

    /**
     * Insert a blank column to the right of colIdx.
     *
     * @param {int} colIdx
     * @returns {{before:{colCount:int}, after:{colCount:int}}}
     */
    TCManualGridModel.prototype.insertColRight = function (colIdx) {
        return _insertCol(this, colIdx + 1);
    };

    /**
     * Delete the column at colIdx, removing its key from every row.
     *
     * Guard: returns null (no-op) when this is the last column, or idx OOB.
     *
     * @param {int} colIdx
     * @returns {{before:{colCount:int}, after:{colCount:int}}|null}
     */
    TCManualGridModel.prototype.deleteColumn = function (colIdx) {
        if (this._columns.length <= 1) { return null; }
        if (colIdx < 0 || colIdx >= this._columns.length) { return null; }
        var before = { colCount: this._columns.length };
        var removedKey = this._columns[colIdx].key;
        this._columns.splice(colIdx, 1);
        this._rows.forEach(function (r) { delete r[removedKey]; });
        this._dirty = true;
        return { before: before, after: { colCount: this._columns.length } };
    };

    /**
     * Duplicate the column at colIdx, inserting the copy immediately to its right.
     *
     * The duplicate gets a fresh unique key so row objects never share keys.
     *
     * @param {int} colIdx
     * @returns {{before:{colCount:int}, after:{colCount:int}}}
     */
    TCManualGridModel.prototype.duplicateColumn = function (colIdx) {
        var before = { colCount: this._columns.length };
        var src = this._columns[colIdx];
        var newKey = _uniqueColKey(this._columns, src.key);
        var newCol = { key: newKey, label: src.label };
        this._columns.splice(colIdx + 1, 0, newCol);
        var srcKey = src.key;
        this._rows.forEach(function (r) {
            r[newKey] = Object.prototype.hasOwnProperty.call(r, srcKey) ? r[srcKey] : '';
        });
        this._dirty = true;
        return { before: before, after: { colCount: this._columns.length } };
    };

    /**
     * Move the column at fromIdx to toIdx, remapping every row's cell order.
     *
     * The column definitions array is reordered; all row objects already hold
     * data by key so no key remapping is needed — only the column definition
     * array position changes.
     *
     * @param {int} fromIdx
     * @param {int} toIdx
     * @returns {{before:{colCount:int}, after:{colCount:int}}}
     */
    TCManualGridModel.prototype.moveColumn = function (fromIdx, toIdx) {
        var state = { colCount: this._columns.length };
        if (fromIdx === toIdx) { return { before: state, after: state }; }
        var col = this._columns.splice(fromIdx, 1)[0];
        this._columns.splice(toIdx, 0, col);
        this._dirty = true;
        return { before: { colCount: this._columns.length }, after: { colCount: this._columns.length } };
    };

    // ── Column operation helpers ───────────────────────────────────────────

    /** Create a blank row object with empty string values for all columns. */
    function _blankRow(columns) {
        var row = {};
        columns.forEach(function (c) { row[c.key] = ''; });
        return row;
    }

    /** Insert a blank column at position insertAt. */
    function _insertCol(model, insertAt) {
        var before = { colCount: model._columns.length };
        var newKey = _uniqueColKey(model._columns, 'col');
        var newCol = { key: newKey, label: '' };
        model._columns.splice(insertAt, 0, newCol);
        model._rows.forEach(function (r) { r[newKey] = ''; });
        model._dirty = true;
        return { before: before, after: { colCount: model._columns.length } };
    }

    /**
     * Generate a unique column key that doesn't exist in the current columns array.
     *
     * @param {Array} columns  current columns array
     * @param {string} base    base key prefix
     * @returns {string}
     */
    function _uniqueColKey(columns, base) {
        var existing = {};
        columns.forEach(function (c) { existing[c.key] = true; });
        // Strip trailing _copy/_N suffix from base so duplicates don't stack
        var cleanBase = base.replace(/_copy(_\d+)?$/, '');
        var candidate = cleanBase + '_copy';
        var i = 2;
        while (existing[candidate]) {
            candidate = cleanBase + '_copy_' + i;
            i++;
        }
        return candidate;
    }

    // ── Cell content picker helpers (#2369) ───────────────────────────────────

    /**
     * Build an anchor snippet.
     *
     * @param {string} url   Destination URL.
     * @param {string} text  Link text; falls back to url when empty.
     * @returns {string}
     */
    TCManualGridModel.prototype.buildLinkSnippet = function (url, text) {
        var safeUrl  = _esc(url);
        var display  = text ? _esc(text) : _esc(url);
        return '<a href="' + safeUrl + '">' + display + '</a>';
    };

    /**
     * Build an image snippet.
     *
     * @param {string} url  Image source URL.
     * @param {string} alt  Alt text (may be empty).
     * @returns {string}
     */
    TCManualGridModel.prototype.buildImgSnippet = function (url, alt) {
        return '<img src="' + _esc(url) + '" alt="' + _esc(alt) + '" />';
    };

    /**
     * Insert a snippet into cell (rowIdx, colIdx) at the given cursor position.
     *
     * When selStart is an empty string (or falsy non-zero), the snippet is
     * appended to the end of the existing value.  When selStart is a number,
     * the range selStart..selEnd is replaced with the snippet.
     *
     * @param {int}            rowIdx
     * @param {int}            colIdx
     * @param {string}         snippet
     * @param {number|string}  selStart  cursor/selection start ('' = no cursor)
     * @param {number|string}  selEnd    cursor/selection end
     */
    TCManualGridModel.prototype.insertAtCursor = function (rowIdx, colIdx, snippet, selStart, selEnd) {
        var current = this.getCell(rowIdx, colIdx);
        var updated;
        if (selStart === '' || selStart === null || selStart === undefined) {
            updated = current + snippet;
        } else {
            var start = Number(selStart);
            var end   = Number(selEnd);
            updated = current.slice(0, start) + snippet + current.slice(end);
        }
        this.setCell(rowIdx, colIdx, updated);
    };

    /**
     * Paste TSV text starting at (anchorRow, anchorCol).
     *
     * Handles \r\n and \n line endings. Clips to grid boundaries (does not
     * add rows or columns — MVP scope; row/col add is #2368).
     *
     * @param {string} tsv         clipboard text (tab-delimited, newline rows)
     * @param {int}    anchorRow   row index of the top-left paste target
     * @param {int}    anchorCol   col index of the top-left paste target
     */
    TCManualGridModel.prototype.pasteTSV = function (tsv, anchorRow, anchorCol) {
        // Normalise line endings.
        var lines = tsv.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
        // Remove trailing empty line produced by some spreadsheets.
        if (lines.length > 0 && lines[lines.length - 1] === '') {
            lines = lines.slice(0, -1);
        }
        var self = this;
        lines.forEach(function (line, lineOffset) {
            var rowIdx = anchorRow + lineOffset;
            if (rowIdx >= self._rows.length) { return; }
            var cells = line.split('\t');
            cells.forEach(function (val, cellOffset) {
                var colIdx = anchorCol + cellOffset;
                if (colIdx >= self._columns.length) { return; }
                self._rows[rowIdx][self._columns[colIdx].key] = val;
            });
        });
        if (lines.length > 0) {
            this._dirty = true;
        }
    };

    // Export model constructor.
    window.TCManualGridModel = TCManualGridModel;

    // =========================================================================
    // TCManualGridEditor — DOM binding layer
    // =========================================================================

    /**
     * Bind the grid editor to a given container element.
     *
     * @param {jQuery}             $container  the #gt-manual-grid-editor wrapper
     * @param {TCManualGridModel}  model       data model instance
     * @param {object}             options
     * @param {Function}           options.onDirty  called whenever the model transitions to dirty
     */
    function TCManualGridEditor($container, model, options) {
        this.$container = $container;
        this.model      = model;
        this.options    = options || {};
        this._activeCell = null; // { row, col }
        this._editing    = false;
        this._render();
        this._bindEvents();
    }

    /** (Re-)render the full grid HTML from the current model state. */
    TCManualGridEditor.prototype._render = function () {
        var model    = this.model;
        var numRows  = model.getRowCount();
        var numCols  = model.getColCount();
        var cols     = model.getColumns();

        var html = '<div class="gt-grid-wrapper" tabindex="0">';
        html += '<table class="gt-manual-grid wp-list-table widefat fixed striped">';

        // Header row — col-index gutter + per-column header with action menu.
        html += '<thead><tr>';
        // Row-ops gutter header (drag handle column + row-number column)
        html += '<th class="gt-grid-rowidx gt-grid-row-gutter"></th>';
        html += '<th class="gt-grid-rowidx"></th>';
        for (var c = 0; c < numCols; c++) {
            var colHidden = model.isColumnHidden(c);
            html += '<th class="gt-grid-header' + (colHidden ? ' gt-grid-col-hidden' : '') + '" data-col="' + c + '">' +
                '<div class="gt-col-header-inner">' +
                '<span class="gt-grid-header-label">' + _esc(cols[c].label) + '</span>' +
                '<input class="gt-grid-header-input" type="text" value="' + _esc(cols[c].label) + '" ' +
                'data-col="' + c + '" style="display:none;" />' +
                (colHidden ? '<span class="gt-visibility-hidden-marker dashicons dashicons-hidden" aria-hidden="true" title="Hidden from frontend"></span>' : '') +
                '<button type="button" class="gt-col-menu-btn" data-col="' + c + '" title="Column options" aria-label="Column options">&#9660;</button>' +
                '</div>' +
                // Column dropdown menu (hidden by default)
                '<div class="gt-col-menu" data-col="' + c + '" style="display:none;">' +
                '<button type="button" class="gt-col-insert-left"  data-col="' + c + '">Insert left</button>' +
                '<button type="button" class="gt-col-insert-right" data-col="' + c + '">Insert right</button>' +
                '<button type="button" class="gt-col-duplicate"    data-col="' + c + '">Duplicate</button>' +
                '<button type="button" class="gt-col-toggle-hidden" data-col="' + c + '">' + (colHidden ? 'Show column' : 'Hide column') + '</button>' +
                '<button type="button" class="gt-col-delete"       data-col="' + c + '">Delete</button>' +
                '</div>' +
                '</th>';
        }
        html += '</tr></thead>';

        // Data rows — draggable handle gutter + row number + data cells.
        html += '<tbody class="gt-rows-draggable">';
        for (var r = 0; r < numRows; r++) {
            var rowHidden = model.isRowHidden(r);
            html += '<tr data-row="' + r + '"' + (rowHidden ? ' class="gt-grid-row-hidden"' : '') + '>';
            // Drag handle cell
            html += '<td class="gt-grid-row-gutter gt-grid-drag-cell">' +
                '<span class="gt-row-drag-handle" data-row="' + r + '" title="Drag to reorder" aria-label="Drag row">&#8597;</span>' +
                '</td>';
            // Row number + row action menu
            html += '<td class="gt-grid-rowidx">' +
                '<span class="gt-rowidx-num">' + (r + 1) + '</span>' +
                (rowHidden ? '<span class="gt-visibility-hidden-marker dashicons dashicons-hidden" aria-hidden="true" title="Hidden from frontend"></span>' : '') +
                '<button type="button" class="gt-row-menu-btn" data-row="' + r + '" title="Row options" aria-label="Row options">&#8942;</button>' +
                '<div class="gt-row-menu" data-row="' + r + '" style="display:none;">' +
                '<button type="button" class="gt-row-insert-above" data-row="' + r + '">Insert above</button>' +
                '<button type="button" class="gt-row-insert-below" data-row="' + r + '">Insert below</button>' +
                '<button type="button" class="gt-row-duplicate"    data-row="' + r + '">Duplicate</button>' +
                '<button type="button" class="gt-row-toggle-hidden" data-row="' + r + '">' + (rowHidden ? 'Show row' : 'Hide row') + '</button>' +
                '<button type="button" class="gt-row-delete"       data-row="' + r + '">Delete</button>' +
                '</div>' +
                '</td>';
            for (var cc = 0; cc < numCols; cc++) {
                var val = model.getCell(r, cc);
                html += '<td class="gt-grid-cell' + (model.isColumnHidden(cc) ? ' gt-grid-col-hidden' : '') + '" data-row="' + r + '" data-col="' + cc + '">' +
                    '<span class="gt-grid-cell-display">' + _esc(val) + '</span>' +
                    '<input class="gt-grid-cell-input" type="text" value="' + _esc(val) + '" ' +
                    'data-row="' + r + '" data-col="' + cc + '" style="display:none;" />' +
                    '</td>';
            }
            html += '</tr>';
        }
        html += '</tbody></table></div>';

        this.$container.html(html);
        this._initRowDrag();
    };

    /**
     * Initialise SortableJS drag-to-reorder on grid rows.
     *
     * Reuses the same SortableJS library already loaded for the field builder.
     * On drag end the model is updated and the grid re-rendered.
     */
    TCManualGridEditor.prototype._initRowDrag = function () {
        var self = this;
        var tbody = this.$container.find('.gt-rows-draggable')[0];
        if (!tbody || typeof Sortable === 'undefined') { return; }

        if (this._rowSortable && typeof this._rowSortable.destroy === 'function') {
            this._rowSortable.destroy();
        }
        this._rowSortable = new Sortable(tbody, {
            handle: '.gt-row-drag-handle',
            animation: 150,
            ghostClass: 'gt-row-placeholder',
            onEnd: function (evt) {
                var from = evt.oldIndex;
                var to   = evt.newIndex;
                if (from === to) { return; }
                self.model.moveRow(from, to);
                self._onModelChanged();
                self._render();
                self._bindEvents();
            }
        });
    };

    TCManualGridEditor.prototype._bindEvents = function () {
        var self = this;

        // Unbind any previous handlers before re-binding — prevents duplicate
        // handlers accumulating when _bindEvents is called after each re-render.
        this.$container.off('.gtGrid');

        // ── Close open menus when clicking outside ─────────────────────────
        $(document).off('click.gtGridMenuClose').on('click.gtGridMenuClose', function (e) {
            if (!$(e.target).closest('.gt-row-menu-btn, .gt-row-menu, .gt-col-menu-btn, .gt-col-menu, .gt-row-toggle-hidden, .gt-col-toggle-hidden').length) {
                self.$container.find('.gt-row-menu, .gt-col-menu').hide();
            }
        });

        // ── Row action menu toggle ─────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-row-menu-btn', function (e) {
            e.stopPropagation();
            var $btn = $(this);
            var $menu = $btn.siblings('.gt-row-menu');
            // Close all other menus first.
            self.$container.find('.gt-row-menu, .gt-col-menu').not($menu).hide();
            $menu.toggle();
        });

        // ── Row: insert above ──────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-row-insert-above', function (e) {
            e.stopPropagation();
            var row = parseInt($(this).data('row'), 10);
            self.$container.find('.gt-row-menu').hide();
            self.model.insertRowAbove(row);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Row: insert below ──────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-row-insert-below', function (e) {
            e.stopPropagation();
            var row = parseInt($(this).data('row'), 10);
            self.$container.find('.gt-row-menu').hide();
            self.model.insertRowBelow(row);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Row: duplicate ─────────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-row-duplicate', function (e) {
            e.stopPropagation();
            var row = parseInt($(this).data('row'), 10);
            self.$container.find('.gt-row-menu').hide();
            self.model.duplicateRow(row);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Row: delete ────────────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-row-delete', function (e) {
            e.stopPropagation();
            var row = parseInt($(this).data('row'), 10);
            self.$container.find('.gt-row-menu').hide();
            if (self.model.getRowCount() <= 1) { return; }
            // Check if row has content — confirm if so.
            var hasContent = false;
            for (var c = 0; c < self.model.getColCount(); c++) {
                if (self.model.getCell(row, c) !== '') { hasContent = true; break; }
            }
            if (hasContent && !window.confirm('Delete this row? Its content will be lost.')) { return; }
            var result = self.model.deleteRow(row);
            if (result !== null) {
                self._onModelChanged();
                self._render();
                self._bindEvents();
            }
        });

        // ── Row: toggle hidden ─────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-row-toggle-hidden', function (e) {
            e.stopPropagation();
            var row = parseInt($(this).data('row'), 10);
            self.$container.find('.gt-row-menu').hide();
            if (!self.model.isRowHidden(row) && self.model.visibleRowCount() <= 1) {
                window.alert('Warning: hiding this row would leave the table with no visible rows. Hiding anyway.');
            }
            self.model.toggleRowHidden(row);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Column action menu toggle ──────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-col-menu-btn', function (e) {
            e.stopPropagation();
            var $btn = $(this);
            var $menu = $btn.closest('th').find('.gt-col-menu');
            // Close all other menus first.
            self.$container.find('.gt-row-menu, .gt-col-menu').not($menu).hide();
            $menu.toggle();
        });

        // ── Column: insert left ────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-col-insert-left', function (e) {
            e.stopPropagation();
            var col = parseInt($(this).data('col'), 10);
            self.$container.find('.gt-col-menu').hide();
            self.model.insertColLeft(col);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Column: insert right ───────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-col-insert-right', function (e) {
            e.stopPropagation();
            var col = parseInt($(this).data('col'), 10);
            self.$container.find('.gt-col-menu').hide();
            self.model.insertColRight(col);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Column: duplicate ──────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-col-duplicate', function (e) {
            e.stopPropagation();
            var col = parseInt($(this).data('col'), 10);
            self.$container.find('.gt-col-menu').hide();
            self.model.duplicateColumn(col);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Column: delete ─────────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-col-delete', function (e) {
            e.stopPropagation();
            var col = parseInt($(this).data('col'), 10);
            self.$container.find('.gt-col-menu').hide();
            if (self.model.getColCount() <= 1) { return; }
            // Check if column has content in any row.
            var hasContent = false;
            for (var r = 0; r < self.model.getRowCount(); r++) {
                if (self.model.getCell(r, col) !== '') { hasContent = true; break; }
            }
            if (hasContent && !window.confirm('Delete this column? Its content in all rows will be lost.')) { return; }
            var result = self.model.deleteColumn(col);
            if (result !== null) {
                self._onModelChanged();
                self._render();
                self._bindEvents();
            }
        });

        // ── Column: toggle hidden ──────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-col-toggle-hidden', function (e) {
            e.stopPropagation();
            var col = parseInt($(this).data('col'), 10);
            self.$container.find('.gt-col-menu').hide();
            if (!self.model.isColumnHidden(col) && self.model.visibleColCount() <= 1) {
                window.alert('Warning: hiding this column would leave the table with no visible columns. Hiding anyway.');
            }
            self.model.toggleColumnHidden(col);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });

        // ── Click-to-edit cell ─────────────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-grid-cell', function (e) {
            var $td  = $(this);
            var row  = parseInt($td.data('row'), 10);
            var col  = parseInt($td.data('col'), 10);
            self._startCellEdit(row, col);
        });

        // ── Click-to-edit header label ─────────────────────────────────────
        this.$container.on('click.gtGrid', '.gt-grid-header-label', function (e) {
            // Don't re-open if already editing.
            var $th = $(this).closest('th.gt-grid-header');
            if ($th.find('.gt-grid-header-input').is(':visible')) { return; }
            var col = parseInt($th.data('col'), 10);
            self._startHeaderEdit(col);
        });

        // ── Cell input keyboard nav ────────────────────────────────────────
        this.$container.on('keydown.gtGrid', '.gt-grid-cell-input', function (e) {
            var $input = $(this);
            var row    = parseInt($input.data('row'), 10);
            var col    = parseInt($input.data('col'), 10);

            if (e.key === 'Escape') {
                e.preventDefault();
                self._cancelCellEdit(row, col);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                self._commitCellEdit(row, col);
                // Move down one row.
                var nextRow = row + 1;
                if (nextRow < self.model.getRowCount()) {
                    setTimeout(function () { self._startCellEdit(nextRow, col); }, 0);
                }
            } else if (e.key === 'Tab') {
                e.preventDefault();
                self._commitCellEdit(row, col);
                var numCols = self.model.getColCount();
                var numRows = self.model.getRowCount();
                var nextRow = row;
                var nextCol = e.shiftKey ? col - 1 : col + 1;
                if (nextCol < 0) {
                    nextCol = numCols - 1;
                    nextRow = row - 1;
                } else if (nextCol >= numCols) {
                    nextCol = 0;
                    nextRow = row + 1;
                }
                if (nextRow >= 0 && nextRow < numRows) {
                    setTimeout(function () { self._startCellEdit(nextRow, nextCol); }, 0);
                }
            }
        });

        // ── Cell input blur: commit ────────────────────────────────────────
        // Guard: do NOT commit when focus is moving into the picker toolbar or
        // link popover — the user is interacting with a picker action, not
        // leaving the cell. We check relatedTarget first (synchronously
        // available in most browsers); fall back to a 0 ms timeout reading
        // document.activeElement for browsers that deliver a null relatedTarget
        // on blur (e.g. some Firefox versions with shadow DOM).
        this.$container.on('blur.gtGrid', '.gt-grid-cell-input', function (e) {
            var $input = $(this);
            var row    = parseInt($input.data('row'), 10);
            var col    = parseInt($input.data('col'), 10);

            // Only commit if still in editing mode for this cell.
            if (!self._editing || !self._activeCell ||
                self._activeCell.row !== row || self._activeCell.col !== col) {
                return;
            }

            // Check if focus is moving INTO the picker toolbar / link popover.
            var related = e.relatedTarget || null;
            if (related && self._$pickerToolbar &&
                $(related).closest(self._$pickerToolbar).length) {
                return; // Focus moved into the toolbar — keep edit state alive.
            }

            if (related !== null) {
                // relatedTarget was available and is NOT inside the toolbar.
                self._commitCellEdit(row, col);
            } else {
                // relatedTarget is null — defer one tick to read activeElement.
                setTimeout(function () {
                    var active = document.activeElement;
                    if (active && self._$pickerToolbar &&
                        $(active).closest(self._$pickerToolbar).length) {
                        return; // Focus went into the toolbar; keep editing.
                    }
                    // Still editing? Commit.
                    if (self._editing && self._activeCell &&
                        self._activeCell.row === row && self._activeCell.col === col) {
                        self._commitCellEdit(row, col);
                    }
                }, 0);
            }
        });

        // ── Header input keyboard nav ──────────────────────────────────────
        this.$container.on('keydown.gtGrid', '.gt-grid-header-input', function (e) {
            var col = parseInt($(this).data('col'), 10);
            if (e.key === 'Escape') {
                e.preventDefault();
                self._cancelHeaderEdit(col);
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                self._commitHeaderEdit(col);
            }
        });

        // ── Header input blur: commit ──────────────────────────────────────
        this.$container.on('blur.gtGrid', '.gt-grid-header-input', function () {
            var col = parseInt($(this).data('col'), 10);
            self._commitHeaderEdit(col);
        });

        // ── TSV paste ──────────────────────────────────────────────────────
        this.$container.on('paste.gtGrid', '.gt-grid-cell-input', function (e) {
            var $input = $(this);
            var row    = parseInt($input.data('row'), 10);
            var col    = parseInt($input.data('col'), 10);

            var clipData = e.originalEvent && e.originalEvent.clipboardData;
            if (!clipData) { return; }
            var tsv = clipData.getData('text/plain') || '';

            // Only handle multi-cell paste (contains tab or newline).
            if (tsv.indexOf('\t') === -1 && tsv.indexOf('\n') === -1 && tsv.indexOf('\r') === -1) {
                return; // Let the browser handle single-value paste.
            }

            e.preventDefault();
            self.model.pasteTSV(tsv, row, col);
            self._onModelChanged();
            self._render();
            self._bindEvents();
        });
    };

    /** Open cell (row, col) for editing. */
    TCManualGridEditor.prototype._startCellEdit = function (row, col) {
        // Commit any previously active cell.
        if (this._editing && this._activeCell) {
            this._commitCellEdit(this._activeCell.row, this._activeCell.col);
        }
        this._activeCell = { row: row, col: col };
        this._editing    = true;

        var $cell  = this.$container.find(
            '.gt-grid-cell[data-row="' + row + '"][data-col="' + col + '"]'
        );
        var $span  = $cell.find('.gt-grid-cell-display');
        var $input = $cell.find('.gt-grid-cell-input');

        $span.hide();
        $input.show().val(this.model.getCell(row, col)).trigger('focus').select();

        this._showPickerToolbar(row, col, $cell, $input);
    };

    TCManualGridEditor.prototype._commitCellEdit = function (row, col) {
        this._hidePickerToolbar();

        var $cell  = this.$container.find(
            '.gt-grid-cell[data-row="' + row + '"][data-col="' + col + '"]'
        );
        var $input = $cell.find('.gt-grid-cell-input');
        var $span  = $cell.find('.gt-grid-cell-display');

        if (!$input.is(':visible')) { return; }

        var val = $input.val();
        var old = this.model.getCell(row, col);
        this.model.setCell(row, col, val);
        $span.text(val).show();
        $input.hide();

        if (val !== old) {
            this._onModelChanged();
        }

        this._editing    = false;
        this._activeCell = null;
    };

    TCManualGridEditor.prototype._cancelCellEdit = function (row, col) {
        this._hidePickerToolbar();

        var $cell  = this.$container.find(
            '.gt-grid-cell[data-row="' + row + '"][data-col="' + col + '"]'
        );
        var $input = $cell.find('.gt-grid-cell-input');
        var $span  = $cell.find('.gt-grid-cell-display');

        $span.show();
        $input.val(this.model.getCell(row, col)).hide();
        this._editing    = false;
        this._activeCell = null;
    };

    /**
     * Show the media + link picker toolbar adjacent to the active cell input.
     *
     * Toolbar buttons use mousedown + preventDefault so the blur handler on
     * the cell input does not fire before the insertion runs.
     *
     * @param {int}    row
     * @param {int}    col
     * @param {jQuery} $cell   the <td> element
     * @param {jQuery} $input  the cell input
     */
    TCManualGridEditor.prototype._showPickerToolbar = function (row, col, $cell, $input) {
        this._hidePickerToolbar();

        var self     = this;
        var model    = this.model;
        var hasMedia = (typeof window.wp !== 'undefined' && typeof window.wp.media !== 'undefined');

        var mediaBtn = hasMedia
            ? '<button type="button" class="button button-small gt-grid-add-media">Add media</button> '
            : '';

        var html = '<div class="gt-cell-picker-toolbar">' +
            mediaBtn +
            '<button type="button" class="button button-small gt-grid-add-link">Add link</button>' +
            '<div class="gt-link-popover" style="display:none;">' +
                '<label>URL <input type="text" class="gt-link-url" /></label>' +
                '<label>Text <input type="text" class="gt-link-text" /></label>' +
                '<button type="button" class="button button-primary button-small gt-link-insert">Insert</button>' +
                ' <button type="button" class="button button-small gt-link-cancel">Cancel</button>' +
            '</div>' +
            '</div>';

        var $toolbar = $(html);
        $cell.append($toolbar);
        this._$pickerToolbar = $toolbar;

        // ── Media button ────────────────────────────────────────────────────
        // Cursor capture: read selectionStart/End on mousedown, before the WP
        // media modal opens and steals focus (which resets those values to 0).
        // The captured values are used in the 'select' callback instead of
        // reading them from the (now-unfocused) input at that later point.
        // The media modal is a full overlay outside the toolbar so the blur
        // guard cannot cover it — the cell WILL commit when the modal opens.
        // Instead, we write directly to the model at the captured cursor
        // position, then update the cell display from the model data.
        if (hasMedia) {
            $toolbar.on('mousedown', '.gt-grid-add-media', function (e) {
                e.preventDefault();
                // Capture cursor position and cell identity NOW, before open().
                var capturedStart = $input[0].selectionStart;
                var capturedEnd   = $input[0].selectionEnd;
                var capturedRow   = row;
                var capturedCol   = col;

                var frame = window.wp.media({
                    title: 'Select or upload media',
                    button: { text: 'Insert' },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var snippet;
                    if (attachment.type === 'image') {
                        snippet = model.buildImgSnippet(attachment.url, attachment.alt || '');
                    } else {
                        snippet = model.buildLinkSnippet(attachment.url, attachment.filename || attachment.url);
                    }
                    // Use captured cursor position — selectionStart/End are 0
                    // after the modal stole focus, so reading them now is stale.
                    model.insertAtCursor(capturedRow, capturedCol, snippet, capturedStart, capturedEnd);
                    var newVal = model.getCell(capturedRow, capturedCol);

                    // If the cell editor is still open for this cell, update it.
                    if (self._editing && self._activeCell &&
                        self._activeCell.row === capturedRow &&
                        self._activeCell.col === capturedCol) {
                        $input.val(newVal);
                        $input.trigger('focus');
                    } else {
                        // The modal caused a blur-commit; re-render the affected
                        // cell display so the insertion is visible.
                        var $cell = self.$container.find(
                            '.gt-grid-cell[data-row="' + capturedRow + '"][data-col="' + capturedCol + '"]'
                        );
                        $cell.find('.gt-grid-cell-display').text(newVal);
                        self._onModelChanged();
                    }
                });
                frame.open();
            });
        }

        // ── Link button: toggle popover ──────────────────────────────────────
        // Cursor capture: read selectionStart/End on mousedown so we have the
        // correct cursor position before focus transfers to the URL input.
        // The blur guard will keep the cell in edit state, but the cursor
        // position in the cell input is lost once the URL input gains focus.
        var _linkCursorStart = '';
        var _linkCursorEnd   = '';
        $toolbar.on('mousedown', '.gt-grid-add-link', function (e) {
            e.preventDefault();
            // Capture cursor before the popover open / focus change.
            _linkCursorStart = $input[0].selectionStart;
            _linkCursorEnd   = $input[0].selectionEnd;
            var $pop = $toolbar.find('.gt-link-popover');
            $pop.toggle();
            if ($pop.is(':visible')) {
                $pop.find('.gt-link-url').trigger('focus');
            }
        });

        // ── Link popover: insert ─────────────────────────────────────────────
        $toolbar.on('mousedown', '.gt-link-insert', function (e) {
            e.preventDefault();
            var url  = $toolbar.find('.gt-link-url').val().trim();
            if (!url) { return; }
            var text = $toolbar.find('.gt-link-text').val().trim();
            var snippet = model.buildLinkSnippet(url, text);
            // Use the cursor position captured when the popover was opened.
            model.insertAtCursor(row, col, snippet, _linkCursorStart, _linkCursorEnd);
            $input.val(model.getCell(row, col));
            $toolbar.find('.gt-link-popover').hide();
            $toolbar.find('.gt-link-url').val('');
            $toolbar.find('.gt-link-text').val('');
            $input.trigger('focus');
        });

        // ── Link popover: cancel ─────────────────────────────────────────────
        $toolbar.on('mousedown', '.gt-link-cancel', function (e) {
            e.preventDefault();
            $toolbar.find('.gt-link-popover').hide();
            $input.trigger('focus');
        });
    };

    /** Remove the picker toolbar if one is currently shown. */
    TCManualGridEditor.prototype._hidePickerToolbar = function () {
        if (this._$pickerToolbar) {
            this._$pickerToolbar.remove();
            this._$pickerToolbar = null;
        }
    };

    TCManualGridEditor.prototype._startHeaderEdit = function (col) {
        var $th    = this.$container.find('.gt-grid-header[data-col="' + col + '"]');
        var $label = $th.find('.gt-grid-header-label');
        var $input = $th.find('.gt-grid-header-input');
        $label.hide();
        $input.show().val(this.model.getColumnLabel(col)).trigger('focus').select();
    };

    TCManualGridEditor.prototype._commitHeaderEdit = function (col) {
        var $th    = this.$container.find('.gt-grid-header[data-col="' + col + '"]');
        var $label = $th.find('.gt-grid-header-label');
        var $input = $th.find('.gt-grid-header-input');
        if (!$input.is(':visible')) { return; }
        var val = $input.val().trim() || this.model.getColumnLabel(col);
        var old = this.model.getColumnLabel(col);
        this.model.setColumnLabel(col, val);
        $label.text(val).show();
        $input.hide();
        if (val !== old) {
            this._onModelChanged();
        }
    };

    TCManualGridEditor.prototype._cancelHeaderEdit = function (col) {
        var $th    = this.$container.find('.gt-grid-header[data-col="' + col + '"]');
        var $label = $th.find('.gt-grid-header-label');
        var $input = $th.find('.gt-grid-header-input');
        $label.show();
        $input.val(this.model.getColumnLabel(col)).hide();
    };

    TCManualGridEditor.prototype._onModelChanged = function () {
        if (typeof this.options.onDirty === 'function' && this.model.isDirty()) {
            this.options.onDirty();
        }
    };

    /** Destroy the editor: unbind events, clear container. */
    TCManualGridEditor.prototype.destroy = function () {
        this.$container.off('.gtGrid');
        this.$container.empty();
    };

    window.TCManualGridEditor = TCManualGridEditor;

    // =========================================================================
    // Builder integration — wire up on DOM ready when source is 'manual'
    // =========================================================================

    // Only run in a real browser context (skip during vitest model tests).
    if (typeof document !== 'undefined' && typeof window.gtManualGridData !== 'undefined') {
        $(function () {
            _initGridFromPayload(window.gtManualGridData);
        });
    }

    /**
     * Boot the grid editor from the PHP-localised payload.
     *
     * @param {object} payload  { table_id, columns, rows, nonce, ajax_url }
     */
    function _initGridFromPayload(payload) {
        var $container = $('#gt-manual-grid-editor');
        if (!$container.length || !payload) { return; }

        var model  = new TCManualGridModel({
            columns: payload.columns || [],
            rows:    payload.rows    || [],
        });

        var editor = new TCManualGridEditor($container, model, {
            onDirty: function () {
                // Mark the builder save button as having pending changes.
                $('#save-table').addClass('gt-has-pending-changes');
                $container.attr('data-dirty', '1');
            },
        });

        // Store the editor on the container so the save flow can access it.
        $container.data('gtGridEditor', editor);

        // Show the grid and hide the "coming soon" placeholder.
        $container.show();
        $('#gt-manual-grid-placeholder').hide();
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /** HTML-escape a string for safe insertion. */
    function _esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

}(typeof jQuery !== 'undefined' ? jQuery : { fn: {} }));
