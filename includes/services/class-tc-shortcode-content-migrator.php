<?php
/**
 * TC_Shortcode_Content_Migrator - rewrite deprecated shortcodes in post content.
 *
 * Issue #2022 (convergence epic #2006, Phase 5). The deprecated
 * [gravity_table] / [gravity_tables] shortcodes stay registered for back-compat,
 * but pages still containing them should be migrated to [tablecrafter]. The
 * existing auto-migration only touches the table's stored shortcode column; this
 * rewrites the actual post_content, with a dry-run count first.
 *
 * Pure string helpers (rewrite / count_in / migrate_rows) carry the logic so the
 * admin AJAX handler is a thin $wpdb glue layer.
 */

class TC_Shortcode_Content_Migrator
{
    /** Matches the opening of a deprecated shortcode tag (word-boundary safe). */
    const PATTERN = '/\[gravity_tables?(?=[\s\]\/])/';

    /** Rewrite all deprecated shortcode openings to [tablecrafter. */
    public static function rewrite(string $content): string
    {
        return preg_replace(self::PATTERN, '[tablecrafter', $content);
    }

    /** Count deprecated shortcode occurrences in a string. */
    public static function count_in(string $content): int
    {
        return (int) preg_match_all(self::PATTERN, $content);
    }

    /**
     * Migrate an array of post rows ({ ID, post_content }).
     *
     * @param array<int,array{ID:int,post_content:string}> $rows
     * @return array{changed:int,occurrences:int,updates:array<int,string>}
     *         updates maps post ID => rewritten content (changed posts only).
     */
    public static function migrate_rows(array $rows): array
    {
        $changed     = 0;
        $occurrences = 0;
        $updates     = array();

        foreach ($rows as $row) {
            $id      = isset($row['ID']) ? (int) $row['ID'] : 0;
            $content = isset($row['post_content']) ? (string) $row['post_content'] : '';
            $count   = self::count_in($content);
            if ($count === 0) {
                continue;
            }
            $changed++;
            $occurrences += $count;
            $updates[$id] = self::rewrite($content);
        }

        return array(
            'changed'     => $changed,
            'occurrences' => $occurrences,
            'updates'     => $updates,
        );
    }
}
