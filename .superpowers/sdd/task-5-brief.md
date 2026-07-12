### Task 5: JS editor UI
File: assets/js/admin/manual-grid-editor.js
- _render(): add `gt-grid-row-hidden` class to hidden rows; add eye-slash marker in row number cell; add `gt-grid-col-hidden` class to hidden col headers and cells.
- Row menu HTML: add "Hide row" / "Show row" button.
- Column menu HTML: add "Hide column" / "Show column" button.
- Add click handlers for hide/show row and hide/show column.
- Last-visible guard: before toggling, check visibleRowCount()/visibleColCount(); if 1, alert warning but proceed.

File: assets/css/admin/manual-grid.css (or wherever grid CSS lives)
- Add .gt-grid-row-hidden and .gt-grid-col-hidden styles (opacity:0.4 + indicator).

