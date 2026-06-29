<?php
/**
 * TC_TSV_Parser_Service
 *
 * Issue #516 — slice 1 of 3. Pure tab-separated-values parser for the
 * future "paste from Excel / Google Sheets / Numbers" admin
 * affordance. Excel-flavoured TSV: tab-delimited rows + a CSV-shaped
 * quoting convention where a cell wrapped in `"` preserves embedded
 * tabs and newlines, and `""` inside the quoted span unescapes to a
 * single `"`.
 *
 * No clipboard JS, no admin UI, no column-type detection in this
 * slice — those land in slices 2 and 3.
 *
 * Three primitives:
 *   parse($tsv)               → array<int,array<int,string>> rows × cells
 *   to_rows($tsv, $col_ids,
 *           $first_row_is_headers)  → array<int,array<string,string>>
 *   detect_dialect($payload)  → 'tsv' | 'csv' | 'unknown'
 *
 * @since 4.7.34
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_TSV_Parser_Service {

    /**
     * Parse a TSV string into rows × cells.
     *
     * Quoted cells preserve embedded tabs and newlines; `""` inside a
     * quoted span unescapes to a single `"`. Trailing newlines and
     * blank middle lines are stripped.
     */
    public static function parse(string $tsv): array {
        if ($tsv === '') {
            return [];
        }
        // Walk the input character by character — the embedded-newline
        // case in quoted cells means we can't do a simple `explode("\n")`
        // followed by a per-line `explode("\t")`.
        $rows = [];
        $current_row = [];
        $current_cell = '';
        $in_quotes = false;
        $len = strlen($tsv);

        $finalize_cell = function () use (&$current_row, &$current_cell) {
            $current_row[] = $current_cell;
            $current_cell = '';
        };
        $finalize_row = function () use (&$rows, &$current_row, &$current_cell, $finalize_cell) {
            $finalize_cell();
            // Strip empty rows (single empty cell).
            if (count($current_row) === 1 && $current_row[0] === '') {
                $current_row = [];
                return;
            }
            $rows[] = $current_row;
            $current_row = [];
        };

        for ($i = 0; $i < $len; $i++) {
            $ch = $tsv[$i];
            if ($in_quotes) {
                if ($ch === '"') {
                    // Look ahead for "" → escaped quote.
                    if ($i + 1 < $len && $tsv[$i + 1] === '"') {
                        $current_cell .= '"';
                        $i++;  // skip the second "
                        continue;
                    }
                    // End of quoted span.
                    $in_quotes = false;
                    continue;
                }
                $current_cell .= $ch;
                continue;
            }
            // Not in quotes.
            if ($ch === '"') {
                // Begin quoted span (only meaningful at start of cell;
                // a `"` mid-cell is treated as literal in canonical CSV
                // but Excel's TSV usage starts the quote at cell-start).
                if ($current_cell === '') {
                    $in_quotes = true;
                    continue;
                }
                $current_cell .= $ch;
                continue;
            }
            if ($ch === "\t") {
                $finalize_cell();
                continue;
            }
            if ($ch === "\n") {
                $finalize_row();
                continue;
            }
            if ($ch === "\r") {
                // CRLF: peek next; if it's \n, the \n branch will
                // finalize the row. If standalone \r (old Mac), treat
                // it as row separator.
                if ($i + 1 < $len && $tsv[$i + 1] === "\n") {
                    continue;  // let the \n handle it
                }
                $finalize_row();
                continue;
            }
            $current_cell .= $ch;
        }
        // Flush trailing cell / row if any content remains.
        if ($current_cell !== '' || count($current_row) > 0) {
            $finalize_row();
        }
        return $rows;
    }

    /**
     * Parse a TSV string and return associative rows.
     *
     * When $first_row_is_headers is true (default), the first row's
     * cells become keys for subsequent rows. When $column_ids is
     * supplied, cells are mapped to those ids by position regardless
     * of headers.
     */
    public static function to_rows(string $tsv, ?array $column_ids = null, bool $first_row_is_headers = true): array {
        $rows = self::parse($tsv);
        if (empty($rows)) {
            return [];
        }
        if ($column_ids !== null) {
            $out = [];
            $start = $first_row_is_headers ? 1 : 0;
            for ($i = $start; $i < count($rows); $i++) {
                $row = $rows[$i];
                $assoc = [];
                foreach ($column_ids as $idx => $col_id) {
                    $assoc[(string) $col_id] = $row[$idx] ?? '';
                }
                $out[] = $assoc;
            }
            return $out;
        }
        if (!$first_row_is_headers) {
            // No headers, no column_ids — emit rows keyed by integer.
            return $rows;
        }
        $headers = $rows[0];
        $out = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $assoc = [];
            foreach ($headers as $idx => $h) {
                $assoc[(string) $h] = $row[$idx] ?? '';
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /**
     * Detect the delimiter dialect of a clipboard payload.
     * Tabs win when both tabs and commas are present (Excel /
     * Sheets / Numbers all default to tab on copy).
     */
    public static function detect_dialect(string $payload): string {
        if (strpos($payload, "\t") !== false) {
            return 'tsv';
        }
        if (strpos($payload, ',') !== false) {
            return 'csv';
        }
        return 'unknown';
    }
}
