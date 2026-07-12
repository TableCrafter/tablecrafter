### Task 6: E2E spec
File: tests/e2e/manual-visibility.spec.js
Flow:
1. Login, create manual table with 2 cols × 3 rows, fill some cells.
2. Hide column 1 via column header dropdown ("Hide column").
3. Hide row 1 via row kebab menu ("Hide row").
4. Save table (primary save + gt_save_manual_rows).
5. Navigate to frontend embed page → verify hidden col/row absent.
6. Return to builder → verify hidden col/row still visible (dimmed).
7. Unhide via menu ("Show column", "Show row").
8. Save → frontend shows both again.
9. afterAll: delete created table + resetSeededTable().
