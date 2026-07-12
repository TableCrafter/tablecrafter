# Task 5 Report — Grid Editor UI (Visibility Toggles)

**Status:** DONE
**Commit:** c429f54e
**Branch:** feature/2370-visibility-toggles

## Test summary

91 vitest tests pass (0 failures) across:
- `tests/js/admin-manual-grid-editor-visibility.test.js`
- `tests/js/admin-manual-grid-editor.test.js`
- `tests/js/admin-manual-grid-row-col-ops.test.js`

## What was done

### `assets/js/admin/manual-grid-editor.js`

1. **`_render()` — column headers**: Added `gt-grid-col-hidden` class to `<th>` when column is hidden; added `&#9762;` hidden marker span inside `gt-col-header-inner`; added "Hide column" / "Show column" button to the column dropdown menu.

2. **`_render()` — data rows**: Added `gt-grid-row-hidden` class to `<tr>` when row is hidden; added `&#9762;` hidden marker span in the row-number cell after the row index number; added "Hide row" / "Show row" button to the row dropdown menu.

3. **`_render()` — data cells**: Added `gt-grid-col-hidden` class to each `<td>` cell whose column is hidden, so opacity can be applied independently of the header.

4. **`_bindEvents()` — `.gt-col-toggle-hidden`**: Click handler that closes the column menu, fires the last-visible guard alert when `visibleColCount() <= 1` and the column is currently visible, calls `model.toggleColumnHidden()`, then re-renders.

5. **`_bindEvents()` — `.gt-row-toggle-hidden`**: Click handler that closes the row menu, fires the last-visible guard alert when `visibleRowCount() <= 1` and the row is currently visible, calls `model.toggleRowHidden()`, then re-renders.

### `admin/css/table-builder.css`

Added new section "visibility toggles UI (#2370)" at end of file:
- `tr.gt-grid-row-hidden > td`: opacity 0.4 + subtle background tint
- `th.gt-grid-header.gt-grid-col-hidden`: opacity 0.4 + subtle background tint
- `td.gt-grid-cell.gt-grid-col-hidden`: opacity 0.4 + subtle background tint
- `.gt-visibility-hidden-marker`: small (11px) grey indicator

## Concerns

None. The guard alert fires before the toggle, per the brief spec (warns but proceeds). The vitest model tests are all model-only so they continue to pass unmodified.
