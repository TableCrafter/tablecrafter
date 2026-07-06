=== TableCrafter – Data to Beautiful Tables ===
Contributors: fahdi
Tags: gravity forms, editable table, google sheets, datatables, airtable
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 8.0.41
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Editable WordPress tables from JSON, CSV & Google Sheets with sort, search & filters. Pro adds Gravity Forms, Airtable & inline editing.

== Description ==

TableCrafter builds fast, responsive, sortable data tables in WordPress from the sources you already use - no code required. Add a shortcode (or the TableCrafter block / Elementor widget) and you are done. Upgrade to Pro to edit your data inline, right on the frontend, like a spreadsheet.

**Free data sources**

* **JSON / REST APIs** (any public endpoint)
* **CSV** files (URL)
* **Public Google Sheets**
* **Excel** (.xlsx) files

**Free features**

* Gutenberg **block** + **Elementor** widget + `[tablecrafter]` shortcode
* Sorting, live search, pagination, and column labels
* Responsive / mobile layouts and accessibility
* CSV / Excel / PDF export
* One-click **demo tables** to get started in seconds

This plugin is the successor to the original "TableCrafter - Data to Beautiful Tables" plugin. Existing installs continue to work - your tables, settings, and shortcodes are preserved, so the upgrade is seamless and fully backward compatible (your existing data and shortcodes keep working).

== Upgrade to Pro ==

