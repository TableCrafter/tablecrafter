/**
 * Gravity Tables -- admin/bind-events.js
 *
 * Ninth slice of #842 (filed as #964). The bindEvents event delegator --
 * the largest single remaining method in admin.js after slices 1-8.
 *
 * Wires every $(document).on(...) handler for the builder UI: save buttons,
 * clipboard paste preview + apply, copy shortcode, field config gear clicks,
 * drag-drop firing events, and more.
 *
 * Called from TC_TableBuilder.init() (which lives in admin/core.js).
 * Behaviour preserved: same handlers, same selectors, same self=this pattern.
 *
 * @since 4.157.0
 */
(function ($) {
    "use strict";

    window.TC_TableBuilder = window.TC_TableBuilder || {};

    Object.assign(window.TC_TableBuilder, {

        bindEvents: function () {
            var self = this;

            // Prevent multiple event binding
            if (this.eventsBound) {
                return;
            }
            this.eventsBound = true;

            // Handle all save buttons
            $(document).on('click', '.gt-save-button', function () {
                self.saveTable($(this));
            });

            // #526 slice 2/3 — Pick fallback image via GT Media Folder
            // adapter. Detected folder plugins (FileBird / FolderPress /
            // WP Media Folder / Real Media Library) get their folder UI
            // surfaced inside the wp.media frame automatically.
            $(document).on('click', '#gt-mf-pick-image-btn', function () {
                if (typeof window.GTMediaFolder === 'undefined' || typeof window.GTMediaFolder.openFrame !== 'function') {
                    return;
                }
                window.GTMediaFolder.openFrame({
                    title: 'Select fallback image',
                    onSelect: function (attachment) {
                        if (attachment && attachment.url) {
                            $('#gt-mf-fallback-url').val(attachment.url);
                        }
                    }
                });
            });

            // #985 v4.168.0 — JSON data source admin UI (slice 3b-2 of #512).
            // #990 v4.171.0 — Airtable picker preview (phase A of #517).
            // #998 v4.175.0 — Notion picker preview (phase 1 of #592).
            // #1010 v4.181.0 — sync_direction picker for JSON + Notion (phase 1 of #613).
            // #1013 v4.183.0 — Unified sync_direction picker across all 3 external sources
            // now that the Airtable push consumer accepts both legacy + canonical naming (#1011).
            $(document).on('change', '#gt-data-source-type', function () {
                var val = $(this).val();
                // #2002 — google_sheets joins the external-source set.
                var isExternal = (val === 'json' || val === 'airtable' || val === 'notion' || val === 'google_sheets' || val === 'xml' || val === 'csv' || val === 'external_db' || val === 'xlsx' || val === 'manual');
                $('.gt-manual-source-fields').toggle(val === 'manual'); // #2366
                $('.gt-json-source-fields').toggle(val === 'json');
                $('.gt-airtable-source-fields').toggle(val === 'airtable');
                $('.gt-notion-source-fields').toggle(val === 'notion');
                $('.gt-google-sheets-source-fields').toggle(val === 'google_sheets');
                $('.gt-xml-source-fields').toggle(val === 'xml');
                $('.gt-csv-source-fields').toggle(val === 'csv');
                $('.gt-xlsx-source-fields').toggle(val === 'xlsx');
                $('.gt-external-db-source-fields').toggle(val === 'external_db');
                $('.gt-woocommerce-source-fields').toggle(val === 'woocommerce_products');
                // #2200 — WooCommerce needs no URL/config, so auto-load its product
                // columns into the picker the moment the source is selected.
                if (val === 'woocommerce_products') {
                    setTimeout(function () { $('.gt-wc-load-columns').trigger('click'); }, 100);
                }
                // #2240 — Airtable needs per-table config; auto-load only when the
                // base + table are already filled in (switching back to a
                // configured source, or editing a saved table).
                if (val === 'airtable'
                    && ($('input[name="airtable_base_id"]').val() || '')
                    && ($('input[name="airtable_table_id"]').val() || '')) {
                    setTimeout(function () { $('.gt-airtable-load-columns').trigger('click'); }, 100);
                }
                // #2241 — same for Notion when the database id is filled in (the
                // saved token rides the table_id fallback server-side).
                if (val === 'notion' && ($('input[name="notion_database_id"]').val() || '')) {
                    setTimeout(function () { $('.gt-notion-load-columns').trigger('click'); }, 100);
                }
                // #2242 — same for External DB when a connection + query are set.
                if (val === 'external_db'
                    && ($('select[name="external_db_connection"]').val() || '') !== ''
                    && ($('textarea[name="external_db_query"]').val() || '')) {
                    setTimeout(function () { $('.gt-external-db-load-columns').trigger('click'); }, 100);
                }
                $('.gt-sync-direction-field').toggle(isExternal);
                // #2108 — the Gravity Form picker is only relevant for the
                // gravity_forms source. Hide it (and drop its `required`) for
                // every other source so a hidden field never blocks the form.
                var isGf = (val === 'gravity_forms');
                $('.gt-gravity-forms-source-fields').toggle(isGf);
                $('#gravity-form').prop('required', isGf);
                // #2118 — contextual Pro upsell: show it the moment a free user
                // selects a Pro source (the <option> carries data-pro="1").
                var isPro = $(this).find('option:selected').attr('data-pro') === '1';
                $('[data-gt-source-upsell]').toggle(isPro);
            });

            // #985 — Test connection button. Fires the gt_preview_json_source AJAX
            // endpoint (slice 3a / v4.166.0) with the form's URL + headers + dot_path
            // and renders the inferred columns + first 5 rows in a preview block.
            $(document).on('click', '#gt-json-test-connection', function () {
                var $btn = $(this);
                var $result = $('#gt-json-test-result');
                var url = $('input[name="json_url"]').val() || '';
                var headers_raw = $('textarea[name="json_headers_raw"]').val() || '';
                var dot_path = $('input[name="json_dot_path"]').val() || '';

                if (!url) {
                    $result.html('<span style="color:#d63638;">URL is required.</span>');
                    return;
                }

                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Fetching...</span>');

                $.post(gtAdmin.ajax_url, {
                    action:   'gt_preview_json_source',
                    nonce:    gtAdmin.nonce,
                    url:      url,
                    headers:  headers_raw,
                    dot_path: dot_path,
                }).done(function (resp) {
                    if (resp && resp.success && resp.data) {
                        var d = resp.data;
                        var colCount = d.columns ? d.columns.length : 0;
                        var msg = '<span style="color:#00a32a;">✓ ' + d.row_count + ' rows total, '
                                + colCount + ' columns. Sampled first '
                                + (d.rows ? d.rows.length : 0) + ' rows.</span>';
                        if (colCount > 0) {
                            msg += ' <em style="color:#50575e;">Columns loaded into field picker below.</em>';
                        }
                        $result.html(msg);
                        // Populate the field picker with the inferred columns so users can
                        // select which ones to show in the table.
                        if (d.columns && d.columns.length > 0 && typeof self.loadJsonColumns === 'function') {
                            self.loadJsonColumns(d.columns);
                        }
                    } else {
                        var errMsg = (resp && resp.data && resp.data.message) || 'Preview failed';
                        var errCode = (resp && resp.data && resp.data.code) || '';
                        $result.html('<span style="color:#d63638;">✗ ' + errMsg
                            + (errCode ? ' [' + errCode + ']' : '') + '</span>');
                    }
                }).fail(function () {
                    $result.html('<span style="color:#d63638;">Network error</span>');
                }).always(function () {
                    $btn.prop('disabled', false);
                });
            });

            // #2015 — generic remote-source preview. Paste a URL, click Preview,
            // and see the inferred columns + total row count fetched live via the
            // matching engine (CSV / XML / Google Sheets).
            $(document).on('click', '.gt-remote-source-preview', function () {
                var $btn       = $(this);
                var sourceType = $btn.data('source-type');
                var urlField   = $btn.data('url-field');
                var pathField  = $btn.data('path-field');
                var $result    = $('.gt-remote-source-preview-result[data-source-type="' + sourceType + '"]');
                var url        = $('input[name="' + urlField + '"]').val() || '';

                if (!url) {
                    $result.html('<span style="color:#d63638;">URL is required.</span>');
                    return;
                }

                var payload = {
                    action:      'gt_preview_remote_source',
                    nonce:       gtAdmin.nonce,
                    source_type: sourceType,
                    url:         url
                };
                if (pathField) {
                    payload.row_path = $('input[name="' + pathField + '"]').val() || '';
                }

                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Fetching...</span>');

                $.post(gtAdmin.ajax_url, payload).done(function (resp) {
                    if (resp && resp.success && resp.data) {
                        var d = resp.data;
                        var colCount = d.columns ? d.columns.length : 0;
                        var msg = '<span style="color:#00a32a;">✓ ' + d.row_count + ' rows, '
                            + colCount + ' columns.</span>';
                        if (colCount > 0) {
                            msg += ' <em style="color:#50575e;">Columns loaded into field picker below.</em>';
                        }
                        $result.html(msg);
                        // Populate the field picker (Available Fields + Table Columns
                        // + preview) just like the JSON path, so CSV / XML / Google
                        // Sheets sources are configurable without manual dragging.
                        if (d.columns && d.columns.length > 0 && typeof self.loadJsonColumns === 'function') {
                            self.loadJsonColumns(d.columns);
                        }
                    } else {
                        var errMsg = (resp && resp.data && resp.data.message) || 'Preview failed';
                        $result.html('<span style="color:#d63638;">✗ ' + errMsg + '</span>');
                    }
                }).fail(function () {
                    $result.html('<span style="color:#d63638;">Network error</span>');
                }).always(function () {
                    $btn.prop('disabled', false);
                });
            });

            // #2200 — WooCommerce: load product columns (friendly labels) into the
            // field picker + trigger the preview, mirroring the JSON / remote flow.
            $(document).on('click', '.gt-wc-load-columns', function () {
                var $btn    = $(this);
                var $result = $('.gt-wc-load-result');
                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Loading products…</span>');
                $.post(gtAdmin.ajax_url, { action: 'gt_preview_wc_source', nonce: gtAdmin.nonce })
                    .done(function (resp) {
                        if (resp && resp.success && resp.data && resp.data.columns) {
                            var d = resp.data;
                            $result.html('<span style="color:#00a32a;">✓ ' + d.row_count + ' products, '
                                + d.columns.length + ' columns loaded into the field picker.</span>');
                            if (typeof self.loadJsonColumns === 'function') {
                                self.loadJsonColumns(d.columns);
                            }
                        } else {
                            var m = (resp && resp.data && resp.data.message) || 'Could not load products';
                            $result.html('<span style="color:#d63638;">✗ ' + m + '</span>');
                        }
                    })
                    .fail(function () { $result.html('<span style="color:#d63638;">Network error</span>'); })
                    .always(function () { $btn.prop('disabled', false); });
            });

            // #2240 — Airtable: live-fetch columns with the entered (or saved)
            // credentials into the field picker + preview, mirroring the WC flow.
            // A blank PAT with a saved table falls back to the stored encrypted
            // token server-side (via table_id).
            $(document).on('click', '.gt-airtable-load-columns', function () {
                var $btn    = $(this);
                var $result = $('.gt-airtable-load-result');
                var baseId  = $('input[name="airtable_base_id"]').val() || '';
                var tableId = $('input[name="airtable_table_id"]').val() || '';
                if (!baseId || !tableId) {
                    $result.html('<span style="color:#d63638;">Base ID and Table ID are required.</span>');
                    return;
                }
                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Connecting to Airtable…</span>');
                $.post(gtAdmin.ajax_url, {
                    action:            'gt_preview_airtable_source',
                    nonce:             gtAdmin.nonce,
                    airtable_pat:      $('input[name="airtable_pat"]').val() || '',
                    airtable_base_id:  baseId,
                    airtable_table_id: tableId,
                    table_id:          $('input[name="table_id"]').val() || ''
                }).done(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.columns) {
                        var d = resp.data;
                        $result.html('<span style="color:#00a32a;">✓ ' + d.row_count + ' rows sampled, '
                            + d.columns.length + ' columns loaded into the field picker.</span>');
                        if (typeof self.loadJsonColumns === 'function') {
                            self.loadJsonColumns(d.columns);
                        }
                    } else {
                        var m = (resp && resp.data && resp.data.message) || 'Could not load Airtable columns';
                        $result.html('<span style="color:#d63638;">✗ ' + m + '</span>');
                    }
                }).fail(function () { $result.html('<span style="color:#d63638;">Network error</span>'); })
                  .always(function () { $btn.prop('disabled', false); });
            });

            // #2241 — Notion: live-fetch columns with the entered (or saved)
            // token into the field picker + preview, mirroring the Airtable flow.
            $(document).on('click', '.gt-notion-load-columns', function () {
                var $btn    = $(this);
                var $result = $('.gt-notion-load-result');
                var dbId    = $('input[name="notion_database_id"]').val() || '';
                if (!dbId) {
                    $result.html('<span style="color:#d63638;">Database ID is required.</span>');
                    return;
                }
                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Connecting to Notion…</span>');
                $.post(gtAdmin.ajax_url, {
                    action:             'gt_preview_notion_source',
                    nonce:              gtAdmin.nonce,
                    notion_token:       $('input[name="notion_token"]').val() || '',
                    notion_database_id: dbId,
                    table_id:           $('input[name="table_id"]').val() || ''
                }).done(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.columns) {
                        var d = resp.data;
                        $result.html('<span style="color:#00a32a;">✓ ' + d.row_count + ' rows sampled, '
                            + d.columns.length + ' columns loaded into the field picker.</span>');
                        if (typeof self.loadJsonColumns === 'function') {
                            self.loadJsonColumns(d.columns);
                        }
                    } else {
                        var m = (resp && resp.data && resp.data.message) || 'Could not load Notion columns';
                        $result.html('<span style="color:#d63638;">✗ ' + m + '</span>');
                    }
                }).fail(function () { $result.html('<span style="color:#d63638;">Network error</span>'); })
                  .always(function () { $btn.prop('disabled', false); });
            });

            // #2242 — External DB: run the entered query against the selected
            // saved connection and load the result columns into the field
            // picker + preview, mirroring the Airtable / Notion flow.
            $(document).on('click', '.gt-external-db-load-columns', function () {
                var $btn    = $(this);
                var $result = $('.gt-external-db-load-result');
                var conn    = $('select[name="external_db_connection"]').val() || '';
                var query   = $('textarea[name="external_db_query"]').val() || '';
                if (conn === '' || !query) {
                    $result.html('<span style="color:#d63638;">Select a connection and enter a SELECT query first.</span>');
                    return;
                }
                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Running query…</span>');
                $.post(gtAdmin.ajax_url, {
                    action:                 'gt_preview_external_db_source',
                    nonce:                  gtAdmin.nonce,
                    external_db_connection: conn,
                    external_db_query:      query
                }).done(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.columns) {
                        var d = resp.data;
                        $result.html('<span style="color:#00a32a;">✓ ' + d.row_count + ' rows, '
                            + d.columns.length + ' columns loaded into the field picker.</span>');
                        if (typeof self.loadJsonColumns === 'function') {
                            self.loadJsonColumns(d.columns);
                        }
                    } else {
                        var m = (resp && resp.data && resp.data.message) || 'Could not load database columns';
                        $result.html('<span style="color:#d63638;">✗ ' + m + '</span>');
                    }
                }).fail(function () { $result.html('<span style="color:#d63638;">Network error</span>'); })
                  .always(function () { $btn.prop('disabled', false); });
            });

            // #2063 — one-click demo table creation.
            $(document).on('click', '.gt-load-demo', function () {
                var $btn    = $(this);
                var demo    = $btn.data('demo');
                // #2196 — template cards live in .gt-template-card (no <p> ancestor),
                // so widen the lookup; fall back to a sibling so feedback always lands.
                var $result = $btn.closest('p, .gt-template-card').find('.gt-load-demo-result');
                if (!$result.length) { $result = $btn.siblings('.gt-load-demo-result'); }

                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Creating demo...</span>');

                $.post(gtAdmin.ajax_url, { action: 'gt_create_demo_table', nonce: gtAdmin.nonce, demo: demo })
                    .done(function (resp) {
                        if (resp && resp.success && resp.data) {
                            $result.html('<span style="color:#00a32a;">' + resp.data.message + '</span> '
                                + '<a class="button button-small" href="' + resp.data.edit_url + '">Open in builder</a>');
                        } else {
                            var m = (resp && resp.data && resp.data.message) || 'Could not create demo';
                            $result.html('<span style="color:#d63638;">' + m + '</span>');
                        }
                    })
                    .fail(function () { $result.html('<span style="color:#d63638;">Network error</span>'); })
                    .always(function () { $btn.prop('disabled', false); });
            });

            // #2021 — run the rebrand data migration from the admin prompt.
            $(document).on('click', '.gt-run-migration', function () {
                var $btn    = $(this);
                var $notice = $btn.closest('.gt-migration-notice');
                var $result = $notice.find('.gt-run-migration-result');
                var nonce   = $notice.data('nonce') || gtAdmin.nonce;

                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Migrating...</span>');

                $.post(gtAdmin.ajax_url, { action: 'gt_run_migration', nonce: nonce }).done(function (resp) {
                    if (resp && resp.success && resp.data) {
                        $result.html('<span style="color:#00a32a;">' + resp.data.message + '</span>');
                        $btn.remove();
                    } else {
                        var m = (resp && resp.data && resp.data.message) || 'Migration failed';
                        $result.html('<span style="color:#d63638;">' + m + '</span>');
                        $btn.prop('disabled', false);
                    }
                }).fail(function () {
                    $result.html('<span style="color:#d63638;">Network error</span>');
                    $btn.prop('disabled', false);
                });
            });

            // #2021 — persist dismissal when the notice's X is clicked.
            $(document).on('click', '.gt-migration-notice .notice-dismiss', function () {
                var nonce = $(this).closest('.gt-migration-notice').data('nonce') || gtAdmin.nonce;
                $.post(gtAdmin.ajax_url, { action: 'gt_dismiss_migration_notice', nonce: nonce });
            });

            // #2022 — migrate deprecated [gravity_table] shortcodes in post content.
            $(document).on('click', '.gt-migrate-shortcodes', function () {
                var $btn    = $(this);
                var dryRun  = String($btn.data('dry-run')) === '1';
                var $result = $btn.closest('p').find('.gt-migrate-shortcodes-result');

                if (!dryRun && !window.confirm('Rewrite deprecated shortcodes in your page content to [tablecrafter]? This edits post content.')) {
                    return;
                }

                $btn.prop('disabled', true);
                $result.html('<span style="color:#50575e;">Working...</span>');

                $.post(gtAdmin.ajax_url, {
                    action:  'gt_migrate_shortcodes',
                    nonce:   gtAdmin.nonce,
                    dry_run: dryRun ? 1 : 0
                }).done(function (resp) {
                    if (resp && resp.success && resp.data) {
                        $result.html('<span style="color:#00a32a;">' + resp.data.message + '</span>');
                    } else {
                        var m = (resp && resp.data && resp.data.message) || 'Migration failed';
                        $result.html('<span style="color:#d63638;">' + m + '</span>');
                    }
                }).fail(function () {
                    $result.html('<span style="color:#d63638;">Network error</span>');
                }).always(function () {
                    $btn.prop('disabled', false);
                });
            });

            // #516 slice 2 — clipboard paste preview + apply.
            $(document).on('click', '#gt-paste-preview-btn', function () {
                var $btn    = $(this);
                var $status = $('#gt-paste-status');
                var tsv     = $('#gt-paste-tsv').val() || '';
                if (!tsv.trim()) { $status.text('Paste data first.').css('color', '#d63638'); return; }
                $btn.prop('disabled', true); $status.text('Parsing...').css('color', '#50575e');
                $.post(gtAdmin.ajax_url, {
                    action: 'gt_clipboard_paste_preview',
                    nonce:  $btn.data('nonce'),
                    tsv:    tsv
                }).done(function (resp) {
                    if (resp && resp.success && resp.data) {
                        $status.text(resp.data.rows + ' rows, ' + resp.data.cols + ' cols, dialect=' + resp.data.dialect).css('color', '#00a32a');
                    } else {
                        $status.text('Preview failed').css('color', '#d63638');
                    }
                }).fail(function () { $status.text('Request failed').css('color', '#d63638'); })
                  .always(function () { $btn.prop('disabled', false); });
            });
            $(document).on('click', '#gt-paste-apply-btn', function () {
                var $btn    = $(this);
                var $status = $('#gt-paste-status');
                var tsv     = $('#gt-paste-tsv').val() || '';
                var tableId = parseInt($('input[name="table_id"]').val(), 10) || 0;
                var mode    = $('input[name="gt-paste-mode"]:checked').val() || 'append';
                var hasHdr  = $('#gt-paste-has-headers').is(':checked') ? 1 : 0;
                if (!tableId) { $status.text('Save the table first.').css('color', '#d63638'); return; }
                if (!tsv.trim()) { $status.text('Paste data first.').css('color', '#d63638'); return; }
                if (mode === 'replace' && !confirm('Replace mode trashes ALL existing active entries on this form. Proceed?')) { return; }
                $btn.prop('disabled', true); $status.text('Applying...').css('color', '#50575e');
                $.post(gtAdmin.ajax_url, {
                    action:      'gt_clipboard_paste_apply',
                    nonce:       $btn.data('nonce'),
                    table_id:    tableId,
                    tsv:         tsv,
                    mode:        mode,
                    has_headers: hasHdr
                }).done(function (resp) {
                    if (resp && resp.success && resp.data) {
                        var d = resp.data;
                        $status.text('Added ' + d.added + ', deleted ' + (d.deleted || 0) + (d.errors ? (', errors ' + d.errors) : '')).css('color', '#00a32a');
                    } else {
                        var err = (resp && resp.data && resp.data.message) || 'Failed';
                        $status.text('Apply failed: ' + err).css('color', '#d63638');
                    }
                }).fail(function () { $status.text('Request failed').css('color', '#d63638'); })
                  .always(function () { $btn.prop('disabled', false); });
            });

            // #519 slice 3 — "Run export now" button. Calls the
            // wp_ajax_gt_run_scheduled_export handler on
            // TC_Scheduled_Export_Service. Capability gate is
            // server-side (manage_options).
            $(document).on('click', '#gt-run-export-now', function () {
                var $btn    = $(this);
                var $status = $('#gt-run-export-now-status');
                var nonce   = $btn.data('nonce');
                var tableId = parseInt($('input[name="table_id"]').val(), 10) || 0;
                if (!tableId) {
                    $status.text('Save the table first.').css('color', '#d63638');
                    return;
                }
                $btn.prop('disabled', true);
                $status.text('Running...').css('color', '#50575e');
                $.post(gtAdmin.ajax_url, {
                    action:   'gt_run_scheduled_export',
                    nonce:    nonce,
                    table_id: tableId
                }).done(function (resp) {
                    if (resp && resp.success && resp.data) {
                        var msg = 'Wrote ' + (resp.data.rows || 0) + ' rows to ' + (resp.data.file || '');
                        if (resp.data.email_sent) { msg += ' (emailed)'; }
                        $status.text(msg).css('color', '#00a32a');
                    } else {
                        var err = (resp && resp.data && (resp.data.message || resp.data.code)) || 'Failed';
                        $status.text('Failed: ' + err).css('color', '#d63638');
                    }
                }).fail(function () {
                    $status.text('Request failed').css('color', '#d63638');
                }).always(function () {
                    $btn.prop('disabled', false);
                });
            });

            // Transpose table button (#346)
            $(document).on('click', '.gt-transpose-btn', function () {
                var tableId = $(this).data('table-id');
                if (!confirm(gtAdmin.transposeConfirm || 'Transpose this table? Rows will become columns and columns will become rows. This cannot be undone without transposing again.')) {
                    return;
                }
                $.post(gtAdmin.ajax_url, {
                    action: 'gt_transpose_table',
                    nonce:    gtAdmin.nonce,
                    table_id: tableId
                }).done(function (res) {
                    if (res.success) {
                        alert(res.data.message || 'Table transposed.');
                        location.reload();
                    } else {
                        alert((res.data && res.data.message) || res.data || 'Transpose failed.');
                    }
                }).fail(function () {
                    alert('Transpose request failed. Please try again.');
                });
            });

            // Initialize floating save button visibility
            this.initFloatingSaveButton();

            // Collapsible sections will be initialized in initCollapsibleSections()

            // Form selection
            $('#gravity-form').on('change', function () {
                var formId = $(this).val();
                if (formId) {
                    self.loadFormFields(formId);
                    self.generatePreview();
                } else {
                    $('#available-fields-list').empty();
                    $('#selected-fields-list').empty();
                }
            });

            // Field configuration modal - prevent multiple bindings
            $(document).off('click.gtFieldConfig');
            $(document).on('click.gtFieldConfig', '.gt-field-config', function (e) {
                e.stopPropagation();
                e.preventDefault();
                //console.log('GT Admin DEBUG: Configure button clicked');

                // Prevent multiple modal openings
                if (self.modalOpening || $('#field-config-modal').is(':visible')) {
                    //console.log('GT Admin DEBUG: Modal already open or opening, ignoring click');
                    return false;
                }

                self.modalOpening = true;
                var fieldId = $(this).data('field-id');
                //console.log('GT Admin DEBUG: Field ID:', fieldId);
                self.openFieldConfig(fieldId);
            });

            // Field removal (removed - handled by more specific handler below)

            $('#save-field-config').on('click', function () {
                //console.log('GT Admin DEBUG: Save button clicked!');
                self.saveFieldConfig();
            });

            // Add a flag to prevent multiple closings
            this.modalClosing = false;

            // Remove all existing handlers to prevent conflicts
            $(document).off('click.gtModalClose click.gtModalCancel');
            $('#cancel-field-config, .gt-modal-close').off('click');

            // Direct binding with protection against multiple calls
            var closeModal = function (e) {
                //console.log('GT Admin: Close modal clicked');
                e.preventDefault();
                e.stopImmediatePropagation();

                if (self.modalClosing) {
                    //console.log('GT Admin: Modal already closing, ignoring click');
                    /* c8 ignore next */
                    return false;
                }

                self.modalClosing = true;
                //console.log('GT Admin: Modal close triggered - setting flag and closing');

                try {
                    self.closeFieldConfig();
                    //console.log('GT Admin: closeFieldConfig completed successfully');
                } catch (error) {
                    console.error('GT Admin: Error closing field config:', error);
                } finally {
                    // #1565: always reset the flag so the modal can be
                    // re-closed on the next click. closeFieldConfig sets it
                    // to false on the happy path (field-config-modal.js line
                    // 511); the finally block guarantees the same if the
                    // call throws before reaching that reset.
                    self.modalClosing = false;
                }

                return false;
            };

            // Bind to both elements directly
            $('#cancel-field-config').on('click', closeModal);
            $('.gt-modal-close').on('click', closeModal);

            // Add escape key support
            $(document).off('keydown.gtModalEscape').on('keydown.gtModalEscape', function (e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    if ($('#field-config-modal').is(':visible')) {
                        //console.log('GT Admin: Escape key pressed, closing modal');
                        /* c8 ignore next */
                        closeModal(e);
                    }
                }
            });

            // Tab switching functionality
            $('.gt-field-modal-tab').on('click', function () {
                var tabId = $(this).data('tab');

                // Remove active class from all tabs and content
                $('.gt-field-modal-tab').removeClass('active');
                $('.gt-field-modal-tab-content').removeClass('active');

                // Add active class to clicked tab and corresponding content
                $(this).addClass('active');
                $('#' + tabId).addClass('active');

                // When entering filtering tab, check if field is a lookup field and show notice
                if (tabId === 'gt-filtering-tab') {
                    self.updateFilteringOptionsForLookupField();
                }
            });

            // Copy shortcode
            $('#copy-shortcode').on('click', function () {
                self.copyShortcode();
            });

            // Lookup configuration
            $('#field-lookup-enabled').on('change', function () {
                if ($(this).is(':checked')) {
                    $('#lookup-config').show();
                } else {
                    $('#lookup-config').hide();
                }

                // Update filtering options when lookup status changes
                self.updateFilteringOptionsForLookupField();
            });

            // Filter configuration
            $('#field-filterable').on('change', function () {
                if ($(this).is(':checked')) {
                    $('#filter-options-group').show();
                    // Update filtering options when filterable status changes
                    self.updateFilteringOptionsForLookupField();
                } else {
                    $('#filter-options-group').hide();
                }
            });

            // Filter type change handler
            $('#field-filter-type').on('change', function () {
                self.handleFilterTypeChange($(this).val());
            });

            // #1741 — show/hide badge map textarea when cell type switches to/from badge
            $('#field-cell-type').on('change', function () {
                $('.gt-badge-map-row').toggle($(this).val() === 'badge');
            });

            $('#field-lookup-type').on('change', function () {
                var lookupType = $(this).val();
                $('.gt-lookup-user-config, .gt-lookup-post-config, .gt-lookup-custom-config').hide();

                if (lookupType === 'user') {
                    $('.gt-lookup-user-config').show();
                } else if (lookupType === 'post') {
                    $('.gt-lookup-post-config').show();
                } else if (lookupType === 'custom') {
                    $('.gt-lookup-custom-config').show();
                }
            });

            // Conditional formatting events - unbind first to prevent duplicates
            $(document).off('click', '#gt-add-conditional-rule');
            $(document).on('click', '#gt-add-conditional-rule', function (e) {
                //console.log('GT Admin DEBUG: === ADD CONDITIONAL RULE BUTTON CLICKED ===');
                e.preventDefault();
                e.stopPropagation();

                var $container = $('#gt-conditional-formatting-rules-container');
                //console.log('GT Admin DEBUG: Rules BEFORE adding:', $container.find('.gt-conditional-formatting-rule').length);

                var $btn = $(this);
                if ($btn.prop('disabled')) {
                    /* c8 ignore next */
                    return;
                }


                // Temporarily disable button to prevent double-clicks
                $btn.prop('disabled', true);
                self.addConditionalFormattingRule();
                //console.log('GT Admin DEBUG: Rules AFTER adding:', $container.find('.gt-conditional-formatting-rule').length);

                // Re-enable after short delay
                setTimeout(function () {
                    $btn.prop('disabled', false);
                    // Also reset the throttling flag to ensure it doesn't get stuck
                    self.isAddingRule = false;
                }, 200);
            });

            $(document).on('click', '.gt-delete-conditional-rule', function () {
                $(this).closest('.gt-conditional-formatting-rule').remove();
            });

            $(document).on('change', '.gt-formatting-rule-action', function () {
                self.handleActionChange($(this));
            });

            // Handle operator change for cell value input visibility
            $(document).on('change', '.gt-formatting-rule-if-clause', function () {
                self.handleOperatorChange($(this));
            });

            // Bulk action checkboxes and other settings - update preview on change
            $('input[name="bulk_delete"], input[name="bulk_export"], input[name="bulk_edit"]').on('change', function () {
                self.updateBulkActions();
                self.generatePreview();
            });

            // Filter checkboxes - update preview on change
            $('input[name="show_deleted_entries"], input[name="filter_user_entries"]').on('change', function () {
                var name = $(this).attr('name');
                var checked = $(this).is(':checked');
                //console.log('GT Admin: Filter checkbox changed:', name, 'to:', checked);
                //console.log('GT Admin: All filter checkboxes state:', {
                //    show_deleted_entries: $('input[name="show_deleted_entries"]').is(':checked'),
                //    filter_user_entries: $('input[name="filter_user_entries"]').is(':checked')
                //});
                self.generatePreview();
            });

            // Update preview when any settings change
            $('input[type="checkbox"], select').on('change', function () {
                self.generatePreview();
            });

            // Specifically handle persistent_filters checkbox to update Advanced Filters in preview
            $('input[name="persistent_filters"]').on('change', function () {
                var isChecked = $(this).is(':checked');
                //console.log('GT Admin: persistent_filters changed to:', isChecked);

                // Update the preview to reflect the Advanced Filters state
                self.updateAdvancedFiltersInPreview(isChecked);
                self.generatePreview();
            });

            // Field selection events (keep click handlers as fallback)
            $(document).on('click', '#available-fields-list .gt-field-item', function (e) {
                // Only handle click if not dragging and not disabled
                if (!$(this).hasClass('ui-sortable-helper') && !$(this).hasClass('gt-field-disabled')) {
                    var fieldId = $(this).data('field-id');
                    self.addFieldToSelection(fieldId);
                    self.generatePreview();
                }
            });

            $(document).on('click', '#selected-fields-list .gt-field-remove', function (e) {
                e.stopPropagation();
                e.preventDefault();
                var fieldId = $(this).data('field-id');
                //console.log('Removing field:', fieldId);
                //console.log('Selected fields before removal:', self.selectedFields);

                if (fieldId && self.removeFieldFromSelection) {
                    self.removeFieldFromSelection(fieldId);
                    self.generatePreview();
                    //console.log('Selected fields after removal:', self.selectedFields);
                } else {
                    console.error('Cannot remove field - missing fieldId or removeFieldFromSelection method');
                }
            });

            // Mobile visibility toggle
            $(document).on('click', '#selected-fields-list .gt-field-mobile-toggle', function (e) {
                e.stopPropagation();
                e.preventDefault();
                var fieldId = $(this).data('field-id');
                var $button = $(this);

                // Initialize responsive settings if not exists
                if (!self.formFields[fieldId].responsive_settings) {
                    self.formFields[fieldId].responsive_settings = {};
                }

                // Toggle mobile visibility
                var currentVisibility = self.formFields[fieldId].responsive_settings.mobile_visible !== false;
                self.formFields[fieldId].responsive_settings.mobile_visible = !currentVisibility;

                // Update button visual state
                $button.toggleClass('active');

                // Update preview if needed
                self.generatePreview();

                // Log for verification
                //console.log('=== Mobile Toggle Clicked ===');
                //console.log('Field ID:', fieldId);
                //console.log('Previous visibility:', currentVisibility);
                //console.log('New visibility:', self.formFields[fieldId].responsive_settings.mobile_visible);
                //console.log('Full responsive settings:', self.formFields[fieldId].responsive_settings);
                //console.log('Button active class:', $button.hasClass('active'));
            });

            // Tablet visibility toggle
            $(document).on('click', '#selected-fields-list .gt-field-tablet-toggle', function (e) {
                e.stopPropagation();
                e.preventDefault();
                var fieldId = $(this).data('field-id');
                var $button = $(this);

                // Initialize responsive settings if not exists
                if (!self.formFields[fieldId].responsive_settings) {
                    self.formFields[fieldId].responsive_settings = {};
                }

                // Toggle tablet visibility
                var currentVisibility = self.formFields[fieldId].responsive_settings.tablet_visible !== false;
                self.formFields[fieldId].responsive_settings.tablet_visible = !currentVisibility;

                // Update button visual state
                $button.toggleClass('active');

                // Update preview if needed
                self.generatePreview();

                // Log for verification
                //console.log('=== Tablet Toggle Clicked ===');
                //console.log('Field ID:', fieldId);
                //console.log('Previous visibility:', currentVisibility);
                //console.log('New visibility:', self.formFields[fieldId].responsive_settings.tablet_visible);
                //console.log('Full responsive settings:', self.formFields[fieldId].responsive_settings);
                //console.log('Button active class:', $button.hasClass('active'));
            });

            // Delete table events
            $(document).on('click', '.gt-delete-table', function (e) {
                e.preventDefault();
                var tableId = $(this).data('table-id');
                var nonce = $(this).data('nonce');

                if (confirm(gtAdmin.strings.confirm_delete || 'Are you sure you want to delete this table?')) {
                    self.deleteTable(tableId, nonce);
                }
            });

            // #1748 — email alert rule add/remove
            $(document).off('click', '#gt-add-alert-rule');
            $(document).on('click', '#gt-add-alert-rule', function (e) {
                e.preventDefault();
                var row = $('<div class="gt-alert-rule" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;">' +
                    '<label>Field ID <input type="text" name="alert_field_id" style="width:80px;" placeholder="e.g. 3"></label>' +
                    '<label>Operator <select name="alert_operator"><option value="&gt;">&gt;</option><option value="&lt;">&lt;</option><option value="=">=</option><option value="&gt;=">&gt;=</option><option value="&lt;=">&lt;=</option><option value="contains">contains</option></select></label>' +
                    '<label>Threshold <input type="text" name="alert_threshold" style="width:100px;" placeholder="e.g. 1000"></label>' +
                    '<label>Recipient <input type="email" name="alert_recipient" style="width:200px;" placeholder="you@example.com"></label>' +
                    '<button type="button" class="button gt-remove-alert-rule">Remove</button>' +
                    '</div>');
                $('#gt-alert-rules-container').append(row);
            });
            $(document).on('click', '.gt-remove-alert-rule', function () {
                $(this).closest('.gt-alert-rule').remove();
            });

            // Duplicate table events (#1740)
            $(document).on('click', '.gt-duplicate-table', function (e) {
                e.preventDefault();
                var tableId = $(this).data('table-id');
                var nonce = $(this).data('nonce');

                if (confirm('Duplicate this table configuration?')) {
                    self.duplicateTable(tableId, nonce);
                }
            });
        }

    });

})(jQuery);
