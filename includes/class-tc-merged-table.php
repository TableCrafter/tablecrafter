<?php
/**
 * Merged-table support for Gravity Tables (#82)
 *
 * TC_Merged_Table aggregates entry rows from multiple source tables into a
 * single combined view. Each row is stamped with _source_table_id so the
 * frontend can route inline edits back to the correct underlying table.
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
class TC_Merged_Table {

    public function get_entries(array $source_table_ids, int $page = 1, int $per_page = 25): array {
        // #1135: GFAPI is a class in Gravity Forms, not a function.
        // The pre-slice-40 guard used function_exists('GFAPI') which was
        // always false in production, silently short-circuiting every
        // merged-table render to []. Switched to class_exists.
        if (empty($source_table_ids) || !class_exists('GFAPI')) {
            return [];
        }
        $page     = max(1, $page);
        $per_page = max(1, $per_page);
        $offset   = ($page - 1) * $per_page;
        $admin    = TC_Admin::get_instance();
        $all_rows = [];
        foreach ($source_table_ids as $table_id) {
            $table_id = (int) $table_id;
            $table    = $admin->get_table($table_id);
            if (!$table) { continue; }
            $form_id = (int) $table->form_id;
            $search  = ['status' => 'active'];
            $entries = GFAPI::get_entries($form_id, $search, null, ['page_size' => $per_page]);
            if (is_wp_error($entries)) { continue; }
            foreach ((array) $entries as $entry) {
                $entry['_source_table_id'] = $table_id;
                $all_rows[] = $entry;
            }
        }
        return array_slice($all_rows, $offset, $per_page);
    }

    /**
     * Merge using left-join: all left rows included; right columns merged in on key match.
     */
    public function left_join(array $left_entries, array $right_entries, string $left_key, string $right_key): array {
        $right_index = [];
        foreach ($right_entries as $row) {
            $val = $row[$right_key] ?? null;
            if ($val !== null) { $right_index[$val] = $row; }
        }
        $result = [];
        foreach ($left_entries as $left_row) {
            $key_val   = $left_row[$left_key] ?? null;
            $right_row = ($key_val !== null && isset($right_index[$key_val])) ? $right_index[$key_val] : [];
            $prefixed  = [];
            foreach ($right_row as $k => $v) { $prefixed['right_' . $k] = $v; }
            $result[] = array_merge($left_row, $prefixed);
        }
        return $result;
    }

    /**
     * Merge using inner-join: only rows with matching keys in both tables.
     */
    public function inner_join(array $left_entries, array $right_entries, string $left_key, string $right_key): array {
        $right_index = [];
        foreach ($right_entries as $row) {
            $val = $row[$right_key] ?? null;
            if ($val !== null) { $right_index[$val] = $row; }
        }
        $result = [];
        foreach ($left_entries as $left_row) {
            $key_val = $left_row[$left_key] ?? null;
            if ($key_val !== null && isset($right_index[$key_val])) {
                $prefixed = [];
                foreach ($right_index[$key_val] as $k => $v) { $prefixed['right_' . $k] = $v; }
                $result[] = array_merge($left_row, $prefixed);
            }
        }
        return $result;
    }

    public function get_total_count(array $source_table_ids): int {
        // #1135: see note on get_entries - GFAPI is a class, not a function.
        if (empty($source_table_ids) || !class_exists('GFAPI')) { return 0; }
        $admin = TC_Admin::get_instance();
        $total = 0;
        foreach ($source_table_ids as $table_id) {
            $table_id = (int) $table_id;
            $table    = $admin->get_table($table_id);
            if (!$table) { continue; }
            $form_id = (int) $table->form_id;
            $search  = ['status' => 'active'];
            $count   = GFAPI::count_entries($form_id, $search);
            if (!is_wp_error($count)) { $total += (int) $count; }
        }
        return $total;
    }
}
