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

            allRows.forEach(function (r) { r.style.display = 'none'; });
            rows.slice(start, start + perPage).forEach(function (r) { r.style.display = ''; });

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

    function init() {
        var tables = document.querySelectorAll('table.gt-table[class*="-source-table"]');
        Array.prototype.forEach.call(tables, enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
