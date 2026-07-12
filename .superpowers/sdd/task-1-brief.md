### Task 1: Failing vitest tests
File: tests/js/admin-manual-grid-editor-visibility.test.js
Tests (all failing at write time):
- toggleRowHidden(0) sets _tc_hidden true, isDirty true
- toggleRowHidden(0) again clears _tc_hidden
- isRowHidden(0) returns false initially
- isRowHidden(0) returns true after toggle
- visibleRowCount() counts correctly
- toggleColumnHidden(0) sets hidden:true, isDirty true
- isColumnHidden(0) returns false initially
- isColumnHidden(0) returns true after toggle
- visibleColCount() counts correctly
- getRows() includes _tc_hidden key in result
- getColumns() includes hidden key in result

