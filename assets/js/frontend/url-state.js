/**
 * TableCrafter - frontend/url-state.js
 *
 * URL drilldown state sync. Twentieth slice under #833. Two methods,
 * ~46 lines.
 *
 *   - updateDrilldownUrlState() - serialize this.drilldownFilters to
 *     ?gt_df=col:val,col:val via history.pushState.
 *   - applyUrlFilters() - parse the inverse on init: read the
 *     drilldownFilters config block + DOM data-attrs and seed
 *     this.filters.
 */
(function (window) {
    'use strict';

    if (typeof window.GravityTable !== 'function') {
        window.GravityTable = function GravityTable() {};
    }

    var $ = window.jQuery;

    GravityTable.prototype.updateDrilldownUrlState = function () {
        if (typeof history.pushState === 'undefined') return;
        var filters = this.drilldownFilters || [];
        var parts = [];
        for (var i = 0; i < filters.length; i++) {
            var f = filters[i];
            if (f && f.col) {
                // colon-separated, comma-joined. encodeURIComponent for the value.
                parts.push(f.col + ':' + encodeURIComponent(f.value || ''));
            }
        }
        var qs = parts.join(',');
        var url = new URL(window.location.href);
        if (qs === '') {
            url.searchParams.delete('gt_df');
        } else {
            url.searchParams.set('gt_df', qs);
        }
        history.pushState(null, '', url.toString());
    };

    // TC_URL_Filter_Service - pre-populate per-column filter inputs and
    // seed this.filters from URL params. config.url_filters arrives as a
    // flat { column_id => sanitized_value } map (server-side parsed via
    // TC_URL_Filter_Service::parse_filters). Empty / disabled = no-op.
    GravityTable.prototype.applyUrlFilters = function () {
        if (!this.config || !this.config.url_filters) return;
        var $wrapper = $('#' + this.wrapperId);
        var urlFilters = this.config.url_filters;
        if (!urlFilters || typeof urlFilters !== 'object') return;
        for (var colId in urlFilters) {
            if (!Object.prototype.hasOwnProperty.call(urlFilters, colId)) continue;
            var val = urlFilters[colId];
            if (val == null || val === '') continue;
            // Pre-populate the visible filter input so the customer sees
            // what's filtered. id="gt-filter-{field_id}" - same selector
            // template uses (text + lookup + select all share this id).
            var $input = $wrapper.find('#gt-filter-' + colId);
            if ($input.length) {
                $input.val(val);
            }
            // Seed this.filters in the shape the AJAX backend strips of
            // its 'filter_' prefix at class-tc-ajax.php (text-filter case).
            this.filters['filter_' + colId] = { type: 'text', value: val };
        }
    };

})(window);
