### Task 4: JS model implementation
File: assets/js/admin/manual-grid-editor.js
Add to TCManualGridModel:
- toggleRowHidden, isRowHidden, visibleRowCount
- toggleColumnHidden, isColumnHidden, visibleColCount
- Ensure getRows() and getColumns() pass through hidden flags

File: assets/js/admin/save-table.js
Update gt_save_manual_rows call: include `hidden` in manual_columns mapping.
Update getRows() is already included; _tc_hidden will be in row objects automatically.

