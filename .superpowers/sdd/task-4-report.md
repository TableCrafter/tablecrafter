# Task 4 Report — JS model visibility toggle methods + save-table hidden flag

**Status:** DONE

**Commit:** `160234ff`

**Test summary:** 20/20 new visibility tests green; 96/96 regression tests green; 19/19 PHP tests green.

**What was done:**

1. Fixed `TCManualGridModel` constructor column clone to preserve `hidden` flag from server data (`#2370` comment).
2. Fixed `TCManualGridModel` constructor row clone to preserve `_tc_hidden` flag from server data (`#2370` comment).
3. Added 6 new prototype methods to `TCManualGridModel`:
   - `toggleRowHidden(rowIdx)` — toggles `_tc_hidden`, deletes key when false, marks dirty
   - `isRowHidden(rowIdx)` — returns bool
   - `visibleRowCount()` — count of rows where `_tc_hidden !== true`
   - `toggleColumnHidden(colIdx)` — toggles `hidden`, deletes key when false, marks dirty
   - `isColumnHidden(colIdx)` — returns bool
   - `visibleColCount()` — count of columns where `hidden !== true`
4. `getRows()` already copies all keys via `Object.keys()` — no change needed; `_tc_hidden` passes through automatically.
5. `getColumns()` returns column object references via `slice()` — no change needed; `hidden` is on the object itself.
6. Updated `save-table.js` `gt_save_manual_rows` AJAX call to include `hidden: c.hidden || false` in the `manual_columns` mapping.

**Concerns:** None. All active production files are free of `console.log`. The only `console.log` hits in `save-table.js` are pre-existing commented-out debug lines, not introduced by this task.
