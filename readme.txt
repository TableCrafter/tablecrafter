=== TableCrafter – Data to Beautiful Tables ===
Contributors: fahdi
Tags: gravity forms, editable table, google sheets, datatables, airtable
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 8.0.21
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Editable data tables for WordPress: show and edit Gravity Forms, Google Sheets, Airtable, CSV and JSON data with sorting, search and filters.

== Description ==

TableCrafter builds fast, responsive, sortable data tables in WordPress from the sources you already use - no code required. Add a shortcode (or the TableCrafter block / Elementor widget) and you are done. Upgrade to Pro to edit your data inline, right on the frontend, like a spreadsheet.

**Free data sources**

* **Gravity Forms** entries
* **WooCommerce** products
* **JSON / REST APIs** (any public endpoint)
* **CSV** files (URL)
* **Public Google Sheets**
* **Airtable** (read)

**Free features**

* Gutenberg **block** + **Elementor** widget + `[tablecrafter]` shortcode
* Sorting, live search, pagination, and column labels
* Responsive / mobile layouts and accessibility
* CSV / Excel / PDF export
* One-click **demo tables** to get started in seconds

This plugin is the successor to the original "TableCrafter - Data to Beautiful Tables" plugin. Existing installs continue to work - your tables, settings, and shortcodes are preserved, so the upgrade is seamless and fully backward compatible (your existing data and shortcodes keep working).

== Upgrade to Pro ==

