# TableCrafter

A powerful WordPress plugin that creates beautiful, interactive data tables with advanced frontend editing, bulk operations, filtering, and comprehensive customization options.

## Quick Start

1. **Install & Activate**: Upload and activate the plugin in WordPress
2. **Navigate**: Go to **Advanced Tables** in your WordPress admin  
3. **Create Table**: Use the drag-and-drop table builder to create your first table
4. **Display**: Use the `[gravity_table id="X"]` shortcode to display tables

## Key Features

- ✅ **Spreadsheet-Like Editing**: Click anywhere in a cell to edit, just like Excel
- ✅ **Advanced Filtering**: Field-specific filters with multi-select and date ranges
- ✅ **Mobile-Responsive**: Complete card layout system for mobile devices
- ✅ **Mixed Date Format Support**: Handles MM/DD/YYYY, M/D/YYYY, and YYYY-MM-DD automatically
- ✅ **Role-Based Access**: Granular control over who can view and edit data
- ✅ **Lookup Fields**: Full support for user, post, and custom table relationships
- ✅ **Bulk Operations**: Delete, export, edit multiple entries at once
- ✅ **Service-Oriented Architecture**: 100+ stateless service classes under
  `includes/services/` keep call sites thin and testable.

## What's New

For per-release detail see [`readme.txt`](readme.txt) (the canonical
WordPress.org-style changelog) or [`docs/CHANGELOG.md`](docs/CHANGELOG.md)
(Keep-a-Changelog format). This README's previous "What's New in v4.1.x"
sections froze at 2026-02-28 and never tracked the v4.7.x line; the
canonical sources are the source of truth.

Today's headline (v4.7.x line):

- **Pipeline self-defense** — every release ZIP is auto-verified by
  `tools/lint-php.sh` (PHP `-l` + WordPress deprecation audit) and
  `tools/test-all.sh` (309-file test suite) before it's built. See
  [docs/RELEASE.md](docs/RELEASE.md) for the full pipeline.
- **In-plugin documentation** — WP admin > Advanced Tables >
  Documentation has a "What's New" section that's auto-updated as
  part of the per-release docs policy.

## Earlier "What's New" releases (v4.1.x and older)

The detailed per-release "What's New in vX.Y.Z" sections that previously
lived here covered v2.0.1 through v4.1.7 — the rebranding to "Advanced
Data Tables for Gravity Forms" (v4.1.0), the Thickbox integration (v4.0),
the production-grade debugging system (v3.2), the mobile-card layout
(v3.0), the service-oriented architecture rewrite (v2.0.1), and many
others.

That history is preserved verbatim in [`readme.txt`](readme.txt) and
[`docs/CHANGELOG.md`](docs/CHANGELOG.md). The 30+ section blocks were
trimmed from this README to keep the front page useful — once the list
crosses ~5 entries, every reader either skims it or scrolls past it.

## Architecture notes

The plugin currently ships:

### Frontend JS

- `assets/js/frontend.js` — small bootstrap (~230 lines), down from a
  6,359-line monolith via the #830/#832/#833 split arc.
- `assets/js/frontend/` — **~50 cohesive modules** that attach methods
  to `GravityTable.prototype` via `Object.assign` and `prototype.X = function`
  patterns. Each module is independently testable in jsdom. Examples:
  - `core.js` (defines `window.GTCore`, loaded first)
  - `selection.js`, `pagination.js`, `sort.js`, `search.js`
  - `edit-history.js`, `edit-field.js`, `edit-save.js`,
    `edit-keyboard-nav.js`, `edit-ajs-lookup.js`
  - `bind-events.js`, `bind-entry-events.js`
  - `render-entries.js`, `entry-row.js`, `responsive-card-view.js`
  - `print.js`, `toolbar-export.js`, `export.js`
  - `ssp.js`, `load-entries.js`, `column-resizing.js`,
    `column-reorder-dnd.js`, `column-order-persistence.js`
  - `filter-panel.js`, `filter-apply.js`, `filter-state-persistence.js`,
    `typeahead.js`, `totals.js`
  - `conditional-format.js`, `alignment-resolver.js`,
    `row-link-resolver.js`, `link-anchor.js`, `presets.js`,
    `scroll-indicators.js`, `responsive-visibility.js`,
    `detail-popup.js`, `detail-row.js`, `actions-cell.js`,
    `text-cell.js`, `toggle-cell.js`, `entry-cell.js`,
    `lookup-dropdown.js`, `date-inputs.js`, `row-actions.js`,
    `row-edit.js`, `delete-entry.js`, `edit-indicators.js`,
    `table-utils.js`, `url-state.js`, `init.js`,
    `post-render-gates.js`, `observers.js`, `util.js`, `a11y-keyboard.js`.

