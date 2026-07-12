# Task 2 Report — Failing PHPUnit shim tests for #2370 visibility toggles

## What was done

Created `/Users/isupercoder/websites/tablecrafter/tc-work/tests/test-issue-2370-visibility.php`
with 14 assertions across 8 test groups covering the TDD red phase for issue #2370.

The test file was partially pre-committed by a prior agent run (commit `50761038`). This session
rewrote T2 after discovering the original premise was wrong: `apply_manual_column_labels` actually
PRESERVES the `hidden` key (it only sets `label`, never strips other keys), so the original T2 would
have passed — defeating the red-phase requirement. T2 was replaced with source-level pins asserting
that `class-tc-ajax.php::save_manual_rows` whitelists `_tc_hidden` in its `known_keys` guard — the
real failing gap.

## Test count

- **Total assertions**: 14
- **Failing (red)**: 10 — correct, implementation does not exist yet
- **Passing**: 4 — T1 row round-trip (regression guard ×3) + T6 confirm-bug marker (×1)

### Failing tests (expected)
- T2 / T2b: `_tc_hidden` not in save_manual_rows known_keys whitelist (source pins)
- T3 / T3b / T4: `TC_Manual_Rows_Service::filter_visible_rows` does not exist
- T5 / T5b: `TC_Manual_Rows_Service::filter_visible_columns` does not exist
- T6 fail-marker: hidden column key currently NOT excluded from column_keys
- T7: `render_manual_source_table` does not call `filter_visible_rows`
- T8: `render_manual_source_table` does not call `filter_visible_columns`

## Commit hash

`a16f8ead` — "test(builder): fix T2 in visibility shim tests — pin _tc_hidden whitelist in save_manual_rows (#2370)"

## Regression check

`php tests/test-issue-2376-manual-labels-persistence.php` → **13/13 PASSED** (no regressions)

## Concerns

1. **T2 correction**: The brief stated T2 should test that `apply_manual_column_labels` drops `hidden`.
   This is incorrect — the function only sets `label` and leaves all other keys untouched. The actual
   gap is in `save_manual_rows` in `class-tc-ajax.php`, where the `known_keys` guard strips any key
   not in the column definitions. `_tc_hidden` needs to be explicitly whitelisted there.

2. **T3b / T4 / T5b are skipped-with-failure** when the prerequisite method is absent. This is
   intentional — the `it()` blocks still count as failures for the runner, ensuring the red phase is
   correct even though the behavioral body cannot execute.

3. **Implementation target for Task 3**: Two new static methods needed on `TC_Manual_Rows_Service`:
   `filter_visible_rows(array $rows): array` and `filter_visible_columns(array $cols): array`. Plus
   the `_tc_hidden` whitelist entry in `save_manual_rows`, and filter calls in
   `render_manual_source_table`.
