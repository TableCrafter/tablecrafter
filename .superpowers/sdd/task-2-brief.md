### Task 2: Failing PHPUnit shim tests
File: tests/test-issue-2370-visibility.php
Tests (all failing at write time):
- Row flag round-trip: replace_rows saves row with _tc_hidden; get_rows returns it
- Column hidden flag survives label merge in save_manual_rows (mock the wpdb calls)
- render_manual_source_table excludes hidden rows (mock get_rows returning mix of hidden/visible)
- render_manual_source_table excludes hidden column from column_keys
- hidden column not in rendered HTML headers
- hidden row data not in rendered HTML

