<?php
/**
 * TC_Pagination_Service
 *
 * Issue #560 — slice 1 of 3. Pure helper for the future server-side
 * pagination feature. Normalizes per-table settings, parses request
 * params (page / page_size / sort / search) into a canonical struct
 * with bounds clamping, and builds the canonical response envelope.
 *
 * Slice 2 wires the REST endpoint
 * `register_rest_route('gt/v1', '/tables/(?P<id>\d+)/rows')` plus
 * the per-table opt-in setting "Server-side pagination mode".
 * Slice 3 binds DataTables `serverSide: true` to the new endpoint
 * and ships a separate streaming CSV export for the full-table case.
 *
 * Plays with #437 (autoload), #546 (asset enqueue), #550 (cache
 * invalidation), #557 (data integrity).
 *
 * @since 4.7.50
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) { exit; }
// @codeCoverageIgnoreEnd
class TC_Pagination_Service {

    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 500;
    private const MIN_PAGE_SIZE = 1;
    private const TRUTHY_STRINGS = ['1', 'true', 'on', 'yes'];

    public static function defaults(): array {
        return [
            'server_side'        => false,
            'default_page_size'  => self::DEFAULT_PAGE_SIZE,
        ];
    }

    public static function normalize_settings(array $settings): array {
        $out = self::defaults();

        if (array_key_exists('server_side', $settings)) {
            $raw = $settings['server_side'];
            if ($raw === true || $raw === 1) {
                $out['server_side'] = true;
            } elseif (is_string($raw) && in_array(strtolower($raw), self::TRUTHY_STRINGS, true)) {
                $out['server_side'] = true;
            } else {
                $out['server_side'] = false;
            }
        }

        if (array_key_exists('default_page_size', $settings)) {
            $raw = $settings['default_page_size'];
            if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
                $n = (int) $raw;
                if ($n >= self::MIN_PAGE_SIZE && $n <= self::MAX_PAGE_SIZE) {
                    $out['default_page_size'] = $n;
                }
            }
        }

        return $out;
    }

    public static function is_enabled(array $settings): bool {
        return !empty($settings['server_side']);
    }

    /**
     * Parse the incoming request params into a canonical struct.
     *
     * @param array $params  raw request params (e.g. $_GET-shaped)
     * @param array $opts    optional overrides; supported: max_page_size
     * @return array{page:int,page_size:int,offset:int,sort_col:?string,sort_dir:string,search:string}
     */
    public static function parse_request(array $params, array $opts = []): array {
        $max_page_size = isset($opts['max_page_size']) && is_int($opts['max_page_size']) && $opts['max_page_size'] > 0
            ? $opts['max_page_size']
            : self::MAX_PAGE_SIZE;

        // page
        $page = 1;
        if (isset($params['page'])) {
            $raw = $params['page'];
            if (is_int($raw)) {
                $page = max(1, $raw);
            } elseif (is_string($raw) && ctype_digit($raw)) {
                $page = max(1, (int) $raw);
            }
        }

        // page_size
        $page_size = self::DEFAULT_PAGE_SIZE;
        if (isset($params['page_size'])) {
            $raw = $params['page_size'];
            if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
                $n = (int) $raw;
                if ($n >= self::MIN_PAGE_SIZE) {
                    $page_size = min($n, $max_page_size);
                }
            }
        }

        $offset = ($page - 1) * $page_size;

        // sort_col
        $sort_col = null;
        if (isset($params['sort_col']) && is_string($params['sort_col']) && $params['sort_col'] !== '') {
            $sort_col = $params['sort_col'];
        }

        // sort_dir
        $sort_dir = 'asc';
        if (isset($params['sort_dir']) && is_string($params['sort_dir'])) {
            $lower = strtolower($params['sort_dir']);
            if ($lower === 'asc' || $lower === 'desc') {
                $sort_dir = $lower;
            }
        }

        // search
        $search = '';
        if (isset($params['search']) && is_string($params['search'])) {
            $search = trim($params['search']);
        }

        return [
            'page'      => $page,
            'page_size' => $page_size,
            'offset'    => $offset,
            'sort_col'  => $sort_col,
            'sort_dir'  => $sort_dir,
            'search'    => $search,
        ];
    }

    /**
     * Build the canonical response envelope. `total_pages` is at
     * least 1 even when `total === 0` so the UI always shows one
     * page; `page_size <= 0` is defensively treated as 1.
     */
    public static function build_response(array $rows, int $total, int $page, int $page_size): array {
        $effective = $page_size > 0 ? $page_size : 1;
        $total_pages = $total > 0 ? (int) ceil($total / $effective) : 1;
        return [
            'rows'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'page_size'   => $page_size,
            'total_pages' => $total_pages,
        ];
    }
}
