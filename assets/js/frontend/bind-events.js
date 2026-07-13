/**
 * TableCrafter - frontend/bind-events.js
 *
 * Global event wiring for a GravityTable instance. Tenth slice
 * under #833. One method, ~220 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - bindEvents()
 *
 * Wires every top-level event listener: toolbar button clicks,
 * filter input changes, search input, sort handlers, pagination,
 * the inline-edit / detail-popup delegated handlers. Called once
 * from init(). All listeners route through this/self so callees
 * in other modules (saveField, editField, deleteEntry, etc.) work
 * via the prototype.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.bindEvents = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);

        // Remove any stale handlers from a previous init cycle (re-mount safety)
        $wrapper.off('.gt-table');

        // #599 slice 3 - Cascading filter dropdown handler. When the
        // visitor changes a filter input that has data-gt-cascade-parent,
        // fetch valid child options for the chosen value and update the
        // child filter's placeholder to surface the cascade. Behavior
        // intentionally minimal: doesn't enforce the child's value (the
        // user can still type anything); it just hints at what's
        // available given the parent. Customers asking for full select-
        // based UX get slice 4.
        $wrapper.on('input.gt-table change.gt-table', '.gt-per-col-filter[data-gt-cascade-parent]', function () {
            var $parent = $(this);
            var parentValue = $parent.val();
            var parentField = $parent.attr('data-gt-cascade-parent');
            var childField = $parent.attr('data-gt-cascade-child');
            if (!parentField || !childField) return;

            var $child = $wrapper.find('.gt-per-col-filter[data-field="' + childField + '"]').first();
            if (!$child.length) return;

            // Reset placeholder when parent is empty.
            if (parentValue === '') {
                $child.attr('placeholder', $child.data('gt-original-placeholder') || childField);
                return;
            }
            // Stash the original placeholder once.
            if (!$child.data('gt-original-placeholder')) {
                $child.data('gt-original-placeholder', $child.attr('placeholder') || childField);
            }

            $.post(self.config.ajax_url || (typeof gtTableData !== 'undefined' && gtTableData.ajax_url) || '/wp-admin/admin-ajax.php', {
                action: 'gt_cascading_filter_options',
                table_id: self.config.table_id,
                parent_field: parentField,
                parent_value: parentValue,
                child_field: childField
            }, function (resp) {
                if (!resp || !resp.success) return;
                var options = (resp.data && resp.data.options) || [];
                if (options.length === 0) {
                    $child.attr('placeholder', 'No matches for "' + parentValue + '"');
                } else {
                    $child.attr('placeholder', options.length + ' option' + (options.length === 1 ? '' : 's') + ' (e.g. ' + options.slice(0, 3).join(', ') + (options.length > 3 ? '…' : '') + ')');
                }
            }, 'json');
        });

        // #1679 - per-column filter inputs. The .gt-per-col-filter row was
        // dead UI: nothing collected the typed values or reloaded the table
        // (only the cascade placeholder hint above was wired). Collect every
        // per-column value into this.filters and reload via the existing
        // server text-filter path. Debounced on input (so we don't fire a
        // request per keystroke); immediate on change/blur and Enter.
        var gtPerColTimer = null;
        function gtRunPerColFilters() {
            if (typeof self.applyPerColumnFilters === 'function') {
                self.applyPerColumnFilters();
            }
        }
        $wrapper.on('input.gt-table', '.gt-per-col-filter', function () {
            if (gtPerColTimer) { clearTimeout(gtPerColTimer); }
            gtPerColTimer = setTimeout(gtRunPerColFilters, 300);
        });
        $wrapper.on('change.gt-table', '.gt-per-col-filter', function () {
            if (gtPerColTimer) { clearTimeout(gtPerColTimer); }
            gtRunPerColFilters();
        });
        $wrapper.on('keydown.gt-table', '.gt-per-col-filter', function (e) {
            if (e.which === 13 || e.key === 'Enter') {
                if (gtPerColTimer) { clearTimeout(gtPerColTimer); }
                gtRunPerColFilters();
            }
        });

        // #568 slice 4 - Click-to-filter cell drill-down (URL + AJAX integration).
        // Visitor clicks a cell value in an enabled column → a chip appears,
        // the URL ?gt_df= parameter is updated, and the table reloads from
        // the server with the new filter applied. Survives pagination.
        var drilldownCols = (self.config && self.config.drilldown_columns) || [];
        if (drilldownCols.length) {
            function $drilldownChipsContainer() {
                var $existing = $wrapper.find('.gt-drilldown-chips').first();
                if ($existing.length) return $existing;
                var $c = $('<div class="gt-drilldown-chips" role="status" aria-live="polite"></div>');
                var $table = $wrapper.find('table.gt-table, .gt-table-element').first();
                if ($table.length) { $table.before($c); } else { $wrapper.prepend($c); }
                return $c;
            }

            function escAttrText(s) {
                return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function renderDrilldownChips() {
                var $c = $drilldownChipsContainer();
                if (!self.drilldownFilters.length) { $c.empty().hide(); return; }
                var html = '';
                $.each(self.drilldownFilters, function (i, f) {
                    var safeCol = String(f.col).replace(/[^A-Za-z0-9_.]/g, '');
                    var label = $('<div>').text(f.col + ' = ' + f.value).html();
                    html += '<span class="gt-drilldown-chip" data-col="' + safeCol + '" data-value="' + escAttrText(f.value) + '">';
                    html += '<span class="gt-drilldown-chip-label">' + label + '</span>';
                    html += '<button type="button" class="gt-drilldown-chip-remove" aria-label="Remove filter">×</button>';
                    html += '</span>';
                });
                $c.html(html).show();
            }

            function toggleDrilldownFilter(col, value) {
                var found = -1;
                for (var i = 0; i < self.drilldownFilters.length; i++) {
                    if (self.drilldownFilters[i].col === col && self.drilldownFilters[i].value === value) {
                        found = i; break;
                    }
                }
                if (found >= 0) {
                    self.drilldownFilters.splice(found, 1);
                } else {
                    self.drilldownFilters.push({ col: col, value: value });
                }
                renderDrilldownChips();
                self.updateDrilldownUrlState();
                self.currentPage = 1; // reset to first page on new filter
                self.loadEntries();
            }

            // Initial render if filters were seeded from URL/config
            if (self.drilldownFilters.length) {
                renderDrilldownChips();
            }

            // Cell click delegate.
            $wrapper.on('click.gt-drilldown', 'tbody td', function (e) {
                var $td = $(this);
                if ($(e.target).closest('a, button, input, textarea, select, label, .gt-editable-cell, .gt-toggle-switch, .gt-actions-cell, .gt-checkbox-cell').length > 0) {
                    return;
                }
                var col = '';
                var classes = ($td.attr('class') || '').split(/\s+/);
                for (var i = 0; i < classes.length; i++) {
                    if (classes[i].indexOf('gt-column-') === 0) {
                        col = classes[i].substring('gt-column-'.length);
                        break;
                    }
                }
                if (!col || drilldownCols.indexOf(col) === -1) return;
                var value = String($td.text() || '').trim();
                if (value === '') return;
                e.preventDefault();
                e.stopPropagation();
                toggleDrilldownFilter(col, value);
            });

            // Chip removal.
            $wrapper.on('click.gt-drilldown', '.gt-drilldown-chip-remove', function (e) {
                e.preventDefault(); e.stopPropagation();
                var $chip = $(this).closest('.gt-drilldown-chip');
                var col = $chip.attr('data-col');
                var value = $chip.attr('data-value');
                if (col && value !== undefined) { toggleDrilldownFilter(col, value); }
            });
        }

        // Row-link interaction (#567) - click + auxclick + keyboard activation
        // moved to assets/js/frontend/a11y-keyboard.js (#839). bindRowLinkEvents
        // wires the three handlers in one call.
        self.bindRowLinkEvents($wrapper);

        // Length selector + Pagination handlers moved to assets/js/frontend/pagination.js (#835).
        // bindPaginationEvents wires .gt-length-select change + .gt-prev-page click +
        // .gt-next-page click in one call.
        self.bindPaginationEvents($wrapper);

        // Search controls moved to assets/js/frontend/search.js (#834 slice 1).
        self.bindSearchEvents($wrapper);

        // Prev/Next page click handlers moved to assets/js/frontend/pagination.js (#835).

        // Sort handler + multi-column state machine moved to assets/js/frontend/sort.js (#834 slice 3).
        self.bindSortEvents($wrapper);

        // #1621 - client-side sort for computed columns (no DB column,
        // so the server sort path can't see them). typeof guard keeps
        // harnesses without the module on the old path.
        if (typeof self.bindComputedSortEvents === 'function') {
            self.bindComputedSortEvents($wrapper);
        }

        // Selection + bulk-action handlers moved to assets/js/frontend/selection.js (#836).
        // bindSelectionEvents wires both the .gt-select-all change and the
        // .gt-bulk-action-btn click in one call.
        self.bindSelectionEvents($wrapper);

        // #1745 - show/hide Fill Column button based on row selection.
        $wrapper.on('change', '.gt-entry-checkbox, .gt-select-all', function () {
            var anyChecked = $wrapper.find('.gt-entry-checkbox:checked').length > 0;
            $wrapper.find('.gt-bulk-fill-btn').toggle(anyChecked);
        });

        // #1745 - open fill modal when button clicked.
        $wrapper.find('.gt-bulk-fill-btn').on('click', function () {
            if (typeof self.openBulkFillModal === 'function') {
                var ids = self.getSelectedEntryIds ? self.getSelectedEntryIds() : [];
                self.openBulkFillModal(ids);
            }
        });

        // Export controls
        $wrapper.find('.gt-export-btn').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $dropdown = $(this).closest('.gt-export-dropdown');
            $dropdown.toggleClass('open');

            // Close other open dropdowns
            $('.gt-export-dropdown').not($dropdown).removeClass('open');
        });

        $wrapper.find('.gt-export-option').on('click', function (e) {
            e.preventDefault();
            var format = $(this).data('format');
            self.exportTable(format);

            // Close dropdown
            $(this).closest('.gt-export-dropdown').removeClass('open');
        });

        // #1680 - the visible-rows export actions (Copy / CSV / Excel / PDF)
        // now live inside the Export dropdown; their actual handlers are wired
        // by initToolbarExport() via the preserved gt-toolbar-*-btn classes.
        // Here we just close the dropdown after the action runs. Delegated so
        // it survives re-renders.
        $wrapper.on('click', '.gt-export-menu-item', function () {
            $(this).closest('.gt-export-dropdown').removeClass('open');
        });

        // Advanced filter panel handlers moved to assets/js/frontend/filter-panel.js (#834 slice 2).
        self.bindFilterPanelEvents($wrapper);

        // 4.7.57: Text-filter typeahead. Each .gt-text-filter input becomes a search-with-suggestions
        // bound to distinct existing values for that column. Helps admins (a) discover what's already
        // in the data without remembering exact spelling and (b) clean up messy free-text columns by
        // finding variants/typos and inline-editing rows to a canonical value.
        self.initTextFilterTypeaheads($wrapper);

        // Handle delete action clicks
        $wrapper.on('click', '.gt-delete-action', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $row = $(this).closest('tr');
            var entryId = $row.data('entry-id');

            if (!entryId) {
                console.error('GT: No entry ID found for delete action');
                return;
            }

            // Show confirmation dialog
            if (confirm('Are you sure you want to permanently delete this entry? This action cannot be undone.')) {
                self.deleteEntry(entryId, $row);
            }
        });

        // Close export dropdowns within this wrapper when clicking outside - scoped to
        // $wrapper so it cannot capture clicks that belong to other plugins (#435)
        $wrapper.on('click.gt-table', function (e) {
            if (!$(e.target).closest('.gt-export-dropdown').length) {
                $wrapper.find('.gt-export-dropdown').removeClass('open');
            }
        });
    };

})(window);
