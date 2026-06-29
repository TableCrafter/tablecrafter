/**
 * TableCrafter — frontend/filter-apply.js
 *
 * applyFilters + clearFilters — the orchestrator pair for the
 * advanced-filter pipeline. #834 slice 4 of N.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - applyFilters()
 *       Walks every .gt-filter-field inside the wrapper, dispatches
 *       per data-field-type:
 *         - date: single-input OR from/to-range, both formatted via
 *           self.convertHtml5DateToFormat. Empty fields skipped.
 *         - number / range: gt-range-min + gt-range-max → number_range.
 *         - dropdown: single-select OR multi-select. Lookup variants
 *           detected via .gt-lookup-filter class.
 *         - checkboxes: collects checked .gt-checkbox-filter values
 *           into a values array.
 *         - default (text): .gt-filter-input value → text filter, or
 *           lookup filter if .gt-lookup-filter class is present.
 *       Each per-field branch is wrapped in try/catch so a malformed
 *       field doesn't abort processing of the others. Sets
 *       self.filters, resets self.currentPage to 1, persists state,
 *       reloads entries.
 *
 *   - clearFilters()
 *       Resets every filter input (.gt-filter-input + date html5 +
 *       date display + search input), wipes self.filters + searchTerm,
 *       resets currentPage to 1, clears persisted state, reloads.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery || window.$;

    Object.assign(window.GravityTable.prototype, {

        applyFilters: function () {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);
            var filters = {};

            $wrapper.find('.gt-filter-field').each(function () {
                var $field = $(this);
                var fieldId = $field.data('field-id');
                var fieldType = $field.data('field-type');

                try {
                    switch (fieldType) {
                        case 'date': {
                            var $singleHtml5 = $field.find('.gt-date-html5:not(.gt-date-from-html5):not(.gt-date-to-html5)');
                            var $fromHtml5 = $field.find('.gt-date-from-html5');
                            var $toHtml5 = $field.find('.gt-date-to-html5');
                            var fromValue = '';
                            var toValue = '';
                            var dateFormat = (self.config && self.config.date_format) ? self.config.date_format : 'm/d/Y';

                            if ($singleHtml5.length > 0) {
                                if ($singleHtml5.val()) {
                                    fromValue = self.convertHtml5DateToFormat($singleHtml5.val(), dateFormat);
                                    toValue = fromValue;
                                }
                            } else {
                                if ($fromHtml5.val()) {
                                    fromValue = self.convertHtml5DateToFormat($fromHtml5.val(), dateFormat);
                                }
                                if ($toHtml5.val()) {
                                    toValue = self.convertHtml5DateToFormat($toHtml5.val(), dateFormat);
                                }
                            }

                            if (fromValue || toValue) {
                                filters['filter_' + fieldId] = {
                                    type: 'date_range',
                                    from: fromValue || '',
                                    to: toValue || ''
                                };
                            }
                            break;
                        }

                        case 'number':
                        case 'range': {
                            var minValue = $field.find('.gt-range-min').val();
                            var maxValue = $field.find('.gt-range-max').val();
                            if (minValue || maxValue) {
                                filters['filter_' + fieldId] = {
                                    type: 'number_range',
                                    min: minValue || '',
                                    max: maxValue || ''
                                };
                            }
                            break;
                        }

                        case 'dropdown': {
                            var $select = $field.find('.gt-dropdown-filter, .gt-lookup-filter');
                            if ($select.length && $select.val()) {
                                if ($select.prop('multiple')) {
                                    var selectedValues = $select.val();
                                    if (selectedValues && selectedValues.length > 0) {
                                        filters['filter_' + fieldId] = {
                                            type: 'dropdown',
                                            values: selectedValues
                                        };
                                    }
                                } else {
                                    var selectedValue = $select.val();
                                    if (selectedValue) {
                                        if ($select.hasClass('gt-lookup-filter')) {
                                            filters['filter_' + fieldId] = {
                                                type: 'lookup',
                                                value: selectedValue
                                            };
                                        } else {
                                            filters['filter_' + fieldId] = {
                                                type: 'dropdown',
                                                value: selectedValue
                                            };
                                        }
                                    }
                                }
                            }
                            break;
                        }

                        case 'checkboxes': {
                            var $checkboxes = $field.find('.gt-checkbox-filter:checked');
                            if ($checkboxes.length) {
                                var values = [];
                                $checkboxes.each(function () {
                                    values.push($(this).val());
                                });
                                if (values.length) {
                                    filters['filter_' + fieldId] = {
                                        type: 'checkboxes',
                                        values: values
                                    };
                                }
                            }
                            break;
                        }

                        default: {
                            var $input = $field.find('.gt-filter-input');
                            var value = $input.val();
                            if (value) {
                                if ($input.hasClass('gt-lookup-filter')) {
                                    filters['filter_' + fieldId] = {
                                        type: 'lookup',
                                        value: value
                                    };
                                } else {
                                    filters['filter_' + fieldId] = {
                                        type: 'text',
                                        value: value
                                    };
                                }
                            }
                            break;
                        }
                    }
                } catch (error) {
                    /* c8 ignore next */
                    if (typeof console !== 'undefined' && console.error) {
                        /* c8 ignore next */
                        console.error('GT Frontend: Error processing filter field', fieldId, ':', error);
                    }
                }
            });

            this.filters = filters;
            this.currentPage = 1;
            this.persistFilterStateLocal();
            this.loadEntries();
        },

        clearFilters: function () {
            var $wrapper = $('#' + this.wrapperId);

            $wrapper.find('.gt-filter-input').val('');
            $wrapper.find('.gt-date-display').val('');
            $wrapper.find('.gt-date-html5').val('');
            $wrapper.find('.gt-per-col-filter').val('');

            this.filters = {};
            this.searchTerm = '';
            $wrapper.find('.gt-search-input').val('');
            this.currentPage = 1;
            this.clearFilterStateLocal();
            this.loadEntries();
        },

        // #1679 — per-column filter row (.gt-per-col-filter inputs under the
        // header). Each input filters its own column via the existing server
        // text-filter path (filter_<fieldId> => {type:'text', value}). Merges
        // into this.filters without clobbering advanced-panel filters; an
        // emptied input removes only its own key. Wired (debounced) from
        // bindEvents().
        applyPerColumnFilters: function () {
            var self = this;
            var $wrapper = $('#' + this.wrapperId);

            if (!this.filters || typeof this.filters !== 'object') {
                this.filters = {};
            }

            $wrapper.find('.gt-per-col-filter').each(function () {
                var fieldId = $(this).attr('data-field');
                if (!fieldId) return;
                var key = 'filter_' + fieldId;
                var value = $(this).val();
                if (value !== null && value !== undefined && String(value).trim() !== '') {
                    // #1681 — lookup columns store an ID but display a name, so
                    // a typed name must be resolved server-side (lookup_name);
                    // plain columns use a straight text LIKE.
                    var isLookup = $(this).attr('data-lookup') === '1' || $(this).data('lookup') === 1;
                    self.filters[key] = { type: isLookup ? 'lookup_name' : 'text', value: value };
                } else {
                    delete self.filters[key];
                }
            });

            this.currentPage = 1;
            if (typeof this.persistFilterStateLocal === 'function') {
                this.persistFilterStateLocal();
            }
            this.loadEntries();
        }

    });

})(window);
