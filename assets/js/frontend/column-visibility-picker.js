(function (window) {
    'use strict';

    if (!window.GravityTable) { return; }

    function storageKey(tableId) {
        return 'gt_col_vis_' + tableId;
    }

    GravityTable.prototype.toggleColumnVisibility = function (fieldId, visible) {
        var wrapper = document.getElementById(this.wrapperId);
        if (!wrapper) { return; }
        var display = visible ? '' : 'none';
        var th = wrapper.querySelector('th[data-field-id="' + fieldId + '"]');
        if (th) { th.style.display = display; }
        var tds = wrapper.querySelectorAll('td[data-field-id="' + fieldId + '"]');
        for (var i = 0; i < tds.length; i++) { tds[i].style.display = display; }

        var key = storageKey(this.config && this.config.table_id || '');
        var stored = {};
        try { stored = JSON.parse(window.localStorage.getItem(key) || '{}'); } catch (e) {}
        stored[String(fieldId)] = visible;
        try { window.localStorage.setItem(key, JSON.stringify(stored)); } catch (e) {}
    };

    GravityTable.prototype.restoreColumnVisibility = function () {
        var key = storageKey(this.config && this.config.table_id || '');
        var stored = {};
        try { stored = JSON.parse(window.localStorage.getItem(key) || '{}'); } catch (e) {}
        for (var fieldId in stored) {
            if (Object.prototype.hasOwnProperty.call(stored, fieldId) && stored[fieldId] === false) {
                this.toggleColumnVisibility(fieldId, false);
            }
        }
    };

    GravityTable.prototype.initColumnPicker = function () {
        var config = this.config || {};
        if (!config.show_column_picker) { return; }
        var wrapper = document.getElementById(this.wrapperId);
        if (!wrapper) { return; }
        var toolbar = wrapper.querySelector('.gt-table-controls');
        if (!toolbar) { return; }

        var self = this;
        var tableId = config.table_id || '';
        var storedKey = storageKey(tableId);
        var stored = {};
        try { stored = JSON.parse(window.localStorage.getItem(storedKey) || '{}'); } catch (e) {}

        var ths = wrapper.querySelectorAll('th[data-field-id]');
        if (!ths.length) { return; }

        var pickerWrap = document.createElement('div');
        pickerWrap.className = 'gt-column-picker';
        pickerWrap.style.position = 'relative';
        pickerWrap.style.display = 'inline-block';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'gt-column-picker-btn gt-toolbar-btn';
        btn.textContent = 'Columns';
        btn.setAttribute('aria-expanded', 'false');

        var dropdown = document.createElement('div');
        dropdown.className = 'gt-column-picker-dropdown';
        dropdown.style.display = 'none';
        dropdown.style.position = 'absolute';
        dropdown.style.background = '#fff';
        dropdown.style.border = '1px solid #ccc';
        dropdown.style.borderRadius = '4px';
        dropdown.style.padding = '8px';
        dropdown.style.zIndex = '999';
        dropdown.style.minWidth = '150px';
        dropdown.style.boxShadow = '0 2px 8px rgba(0,0,0,.12)';
        dropdown.style.top = '100%';
        dropdown.style.left = '0';

        for (var i = 0; i < ths.length; i++) {
            var th = ths[i];
            var fieldId = th.getAttribute('data-field-id');
            var label = th.textContent.trim();
            var isVisible = stored.hasOwnProperty(fieldId) ? stored[fieldId] !== false : true;

            var item = document.createElement('label');
            item.className = 'gt-column-picker-item';
            item.style.display = 'flex';
            item.style.alignItems = 'center';
            item.style.gap = '6px';
            item.style.cursor = 'pointer';
            item.style.padding = '3px 0';
            item.style.whiteSpace = 'nowrap';

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = isVisible;
            cb.setAttribute('data-field-id', fieldId);

            (function (fid) {
                cb.addEventListener('change', function () {
                    self.toggleColumnVisibility(fid, this.checked);
                });
            })(fieldId);

            item.appendChild(cb);
            item.appendChild(document.createTextNode(label));
            dropdown.appendChild(item);
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = dropdown.style.display !== 'none';
            dropdown.style.display = isOpen ? 'none' : 'block';
            btn.setAttribute('aria-expanded', String(!isOpen));
        });

        document.addEventListener('click', function () {
            dropdown.style.display = 'none';
            btn.setAttribute('aria-expanded', 'false');
        });

        pickerWrap.appendChild(btn);
        pickerWrap.appendChild(dropdown);
        toolbar.appendChild(pickerWrap);
    };

})(window);
