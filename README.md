# 🤲 Spread'em

**Really spreading your WooCommerce database.**

Free. No upsells. No account required. Just install and go.

Spread'em works with the WooCommerce product catalogue you already use. Select products from the existing admin list, hit **🤲 Spread Em** in the bulk-actions menu, and open that data in a true spreadsheet view for fast inline editing — no page reloads, no clicking into individual product pages.

The core idea is simple: keep WooCommerce's existing catalogue and product data flow intact, then add the sheet view that makes bulk editing faster. Over time, the next big improvement is making catalogue selection and filtering smarter before you even open the sheet.

---

## Features

- **Live draft sync** — in-progress catalogue edits are shared between all active admin sessions in near-real-time without a full page refresh (see [Live Draft Sync](#live-draft-sync) below)
- **Spreadsheet view for existing WooCommerce data** — use the catalogue flow you already know, but open the selected products in one editable table with name, SKU, price, sale price, stock, weight, dimensions, status, visibility, categories, tags, attributes, description, and more
- **Parent category filter includes child categories** — when filtering products in the WooCommerce admin catalogue, selecting a parent product category also includes products assigned to descendant categories
- **Variable products + variations** — parent and all child variations load together; variation-only fields (price, stock, SKU) are editable; inherited fields (name, categories) are shown read-only on children
- **Per-column override** — type a value once and push it to every visible row with one click
- **Custom meta columns** — non-protected custom meta fields automatically appear as individual editable columns, just like a WooCommerce CSV export
- **Checkbox pickers for categories & tags** — tick/untick terms directly in the cell; changes are written back with `wp_set_post_terms`
- **Drag-to-resize columns** — grab the right edge of any column header and drag; widths are saved to `localStorage` and persist between sessions
- **Undo last change** — single-level undo for quick corrections
- **Save only what changed** — only dirty rows are sent to the server, keeping AJAX payloads minimal
- **Hide parent rows** — toggle to show variations only for cleaner editing of large catalogues
- **Change log with before/after values** — every saved field change is recorded with old/new values, user, and timestamp
- **Save-state rollback** — each Save click is grouped into a save state so you can revert an entire save action from the log page
- **Per-change rollback** — revert individual field changes from the log table
- **Lightweight retention cap** — log history is automatically pruned to the most recent 15 entries
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

## Testing

Automated tests now exist under `tests/` and run with PHPUnit.

### Local setup

1. Install Composer (if not already installed).
2. Install dev dependencies:
   ```
   composer install
   ```
3. Run the test suite:
   ```
   composer test
   ```

### Current coverage

- Capability mapping and permission checks in `SpreadEm_Permissions`
- Live activity feed pruning, bounding, and scope filtering in `SpreadEm_Ajax`
- Live scope access checks, direct-message filtering, and presence pruning in `SpreadEm_Ajax`

If `composer` or `phpunit` is not installed on your machine yet, tests cannot execute until one of those tools is available.

---

## Live Draft Sync

Spread Em includes a lightweight mechanism that lets multiple admins see each other's **in-progress catalogue edits** (before Save is clicked) without a full page refresh.

### How it works

1. Every time an admin edits a cell, the change is queued for a **debounced push** to the server (default 500 ms after the last keystroke).  
2. The push is sent to the `spread_em_save_draft` AJAX endpoint, which stores it in a WordPress transient under a session-specific key.  
3. Other admins on the same editor session poll the `spread_em_poll_draft` endpoint at a configurable interval (default **10 seconds**).  
4. When the server version has not advanced since the client's last token the response is a tiny `{"has_updates":false}` JSON object — no DOM update occurs and the DB write is skipped, keeping shared-hosting load minimal.  
5. When there are new deltas the server returns only the changed `(product_id, field, value)` tuples and the editor applies them to any cell that is not currently focused.

### Ghost entry prevention

- Each push request carries a **`client_request_id`** (a UUID generated in the browser). If the same request is retried after a network hiccup the server recognises the UUID and returns the original token without re-applying the change, so retries are fully idempotent.
- A new row is never permanently added to the UI until the server acknowledges it.

### Tuning knobs

| Config key | Default | Description |
|---|---|---|
| `poll_interval` | 10 000 ms | Draft poll interval when the browser tab is visible |
| `poll_hidden_interval` | 30 000 ms | Draft poll interval when the browser tab is hidden |
| `debounce_ms` | 500 ms | How long to wait after the last keystroke before sending a draft push |
| `DRAFT_TTL` | 120 s | Server-side transient TTL; drafts expire automatically without a cleanup job |

These are set in `SpreadEm_Admin::enqueue_assets()` (PHP) and read by `spreadEmData.live` (JavaScript). To change them without editing plugin files, hook into `admin_enqueue_scripts` at a later priority and call `wp_localize_script` with updated values.

### Limitations

- **Not real-time** — changes appear within one poll interval (up to 10 s by default), not instantly.  
- **Admin-only** — all endpoints require the `spread_em_live_individual_contributor` or `spread_em_live_global_operator` capability; unauthenticated requests are rejected.  
- **Requires JavaScript** — the feature degrades silently if JS is disabled.  
- **Transient-backed** — on installations that disable WordPress transients or use a persistent object cache with an aggressive eviction policy, drafts may not be shared between processes. Standard MySQL-backed transients work without extra configuration.  
- **Single shared session** — all admins editing the same workspace share one draft session; granular per-user conflict resolution (e.g., merge dialogs) is not implemented.

---



## Compatibility Notes

- **WooCommerce admin category filtering is intentionally adjusted** — by default, WooCommerce's admin product category filter matches only the exact selected term. Spread'em changes that behavior so selecting a parent category also includes products assigned to its child categories.
- **Why this matters** — the spreadsheet editor is launched from the existing WooCommerce product catalogue selection flow, so this change helps parent-category selection behave the way many store owners expect when preparing a bulk edit.
- **Compatibility implication** — if another plugin depends on the default exact-match-only behavior of the admin `product_cat` filter on the product list screen, test that interaction. Spread'em applies this change only in the admin main product query.

## Permissions Model

Spread'em uses plugin capabilities (not hardcoded role names) so role-builder plugins can assign responsibilities as a drop-in setup.

Capabilities:

- `spread_em_use_editor`
- `spread_em_live_individual_contributor`
- `spread_em_live_global_operator`
- `spread_em_view_logs`
- `spread_em_revert_changes`
- `spread_em_send_im`

Default grants:

- `administrator`: all capabilities
- `shop_manager`: all except `spread_em_live_global_operator`

This mapping is filterable through `spread_em_default_capability_map` for custom deployments.

---

## Screenshots

- `spread-em/screenshot-1.png` — WooCommerce product list with Spread Em in Bulk Actions
- `spread-em/screenshot-2.png` — Spreadsheet editor with inline product editing

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
- WordPress.org plugin directory submission checklist completion
- Screenshot / demo GIF in this README

---

*Built because the default WooCommerce bulk editor is painful. Hope it helps.*
