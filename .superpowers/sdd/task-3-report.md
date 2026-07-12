# Task 3 Report — PHP visibility filter helpers and render exclusion

**Status:** DONE
**Commit:** 6ea89a58
**Branch:** feature/2370-visibility-toggles

## Test summary

19/19 tests passed in `test-issue-2370-visibility.php` (up from 4/14 before implementation).

Regression suites all green:
- `test-issue-2366-manual-source.php`: 61/61 PASSED
- `test-issue-2369-cell-content.php`: 14/14 PASSED
- `test-issue-2376-manual-labels-persistence.php`: 13/13 PASSED
- Vitest `admin-manual-grid-editor.test.js` + `admin-manual-grid-row-col-ops.test.js`: 71 PASS, 0 FAIL
- PHP lint: no syntax errors in any modified file

## Changes made

### `includes/services/class-tc-manual-rows-service.php`
- Added `filter_visible_rows(array $rows): array` — filters out rows where `_tc_hidden` is truthy, re-indexes result.
- Added `filter_visible_columns(array $columns): array` — filters out column defs where `hidden` is truthy, re-indexes result.

### `includes/class-tc-shortcode.php`
- In `render_manual_source_table`: applied `filter_visible_rows()` immediately after `get_rows()` to exclude hidden rows before HTML render.
- Replaced the bare `foreach ($settings['manual_columns'])` loop with `filter_visible_columns()` call first, so hidden column keys are excluded from `$column_keys`.

### `includes/class-tc-ajax.php`
- In `save_manual_rows` known_keys guard: added `_tc_hidden` to the whitelist so the flag survives sanitization.
- In `save_manual_rows` cell sanitization loop: added a `_tc_hidden` branch that casts to `(bool)` instead of running through `sanitize_manual_cell_value`.
- In `save_manual_rows` label-merge: built a `$col_by_key` map alongside `$label_map`; added `hidden` flag copy from incoming to existing column defs per the spec.
- In builder preview (`$data_source_type === 'manual'` block): applied `filter_visible_rows()` to preview rows and `filter_visible_columns()` to preview columns; replaced the inner label-lookup loop with iteration over the pre-filtered `$visible_cols`.

## Concerns

None. The `apply_manual_column_labels` method in `class-tc-admin.php` already preserved `hidden` because it only mutates `['label']` — no change was needed there (confirmed by verifying the spec note and running the regression suite).
