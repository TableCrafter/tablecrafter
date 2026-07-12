# Task 1 Report — Failing vitest for row/column visibility toggle model methods (#2370)

## What was done

Created `/Users/isupercoder/websites/tablecrafter/tc-work/tests/js/admin-manual-grid-editor-visibility.test.js` with 20 vitest tests covering all 8 new TCManualGridModel methods specified in the brief:

- `isRowHidden` — 1 test (initial false state)
- `toggleRowHidden` — 3 tests (set true, clear on second, isolation)
- `visibleRowCount` — 4 tests (all visible, decrement, all hidden, restore)
- `getRows _tc_hidden pass-through` — 2 tests (key present after toggle, absent on untouched rows)
- `isColumnHidden` — 1 test (initial false state)
- `toggleColumnHidden` — 3 tests (set true, clear on second, isolation)
- `visibleColCount` — 4 tests (all visible, decrement, all hidden, restore)
- `getColumns hidden pass-through` — 2 tests (key present after toggle, absent on untouched columns)

## Test results

- New visibility tests: **20 failing** (all `TypeError: m.<method> is not a function` — methods don't exist yet)
- Existing tests (admin-manual-grid-editor.test.js + admin-manual-grid-row-col-ops.test.js): **71 passing, 0 failing** — no regressions

## Commit

Hash: `d6c0f382`
Message: `test(builder): failing vitest for row/column visibility toggle model methods (#2370)`

## Concerns

One note on `getRows` / `_tc_hidden` pass-through: the current constructor deep-clones rows using only the column keys (`cols.forEach(...)`) which means `_tc_hidden` would be stripped on construction if it were in the input. The tests are written correctly — they call `toggleRowHidden()` AFTER construction (which will write directly to the internal row object), then call `getRows()` to verify the flag passes through. This means `getRows()` in Task 4 must return the full row object (including `_tc_hidden`) rather than filtering to only column keys. This is a design constraint Task 4 must account for.
