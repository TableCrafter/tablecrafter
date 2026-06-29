/**
 * TableCrafter — frontend/edit-history.js
 *
 * Undo / Redo history for inline cell edits. First slice under #833
 * (which itself is a child of the #830 split of frontend.js).
 *
 * Nine helpers attached directly to GravityTable.prototype:
 *
 *   - initUndoRedo()              — wire toolbar buttons, keyboard
 *                                   shortcuts, initial state. Called
 *                                   from init() in frontend.js.
 *   - pushHistoryEntry(entry)     — push to undo stack, evict oldest
 *                                   past _undoLimit, clear redo stack.
 *                                   Called by saveField after each
 *                                   successful edit (unless
 *                                   _suppressHistory is true).
 *   - updateUndoRedoButtons()     — sync disabled state of both buttons
 *                                   to current stack contents.
 *   - undoLastEdit()              — pop undo, push to redo, replay with
 *                                   oldValue, show toast.
 *   - redoLastEdit()              — pop redo, push to undo, replay with
 *                                   newValue, show toast.
 *   - replayHistoryEntry(entry, useNew)
 *                                 — re-issue saveField for the entry's
 *                                   cell with _suppressHistory set, so
 *                                   the replay doesn't re-enter the
 *                                   stack. Skips silently if row is no
 *                                   longer in the DOM (#535).
 *   - getFieldLabel(fieldId)      — resolve a human-readable label for
 *                                   the field used in toast messages.
 *                                   Reads config.column_config, falls
 *                                   back to the <th> text, then to
 *                                   "Field N".
 *   - shortValue(v)               — truncate a value for toast display
 *                                   (40 chars, ellipsis, "(empty)" for
 *                                   empty string / null).
 *   - showUndoToast(msg)          — render a transient aria-live toast.
 *
 * Listed as separate prototype assignments (not Object.assign) so the
 * pre-#831 file-grep contracts in our PHP test suite — which look for
 * `GravityTable.prototype.X = function` literals — keep working as the
 * #833 split progresses.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.initUndoRedo = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);

        this._undoStack = [];
        this._redoStack = [];
        this._undoLimit = 20;
        this._suppressHistory = false;

        // Inject Undo / Redo buttons into the table controls
        var $controls = $wrapper.find('.gt-table-controls').first();
        if ($controls.length && !$controls.find('.gt-undo-btn').length) {
            $controls.append(
                '<button type="button" class="gt-undo-btn" disabled title="Undo last edit (Cmd/Ctrl+Z)">↶ Undo</button>' +
                '<button type="button" class="gt-redo-btn" disabled title="Redo (Cmd/Ctrl+Shift+Z)">↷ Redo</button>'
            );
        }

        $wrapper.on('click', '.gt-undo-btn', function (e) { e.preventDefault(); self.undoLastEdit(); });
        $wrapper.on('click', '.gt-redo-btn', function (e) { e.preventDefault(); self.redoLastEdit(); });

        // Global keybinds (only fire when focus is inside this wrapper or its modal)
        $(document).on('keydown.gtUndo' + this.wrapperId, function (e) {
            var key = e.key || '';
            var isMod = e.metaKey || e.ctrlKey;
            if (!isMod) return;
            if (key !== 'z' && key !== 'Z' && key !== 'y' && key !== 'Y') return;

            // Only handle if the active element is inside this table's wrapper
            // and is NOT an editable input (so native browser undo still works
            // while typing inside a cell editor).
            var active = document.activeElement;
            if (!active) return;
            var inWrapper = $wrapper[0].contains(active);
            if (!inWrapper) return;
            if ($(active).is('input, textarea, select')) return;
            // #535: also skip elements the page has marked as editable. The
            // browser's own undo already operates on those — capturing here
            // would double-fire (our stack pop + the browser's own undo) and
            // the cell value drifts out of sync with what the user sees.
            // Using the browser-native HTMLElement.isContentEditable property
            // is more correct than a selector check: it returns true when the
            // element OR any ancestor has contenteditable="true", and false
            // when contenteditable="false" explicitly opts out — exactly the
            // semantics we want. (gravity-tables itself never turns this on;
            // the inline editor uses real <input> elements, see #433.)
            if (active.isContentEditable) return;

            if (key === 'z' || key === 'Z') {
                e.preventDefault();
                if (e.shiftKey) self.redoLastEdit();
                else self.undoLastEdit();
            } else if (key === 'y' || key === 'Y') {
                e.preventDefault();
                self.redoLastEdit();
            }
        });

        // Clear stacks on entry reload (paging, filters, etc.) so undo can't
        // target stale rows.
        this.updateUndoRedoButtons();
    };

    GravityTable.prototype.pushHistoryEntry = function (entry) {
        this._undoStack.push(entry);
        if (this._undoStack.length > this._undoLimit) this._undoStack.shift();
        this._redoStack = []; // any new edit invalidates the redo path
        this.updateUndoRedoButtons();
    };

    GravityTable.prototype.updateUndoRedoButtons = function () {
        var $wrapper = $('#' + this.wrapperId);
        $wrapper.find('.gt-undo-btn').prop('disabled', this._undoStack.length === 0);
        $wrapper.find('.gt-redo-btn').prop('disabled', this._redoStack.length === 0);
    };

    GravityTable.prototype.undoLastEdit = function () {
        if (!this._undoStack.length) return;
        var entry = this._undoStack.pop();
        this._redoStack.push(entry);
        this.replayHistoryEntry(entry, /* useNew */ false);
        this.showUndoToast('Undone: ' + entry.fieldLabel + ' reverted to "' + this.shortValue(entry.oldValue) + '"');
        this.updateUndoRedoButtons();
    };

    GravityTable.prototype.redoLastEdit = function () {
        if (!this._redoStack.length) return;
        var entry = this._redoStack.pop();
        this._undoStack.push(entry);
        this.replayHistoryEntry(entry, /* useNew */ true);
        this.showUndoToast('Redone: ' + entry.fieldLabel + ' set to "' + this.shortValue(entry.newValue) + '"');
        this.updateUndoRedoButtons();
    };

    GravityTable.prototype.replayHistoryEntry = function (entry, useNew) {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $cell = $wrapper.find('td.gt-editable-cell[data-entry-id="' + entry.entryId + '"][data-field-id="' + entry.fieldId + '"]').first();
        if ( ! $cell.length ) {
            // Stale reference (#535): the row this entry pointed at is no longer
            // in the DOM — deleted, paginated away, or filtered out. Earlier
            // versions fell back to a detached placeholder and called saveField()
            // anyway, which could resurrect a deleted entry server-side or
            // surface as an "Undefined index" / 500 in the user's face. Skip
            // silently, surface a toast so the user knows why nothing visibly
            // changed, and do NOT re-push to either stack.
            self.showUndoToast('Cannot ' + (useNew ? 'redo' : 'undo') + ': row no longer in view');
            return;
        }
        // Mirror what saveField expects: original-value lets keyboard nav re-edit,
        // but we don't push to history during replay.
        $cell.data('original-value', useNew ? entry.oldValue : entry.newValue);
        self._suppressHistory = true;
        self.saveField(entry.entryId, entry.fieldId, useNew ? entry.newValue : entry.oldValue, $cell);
        setTimeout(function () { self._suppressHistory = false; }, 50);
    };

    GravityTable.prototype.getFieldLabel = function (fieldId) {
        var cfg = this.config && this.config.column_config ? this.config.column_config[fieldId] : null;
        if (cfg && cfg.label) return cfg.label;
        var $wrapper = $('#' + this.wrapperId);
        var $th = $wrapper.find('thead th.gt-column-' + String(fieldId).replace(/[^A-Za-z0-9_-]/g, '')).first();
        return $th.length ? $th.text().trim().replace(/▲|▼|\s+/g, ' ').trim() : ('Field ' + fieldId);
    };

    GravityTable.prototype.shortValue = function (v) {
        v = (v == null) ? '' : String(v);
        if (v === '') return '(empty)';
        if (v.length > 40) return v.slice(0, 38) + '…';
        return v;
    };

    GravityTable.prototype.showUndoToast = function (msg) {
        var $existing = $('.gt-undo-toast');
        if ($existing.length) $existing.remove();
        var $t = $('<div class="gt-undo-toast" role="status" aria-live="polite"></div>').text(msg);
        $('body').append($t);
        setTimeout(function () { $t.addClass('gt-undo-toast-shown'); }, 10);
        setTimeout(function () {
            $t.removeClass('gt-undo-toast-shown');
            setTimeout(function () { $t.remove(); }, 300);
        }, 2200);
    };

})(window);
