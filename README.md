![TableCrafter – Beautiful Data Tables for WordPress](https://ps.w.org/tablecrafter-wp-data-tables/assets/banner-1544x500.png)

# TableCrafter

**Turn Google Sheets, CSV, JSON, and Excel into searchable, beautiful WordPress tables. No code required.**

[![Version](https://img.shields.io/wordpress/plugin/v/tablecrafter-wp-data-tables?label=Version&color=21759b)](https://wordpress.org/plugins/tablecrafter-wp-data-tables/)
[![Rating](https://img.shields.io/wordpress/plugin/stars/tablecrafter-wp-data-tables?label=Rating&color=ffb900)](https://wordpress.org/plugins/tablecrafter-wp-data-tables/#reviews)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/tablecrafter-wp-data-tables?label=Downloads)](https://wordpress.org/plugins/tablecrafter-wp-data-tables/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](LICENSE)

---

![TableCrafter table showing search, filters, sorting, and responsive layout](https://ps.w.org/tablecrafter-wp-data-tables/assets/screenshot-1.png)

---

## What you get free

Connect any of the four built-in data sources and paste one shortcode. No API keys, no database schema, no row limits.

### Data sources

| Source | Notes |
|--------|-------|
| **JSON / REST API** | Any public endpoint; use `root=` to reach nested arrays |
| **CSV** | Remote URL, fetched server-side |
| **Google Sheets** | Any sheet set to "Anyone with the link" |
| **Excel (.xlsx)** | Upload or remote URL |

### Display and interaction

- Live search across all columns
- Column sorting (ascending / descending)
- Pagination with configurable page sizes
- Multi-select dropdown filters and date-range filters
- Status badge columns (color-coded by value)
- Star rating columns
- Sticky header row on scroll
- Mobile card layout, responsive by default
- No row limits imposed by the plugin

### Export and print

- Export to CSV, Excel, or JSON
- One-click print view with print-optimized layout

### WordPress integration

- **Gutenberg block** with live data preview while you design
- **Elementor widget** with live data preview
- `[tablecrafter]` shortcode usable in any page, post, or widget

---

## Install

### From WordPress.org (recommended)

Go to **Plugins > Add New**, search for **TableCrafter**, and click **Install Now**. Or use WP-CLI:

```bash
wp plugin install tablecrafter-wp-data-tables --activate
```

[View the plugin on WordPress.org](https://wordpress.org/plugins/tablecrafter-wp-data-tables/)

### Quick-start shortcode

Point the shortcode at any public data source and the table appears immediately:

```
[tablecrafter source="https://docs.google.com/spreadsheets/d/YOUR_SHEET_ID/edit"]
```

```
[tablecrafter source="https://api.example.com/products.json" root="data.items" include="name,price,sku"]
```

Use the visual builder under **TableCrafter > Create New** to generate your shortcode with a click instead of typing parameters by hand.

---

## Upgrade to Pro

[TableCrafter Pro](https://tablecrafter.com) adds live connected integrations, inline editing, and a full data-ops suite.

| Feature | Free | Pro |
|---------|:----:|:---:|
| JSON, CSV, Google Sheets, Excel | Yes | Yes |
| Search, sort, filters, pagination | Yes | Yes |
| CSV / Excel / JSON export and print | Yes | Yes |
| Mobile responsive layout | Yes | Yes |
| **Gravity Forms** entries as table rows | | Yes |
| **WooCommerce** products | | Yes |
| **Airtable** bases (live sync) | | Yes |
| **Notion** databases (live sync) | | Yes |
| **XML** feeds | | Yes |
| **External databases** (MySQL, SQL Server) | | Yes |
| **Inline spreadsheet editing** on the frontend | | Yes |
| Bulk fill and row duplication | | Yes |
| Two-way write-back sync | | Yes |
| Conditional formatting | | Yes |
| Role-based view and edit permissions | | Yes |
| Scheduled export and background refresh | | Yes |

[Get TableCrafter Pro at tablecrafter.com](https://tablecrafter.com)

---

## Development

This repository is the public distribution mirror for the free edition of TableCrafter. All source development, issue tracking, and pull requests live in the private upstream repository. Each release is produced by `tools/build-free.sh` and synced here as a direct commit -- plugin files in this repo should not be edited directly.

To report a bug or request a feature:

- Open a support thread on [WordPress.org](https://wordpress.org/support/plugin/tablecrafter-wp-data-tables/)
- Or contact us at [tablecrafter.com](https://tablecrafter.com)

---

[GitHub organization](https://github.com/TableCrafter) | [tablecrafter.com](https://tablecrafter.com) | [WordPress.org listing](https://wordpress.org/plugins/tablecrafter-wp-data-tables/)
