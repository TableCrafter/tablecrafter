/**
 * TableCrafter — frontend/edit-ajs-lookup.js
 *
 * Search-as-you-type inline editor for AJS Toolkit client / pit /
 * destination / material lookups. Fourth slice under #833.
 *
 * Public surface (attached directly to GravityTable.prototype):
 *
 *   - editAjsToolkitLookupField($field, fieldId, currentValue, entryId)
 *
 * The editor:
 *   1. Inspects this.config.lookup_fields[fieldId].table to pick the
 *      lookup type ('clients' / 'pits' / 'destinations' / 'materials').
 *   2. Renders a text input + hidden suggestion <ul> inside $field.
 *   3. Debounces typing at 200ms, calls the toolkit's
 *      ajs_toolkit_search AJAX endpoint with q + type + nonce.
 *   4. On suggestion click: writes the text and calls this.saveField.
 *   5. On Enter: saves the current input value via this.saveField.
 *   6. On Escape: restores the original value, restores original-padding.
 *   7. On blur (200ms grace): saves if changed, otherwise restores.
 *   8. Focus + select-all on entry (50ms setTimeout to ensure the DOM
 *      has settled before setSelectionRange).
 *
 * Pairs with edit-save.js (slice 2) — every save path routes through
 * this.saveField on the prototype.
 */
(function (window) {
    'use strict';

    /* c8 ignore next 4 */
    if (typeof window.GravityTable !== 'function') {
        // Stub constructor; frontend.js preserves prototype on replace.
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    /**
     * Search-as-you-type inline edit for AJS Toolkit client lookup (matches frontend form behavior).
     */
    GravityTable.prototype.editAjsToolkitLookupField = function ($field, fieldId, currentValue, entryId) {
        var self = this;
        var tk = this.config.ajs_toolkit || {};
        var lf = this.config.lookup_fields && this.config.lookup_fields[fieldId];
        var lookupType = 'clients';
        if (lf && lf.table && String(lf.table).indexOf('ajs_source_pits') !== -1) {
            lookupType = 'pits';
        } else if (lf && lf.table && String(lf.table).indexOf('ajs_load_destinations') !== -1) {
            lookupType = 'destinations';
        } else if (lf && lf.table && String(lf.table).indexOf('ajs_materials') !== -1) {
            lookupType = 'materials';
        }

        var wrap = '<div class="gt-ajs-lookup-wrap" style="position:relative;width:100%;min-width:160px;">';
        wrap += '<input type="text" class="gt-edit-input gt-ajs-lookup-input" autocomplete="off" inputmode="search" value="' + self.escapeHtml(currentValue) + '" />';
        wrap += '<ul class="gt-ajs-lookup-suggest" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:10060;max-height:220px;overflow:auto;background:#fff;border:1px solid #0073aa;list-style:none;margin:0;padding:0;"></ul>';
        wrap += '</div>';

        $field.html(wrap);
        $field.addClass('gt-editing');

        var $input = $field.find('.gt-ajs-lookup-input');
        var $ul = $field.find('.gt-ajs-lookup-suggest');
        var debounceT;

        function runSearch(term) {
            $.get(tk.ajax_url, {
                action: 'ajs_toolkit_search',
                q: term,
                type: lookupType,
                nonce: tk.nonce
            }).done(function (data) {
                $ul.empty();
                var results = (data && data.results) ? data.results : [];
                if (!results.length) {
                    $ul.hide();
                    return;
                }
                results.forEach(function (row) {
                    var text = row.text || row.id || '';
                    var $li = $('<li/>').css({ padding: '8px 10px', cursor: 'pointer' }).text(text);
                    $li.on('mousedown', function (e) {
                        /* c8 ignore next */
                        e.preventDefault();
                    });
                    $li.on('click', function () {
                        $input.val(text);
                        $ul.hide().empty();
                        self.saveField(entryId, fieldId, text, $field);
                    });
                    $ul.append($li);
                });
                $ul.show();
            });
        }

        $input.on('input', function () {
            clearTimeout(debounceT);
            var v = String($(this).val() || '').trim();
            debounceT = setTimeout(function () {
                if (v.length < 1) {
                    $ul.hide().empty();
                    return;
                }
                runSearch(v);
            }, 200);
        });

        $input.on('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                $field.html(self.escapeHtml(currentValue));
                if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                    $field.css('padding', $field.data('original-padding'));
                    $field.removeData('original-padding');
                }
                $field.removeClass('gt-editing');
            } else if (e.key === 'Enter') {
                e.preventDefault();
                self.saveField(entryId, fieldId, $input.val(), $field);
            }
        });

        $input.on('blur', function () {
            setTimeout(function () {
                if (!$field.find('.gt-ajs-lookup-input').length) {
                    /* c8 ignore next */
                    return;
                }
                $ul.hide();
                var nv = $input.val();
                var ov = $field.data('original-value') || '';
                if (nv !== ov) {
                    self.saveField(entryId, fieldId, nv, $field);
                } else {
                    $field.html(self.escapeHtml(ov));
                    if ($field.hasClass('gt-editable-cell') && $field.data('original-padding')) {
                        $field.css('padding', $field.data('original-padding'));
                        $field.removeData('original-padding');
                    }
                    $field.removeClass('gt-editing');
                }
            }, 200);
        });

        setTimeout(function () {
            $input.focus();
            if ($input[0] && $input[0].setSelectionRange) {
                $input[0].setSelectionRange(0, $input.val().length);
            }
        }, 50);
    };

})(window);
