/**
 * gt-external-interactive.js — #1960
 *
 * Progressive enhancement for static external-source tables (JSON / CSV / XML /
 * Google Sheets / Airtable / Notion / External DB). Adds click-to-sort headers,
 * client-side pagination (data-per-page), and a search filter — no dependencies,
 * no build step. Degrades gracefully: without JS the full static table still
 * renders.
 */
(function () {
    'use strict';

    function makeButton(label, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'button gt-ext-pager-btn';
        b.textContent = label;
        b.addEventListener('click', onClick);
        return b;
    }

    function csvCell(s) {
        s = (s == null) ? '' : String(s);
        return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }

    function enhance(table) {
        if (table.__gtEnhanced || !table.tBodies[0]) { return; }
        table.__gtEnhanced = true;

        var tbody   = table.tBodies[0];
        var allRows = Array.prototype.slice.call(tbody.rows);
        var perPage = parseInt(table.getAttribute('data-per-page'), 10) || 25;
        var page = 1, filter = '', sortCol = -1, sortDir = 1;

        // #2142 — honour the shortcode toggles emitted as data-attributes.
        var showSearch = table.getAttribute('data-search') !== 'false';
        var showExport = table.getAttribute('data-export') === 'true';

        var wrap = table.parentNode;
        var head = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0].cells : [];

        // #2142 — export the filtered + sorted rows (what the user sees) to CSV.
        function exportCsv() {
            var cols = Array.prototype.map.call(head, function (th) { return th.textContent.trim(); });
            var lines = [cols.map(csvCell).join(',')];
            visibleRows().forEach(function (r) {
                lines.push(Array.prototype.map.call(r.cells, function (c) {
                    return csvCell(c.textContent.trim());
                }).join(','));
            });
            var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href = url;
            a.download = 'tablecrafter-export.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        }

        // Toolbar (search + export) above the table.
        if (showSearch || showExport) {
            var toolbar = document.createElement('div');
            toolbar.className = 'gt-ext-toolbar';
            wrap.insertBefore(toolbar, table);

            if (showSearch) {
                var search = document.createElement('input');
                search.type = 'search';
                search.className = 'gt-ext-search';
                search.placeholder = 'Search…';
                search.setAttribute('aria-label', 'Search table');
                toolbar.appendChild(search);
                search.addEventListener('input', function () {
                    filter = search.value.toLowerCase();
                    page = 1;
                    render();
                });
            }

            if (showExport) {
                var exportBtn = makeButton('Export CSV', exportCsv);
                exportBtn.className = 'button gt-ext-export-btn';
                toolbar.appendChild(exportBtn);
            }
        }

        // Sortable headers.
        Array.prototype.forEach.call(head, function (th, i) {
            th.style.cursor = 'pointer';
            th.setAttribute('role', 'button');
            th.title = 'Sort by this column';
            th.addEventListener('click', function () {
                if (sortCol === i) { sortDir = -sortDir; } else { sortCol = i; sortDir = 1; }
                page = 1;
                render();
            });
        });

        // Pager container (after the table).
        var pager = document.createElement('div');
        pager.className = 'gt-ext-pager';
        if (table.nextSibling) { wrap.insertBefore(pager, table.nextSibling); } else { wrap.appendChild(pager); }

        function visibleRows() {
            var rows = allRows;
            if (filter) {
                rows = rows.filter(function (r) { return r.textContent.toLowerCase().indexOf(filter) !== -1; });
            }
            if (sortCol >= 0) {
                rows = rows.slice().sort(function (a, b) {
                    var x = (a.cells[sortCol] ? a.cells[sortCol].textContent : '').trim();
                    var y = (b.cells[sortCol] ? b.cells[sortCol].textContent : '').trim();
                    var nx = parseFloat(x), ny = parseFloat(y);
                    if (!isNaN(nx) && !isNaN(ny) && x !== '' && y !== '') { return (nx - ny) * sortDir; }
                    return x.localeCompare(y) * sortDir;
                });
            }
            return rows;
        }

        function render() {
            var rows  = visibleRows();
            var pages = Math.max(1, Math.ceil(rows.length / perPage));
            if (page > pages) { page = pages; }
            var start = (page - 1) * perPage;

            // #2131 — DOM virtualization: keep ONLY the current page's rows in
            // the DOM instead of leaving every row attached (display:none). For
            // large datasets (thousands of rows) this keeps the live tbody tiny,
            // so sort/filter/paginate stay fast and layout/reflow cost is bounded
            // by per_page, not row count. Off-page rows live in the `allRows`
            // array (detached but referenced), so sort/search/export still see
            // the full set.
            while (tbody.firstChild) { tbody.removeChild(tbody.firstChild); }
            var frag = document.createDocumentFragment();
            rows.slice(start, start + perPage).forEach(function (r) {
                r.style.display = '';
                frag.appendChild(r);
            });
            tbody.appendChild(frag);

            pager.innerHTML = '';
            var info = document.createElement('span');
            info.className = 'gt-ext-pager-info';
            info.textContent = rows.length
                ? ('Showing ' + (start + 1) + '–' + Math.min(start + perPage, rows.length) + ' of ' + rows.length)
                : 'No matching entries';
            pager.appendChild(info);

            if (pages > 1) {
                var prev = makeButton('‹ Prev', function () { if (page > 1) { page--; render(); } });
                var next = makeButton('Next ›', function () { if (page < pages) { page++; render(); } });
                prev.disabled = (page === 1);
                next.disabled = (page === pages);
                pager.appendChild(prev);
                pager.appendChild(next);
            }
        }

        render();
    }

    // #2143 — legacy inline auto-refresh. Re-fetch the source via admin-ajax on
    // a timer and swap the rendered table in place, restoring 3.5.x behavior.
    function setupAutoRefresh(wrapper) {
        if (wrapper.__gtRefreshing) { return; }
        var cfg = (typeof window !== 'undefined' && window.gtExtRefresh) ? window.gtExtRefresh : null;
        if (!cfg || !cfg.ajaxurl) { return; }

        var interval = parseInt(wrapper.getAttribute('data-refresh-interval'), 10) || 300000;
        if (interval < 5000) { interval = 5000; }
        var showIndicator   = wrapper.getAttribute('data-refresh-indicator') !== 'false';
        var showCountdown   = wrapper.getAttribute('data-refresh-countdown') === 'true';
        var showLastUpdated = wrapper.getAttribute('data-refresh-last-updated') !== 'false';
        var attsJson        = wrapper.getAttribute('data-refresh-atts') || '{}';
        wrapper.__gtRefreshing = true;

        var status = document.createElement('div');
        status.className = 'gt-ext-refresh-status';
        wrapper.appendChild(status);

        var remaining = Math.round(interval / 1000);
        function paint(state) {
            var bits = [];
            if (showIndicator && state === 'loading') { bits.push('⟳ Refreshing…'); }
            if (showLastUpdated && status.__last) { bits.push('Updated ' + status.__last); }
            if (showCountdown && state !== 'loading') { bits.push('Next refresh in ' + remaining + 's'); }
            status.textContent = bits.join('  ·  ');
        }

        function fetchOnce() {
            paint('loading');
            var body = new URLSearchParams();
            body.set('action', 'gt_inline_refresh');
            body.set('nonce', cfg.nonce || '');
            body.set('atts', attsJson);
            fetch(cfg.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (json) {
                if (!json || !json.success || !json.data || !json.data.html) { paint('idle'); return; }
                var doc = document.createElement('div');
                doc.innerHTML = json.data.html;
                var fresh = doc.querySelector('table.gt-table[class*="-source-table"]');
                var current = wrapper.querySelector('table.gt-table[class*="-source-table"]');
                if (fresh && current) {
                    // Drop the enhancement chrome the old table created, swap the
                    // table node, then re-enhance the fresh one.
                    var old = wrapper.querySelectorAll('.gt-ext-toolbar, .gt-ext-pager');
                    Array.prototype.forEach.call(old, function (n) { n.parentNode.removeChild(n); });
                    current.parentNode.replaceChild(fresh, current);
                    enhance(fresh);
                }
                try {
                    var d = new Date((json.data.updated ? json.data.updated * 1000 : Date.now()));
                    status.__last = d.toLocaleTimeString();
                } catch (e) { status.__last = ''; }
                remaining = Math.round(interval / 1000);
                paint('idle');
            }).catch(function () { paint('idle'); });
        }

        setInterval(fetchOnce, interval);
        if (showCountdown) {
            setInterval(function () {
                remaining -= 1;
                if (remaining < 0) { remaining = Math.round(interval / 1000); }
                paint('idle');
            }, 1000);
        }
        paint('idle');
    }

    function init() {
        var tables = document.querySelectorAll('table.gt-table[class*="-source-table"]');
        Array.prototype.forEach.call(tables, enhance);

        var autoWrappers = document.querySelectorAll('[data-auto-refresh="true"]');
        Array.prototype.forEach.call(autoWrappers, setupAutoRefresh);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
