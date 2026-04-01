=== Spread Em ===
Contributors: egglose5
Tags: woocommerce, products, bulk edit, spreadsheet, inventory
Requires at least: 6.0
Tested up to: 6.9.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce

Spread Em adds a spreadsheet editor to WooCommerce so you can bulk edit products inline from the existing catalog flow.

== Description ==

Spread Em is a practical spreadsheet editor that removes the middle man from the WooCommerce export and import process.

Instead of exporting products to CSV, editing in Excel or Sheets, and importing back, you can select products in the WordPress admin and edit them inline in a sheet-style table.

Core features:

* Spreadsheet view for existing WooCommerce product data
* Variable products and variations in one grid
* Per-column override to apply values across visible rows
* Custom meta fields exposed as editable columns
* Category and tag term pickers in-cell
* Drag-to-resize columns with persisted widths
* Undo last change
* Save only changed rows for smaller AJAX payloads

Design approach:

* Mirrors WooCommerce CSV exporter field structure where possible
* Uses WooCommerce product APIs for writes
* Keeps data flow compatible with standard WooCommerce behavior

== Installation ==

1. Upload the plugin folder to the /wp-content/plugins/ directory, or install from a zip in Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins menu in WordPress.
3. Ensure WooCommerce is installed and active.
4. Go to WooCommerce > Products (or Products), select products, choose Spread Em in Bulk actions, and click Apply.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

Yes. WooCommerce must be active.

= Does this replace the WooCommerce product editor? =

No. It adds a spreadsheet editing workflow for faster bulk updates.

= Can I edit variations? =

Yes. Parent products and child variations are loaded together.

= Will this work with custom metadata? =

Non-protected custom meta fields are surfaced as editable columns.

== Screenshots ==

1. Product selection from WooCommerce product list
2. Spreadsheet editor with inline editing
3. Variation rows and column override actions

== Changelog ==

= 1.0.0 =
* Initial public release.
* Spreadsheet editor for WooCommerce products.
* Inline bulk editing with dirty-row saves.
* Support for variable products and variations.
* Custom meta columns and taxonomy editing.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