[TableCrafter Pro](https://tablecrafter.com) unlocks the connected integrations and editing suite:

* **Gravity Forms** entries and **WooCommerce** products
* **Airtable** and **Notion** databases as live tables
* **XML** feeds and **External Databases** (MySQL / MS SQL)
* **Frontend inline editing**, bulk fill, and row duplication
* Two-way sync / write-back (Airtable, Notion, External DB)
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

1. Table dashboard: live, searchable tables from any data source with one click column sorting.
2. Inline editing: click any cell to edit directly in the table, just like a spreadsheet.
3. Source picker: connect Gravity Forms, Google Sheets, Airtable, Notion, CSV, JSON or an external database in seconds.
4. Column builder: configure display labels, field mapping, filters, and visibility per column with drag and drop ordering.
5. Export: download filtered table data as CSV, Excel, or JSON with a single click.
6. Mobile view: fully responsive tables with touch friendly interactions on any screen size.

== Changelog ==

= 8.0.41 =
* JSON export option added alongside CSV and Excel download.
* Advanced search grammar: type quoted phrases, -word to exclude, field:value, or comparison operators (>, <, >=, <=) directly in the search box on standard tables.
* Inline editing cell-type parity: url, datetime, multiselect, checkbox_group, and color cells now render dedicated input widgets with type-aware validation when editing inline.
* WP.org listing screenshot captions updated to reflect current feature set.

= 8.0.40 =
* **The All Tables list now shows each table's data source (#2271).** The old "Gravity Form" column rendered "Form ID: 0" for every non-Gravity-Forms table. The new "Source" column shows the actual source -- Gravity Forms (with the form name), JSON / REST, CSV, Google Sheets, Airtable, Notion, External Database, WooCommerce -- so mixed-source sites can tell tables apart at a glance.

= 8.0.39 =
* **Readable field-picker chip labels for JSON, CSV, XML, and Google Sheets (#2247).** The column picker in the table builder now shows humanized labels (e.g. "Product Name" instead of product_name) for these sources, consistent with Airtable, Notion, and External DB chips added in 8.0.36-8.0.37.
* **Pro: External Database tables can now be viewed by the public (#2254).** Each table has a new "Allow public viewing" checkbox. When disabled, logged-out visitors see a clean "This table is not available." message.

= 8.0.38 =
* **Dashboard stats redesigned.** Cards now show Total, In use (tables actually embedded on a post/page), one card per data source in use, and Trash (hidden when empty; matches the Trash tab). Plus a one-time self-heal that folds orphaned pre-Trash-system deletes into the Trash tab as restorable entries.

= 8.0.37 =
* **Pro data sources configure live in the builder.** In TableCrafter Pro, Airtable, Notion, and External Database tables now load their columns into the field picker and show a live sample preview without saving first. Also fixes a Pro bug where External Database tables never rendered due to an unregistered capability.

= 8.0.36 =
* **Readable headers for external-source tables (#2205, #2245).** JSON / CSV / XML / Google Sheets tables now humanize raw column keys in their headers -- `product_name` shows as "Product Name", saved column labels win, and known acronyms (ID, URL, SKU) stay upper-case. Applies to both the frontend table and the builder live preview.

= 8.0.35 =
* Cleaner table builder: removed the redundant per-step Save buttons (the floating Save covers every step).
* Refreshed the dashboard "What's New" list, which had gone stale.

= 8.0.34 =
* Fixed: the dashboard "Total" tables count included trashed tables, so it could read much higher than the number of real tables. It now counts only your live tables.

= 8.0.33 =
* Maintenance release. No functional changes to the free plugin (a Pro-only plugin-update-routing fix).

= 8.0.32 =
* Fixed: tables set to server-side processing rendered no rows. The entry-fetch guard used a check that never matched (`function_exists` on a class method), so every request returned an empty result. Server-side tables now load their entries.

= 8.0.31 =
* Improved (Pro): building a WooCommerce products table now auto-loads the product columns (Product, SKU, Price, Stock, Rating, Add to Cart) into the field picker and shows a live preview in the builder — previously the column picker and preview were empty.

= 8.0.30 =
* Fixed (Pro): WooCommerce product tables now render. They previously showed "Gravity Forms is required for this table" because the render path had no WooCommerce case. Product links, prices, stock and add-to-cart now display.

= 8.0.29 =
* Fixed: on the Welcome screen, the "Gravity Forms is not active" notice was unreadable inside the colored header; it now appears above it.
* Fixed: the "Start from a template" buttons now confirm when a table is created (they were creating the table but showing no feedback).
* Improved: the account-page upgrade button and the template result buttons now match the TableCrafter brand.

= 8.0.28 =
* Fixed: the upgrade button on the License & Account page could overflow its card on narrow widths; it now stays inside.
* Improved: redesigned the Welcome / "Start from a template" screen in the TableCrafter brand (teal header, branded template cards and buttons).

= 8.0.27 =
* Fixed: the "Rate plugin" link now opens the WordPress.org review form so you can actually leave a review (it previously just landed on the plugin page).

= 8.0.26 =
* Fixed: the License &amp; Account page had inaccurate upgrade copy — it priced Pro at $9.99/mo (it's $7.99), advertised a 10-day trial (it's 7), and listed Free features (unlimited tables, JSON) as Pro. Corrected the pricing, trial length, and Pro feature list, and added a clearer price display.

= 8.0.25 =
* Improved: redesigned the in-plugin upgrade card with an on-brand look and an accurate Pro pitch (frontend editing, bulk operations, advanced filters, and premium data sources) — no longer lists "unlimited tables" as a Pro perk, since that's free.

= 8.0.24 =
* Fixed: the "Gravity Forms is not active" admin notice now lists the correct free data sources (JSON, CSV, Google Sheets, Excel) and notes that Gravity Forms entry tables are a Pro feature.
* Improved: the license-activation screen now shows where to find your license key, with links to your account and pricing.

= 8.0.23 =
* Fixed: free activation on a fresh site. The Freemius opt-in was missing the WordPress.org-compliant flag, so "Allow & Continue" could error with an invalid-license message; free installs now connect (or skip) cleanly.

= 8.0.22 =
* Changed: the premium integrations — Gravity Forms, WooCommerce and Airtable — are now Pro features (joining Notion, XML and External DB). The free tier covers JSON, CSV, Google Sheets and Excel. Free users opening a Pro-source table see an upgrade prompt.

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

Full history: https://github.com/TableCrafter/tablecrafter-pro/blob/main/docs/CHANGELOG.md
