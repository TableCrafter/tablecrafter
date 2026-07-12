# SDD Progress Ledger — #2370 Row/Column Visibility Toggles

Branch: feature/2370-visibility-toggles
Base commit (branch point): b3b2db74

## Tasks

- [ ] Task 1: Failing vitest tests (model: toggleRow/Column, dirty tracking, visibility guards)
- [ ] Task 2: Failing PHPUnit shim tests (row flag round-trip, column flag label-merge survival, render excludes hidden, export exclusion)
- [ ] Task 3: PHP implementation (row hidden flag in row_json; column hidden flag in manual_columns; save_manual_rows merge preserves hidden; render_manual_source_table filters hidden rows/cols; AJAX table data filters hidden)
- [ ] Task 4: JS model implementation (toggleRowHidden, toggleColumnHidden, isRowHidden, isColumnHidden, visibleRowCount, visibleColCount; getRows/getColumns pass hidden through; save-table.js posts hidden flags)
- [ ] Task 5: JS editor UI (grid render dims hidden rows/cols + eye-slash; row menu Hide/Show; col menu Hide/Show; last-visible-column/row guard warning)
- [ ] Task 6: E2E spec (manual-visibility.spec.js: create table, hide col via header menu, hide row via kebab, save, frontend renders neither, editor still shows both dimmed, unhide → return, afterAll cleanup)

## Log

- Task 1: complete (commit d6c0f38, red-phase: 20 fail/0 pass, 71 existing tests green)
- Task 2: complete (commit 5076103, red-phase: 8 fail/8 pass (1 round-trip PASS is intentional regression guard), 13/13 existing 2376 tests green). Design note: uses filter_visible_rows + filter_visible_columns as static helpers on TC_Manual_Rows_Service. Task 3 must add these helpers + wire in render_manual_source_table.
- Task 3: complete (commits a16f8ead + 6ea89a58, 19/19 PHP tests green, 71 vitest green, 61+14+13 regression tests green). filter_visible_rows + filter_visible_columns added to TC_Manual_Rows_Service. render_manual_source_table and builder preview AJAX path filter hidden rows+cols. save_manual_rows whitelists _tc_hidden.
- Task 3 review: APPROVED. Minor findings: (1) redundant class_exists guard in render_manual_source_table (dead code, harmless); (2) _tc_hidden could appear as column key in legacy "union of row keys" fallback (pre-existing gap, not introduced by this PR). Both non-blocking.
- Task 4: complete (commit 160234ff, 20/20 vitest visibility tests green, 71+17+17 regression tests green). Constructor now preserves _tc_hidden and hidden from server data.
- Task 4 review: APPROVED. Important finding from reviewer (hidden: false always sent) — verified safe: PHP uses array_key_exists + (bool) cast, not isset, so false correctly stored. No fix needed.
- Task 5: complete (commit c429f54e, 91 vitest green). _render dimmed hidden rows/cols, Hide/Show menu items, event handlers for toggle-hidden, CSS in table-builder.css.
- Task 5 review: NEEDS_FIXES — toggle buttons missing from outside-click exclusion selector (#2378 defense-in-depth rule). FIXED commit 3b0c0e1 (exclusion list now includes .gt-row-toggle-hidden, .gt-col-toggle-hidden); 20/20 visibility tests green.
- Task 6: complete (commit 6ad28d07, 5/5 E2E green + no regressions in manual-grid-editor/manual-cell-content).
- Final whole-branch review: NEEDS_FIXES → all addressed in commit feec5de2 (preview isset guard, known_keys bypass restored, _tc_hidden stored only when true, T2c/T2d payload pins, T2b window widened, redundant class_exists removed, dashicons-hidden marker, E2E dead code removed).
- Gates: vitest 2898/0, shim 21/21, php -l clean, E2E visibility 5/5 + cell-content 4/4 + grid-editor 3/3 (isolation rerun after parallel-run collision).
- SHIPPED: PR #2380 squash-merged to main (d9dd4ff7) 2026-07-10; issue #2370 closed; epic #2320 tally comment posted; E2E env down.
