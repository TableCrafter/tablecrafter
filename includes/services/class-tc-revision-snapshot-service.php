<?php
/**
 * TC_Revision_Snapshot_Service
 *
 * Issue #536 — slice 1 of 3. Pure helper for table revision history.
 * Builds revision payloads, summarizes diffs between revisions,
 * applies retention policy.
 *
 * Slice 2 ships the DB migration (`{prefix}gt_revisions` table) and
 * wires the save-path hook in `TC_Admin::save_table()` to call
 * `make_snapshot()` and persist via the new repository. Slice 3 ships
 * the admin UI (browse / preview side-by-side / restore) modeled on
 * WordPress core's post-revision UX.
 *
 * @since 4.7.43
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Revision_Snapshot_Service {

    /**
     * Build a revision row from a table snapshot.
     *
     * @return array{table_id:int,payload:string,user_id:int,created_at:string}
     */
    public static function make_snapshot(array $table, int $user_id, ?DateTimeImmutable $now = null): array {
        if ($now === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        return [
            'table_id'   => (int) ($table['id'] ?? 0),
            'payload'    => json_encode($table) ?: '{}',
            'user_id'    => $user_id,
            'created_at' => $now->format('c'),
        ];
    }

    /**
     * Short human-readable diff summary between two table snapshots.
     */
    public static function summarize_diff(?array $previous, array $current): string {
        if ($previous === null) {
            return 'Initial version';
        }

        $changes = [];

        $prev_title = (string) ($previous['title'] ?? '');
        $curr_title = (string) ($current['title']  ?? '');
        if ($prev_title !== $curr_title) {
            $changes[] = sprintf('title changed (%s → %s)', $prev_title, $curr_title);
        }

        $prev_cols = is_array($previous['columns'] ?? null) ? count($previous['columns']) : 0;
        $curr_cols = is_array($current['columns']  ?? null) ? count($current['columns'])  : 0;
        if ($prev_cols !== $curr_cols) {
            $delta = $curr_cols - $prev_cols;
            $changes[] = sprintf('%d column%s %s', abs($delta), abs($delta) === 1 ? '' : 's', $delta > 0 ? 'added' : 'removed');
        }

        $prev_rows = is_array($previous['rows'] ?? null) ? count($previous['rows']) : 0;
        $curr_rows = is_array($current['rows']  ?? null) ? count($current['rows'])  : 0;
        if ($prev_rows !== $curr_rows) {
            $delta = $curr_rows - $prev_rows;
            $changes[] = sprintf('%d row%s %s', abs($delta), abs($delta) === 1 ? '' : 's', $delta > 0 ? 'added' : 'removed');
        }

        $prev_settings = $previous['settings'] ?? [];
        $curr_settings = $current['settings']  ?? [];
        if ($prev_settings !== $curr_settings) {
            $changes[] = 'settings updated';
        }

        if (empty($changes)) {
            return 'No changes';
        }
        return implode('; ', $changes);
    }

    /**
     * #1615 - shape stored revisions (most-recent-first, payload as a
     * JSON string) into list rows for the History modal. Each entry's
     * summary diffs it against the NEXT OLDER snapshot; the oldest
     * reads "Initial version"; unreadable payloads degrade per entry.
     *
     * @param array<int,array{table_id:int,payload:string,user_id:int,created_at:string}> $revisions
     * @return array<int,array{index:int,created_at:string,user_id:int,summary:string}>
     */
    public static function summaries_for_admin(array $revisions): array {
        $decoded = [];
        foreach ($revisions as $rev) {
            $payload = is_array($rev) && isset($rev['payload']) && is_string($rev['payload'])
                ? json_decode($rev['payload'], true)
                : null;
            $decoded[] = is_array($payload) ? $payload : null;
        }
        $out = [];
        $count = count($revisions);
        for ($i = 0; $i < $count; $i++) {
            $rev = is_array($revisions[$i]) ? $revisions[$i] : [];
            if ($decoded[$i] === null) {
                $summary = 'Snapshot unreadable';
            } else {
                $older = null;
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($decoded[$j] !== null) {
                        $older = $decoded[$j];
                        break;
                    }
                }
                $summary = self::summarize_diff($older, $decoded[$i]);
            }
            $out[] = [
                'index'      => $i,
                'created_at' => (string) ($rev['created_at'] ?? ''),
                'user_id'    => (int) ($rev['user_id'] ?? 0),
                'summary'    => $summary,
            ];
        }
        return $out;
    }

    /**
     * Apply retention policy: KEEP the most recent $keep_n AND any
     * revision within $keep_days of $now. Returns the list of
     * revisions to keep.
     *
     * @param array<int,array{revision_id:int,created_at:string}> $revisions
     */
    public static function apply_retention(array $revisions, int $keep_n = 5, int $keep_days = 0, ?DateTimeImmutable $now = null): array {
        if ($keep_n <= 0 && $keep_days <= 0) {
            return $revisions;
        }
        if ($now === null) {
            // @codeCoverageIgnoreStart
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            // @codeCoverageIgnoreEnd
        }

        // Sort revisions by created_at descending (most recent first).
        $sorted = $revisions;
        usort($sorted, function ($a, $b) {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $tb <=> $ta;  // desc
        });

        $keep_by_id = [];

        if ($keep_n > 0) {
            $top_n = array_slice($sorted, 0, $keep_n);
            foreach ($top_n as $rev) {
                $id = (string) ($rev['revision_id'] ?? '');
                if ($id !== '') {
                    $keep_by_id[$id] = $rev;
                }
            }
        }

        if ($keep_days > 0) {
            $cutoff = $now->modify('-' . $keep_days . ' days')->getTimestamp();
            foreach ($sorted as $rev) {
                $ts = strtotime((string) ($rev['created_at'] ?? '')) ?: 0;
                if ($ts >= $cutoff) {
                    $id = (string) ($rev['revision_id'] ?? '');
                    if ($id !== '') {
                        $keep_by_id[$id] = $rev;
                    }
                }
            }
        }

        return array_values($keep_by_id);
    }

    /**
     * #536 slice 2a — Storage helpers.
     *
     * `option_key($table_id)` returns the WP option key under which a
     * given table's revisions array lives. Returns empty string for
     * non-positive ids so callers can short-circuit cleanly.
     */
    public static function option_key(int $table_id): string {
        if ($table_id <= 0) {
            return '';
        }
        return 'gt_revisions_table_' . $table_id;
    }

    /**
     * Append a snapshot to the per-table revisions list, then keep
     * the last $keep_n by created_at desc. The reader/writer
     * callables let tests inject an in-memory store; production
     * callers pass `[get_option, update_option]`.
     *
     * @param int      $table_id
     * @param array    $snapshot   produced by make_snapshot()
     * @param callable $reader     (string $key) => array|null
     * @param callable $writer     (string $key, array $value) => bool
     * @param int      $keep_n     retention count (default 5)
     */
    public static function persist(int $table_id, array $snapshot, callable $reader, callable $writer, int $keep_n = 5): bool {
        $key = self::option_key($table_id);
        if ($key === '') {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        $current = $reader($key);
        if (!is_array($current)) {
            $current = [];
        }
        $current[] = $snapshot;

        if ($keep_n > 0 && count($current) > $keep_n) {
            // Sort by created_at desc; slice top $keep_n.
            usort($current, function ($a, $b) {
                $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
                $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
                return $tb <=> $ta;
            });
            $current = array_slice($current, 0, $keep_n);
        }

        return (bool) $writer($key, $current);
    }

    /**
     * Read the per-table revisions list. Returns [] when missing.
     *
     * @param int      $table_id
     * @param callable $reader   (string $key) => array|null
     * @return array<int, array>
     */
    public static function load(int $table_id, callable $reader): array {
        $key = self::option_key($table_id);
        if ($key === '') {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }
        $current = $reader($key);
        return is_array($current) ? $current : [];
    }

    /**
     * Set difference: revisions in $all that are not in $kept (by
     * `revision_id`). Slice 2's repository uses this to issue DELETE
     * statements after `apply_retention()` decides what to keep.
     */
    public static function reasons_to_drop(array $all, array $kept): array {
        $kept_ids = array_flip(array_map(function ($r) {
            return (string) ($r['revision_id'] ?? '');
        }, $kept));
        $out = [];
        foreach ($all as $rev) {
            $id = (string) ($rev['revision_id'] ?? '');
            if ($id === '' || !isset($kept_ids[$id])) {
                $out[] = $rev;
            }
        }
        return $out;
    }
}
