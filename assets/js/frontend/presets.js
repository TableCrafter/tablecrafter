/**
 * TableCrafter — frontend/presets.js
 *
 * Saved-filter / saved-view "preset" subsystem. Fifth slice under
 * #833. Eight helpers, ~232 lines.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - initPresets()              — bootstrap UI, wire toolbar
 *                                  handlers (select change, save,
 *                                  delete), kick off loadPresets.
 *   - findPresetById(presetId)   — pure linear search in
 *                                  this._presets.
 *   - renderPresetOptions()      — populate the toolbar <select>
 *                                  from this._presets, restore the
 *                                  currently-selected value if it
 *                                  survived the refresh.
 *   - loadPresets()              — AJAX gt_get_filter_presets, write
 *                                  response into this._presets, then
 *                                  renderPresetOptions.
 *   - savePresetPrompt()         — window.prompt for the name, then
 *                                  AJAX gt_save_filter_preset with the
 *                                  current this.filters payload.
 *   - deletePreset(presetId)     — AJAX gt_delete_filter_preset, then
 *                                  refresh options.
 *   - applyPresetById(presetId)  — findPresetById + applyPresetFilters.
 *   - applyPresetFilters(filters)— write filter values into the input
 *                                  DOM, handling each filter type
 *                                  (date_range, number_range, dropdown,
 *                                  lookup, checkboxes, text), then call
 *                                  this.applyFilters.
 *
 * Backend AJAX actions used:
 *   - gt_get_filter_presets
 *   - gt_save_filter_preset
 *   - gt_delete_filter_preset
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.initPresets = function () {
        var self = this;
        var $wrapper = $('#' + this.wrapperId);
        var $bar = $wrapper.find('.gt-presets');
        if (!$bar.length) return;

        this._presets = [];

        var tableId = parseInt($bar.attr('data-table-id'), 10) || 0;
        if (!tableId) return;
        this._presetTableId = tableId;

        $bar.on('change', '.gt-preset-select', function () {
            var presetId = $(this).val();
            if (!presetId) {
                $bar.find('.gt-preset-delete').hide();
                return;
            }
            $bar.find('.gt-preset-delete').show();
            self.applyPresetById(presetId);
        });

        $bar.on('click', '.gt-preset-save', function () {
            self.savePresetPrompt();
        });

        $bar.on('click', '.gt-preset-delete', function () {
            var $sel = $bar.find('.gt-preset-select');
            var presetId = $sel.val();
            if (!presetId) return;
            var preset = self.findPresetById(presetId);
            var name = preset ? preset.name : 'this preset';
            if (!confirm('Delete preset "' + name + '"?')) return;
            self.deletePreset(presetId);
        });

        this.loadPresets();
    };

    GravityTable.prototype.findPresetById = function (presetId) {
        var arr = this._presets || [];
        for (var i = 0; i < arr.length; i++) {
            if (arr[i].id === presetId) return arr[i];
        }
        return null;
    };

    // #1605 — capture the full visitor-adjustable view state so a
    // preset survives device changes (column order, sort stack, page
    // size — the pieces that previously lived only in localStorage).
    GravityTable.prototype.capturePresetView = function () {
        var $wrapper = $('#' + this.wrapperId);
        var order = [];
        $wrapper.find('thead tr').first().find('th[data-field-id]').each(function () {
            order.push(String($(this).attr('data-field-id')));
        });
        var stack = Array.isArray(this.sortStack) && this.sortStack.length
            ? this.sortStack
            : (this.sortField ? [{ field: String(this.sortField), order: this.sortOrder || 'desc' }] : []);
        return {
            column_order: order,
            sort_stack: stack,
            per_page: parseInt((this.config && this.config.per_page) || 0, 10) || 25
        };
    };

    // #1605 — re-apply a stored view. Column order routes through the
    // existing stored-order helpers so localStorage stays in sync and
    // the established head/body reorder path does the DOM work.
    GravityTable.prototype.applyPresetView = function (view) {
        if (!view || typeof view !== 'object') return;
        if (Array.isArray(view.column_order) && view.column_order.length
            && typeof this.saveStoredColumnOrder === 'function') {
            this.saveStoredColumnOrder(view.column_order);
            if (typeof this.applyStoredColumnOrderToHead === 'function') {
                this.applyStoredColumnOrderToHead();
            }
            if (typeof this.applyStoredColumnOrderToBody === 'function') {
                this.applyStoredColumnOrderToBody();
            }
        }
        if (Array.isArray(view.sort_stack) && view.sort_stack.length) {
            this.sortStack = view.sort_stack;
            this.sortField = view.sort_stack[0].field;
            this.sortOrder = view.sort_stack[0].order;
        }
        var pp = parseInt(view.per_page, 10);
        if (this.config && !isNaN(pp) && pp > 0) {
            this.config.per_page = pp;
        }
    };

    GravityTable.prototype.findDefaultPreset = function () {
        var arr = this._presets || [];
        for (var i = 0; i < arr.length; i++) {
            if (arr[i] && arr[i].is_default) return arr[i];
        }
        return null;
    };

    // #1605 — one-shot default-view application on load. Skips when
    // the visitor already has explicit state (URL filters, restored
    // localStorage filters, or an active search) so the pin only
    // fills the fresh-device gap it was designed for.
    GravityTable.prototype.maybeApplyDefaultPreset = function () {
        if (this._defaultViewApplied) return;
        this._defaultViewApplied = true;
        var hasFilters = this.filters && typeof this.filters === 'object'
            && Object.keys(this.filters).length > 0;
        var hasSearch = typeof this.searchTerm === 'string' && this.searchTerm !== '';
        if (hasFilters || hasSearch) return;
        var def = this.findDefaultPreset();
        if (!def) return;
        this.applyPresetById(def.id);
    };

    GravityTable.prototype.renderPresetOptions = function () {
        var $wrapper = $('#' + this.wrapperId);
        var $sel = $wrapper.find('.gt-preset-select');
        if (!$sel.length) return;
        var current = $sel.val();
        var html = '<option value="">Saved presets&hellip;</option>';
        var arr = this._presets || [];
        for (var i = 0; i < arr.length; i++) {
            // #1605 — star-prefix the default view.
            var label = (arr[i].is_default ? '★ ' : '') + arr[i].name;
            html += '<option value="' + this.escapeHtml(arr[i].id) + '">' + this.escapeHtml(label) + '</option>';
        }
        $sel.html(html);
        if (current && this.findPresetById(current)) {
            $sel.val(current);
            $wrapper.find('.gt-preset-delete').show();
        } else {
            $wrapper.find('.gt-preset-delete').hide();
        }
    };

    GravityTable.prototype.loadPresets = function () {
        var self = this;
        if (!this._presetTableId) return;
        $.post(this.config.ajax_url, {
            action: 'gt_get_filter_presets',
            nonce: this.config.nonce,
            table_id: this._presetTableId
        }, function (response) {
            if (response && response.success && response.data && Array.isArray(response.data.presets)) {
                self._presets = response.data.presets;
                self.renderPresetOptions();
                // #1605 — default pin auto-applies on fresh devices.
                if (typeof self.maybeApplyDefaultPreset === 'function') {
                    self.maybeApplyDefaultPreset();
                }
            }
        });
    };

    GravityTable.prototype.savePresetPrompt = function () {
        var self = this;
        var name = window.prompt('Save current view as preset (filters, column order, sort, page size). Enter a name:');
        if (name === null) return;
        name = (name || '').trim();
        if (!name) {
            alert('Preset name is required.');
            return;
        }
        var filtersJson = JSON.stringify(this.filters || {});
        // #1605 — capture the full view state + default pin.
        var viewJson = JSON.stringify(typeof this.capturePresetView === 'function' ? this.capturePresetView() : {});
        var isDefault = window.confirm('Make this your default view for this table?') ? '1' : '0';
        $.post(this.config.ajax_url, {
            action: 'gt_save_filter_preset',
            nonce: this.config.nonce,
            table_id: this._presetTableId,
            name: name,
            filters: filtersJson,
            view: viewJson,
            is_default: isDefault
        }, function (response) {
            if (response && response.success && response.data) {
                self._presets = response.data.presets || [];
                self.renderPresetOptions();
                $('#' + self.wrapperId).find('.gt-preset-select').val(response.data.preset_id);
                $('#' + self.wrapperId).find('.gt-preset-delete').show();
            } else {
                alert('Failed to save preset: ' + ((response && response.data) || 'Unknown error'));
            }
        }).fail(function () {
            alert('Failed to save preset.');
        });
    };

    GravityTable.prototype.deletePreset = function (presetId) {
        var self = this;
        $.post(this.config.ajax_url, {
            action: 'gt_delete_filter_preset',
            nonce: this.config.nonce,
            table_id: this._presetTableId,
            preset_id: presetId
        }, function (response) {
            if (response && response.success && response.data) {
                self._presets = response.data.presets || [];
                self.renderPresetOptions();
                $('#' + self.wrapperId).find('.gt-preset-select').val('');
                $('#' + self.wrapperId).find('.gt-preset-delete').hide();
            } else {
                alert('Failed to delete preset.');
            }
        });
    };

    GravityTable.prototype.applyPresetById = function (presetId) {
        var preset = this.findPresetById(presetId);
        if (!preset) return;
        // #1605 — view first (column order / sort / page size), then
        // filters; applyPresetFilters ends in applyFilters, which
        // reloads entries once with the full state in place.
        if (typeof this.applyPresetView === 'function' && preset.view) {
            this.applyPresetView(preset.view);
        }
        this.applyPresetFilters(preset.filters || {});
    };

    GravityTable.prototype.applyPresetFilters = function (filters) {
        var $wrapper = $('#' + this.wrapperId);

        // Clear current filter inputs first
        $wrapper.find('.gt-filter-input').val('');
        $wrapper.find('.gt-date-display').val('');
        $wrapper.find('.gt-date-html5').val('');
        $wrapper.find('.gt-range-min, .gt-range-max').val('');
        $wrapper.find('.gt-checkbox-filter').prop('checked', false);
        $wrapper.find('.gt-dropdown-filter, .gt-lookup-filter').each(function () {
            if ($(this).prop('multiple')) {
                $(this).val([]);
            } else {
                $(this).val('');
            }
        });

        // Walk filters and populate inputs
        Object.keys(filters || {}).forEach(function (key) {
            var match = /^filter_(.+)$/.exec(key);
            if (!match) return;
            var fieldId = match[1];
            var f = filters[key];
            var $field = $wrapper.find('.gt-filter-field[data-field-id="' + fieldId + '"]');
            if (!$field.length || !f || !f.type) return;

            switch (f.type) {
                case 'date_range':
                    var $singleHtml5 = $field.find('.gt-date-html5:not(.gt-date-from-html5):not(.gt-date-to-html5)');
                    var $fromHtml5 = $field.find('.gt-date-from-html5');
                    var $toHtml5 = $field.find('.gt-date-to-html5');
                    // Saved values are display-formatted; setting the html5 input expects YYYY-MM-DD,
                    // so write into the display input which existing apply path can re-read. The
                    // existing applyFilters reads the html5 input only — so we restore both.
                    if ($singleHtml5.length && f.from && f.from === f.to) {
                        var iso = parseDateToIso(f.from);
                        if (iso) $singleHtml5.val(iso);
                    } else {
                        if (f.from && $fromHtml5.length) {
                            var isoFrom = parseDateToIso(f.from);
                            if (isoFrom) $fromHtml5.val(isoFrom);
                        }
                        if (f.to && $toHtml5.length) {
                            var isoTo = parseDateToIso(f.to);
                            if (isoTo) $toHtml5.val(isoTo);
                        }
                    }
                    break;
                case 'number_range':
                    if (f.min != null) $field.find('.gt-range-min').val(f.min);
                    if (f.max != null) $field.find('.gt-range-max').val(f.max);
                    break;
                case 'dropdown':
                case 'lookup':
                    var $sel = $field.find('.gt-dropdown-filter, .gt-lookup-filter');
                    if (f.values && Array.isArray(f.values)) {
                        $sel.val(f.values);
                    } else if (f.value != null) {
                        $sel.val(f.value);
                    }
                    $sel.trigger('change');
                    break;
                case 'checkboxes':
                    if (Array.isArray(f.values)) {
                        f.values.forEach(function (v) {
                            $field.find('.gt-checkbox-filter[value="' + v + '"]').prop('checked', true);
                        });
                    }
                    break;
                case 'text':
                default:
                    if (f.value != null) {
                        $field.find('.gt-filter-input').val(f.value);
                    }
                    break;
            }
        });

        function parseDateToIso(val) {
            // Parses common formats (m/d/Y, Y-m-d) into yyyy-mm-dd for HTML5 date inputs
            if (!val) return '';
            if (/^\d{4}-\d{2}-\d{2}$/.test(val)) return val;
            var m = /^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/.exec(val);
            if (m) {
                var mm = ('0' + m[1]).slice(-2);
                var dd = ('0' + m[2]).slice(-2);
                return m[3] + '-' + mm + '-' + dd;
            }
            return '';
        }

        this.applyFilters();
    };

})(window);
