### Task 3: PHP implementation
Files to modify:
- includes/class-tc-ajax.php: save_manual_rows — add `_tc_hidden` to allowed keys; update column label merge to preserve `hidden` flag; filter hidden rows/cols in AJAX data path for manual tables
- includes/class-tc-shortcode.php: render_manual_source_table — filter hidden rows and hidden column keys before passing to render_external_source_table_html

