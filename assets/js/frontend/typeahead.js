/**
 * TableCrafter - frontend/typeahead.js
 *
 * Text-filter typeahead. #834 slice 5 of 5 - closes #834.
 *
 * Surface (attached to GravityTable.prototype via Object.assign):
 *
 *   - initTextFilterTypeaheads($wrapper)
 *       Wires each `input.gt-text-filter` inside the wrapper as a
 *       typeahead. Idempotent via a `gtTypeaheadInit` data flag - 
 *       safe to call after re-render.
 *
 *       Per input:
 *         - Wraps in `.gt-filter-typeahead-wrap` and appends a
 *           `.gt-filter-typeahead-suggest` <ul role="listbox">.
 *         - input event: debounced (200ms) AJAX to
 *           `gt_get_filter_suggestions` with the column's field_id.
 *         - focus event: immediate refresh (no debounce).
 *         - blur event: 200ms-delayed hide (so a suggestion click
 *           can fire before the list disappears).
 *         - keydown: ArrowDown/Up cycles the active item,
 *           Enter activates it (sets input value + calls
 *           applyFilters), Escape hides the list.
 *
 *       Bails when ajax_url / nonce / form_id are missing from config.
 *
 * Closes #834 (filter-sort umbrella).
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

        initTextFilterTypeaheads: function ($wrapper) {
            var self = this;
            var ajaxUrl = (this.config && this.config.ajax_url) || (window.gtTableData && window.gtTableData[this.wrapperId] && window.gtTableData[this.wrapperId].ajax_url);
            var nonce = (this.config && this.config.nonce) || (window.gtTableData && window.gtTableData[this.wrapperId] && window.gtTableData[this.wrapperId].nonce);
            var formId = (this.config && this.config.form_id) || (window.gtTableData && window.gtTableData[this.wrapperId] && window.gtTableData[this.wrapperId].form_id);
            if (!ajaxUrl || !nonce || !formId) { return; }

            $wrapper.find('input.gt-text-filter').each(function () {
                var $input = $(this);
                if ($input.data('gtTypeaheadInit')) { return; }

                var $field = $input.closest('[data-field-id]');
                var fieldId = $field.attr('data-field-id');
                if (!fieldId) { return; }

                $input.data('gtTypeaheadInit', true);

                // Wrap and append the suggest list.
                $input.wrap('<div class="gt-filter-typeahead-wrap" style="position:relative;width:100%;" />');
                var $wrap = $input.parent();
                var $list = $('<ul class="gt-filter-typeahead-suggest" role="listbox" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:10050;max-height:240px;overflow-y:auto;margin:2px 0 0;padding:0;list-style:none;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 6px 18px rgba(0,0,0,0.12);" />');
                $wrap.append($list);

                var activeIdx = -1;
                var inflight = null;
                var debounceT;

                function renderResults(results) {
                    $list.empty();
                    activeIdx = -1;
                    if (!results || !results.length) { $list.hide(); return; }
                    results.forEach(function (row, i) {
                        var label = (row && (row.label || row.value)) || '';
                        var $li = $('<li role="option" />').text(label).css({
                            padding: '8px 12px',
                            cursor: 'pointer',
                            borderBottom: '1px solid #f0f0f1',
                            color: '#1d2327'
                        });
                        $li.on('mousedown', function (e) { e.preventDefault(); });
                        $li.on('click', function () {
                            $input.val(label);
                            $list.hide().empty();
                            self.applyFilters();
                        });
                        $li.on('mouseenter', function () { activeIdx = i; refreshActive(); });
                        $list.append($li);
                    });
                    $list.show();
                }

                function refreshActive() {
                    // #1049 Option 2 v4.221.0 - vanilla DOM. Fires on every
                    // arrow-key press in a typeahead; was $list.children('li')
                    // jQuery selector parse + .each() + $(this).css() per
                    // item. Native: children NodeList + el.style.background.
                    var lis = $list[0].children;
                    for (var i = 0, n = lis.length; i < n; i++) {
                        lis[i].style.background = (i === activeIdx) ? '#f0f6fc' : 'transparent';
                    }
                }

                function fetchSuggestions(q) {
                    if (inflight) { inflight.abort(); }
                    inflight = $.ajax({
                        url: ajaxUrl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'gt_get_filter_suggestions',
                            nonce: nonce,
                            form_id: formId,
                            field_id: fieldId,
                            q: q,
                            limit: 50
                        }
                    }).done(function (resp) {
                        var results = (resp && resp.success && resp.data && resp.data.results) ? resp.data.results : [];
                        renderResults(results);
                    }).fail(function (xhr) {
                        /* c8 ignore next */
                        if (xhr && xhr.statusText !== 'abort') { $list.hide(); }
                    });
                }

                $input.on('input.gtTypeahead', function () {
                    var q = String($input.val() || '').trim();
                    clearTimeout(debounceT);
                    debounceT = setTimeout(function () { fetchSuggestions(q); }, 200);
                });

                $input.on('focus.gtTypeahead', function () {
                    fetchSuggestions(String($input.val() || '').trim());
                });

                $input.on('blur.gtTypeahead', function () {
                    // Delay so click on a suggestion can fire first.
                    setTimeout(function () { $list.hide(); }, 200);
                });

                $input.on('keydown.gtTypeahead', function (e) {
                    var $items = $list.children('li');
                    if (e.key === 'ArrowDown' && $items.length) {
                        e.preventDefault();
                        activeIdx = (activeIdx + 1) % $items.length;
                        refreshActive();
                        $list.show();
                    } else if (e.key === 'ArrowUp' && $items.length) {
                        e.preventDefault();
                        activeIdx = activeIdx <= 0 ? $items.length - 1 : activeIdx - 1;
                        refreshActive();
                    } else if (e.key === 'Enter') {
                        if (activeIdx >= 0 && $items.eq(activeIdx).length) {
                            e.preventDefault();
                            $items.eq(activeIdx).trigger('click');
                        }
                        // Otherwise fall through to GT's existing keypress→applyFilters handler.
                    } else if (e.key === 'Escape') {
                        $list.hide().empty();
                    }
                });
            });
        }

    });

})(window);
