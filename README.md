# 🤲 Spread'em

**Really spreading your WooCommerce database.**

Free. No upsells. No account required. Just install and go.

Spread'em works with the WooCommerce product catalogue you already use. Select products from the existing admin list, hit **🤲 Spread Em** in the bulk-actions menu, and open that data in a true spreadsheet view for fast inline editing — no page reloads, no clicking into individual product pages.

The core idea is simple: keep WooCommerce's existing catalogue and product data flow intact, then add the sheet view that makes bulk editing faster. Over time, the next big improvement is making catalogue selection and filtering smarter before you even open the sheet.

---

## Features

- **Spreadsheet view for existing WooCommerce data** — use the catalogue flow you already know, but open the selected products in one editable table with name, SKU, price, sale price, stock, weight, dimensions, status, visibility, categories, tags, attributes, description, and more
- **Variable products + variations** — parent and all child variations load together; variation-only fields (price, stock, SKU) are editable; inherited fields (name, categories) are shown read-only on children
- **Per-column override** — type a value once and push it to every visible row with one click
- **Custom meta columns** — non-protected custom meta fields automatically appear as individual editable columns, just like a WooCommerce CSV export
- **Checkbox pickers for categories & tags** — tick/untick terms directly in the cell; changes are written back with `wp_set_post_terms`
- **Drag-to-resize columns** — grab the right edge of any column header and drag; widths are saved to `localStorage` and persist between sessions
- **Undo last change** — single-level undo for quick corrections
- **Save only what changed** — only dirty rows are sent to the server, keeping AJAX payloads minimal
- **Hide parent rows** — toggle to show variations only for cleaner editing of large catalogues
- **Zero dependencies beyond WooCommerce** — no external libraries, no SaaS, no telemetry

---

## Installation

### From GitHub (manual)

1. Download the latest zip from the [Releases page](https://github.com/egglose5/spread-em/releases) **or** clone the repo:
   ```
   git clone https://github.com/egglose5/spread-em.git
   ```
2. Copy the `spread-em` folder into your WordPress `wp-content/plugins/` directory.
3. In WordPress admin go to **Plugins → Installed Plugins** and activate **Spread Em**.
4. WooCommerce must be active — the plugin will warn you if it isn't.

### Usage

1. Go to **WooCommerce → Products** (or **Products** in the admin menu).
2. Select one or more products using the checkboxes.
3. In the **Bulk actions** dropdown choose **🤲 Spread Em** and click **Apply**.
4. Edit cells inline. Changed rows are highlighted yellow.
5. Click **Save All Changes** when done.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 7.0+ |
| PHP | 8.0+ |

---

## Screenshots

> *Coming soon — PRs welcome!*

---

## Contributing

Pull requests are welcome. For major changes please open an issue first to discuss what you'd like to change.

1. Fork the repo
2. Create a feature branch (`git checkout -b feature/my-thing`)
3. Commit your changes
4. Open a pull request against `main`

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — free to use, modify, and distribute.

---

## Roadmap / Known TODOs

- Better catalogue selection and filtering before opening the spreadsheet view: status, category, tag, stock status, price range, product type, date modified, and name/SKU search
- WordPress.org plugin directory submission
- Screenshot / demo GIF in this README

---

*Built because the default WooCommerce bulk editor is painful. Hope it helps.*