[TableCrafter Pro](https://tablecrafter.com) unlocks the connected sources and editing suite:

* **Notion** databases as live tables
* **XML** feeds and **External Databases** (MySQL / MS SQL)
* **Private Google Sheets** and **Airtable two-way sync** (write-back)
* **Frontend inline editing**, bulk fill, and row duplication
* Advanced filters, conditional formatting, role-based permissions, scheduled export, and background (SWR) refresh

<!-- fs_premium_only_begin -->
Pro support, licensing, and automatic updates are delivered through your TableCrafter account.
<!-- fs_premium_only_end -->

== Installation ==

1. Upload the `tablecrafter-wp-data-tables` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin.
3. Open **TableCrafter → Create New**, pick a data source, and add the generated shortcode to any page.

== Usage ==

### Visual builder (recommended)

Go to the **TableCrafter** admin menu, pick a data source, toggle the options you want (search, filters, export), preview, and copy the generated shortcode:

    [tablecrafter id="123"]

### Inline shortcode (no builder needed)

You can also point the shortcode straight at a JSON API, CSV file, or public Google Sheet:

    [tablecrafter source="https://api.example.com/data.json"]

**Parameters:**

* `source` - URL to a JSON API, CSV file, or public Google Sheet.
* `root` - dot-path to the data array inside a JSON response (e.g. `root="data.results"`).
* `include` - comma-separated list of columns to show, in order (e.g. `include="name,price,symbol"`).
* `exclude` - comma-separated list of columns to hide.
* `id` - render a table you built in the visual builder.

**Examples:**

Nested JSON, curated columns:

    [tablecrafter source="https://api.example.com/items.json" root="items.list" include="name,price"]

A public Google Sheet (set sharing to "Anyone with the link"):

    [tablecrafter source="https://docs.google.com/spreadsheets/d/SHEET_ID/edit"]

== Frequently Asked Questions ==

= Do I need Gravity Forms? =
No. Gravity Forms is one source among many - JSON, CSV, and public Google Sheets work without it.

= I used the old TableCrafter free plugin. Will my shortcodes still work? =
Yes. Existing tables, settings, and shortcodes are preserved, including the inline `[tablecrafter source="..."]` form with `root`, `include`, and `exclude`.

= Does this store my source data in the WordPress database? =
For live sources (JSON / CSV / Google Sheets) it fetches on demand and caches the result temporarily to keep your site fast - it does not permanently copy the data into your database.

= Does it work with Elementor? =
Yes - there is a TableCrafter widget with a live data preview while you design, plus a Gutenberg block and the shortcode.

= Is it secure? =
Yes. Remote URLs are fetched server-side through an SSRF guard that blocks internal/loopback addresses, and all admin actions use WordPress capability and nonce checks.

= What if my API has CORS issues? =
There are none to worry about: remote sources are fetched by your server, not the browser, so browser CORS restrictions never apply.

= Can I customize the table styling? =
Yes. Tables use standard HTML markup and CSS variables, so you can override colors, spacing, and more from your theme's CSS.

= How do I get Notion, XML, External DB, private Sheets, or inline editing? =
Those are part of TableCrafter Pro - see the Upgrade to Pro section.

== Screenshots ==

1. Inline, spreadsheet-style editing of your data right on the frontend.
2. Build a table from any source - Gravity Forms, Google Sheets, Airtable, CSV, JSON or WooCommerce.
3. Sorting, live search, filtering and pagination out of the box.
4. Responsive layout that adapts to phones and tablets.
5. One-click demo tables to get started in seconds.

== Changelog ==

= 8.0.21 =
* Fixed: a packaging issue forced PHP 8.3 even though the plugin supports lower. The plugin now correctly runs on PHP 8.1+ (the bundled spreadsheet library's floor). Resolves a fatal error on PHP 8.1/8.2 sites.
* Changed: minimum PHP is now stated as 8.1 (matches the bundled libraries).

= 8.0.20 =
* Fixed: inline table auto-refresh (auto_refresh / refresh_interval) now works again — the settings were being dropped before they took effect. Live tables poll and update on schedule as intended.

= 8.0.19 =
* New (Pro, foundation): a Support area in the admin to manage customer support threads. This is phase one of an AI-assisted support system with human takeover coming in future updates.

= 8.0.18 =
* Performance: large external-source tables now stay fast — only the current page's rows are kept in the page at a time, so tables with thousands of rows sort, filter, and paginate smoothly.

= 8.0.17 =
* New: "Start from a template" — create a ready-to-edit table in one click from 5 prebuilt templates (Inventory, Business Directory, CRM Pipeline, Event List, Load Tracker), each with sample data and columns already set up.

= 8.0.16 =
* New: embed any table on another website. Each table now has a "Copy embed code" button that gives you an <iframe> snippet; the embedded view is public and read-only, with a small "Made with TableCrafter" link (removed on Pro).

= 8.0.15 =
* New: tables now auto-format your data into beautiful cells — ISO dates become readable dates, large numbers get thousands separators, and links become clickable, all automatically (years and short IDs are left untouched).

= 8.0.14 =
* New: a "Plans: Free vs Pro" section in the docs with a clear Free/Pro badge on every data source and feature, so you can see at a glance what's included and what Pro unlocks.
* New: when you pick a Pro-only data source in the table builder, an in-context note now explains it's a Pro feature with an upgrade link — no more guessing.

= 8.0.13 =
* Fixed: the plugin's uninstall cleanup now runs through the licensing SDK's uninstall hook instead of a separate uninstall.php, so updates deploy cleanly. Same result — deleting the plugin removes its tables, options and scheduled tasks.

= 8.0.12 =
* New: inline tables can auto-refresh again — the classic `auto_refresh`, `refresh_interval`, `refresh_indicator`, `refresh_countdown` and `refresh_last_updated` settings now poll the source and update the table in place.
* New: restored inline Airtable sources (`source="airtable://base/table?token=..."`) from the previous major version — Airtable display stays in the free version.
* Fixed: old admin bookmarks (`?page=tablecrafter-wp-data-tables`) now redirect to the current screen instead of 404ing.
* Fixed: inline tables again carry the classic `.tablecrafter-container` wrapper class, so theme CSS that targeted it keeps working.
* Added: an `uninstall.php` so deleting the plugin fully removes its tables, options and scheduled tasks.

= 8.0.11 =
* Fixed: Elementor "TableCrafter Table" widgets built on an inline data-source URL (from the previous major version) render again — the inline Data Source URL / columns / toggles controls are back and take precedence over Table ID.

= 8.0.10 =
* Fixed: the free build now bundles the licensing SDK (it could be missing in some builds, causing a fatal error) and no longer ships internal development files. The SDK load is also guarded so it can never fatal.

= 8.0.9 =
* Fixed: posts using the previous version's `tablecrafter/data-table` block now render again. The block is re-registered and maps to the inline data-source renderer, and the editor recognizes it instead of showing "unsupported block".

= 8.0.8 =
* New: inline-source tables now honor the classic `per_page` setting and the `search` / `export` toggles, and can show a CSV export button (`export="true"`). Restores 3.5.x interactive parity (search + click-to-sort + pagination already worked).

= 8.0.7 =
* New: backward compatibility for the classic inline shortcode - `[tablecrafter source="..." root="..." include="..." exclude="..."]` once again renders JSON, CSV, and public Google Sheet URLs directly, so shortcodes from the previous free plugin keep working. Restored the Usage and parameter documentation and expanded the FAQ.

= 8.0.6 =
* New: after TableCrafter does something useful for you (a table goes live, you save an inline edit, or you export data) it shows a single, dismissible note asking for a WordPress.org review. Never on a timer; "Maybe later" snoozes it and "Don't show again" hides it for good.

= 8.0.5 =
* New: an Activation funnel panel on the TableCrafter dashboard shows how far this install has progressed through onboarding (activated, opened the builder, created a table, published a table, saved an inline edit, exported). Stored locally only - nothing leaves your site.

= 8.0.4 =
* Improved: clearer WordPress.org listing - the short description and intro now lead with editable, multi-source tables, plus a Screenshots section.

= 8.0.3 =
* Changed: the "What's new" admin notice now describes the v8 product instead of v7, and re-appears once for anyone who dismissed the older notice.

= 8.0.2 =
* New: Google Sheets one-click demo on the getting-started screen (requires internet; the JSON/CSV demos still work offline).
* Fixed: the table builder no longer shows the Gravity Form dropdown for non-Gravity-Forms data sources.

= 8.0.1 =
* Maintenance and version alignment with the Pro release. No changes to free features. (Pro adds an External DB connection-management screen.)

= 8.0.0 =
* Converged into a single source-agnostic product. Gravity Forms is now one data source among many.
* One-click demo tables + first-activation welcome screen.
* CSV / XML / Google Sheets builder parity (auto-load columns + inline preview).
* Feature-based plans (no row/column caps on the free tier).

Full history: https://github.com/TableCrafter/tablecrafter/blob/main/docs/CHANGELOG.md
