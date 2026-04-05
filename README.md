# 🤲 Spread'em

**Really spreading your WooCommerce database.**

Free. No upsells. No account required. Just install and go.

Spread'em works with the WooCommerce product catalogue you already use. Select products from the existing admin list, hit **🤲 Spread Em** in the bulk-actions menu, and open that data in a true spreadsheet view for fast inline editing — no page reloads, no clicking into individual product pages.

The core idea is simple: keep WooCommerce's existing catalogue and product data flow intact, then add the sheet view that makes bulk editing faster. Over time, the next big improvement is making catalogue selection and filtering smarter before you even open the sheet.

---

## Features

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

## Compatibility Notes

- **WooCommerce admin category filtering is intentionally adjusted** — by default, WooCommerce's admin product category filter matches only the exact selected term. Spread'em changes that behavior so selecting a parent category also includes products assigned to its child categories.
- **Why this matters** — the spreadsheet editor is launched from the existing WooCommerce product catalogue selection flow, so this change helps parent-category selection behave the way many store owners expect when preparing a bulk edit.
- **Compatibility implication** — if another plugin depends on the default exact-match-only behavior of the admin `product_cat` filter on the product list screen, test that interaction. Spread'em applies this change only in the admin main product query.

## Live Draft Sync

Spread'em includes a lightweight **admin-only live draft sync** that lets multiple admins see each other's in-progress catalogue edits without a full page refresh, and prevents ghost entries caused by unacknowledged writes.

### How it works

1. **Push** – Each time a cell value is confirmed (on blur / selection change), the delta is sent to the server with a short debounce (≈400 ms per field). The server stores the current in-progress value in a WP transient keyed to the shared editing session.
2. **Poll** – Each connected browser polls the server for changes since its last known version token. When another admin has pushed an update the server returns only the changed cells (delta), not the full dataset. If a cell currently has keyboard focus it is never overwritten.
3. **Presence** – Every poll heartbeats the current user into a shared presence map so all admins see who else is actively editing.
4. **Activity feed** – Push and save events are logged in a bounded activity feed (max 120 events, 30-minute TTL) visible to global-operator admins in the Live Operator Console.
5. **Direct messaging** – Admins can send instant messages to other active users within the editing session.

### Idempotency / ghost-entry prevention

Each live-push AJAX request carries a `client_request_id` UUID. The server records the last 50 processed IDs per session; if an identical ID arrives again (network retry), the response is returned immediately without re-processing, preventing duplicate activity events.

The final **Save All Changes** button is the only action that permanently commits edits to the WooCommerce database. The UI always waits for server acknowledgement before treating a save as successful.

### Tuning knobs

| Setting | Default | Description |
|---|---|---|
| `poll_interval` | `10000` ms (10 s) | Base polling cadence. Passed from PHP via `spreadEmData.live.poll_interval`. Increase for heavily loaded shared hosts. |
| Background backoff | `max(30 s, poll_interval × 3)` | When the browser tab is hidden the poll interval is automatically lengthened to reduce idle load. |
| Jitter | 0–1 s random | Added to every poll delay to spread concurrent sessions across the server. |
| Push debounce | 400 ms per field | Rapid keystrokes in a single cell are collapsed into one AJAX request. |
| Transient TTL | 1 hour (`HOUR_IN_SECONDS`) | How long session state persists server-side with no activity. |
| Activity event TTL | 30 minutes | Stale activity events are pruned on every read. |
| Presence TTL | 30 s | Users who stop polling drop out of the presence map. |

### Limitations

- **Not realtime** – Changes appear in peer browsers after the next poll cycle (default ≈10 s).
- **Admin-only** – All sync endpoints are gated behind `wp_ajax_*` (logged-in users) and plugin capability checks (`spread_em_live_individual_contributor` / `spread_em_live_global_operator`). Non-admin users are never exposed.
- **Requires JavaScript** – Live sync is entirely JS-driven; browsers with JS disabled will not receive peer edits.
- **Transient storage** – Session state uses WP transients. On object-cache-less shared hosts this writes to the `wp_options` table. The data is small (bounded by cell count and activity cap) and auto-expires.
- **Same WP install only** – Multi-site / multi-server setups sharing a single object cache will sync naturally; separate installs do not share session state.

---



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
