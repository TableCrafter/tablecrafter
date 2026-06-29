/* global gtAdminAutosaveData */
/**
 * Gravity Tables — admin editor auto-save (#455).
 *
 * Periodically writes the in-progress table editor state to localStorage
 * keyed by table id. If the user leaves the tab with unsaved changes, the
 * browser shows the standard "Leave site?" prompt. On returning to the
 * editor, if the local draft is newer than the server-saved version, a
 * banner offers to Restore or Discard.
 *
 * Public surface (window.GTAdminAutosave):
 *   saveDraft()             — write current form state to localStorage
 *   loadDraft()             — return the parsed local draft or null
 *   discardDraft()          — remove the local draft entry
 *   hasUnsavedChanges()     — true while the form is dirty since last save
 */
(function (window, document) {
    'use strict';

    var defaults = {
        tableId:        0,
        intervalMs:     60000,
        serverUpdatedAt: '',          // mysql-format timestamp from server
        formSelector:   '#gt-table-builder-form',
        bannerSelector: '#gt-autosave-banner',
        timestampSelector: '#gt-autosave-timestamp'
    };

    var cfg = (typeof gtAdminAutosaveData === 'object' && gtAdminAutosaveData)
        ? Object.assign({}, defaults, gtAdminAutosaveData)
        : defaults;

    var dirty       = false;
    var intervalId  = 0;
    var beforeUnloadBound = false;

    function key() {
        return 'gt_table_draft_' + cfg.tableId;
    }

    function readForm() {
        var form = document.querySelector(cfg.formSelector);
        if (!form) { return null; }
        var data = {};
        var inputs = form.querySelectorAll('input, textarea, select');
        for (var i = 0; i < inputs.length; i++) {
            var el = inputs[i];
            if (!el.name) { continue; }
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) { data[el.name] = el.value; }
            } else {
                data[el.name] = el.value;
            }
        }
        return data;
    }

    function saveDraft() {
        if (!cfg.tableId) { return; }
        var state = readForm();
        if (!state) { return; }
        try {
            window.localStorage.setItem(key(), JSON.stringify({
                savedAt: Date.now(),
                state:   state
            }));
        } catch (e) { /* QuotaExceeded — silently bail. */ return; }
        dirty = false;
        updateTimestamp();
    }

    function loadDraft() {
        try {
            var raw = window.localStorage.getItem(key());
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    function discardDraft() {
        try { window.localStorage.removeItem(key()); } catch (e) { /* noop */ }
    }

    function hasUnsavedChanges() {
        return dirty;
    }

    function updateTimestamp() {
        var el = document.querySelector(cfg.timestampSelector);
        if (!el) { return; }
        var now = new Date();
        el.textContent = 'Last auto-saved ' + now.toLocaleTimeString();
    }

    function onBeforeUnload(e) {
        if (!hasUnsavedChanges()) { return; }
        // Standards-compliant prompt — most browsers ignore the message
        // string and show their own copy.
        e.preventDefault();
        e.returnValue = '';
        return '';
    }

    function bindBeforeUnloadOnce() {
        if (beforeUnloadBound) { return; }
        window.addEventListener('beforeunload', onBeforeUnload);
        beforeUnloadBound = true;
    }

    function bindFormInputs() {
        var form = document.querySelector(cfg.formSelector);
        if (!form) { return; }
        form.addEventListener('input',  function () { dirty = true; bindBeforeUnloadOnce(); });
        form.addEventListener('change', function () { dirty = true; bindBeforeUnloadOnce(); });
    }

    function maybeShowRestoreBanner() {
        var draft = loadDraft();
        if (!draft || !draft.savedAt) { return; }

        // Compare local draft savedAt (ms) with server updated_at (mysql).
        var serverMs = cfg.serverUpdatedAt ? Date.parse(cfg.serverUpdatedAt.replace(' ', 'T') + 'Z') : 0;
        if (serverMs && draft.savedAt <= serverMs) {
            // Server is newer or equal — local draft is stale, drop it silently.
            discardDraft();
            return;
        }

        var host = document.querySelector(cfg.bannerSelector);
        if (!host) { return; }

        var ts   = new Date(draft.savedAt).toLocaleString();
        host.innerHTML =
            '<div class="notice notice-info gt-autosave-banner">' +
                '<p>You have unsaved changes from ' + ts + '. ' +
                    '<button type="button" class="button" data-gt-action="restore">Restore draft</button> ' +
                    '<button type="button" class="button-link" data-gt-action="discard">Discard</button>' +
                '</p>' +
            '</div>';

        host.addEventListener('click', function (ev) {
            var action = ev.target && ev.target.getAttribute('data-gt-action');
            if (action === 'restore') {
                applyDraft(draft.state);
                host.innerHTML = '';
            } else if (action === 'discard') {
                discardDraft();
                host.innerHTML = '';
            }
        });
    }

    function applyDraft(state) {
        var form = document.querySelector(cfg.formSelector);
        if (!form || !state) { return; }
        Object.keys(state).forEach(function (name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (el) { el.value = state[name]; }
        });
    }

    function start() {
        if (!cfg.tableId) { return; }            // unsaved-new-table: nothing to namespace.
        bindFormInputs();
        maybeShowRestoreBanner();
        if (intervalId) { window.clearInterval(intervalId); }
        intervalId = window.setInterval(function () {
            if (hasUnsavedChanges()) { saveDraft(); }
        }, Math.max(5000, cfg.intervalMs));
    }

    // After a successful server save, the host page can call
    // GTAdminAutosave.discardDraft() to drop the local copy.

    window.GTAdminAutosave = {
        saveDraft:         saveDraft,
        loadDraft:         loadDraft,
        discardDraft:      discardDraft,
        hasUnsavedChanges: hasUnsavedChanges,
        _start:            start
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window, document);