### Admin JS

- `admin/js/admin.js` — table builder + admin UI core.
- `assets/js/admin/` — split modules (`save-table.js`,
  `bind-events.js`, etc.) extracted from the monolith as the admin
  surface grew.

### PHP

- **100+ stateless service classes** under `includes/services/class-tc-*.php`.
  `grep '^class TC_' includes/services/*.php` for the full list.
- Input sanitization and validation centralised in
  `class-tc-sanitization-service.php` and
  `class-tc-validation-service.php`.
- **Multi-source data architecture** — beyond Gravity Forms entries,
  tables can pull rows from:
  - **JSON data sources** (`includes/services/class-tc-json-source-service.php`,
    `class-tc-shortcode.php::render_json_source_table`).
  - **Airtable** (`class-tc-airtable-credential-service.php`,
    `class-tc-airtable-sync-engine.php`, `class-tc-airtable-rate-limiter.php`,
    `class-tc-airtable-audit-log.php`).
  - **Notion** (`class-tc-notion-sync-engine.php`,
    `class-tc-shortcode.php::render_notion_source_table`).
  - **WooCommerce products** (server-side processing via `ssp.js`).
- **Two-way sync substrate (#613)** — `sync_direction` setting (pull
  / push / two_way) with backwards-compatible legacy naming
  (`pull_only` / `push_only` / `bidirectional`). Push consumers in
  `class-tc-ajax.php` accept both naming conventions via alias map
  (#1011).

### Test infrastructure

- **497+ PHP contract tests** under `tests/test-issue-*.php`, run via
  `tools/test-all.sh` (~2.8s for the whole suite).
- **60+ behavioral vitest files** under `tests/js/*.test.js` (~900
  tests covering jsdom-driven prototype behavior).
- **Shared bundle helper** `tests/helpers/bundle.php` exports
  `gt_test_bundle_js(array $paths): string` — replaced ~32 per-test
  `gt_js_bundle_*` local helpers in the six-phase #891 arc
  (v4.184.0 → v4.189.0).
- **Behavioral vitest replacements** for the worst file-grep
  contracts (the ones that passed by substring coincidence): see
  `frontend-row-click-exclusions.test.js`,
  `frontend-selection.test.js` (multi-table scoping),
  `frontend-responsive-multi-table.test.js`. Each was TDD-verified
  by deliberately breaking the implementation to confirm RED before
  shipping.

Earlier versions of this README claimed specific "59% faster" / "73%
smaller bundle" / "2,314 → 555 lines" numbers — those came from the
abandoned vanilla-JS rewrite plan and were never validated against
shipping code. Removed in v4.7.153 to keep this section honest. The
real reduction came from the #830/#832/#833 split arc which moved
the runtime from a 6,359-line monolith into ~50 small modules without
changing customer-facing behavior — verified by per-slice browser
smoke and the vitest behavioral layer above.

## Author

**Fahad Murtaza @ iSuperCoder.com**  
Contact: [https://isupercoder.com/contact](https://isupercoder.com/contact)

## Support

For support, questions, or feature requests, please email: **<info@fahdmurtaza.com>**

---

**Version:** see the `Version:` header in `tablecrafter.php` (or the `TC_VERSION` constant) — the plugin header is the source of truth, this README will not be hand-updated each release.  
**Last Updated:** see commit history (this README is rewritten only when its structure changes; per-release notes live in `readme.txt` and `docs/CHANGELOG.md`).

