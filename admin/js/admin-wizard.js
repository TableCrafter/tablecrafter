/* TableCrafter - Table Creation Wizard JS (#1977) */
(function () {
    var state = {
        step:       1,
        source:     '',       // gravity_forms | json | woocommerce_products
        title:      '',
        formId:     0,
        formTitle:  '',
        jsonUrl:    '',
        jsonTested: false,
        remoteUrl:    '',     // #2039 - csv / google_sheets URL
        remoteTested: false,
        columns:    [],       // [{key, label, type}]
        perPage:    25,
        search:     true,
        mobile:     true,
    };

    var TOTAL_STEPS = 5;
    var SOURCE_LABELS = {
        gravity_forms:       'Gravity Forms',
        json:                'JSON / REST API',
        csv:                 'CSV file or URL',
        google_sheets:       'Google Sheets',
        woocommerce_products:'WooCommerce Products',
    };

    /* ── Boot ────────────────────────────────────────────────────────────── */
    function init() {
        populateGFForms();
        bindSourceCards();
        bindStep2Fields();
        bindStep3Controls();
        bindNavButtons();
        updateNav();
    }

    /* ── Populate GF form dropdown ───────────────────────────────────────── */
    function populateGFForms() {
        var raw = document.getElementById('gt-wizard-forms-data');
        if (!raw) { return; }
        var forms;
        try { forms = JSON.parse(raw.textContent || raw.innerHTML); } catch(e) { return; }
        var select = document.getElementById('gt-wizard-form-id');
        if (!select || !forms || !forms.length) { return; }
        forms.forEach(function (f) {
            var opt = document.createElement('option');
            opt.value = f.id;
            opt.textContent = f.title;
            select.appendChild(opt);
        });
    }

    /* ── Step 1 - Source cards ───────────────────────────────────────────── */
    function bindSourceCards() {
        document.querySelectorAll('.gt-wizard-source-card:not(.gt-wizard-source-card--advanced)').forEach(function (card) {
            card.addEventListener('click', function () {
                document.querySelectorAll('.gt-wizard-source-card').forEach(function (c) {
                    c.classList.remove('gt-wizard-source-card--selected');
                });
                card.classList.add('gt-wizard-source-card--selected');
                var radio = card.querySelector('input[type="radio"]');
                if (radio) { radio.checked = true; }
                state.source = card.dataset.source || '';
                hideValidation(1);
            });
        });
    }

    /* ── Step 2 - Fields ─────────────────────────────────────────────────── */
    function bindStep2Fields() {
        var titleInput = document.getElementById('gt-wizard-table-title');
        if (titleInput) {
            titleInput.addEventListener('input', function () {
                state.title = titleInput.value.trim();
                hideValidation(2);
            });
        }

        var formSelect = document.getElementById('gt-wizard-form-id');
        if (formSelect) {
            formSelect.addEventListener('change', function () {
                state.formId    = parseInt(formSelect.value, 10) || 0;
                state.formTitle = formSelect.options[formSelect.selectedIndex]
                    ? formSelect.options[formSelect.selectedIndex].text
                    : '';
                hideValidation(2);
                /* Clear previously-loaded columns when form changes */
                state.columns = [];
                resetStep3UI();
            });
        }

        var jsonInput = document.getElementById('gt-wizard-json-url');
        if (jsonInput) {
            jsonInput.addEventListener('input', function () {
                state.jsonUrl    = jsonInput.value.trim();
                state.jsonTested = false;
                hideValidation(2);
            });
        }

        var testBtn = document.querySelector('.gt-wizard-test-json');
        if (testBtn) { testBtn.addEventListener('click', testJsonConnection); }

        // #2039 - CSV / Google Sheets URL inputs + generic remote Test Connection.
        ['gt-wizard-csv-url', 'gt-wizard-sheets-url'].forEach(function (id) {
            var input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', function () {
                    state.remoteUrl    = input.value.trim();
                    state.remoteTested = false;
                    hideValidation(2);
                });
            }
        });
        document.querySelectorAll('.gt-wizard-test-remote').forEach(function (btn) {
            btn.addEventListener('click', function () { testRemoteConnection(btn); });
        });
    }

    /* ── JSON test connection ─────────────────────────────────────────────── */
    function testJsonConnection() {
        var btn    = document.querySelector('.gt-wizard-test-json');
        var result = document.querySelector('.gt-wizard-test-result');
        if (!btn || !result) { return; }

        if (!state.jsonUrl) {
            showTestResult(result, 'error', 'Please enter a JSON URL first.');
            return;
        }

        btn.classList.add('is-loading');
        btn.disabled = true;
        result.style.display = 'none';

        var data = new FormData();
        data.append('action', 'gt_preview_json_source');
        data.append('nonce',  gtWizardData.nonce);
        data.append('url', state.jsonUrl);

        fetch(gtWizardData.ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                if (resp.success) {
                    state.jsonTested = true;
                    showTestResult(result, 'success', '✓ Connected successfully.');
                    /* Store column keys from preview for Step 3 */
                    if (resp.data && resp.data.columns) {
                        state.columns = resp.data.columns.map(function (c) {
                            var key   = String(c.id    || '');
                            var label = String(c.label || key);
                            var type  = String(c.type  || 'text');
                            return { key: key, label: label, type: type };
                        });
                    }
                } else {
                    showTestResult(result, 'error', resp.data && resp.data.message ? resp.data.message : 'Connection failed.');
                }
            })
            .catch(function () {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                showTestResult(result, 'error', 'Network error. Please try again.');
            });
    }

    /* ── CSV / Google Sheets test connection (#2039) ──────────────────────── */
    function testRemoteConnection(btn) {
        var branch = btn.closest('.gt-wizard-branch');
        var result = branch ? branch.querySelector('.gt-wizard-test-result') : null;
        if (!result) { return; }

        if (!state.remoteUrl) {
            showTestResult(result, 'error', 'Please enter a URL first.');
            return;
        }

        btn.classList.add('is-loading');
        btn.disabled = true;
        result.style.display = 'none';

        var data = new FormData();
        data.append('action',      'gt_preview_remote_source');
        data.append('nonce',       gtWizardData.nonce);
        data.append('source_type', btn.dataset.sourceType || '');
        data.append('url',         state.remoteUrl);

        fetch(gtWizardData.ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                if (resp.success) {
                    state.remoteTested = true;
                    showTestResult(result, 'success', '✓ Connected successfully.');
                    if (resp.data && resp.data.columns) {
                        state.columns = resp.data.columns.map(function (c) {
                            var key   = String(c.id    || '');
                            var label = String(c.label || key);
                            var type  = String(c.type  || 'text');
                            return { key: key, label: label, type: type };
                        });
                    }
                } else {
                    showTestResult(result, 'error', resp.data && resp.data.message ? resp.data.message : 'Connection failed.');
                }
            })
            .catch(function () {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                showTestResult(result, 'error', 'Network error. Please try again.');
            });
    }

    function showTestResult(el, type, msg) {
        el.className = 'gt-wizard-test-result ' + type;
        el.textContent = msg;
        el.style.display = 'block';
    }

    /* ── Step 3 - Column picker ──────────────────────────────────────────── */
    function resetStep3UI() {
        var list    = document.querySelector('.gt-wizard-cols-list');
        var loading = document.querySelector('.gt-wizard-cols-loading');
        var error   = document.querySelector('.gt-wizard-cols-error');
        if (list)    { list.innerHTML = ''; list.style.display = 'none'; }
        if (loading) { loading.style.display = 'none'; }
        if (error)   { error.style.display = 'none'; }
        updateColCount();
    }

    function loadColumns() {
        if ((state.source === 'json' || state.source === 'csv' || state.source === 'google_sheets') && state.columns.length) {
            renderColumnList(state.columns, true);
            return;
        }
        if (state.source === 'woocommerce_products') {
            renderColumnList(wcDefaultColumns(), true);
            return;
        }
        if (state.source === 'gravity_forms' && state.formId) {
            fetchGFFields();
            return;
        }
        resetStep3UI();
    }

    function wcDefaultColumns() {
        return [
            { key: 'title',       label: 'Product Name',  type: 'text' },
            { key: 'price',       label: 'Price',         type: 'number' },
            { key: 'sku',         label: 'SKU',           type: 'text' },
            { key: 'stock_status',label: 'Stock Status',  type: 'text' },
            { key: 'categories',  label: 'Categories',    type: 'text' },
            { key: 'description', label: 'Description',   type: 'text' },
        ];
    }

    function fetchGFFields() {
        var list    = document.querySelector('.gt-wizard-cols-list');
        var loading = document.querySelector('.gt-wizard-cols-loading');
        var error   = document.querySelector('.gt-wizard-cols-error');
        if (loading) { loading.style.display = 'flex'; }
        if (list)    { list.style.display = 'none'; }
        if (error)   { error.style.display = 'none'; }

        var data = new FormData();
        data.append('action',  'gt_get_form_fields');
        data.append('nonce',   gtWizardData.nonce);
        data.append('form_id', state.formId);

        fetch(gtWizardData.ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (loading) { loading.style.display = 'none'; }
                /* get_form_fields returns wp_send_json_success($fields_array) - 
                   resp.data is the array directly, not resp.data.fields */
                if (resp.success && Array.isArray(resp.data) && resp.data.length) {
                    var cols = resp.data.map(function (f) {
                        return { key: String(f.id), label: f.label || String(f.id), type: f.type || 'text' };
                    });
                    renderColumnList(cols, true);
                } else {
                    var errMsg = (typeof resp.data === 'string') ? resp.data : 'Could not load fields.';
                    showColError(error, errMsg);
                }
            })
            .catch(function () {
                if (loading) { loading.style.display = 'none'; }
                showColError(error, 'Network error. Please try again.');
            });
    }

    function showColError(el, msg) {
        if (!el) { return; }
        el.querySelector('.gt-wizard-cols-error-msg').textContent = msg;
        el.style.display = 'flex';
    }

    function renderColumnList(cols, allChecked) {
        var list    = document.querySelector('.gt-wizard-cols-list');
        var loading = document.querySelector('.gt-wizard-cols-loading');
        if (!list) { return; }
        if (loading) { loading.style.display = 'none'; }

        list.innerHTML = '';
        cols.forEach(function (col) {
            var id   = 'gt-wz-col-' + col.key;
            var item = document.createElement('label');
            item.className = 'gt-wizard-col-item';
            item.innerHTML =
                '<input type="checkbox" id="' + escAttr(id) + '"' +
                ' value="' + escAttr(col.key) + '"' +
                (allChecked ? ' checked' : '') + '>' +
                '<span class="gt-wizard-col-label">' + escHtml(col.label) + '</span>' +
                '<span class="gt-wizard-col-type">' + escHtml(col.type) + '</span>';
            list.appendChild(item);
        });

        list.style.display = 'flex';

        /* Build initial state from checked boxes */
        syncColumnsFromList();
        updateColCount();
        list.addEventListener('change', function () {
            syncColumnsFromList();
            updateColCount();
            hideValidation(3);
        });
    }

    function syncColumnsFromList() {
        var list = document.querySelector('.gt-wizard-cols-list');
        if (!list) { return; }
        var fullCols = [];
        list.querySelectorAll('.gt-wizard-col-item').forEach(function (item) {
            var cb    = item.querySelector('input[type="checkbox"]');
            var label = item.querySelector('.gt-wizard-col-label');
            var type  = item.querySelector('.gt-wizard-col-type');
            if (cb && cb.checked) {
                fullCols.push({ key: cb.value, label: label ? label.textContent : cb.value, type: type ? type.textContent : 'text' });
            }
        });
        state.columns = fullCols;
    }

    function updateColCount() {
        var el = document.querySelector('.gt-wizard-col-count');
        if (!el) { return; }
        var n = state.columns.length;
        el.textContent = n + ' column' + (n !== 1 ? 's' : '') + ' selected';
    }

    function bindStep3Controls() {
        var selAll   = document.querySelector('.gt-wizard-select-all');
        var deselAll = document.querySelector('.gt-wizard-deselect-all');
        if (selAll) {
            selAll.addEventListener('click', function () {
                document.querySelectorAll('.gt-wizard-cols-list input[type="checkbox"]').forEach(function (cb) { cb.checked = true; });
                syncColumnsFromList();
                updateColCount();
            });
        }
        if (deselAll) {
            deselAll.addEventListener('click', function () {
                document.querySelectorAll('.gt-wizard-cols-list input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
                syncColumnsFromList();
                updateColCount();
            });
        }
    }

    /* ── Step 4 - Display options ────────────────────────────────────────── */
    function syncStep4() {
        var perPage = document.getElementById('gt-wizard-per-page');
        var search  = document.getElementById('gt-wizard-search');
        var mobile  = document.getElementById('gt-wizard-mobile-card');
        if (perPage) { state.perPage = parseInt(perPage.value, 10) || 25; }
        if (search)  { state.search  = search.checked; }
        if (mobile)  { state.mobile  = mobile.checked; }
    }

    /* ── Step 5 - Review summary ─────────────────────────────────────────── */
    function populateSummary() {
        syncStep4();
        setValue('source',     SOURCE_LABELS[state.source] || state.source);
        setValue('title',      state.title);
        setValue('connection', state.source === 'gravity_forms'
            ? (state.formTitle || 'Form #' + state.formId)
            : state.source === 'json'
            ? (state.jsonUrl || ' - ')
            : (state.source === 'csv' || state.source === 'google_sheets')
            ? (state.remoteUrl || ' - ')
            : 'Product catalog');
        setValue('columns',    state.columns.length
            ? state.columns.length + ' selected: ' +
              state.columns.slice(0, 5).map(function (c) { return c.label; }).join(', ') +
              (state.columns.length > 5 ? '…' : '')
            : 'None');
        setValue('per_page',   state.perPage);
        setValue('search',     state.search ? 'On' : 'Off');
        setValue('mobile',     state.mobile ? 'On (card view)' : 'Off');
    }

    function setValue(key, val) {
        var el = document.querySelector('[data-summary="' + key + '"]');
        if (el) { el.textContent = val; }
    }

    /* ── Create table (AJAX) ─────────────────────────────────────────────── */
    function createTable() {
        var createBtn = document.querySelector('.gt-wizard-btn-create');
        var errBox    = document.querySelector('.gt-wizard-create-error');
        var errMsg    = document.querySelector('.gt-wizard-create-error-msg');
        var successEl = document.querySelector('.gt-wizard-success');
        var summaryEl = document.querySelector('.gt-wizard-summary');

        if (errBox)  { errBox.style.display = 'none'; }
        if (createBtn) {
            createBtn.classList.add('is-loading');
            createBtn.disabled = true;
            createBtn.textContent = 'Creating…';
        }

        var colKeys    = state.columns.map(function (c) { return c.key; });
        var colLabels  = {};
        state.columns.forEach(function (c) { colLabels[c.key] = c.label; });

        var settings = {
            data_source_type: state.source,
            per_page:         state.perPage,
            show_search:      state.search ? '1' : '0',
            responsive_mode:  state.mobile ? 'basic' : 'disabled',
            responsive_table: state.mobile ? '1' : '0',
        };

        if (state.source === 'gravity_forms') {
            settings.form_id = state.formId;
        }
        if (state.source === 'json') {
            settings.json_url = state.jsonUrl;
        }
        if (state.source === 'csv') {
            settings.csv_url = state.remoteUrl;
        }
        if (state.source === 'google_sheets') {
            settings.google_sheets_url = state.remoteUrl;
        }

        var data = new FormData();
        data.append('action',     'gt_save_table');
        data.append('nonce',      gtWizardData.nonce);
        data.append('title',      state.title);
        data.append('form_id',    state.formId || 0);
        data.append('table_id',   '');

        /* settings[] array */
        Object.keys(settings).forEach(function (k) {
            data.append('settings[' + k + ']', settings[k]);
        });
        /* selected_fields[] */
        colKeys.forEach(function (k) {
            data.append('selected_fields[]', k);
        });
        /* field_labels[] */
        Object.keys(colLabels).forEach(function (k) {
            data.append('field_labels[' + k + ']', colLabels[k]);
        });

        fetch(gtWizardData.ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (createBtn) { createBtn.classList.remove('is-loading'); createBtn.disabled = false; }
                if (resp.success && resp.data && resp.data.table_id) {
                    var tableId  = resp.data.table_id;
                    var shortcode = resp.data.shortcode || '[tablecrafter id="' + tableId + '"]';

                    /* Hide summary + create btn, show success */
                    if (summaryEl)  { summaryEl.style.display = 'none'; }
                    if (createBtn)  { createBtn.style.display = 'none'; }
                    document.querySelector('.gt-wizard-btn-back').style.display = 'none';
                    document.querySelector('.gt-wizard-cancel').style.display   = 'none';

                    var scEl = document.querySelector('.gt-wizard-shortcode-code');
                    if (scEl) { scEl.textContent = shortcode; }
                    var goEl = document.querySelector('.gt-wizard-go-builder');
                    if (goEl) {
                        goEl.href = gtWizardData.builderUrl + '&id=' + tableId;
                    }
                    if (successEl) { successEl.style.display = 'block'; }

                    bindCopyShortcode(shortcode);

                    /* Mark Step 5 indicator done */
                    markDone(5);
                } else {
                    var msg = (resp.data && resp.data.message) ? resp.data.message : 'Save failed. Please try again.';
                    if (errMsg) { errMsg.textContent = msg; }
                    if (errBox) { errBox.style.display = 'flex'; }
                    if (createBtn) {
                        createBtn.textContent = 'Create Table';
                        createBtn.insertAdjacentHTML('afterbegin', '<span class="dashicons dashicons-yes"></span> ');
                    }
                }
            })
            .catch(function () {
                if (createBtn) { createBtn.classList.remove('is-loading'); createBtn.disabled = false; }
                if (errMsg) { errMsg.textContent = 'Network error. Please try again.'; }
                if (errBox) { errBox.style.display = 'flex'; }
            });
    }

    function bindCopyShortcode(shortcode) {
        var btn = document.querySelector('.gt-wizard-copy-shortcode');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(shortcode).then(function () {
                    btn.querySelector('.dashicons').className = 'dashicons dashicons-yes';
                    setTimeout(function () {
                        btn.querySelector('.dashicons').className = 'dashicons dashicons-clipboard';
                    }, 2000);
                });
            } else {
                /* Fallback */
                var ta = document.createElement('textarea');
                ta.value = shortcode;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
        });
    }

    /* ── Navigation ──────────────────────────────────────────────────────── */
    function bindNavButtons() {
        var btnBack   = document.querySelector('.gt-wizard-btn-back');
        var btnNext   = document.querySelector('.gt-wizard-btn-next');
        var btnCreate = document.querySelector('.gt-wizard-btn-create');

        if (btnNext) {
            btnNext.addEventListener('click', function () {
                if (!validateStep(state.step)) { return; }
                if (state.step < TOTAL_STEPS) {
                    markDone(state.step);
                    goToStep(state.step + 1);
                }
            });
        }
        if (btnBack) {
            btnBack.addEventListener('click', function () {
                if (state.step > 1) { goToStep(state.step - 1); }
            });
        }
        if (btnCreate) {
            btnCreate.addEventListener('click', function () {
                createTable();
            });
        }
    }

    function goToStep(n) {
        /* Hide current panel */
        var currentPanel = document.querySelector('.gt-wizard-panel--active');
        if (currentPanel) { currentPanel.classList.remove('gt-wizard-panel--active'); }

        /* Deactivate current indicator */
        var currentStep = document.querySelector('.gt-wizard-step--active');
        if (currentStep) { currentStep.classList.remove('gt-wizard-step--active'); }

        state.step = n;

        /* Show new panel */
        var newPanel = document.querySelector('[data-panel="' + n + '"]');
        if (newPanel) { newPanel.classList.add('gt-wizard-panel--active'); }

        /* Activate indicator */
        var newStep = document.querySelector('[data-step="' + n + '"]');
        if (newStep) { newStep.classList.add('gt-wizard-step--active'); }

        updateNav();

        /* Hooks on entering a step */
        if (n === 2) { showBranchForSource(); }
        if (n === 3) { loadColumns(); }
        if (n === 5) { populateSummary(); }

        window.scrollTo(0, 0);
    }

    function showBranchForSource() {
        document.querySelectorAll('.gt-wizard-branch').forEach(function (b) {
            b.style.display = (b.dataset.branch === state.source) ? 'block' : 'none';
        });
        /* Update step 2 heading */
        var titles = {
            gravity_forms:       'Name your table & pick a form',
            json:                'Name your table & enter the API URL',
            woocommerce_products:'Name your table',
        };
        var h = document.querySelector('.gt-wizard-step2-title');
        if (h) { h.textContent = titles[state.source] || 'Name your table & connect'; }
    }

    function updateNav() {
        var btnBack   = document.querySelector('.gt-wizard-btn-back');
        var btnNext   = document.querySelector('.gt-wizard-btn-next');
        var btnCreate = document.querySelector('.gt-wizard-btn-create');
        var succ      = document.querySelector('.gt-wizard-success');
        var alreadySuccess = succ && succ.style.display === 'block';

        if (alreadySuccess) { return; }
        if (btnBack)   { btnBack.style.display   = state.step > 1 ? 'inline-flex' : 'none'; }
        if (btnNext)   { btnNext.style.display   = state.step < TOTAL_STEPS ? 'inline-block' : 'none'; }
        if (btnCreate) { btnCreate.style.display = state.step === TOTAL_STEPS ? 'inline-flex' : 'none'; }
    }

    function markDone(n) {
        var el = document.querySelector('[data-step="' + n + '"]');
        if (el) { el.classList.add('gt-wizard-step--done'); }

        /* Color connector */
        var connectors = document.querySelectorAll('.gt-wizard-step-connector');
        if (connectors[n - 1]) { connectors[n - 1].classList.add('gt-wizard-step-connector--done'); }
    }

    /* ── Validation ──────────────────────────────────────────────────────── */
    function validateStep(n) {
        var msg = document.querySelector('[data-panel="' + n + '"] .gt-wizard-validation-msg');

        if (n === 1) {
            if (!state.source) { showMsg(msg); return false; }
        }
        if (n === 2) {
            if (!state.title) { showMsg(msg); return false; }
            if (state.source === 'gravity_forms' && !state.formId) { showMsg(msg); return false; }
            if (state.source === 'json' && !state.jsonUrl)         { showMsg(msg); return false; }
            if ((state.source === 'csv' || state.source === 'google_sheets') && !state.remoteUrl) { showMsg(msg); return false; }
        }
        if (n === 3) {
            if (state.columns.length === 0) { showMsg(msg); return false; }
        }
        return true;
    }

    function showMsg(el) {
        if (el) {
            el.style.display = 'flex';
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function hideValidation(step) {
        var msg = document.querySelector('[data-panel="' + step + '"] .gt-wizard-validation-msg');
        if (msg) { msg.style.display = 'none'; }
    }

    /* ── Utils ───────────────────────────────────────────────────────────── */
    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escAttr(s) { return escHtml(s); }

    /* ── Entry ───────────────────────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
