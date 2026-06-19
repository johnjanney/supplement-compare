# Operator Runbook — Supplement Compare

Day-to-day instructions for the site operator. As features ship, the matching
section below is filled in. Until then, each section reads "TBD".

This file is the operator's reference. Architectural rationale belongs in
[`PROJECTBRIEF.md`](PROJECTBRIEF.md); version history belongs in
[`CHANGELOG.md`](CHANGELOG.md); deferred decisions belong in
[`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).

---

## 1. Installing and updating the plugin

The plugin source lives under `plugin/` in this repo. To install on the live
site:

1. Package the plugin directory as a zip:

   ```bash
   scripts/package-plugin.sh
   ```

   This reads the current version from the plugin header, stages
   `plugin/` under a `supplement-compare/` top-level directory (which
   WordPress expects — the slug, header text domain, and zip dir all
   stay aligned), strips `.gitkeep` placeholders and WSL Zone.Identifier
   cruft, and writes `supplement-compare-X.Y.Z.zip` at the repo root.

   Requires `zip` (Ubuntu/WSL2: `sudo apt install zip`).

   The script also cross-checks that the plugin header version matches
   the `SUPPLEMENT_COMPARE_VERSION` constant — if they disagree, it
   refuses to build until you fix them with `scripts/bump-version.sh`.

2. In WordPress admin → Plugins → Add New → Upload Plugin, upload the `.zip`.

3. Click **Activate**. Activation runs `Supcomp_Activator::activate`, which
   creates the eight `wp_supcomp_*` tables and seeds default options
   (currency = USD, staleness thresholds 48h / 168h, default affiliate
   disclosure copy).

**Verifying the upload took.** The version shown in WP Admin → Plugins next
to "Supplement Compare" must match the version in `plugin/supplement-compare.php`
and `CHANGELOG.md`. If it doesn't, you uploaded an older build. This is
load-bearing — every functional change bumps the version for exactly this
reason (PROJECTBRIEF.md §11).

**Updating.** Repeat the steps above with a newer `.zip`. WordPress's
"Replace" flow handles overwriting. On boot, `Supcomp_Installer::maybe_upgrade()`
detects a stale schema-version option and re-runs `dbDelta`, which adds any
new columns or indexes without disturbing existing data.

**Deactivation** is safe — it does nothing destructive. **Uninstall**
(clicking "Delete" on the plugin) currently does nothing either; intentional,
because we'd rather leave orphan tables than risk silently wiping operator
curation work. See `plugin/uninstall.php` for the deferred decision.

## 2. Refreshing products from inside WordPress (the new way, v1.3.0+)

As of v1.3.0 you can extract products directly from WP Admin — no Python,
no SSH, no CLI. **v1.4.0** added WooCommerce; **v1.6.0** adds the generic
JSON-LD fallback for sites that publish schema.org Product schema in
their HTML (typical of Magento, BigCommerce, Squarespace Commerce, and
any site running an SEO plugin like Yoast or Rank Math).

**One-time setup per site:**

1. **Add the merchant** under **Supplement Compare → Merchants** if it's
   not there yet. The merchant row carries the affiliate-URL template
   that `/out/{id}` redirects use — without a merchant link, extracted
   offers can be inserted but the Buy buttons won't fire.
2. **Add the extractor site** under **Supplement Compare → Extractor
   Sites → Add new**:
   - **Slug** — short identifier (e.g. `examplestore`).
   - **Label** — display name.
   - **Site URL** — the public store URL (e.g. `https://examplestore.com`).
     The extractor probes `/products.json` (Shopify) and
     `/wp-json/wc/store/v1/products` (Woo) under this base.
   - **Platform hint** — leave on `auto` to let the extractor cascade
     Shopify → Woo → generic JSON-LD. Pin to a specific platform if a
     site supports multiple endpoints or you want to skip the probe
     overhead. `generic` is the broadest match (any site with schema.org
     Product JSON-LD in their HTML and a discoverable XML sitemap) but
     also the slowest — each product is a separate HTML fetch and parse.
     `wix` uses the same generic JSON-LD engine (Wix stores expose
     schema.org Product data with quirky key casing the handler tolerates)
     but skips the Shopify/Woo probes and labels offers `source = wix`.
     You don't have to pin `wix` for the extractor to *find* a Wix site —
     `auto` reaches it through the generic step — pin it only when you
     already know the platform and want the offers labeled accordingly.
     `json` is for client-rendered (single-page-app) storefronts that serve
     **no** product HTML at all — see "JSON-API storefronts" below. It is
     never reached by `auto`; you must pin it and supply a mapping.
   - **Crawl all sitemap URLs** — generic JSON-LD only; off by default.
     Normally the generic handler keeps only sitemap URLs whose path looks
     like a product (`/product/`, `/shop/`, `/p/`, …). Some modern
     storefronts — headless / single-page-app builds (e.g. a Next.js site
     fronted by a CDN) — serve their products at top-level slugs like
     `/my-product` with no such prefix, *and* their `/wp-json` or other
     platform APIs may be firewalled off. For those, the path filter finds
     nothing (or only category pages). Tick this box and the handler instead
     fetches **every** URL in the sitemap and keeps the ones that carry
     `schema.org` Product structured data, ignoring the rest. It costs extra
     HTTP fetches (spread across scheduled pages, so it won't time out), so
     leave it **off** for normal Shopify / Woo / WordPress stores. Worked
     example: `example.com` (Next.js on a CDN, products at flat
     slugs, `/wp-json` blocked) — pin **Platform = generic** and tick this
     box.
   - **JSON handler mapping** — only used when Platform hint = `json`. The
     declarative map from the merchant's JSON API onto offer fields. See
     "JSON-API storefronts" below.
   - **Merchant** — pick the linked Merchants row. Required for
     `/out/{id}` to fire downstream.
   - **Enabled** — leave checked.

### JSON-API storefronts (single-page-app sites)

Some modern stores render entirely in the browser: the page you get back is
an empty shell (`<div id="root"></div>`) and a JavaScript bundle that fetches
the catalogue from a JSON API after load. The Shopify, Woo, and generic
JSON-LD handlers all come back empty on these — there is no server-rendered
product markup to read, and `/products.json` / `/wp-json` return the shell or
404. But the underlying API is usually a clean, public JSON feed, and the
`json` handler reads it directly.

Setup:

1. **Find the API.** Open the storefront in your browser, open DevTools →
   **Network**, filter to **Fetch/XHR**, and reload. Look for the request
   that returns product JSON (an array of products with names and prices).
   Its URL is your `list_url` — often on a different host, e.g.
   `https://api.example.com/v1/products`.
2. **Set Platform hint = `json`** and paste a mapping into **JSON handler
   mapping**. The mapping tells the handler where the products are and which
   source field fills which offer column. A worked example for a store whose
   feed looks like `{"products":[{"name":…,"variants":[{"price":…}]}]}`:

   ```json
   {
     "list_url": "https://api.example.com/v1/products",
     "pagination": { "mode": "none" },
     "products_path": "products",
     "variants_path": "variants",
     "fields": {
       "product_title":     "name",
       "source_product_id": "id",
       "product_type":      "category",
       "currency":          "currency",
       "sku":               "@variant.sku",
       "source_variant_id": "@variant.id",
       "current_price":     "@variant.price",
       "regular_price":     "@variant.price",
       "stock_status":      { "from": "in_stock", "transform": "bool_to_status" }
     },
     "raw_attributes": ["form", "@variant.dosage", "@variant.dosage_unit"]
   }
   ```

   - `products_path` / `variants_path` are dot-paths into the response
     (`data.items` works too). Omit `variants_path` for one row per product.
   - A path prefixed `@variant.` is read from the current variant; everything
     else is read from the product.
   - A field can map to a **fallback list**, e.g. `"source_product_id": ["sku",
     "slug"]` — the first non-empty value wins. This matters most for
     `source_product_id`, the offer's identity/key: if any product has a blank
     value for your chosen field (some Woo feeds leave SKU empty on a few
     products), keying on it collapses those products onto one row and orphans
     them. Fall back to an always-present unique field (`slug` or `id`) so every
     product gets a stable key.
   - `pagination`: `{"mode":"none"}` if the feed returns everything in one
     call; `{"mode":"page","param":"page","size":100}` if it pages via a
     query parameter.
   - **Stock**: map a status string directly, or derive one from a
     boolean/quantity with a `*_to_status` transform. A raw inventory count
     is never stored — if what you map isn't a recognized status it becomes
     `unknown`, by design (the site is a price ledger, not a stock counter).
   - `raw_attributes` captures extra fields (form, dosage, volume-tier
     prices) into `raw_attributes_json` for the downstream parser; they are
     not surfaced as columns.
3. **Click "Test mapping"** (next to Save). It fetches page 1 and shows the
   first few mapped rows *without saving*. If the table is empty or a column
   is blank, fix the path and test again. Save once it looks right.

#### Product URL rewrite (headless / backend-host leaks)

Some headless storefronts publish a **backend or staging product URL** in
their feed instead of the public one — e.g. a Next.js frontend over
WooCommerce whose API returns `https://wp.store.com/slug/` or a
`*.wpcomstaging.com` host. Shipping those as buy links is wrong (they point at
the admin/staging host). The **Product URL rewrite** field (any platform, not
just `json`) fixes them after extraction:

```json
{
  "from_host": "wp.store.com",
  "to_host": "www.store.com",
  "from_path_prefix": "/product/",
  "to_path_prefix": "/products/",
  "strip_trailing_slash": true
}
```

A URL is only rewritten when its host matches `from_host`, so correct URLs are
never touched. Drop `from_path_prefix`/`to_path_prefix` if the path is the same
on both hosts. The "Test mapping" preview shows the rewritten `source_product_url`.

#### Worked example: example.com (Next.js + headless WooCommerce)

example.com is a Next.js storefront whose WooCommerce *Store API* is
firewalled (so the Woo handler 403s) and whose products sit at flat slugs (so
generic crawl-all was the only option — slow, ~one fetch per product). But it
exposes the full Woo catalogue at `https://example.com/api/products`.
Pin Platform = `json` with:

```json
{
  "list_url": "https://example.com/api/products",
  "pagination": { "mode": "none" },
  "products_path": "",
  "fields": {
    "product_title":     "name",
    "source_product_id": ["sku", "slug"],
    "sku":               "sku",
    "current_price":     "price",
    "regular_price":     "regular_price",
    "sale_price":        "sale_price",
    "product_type":      "type",
    "stock_status":      { "from": "stock_status", "transform": "woo_stock_to_status" },
    "source_product_url": "permalink"
  },
  "currency_default": "USD",
  "raw_attributes": ["slug", "categories"]
}
```

…and a Product URL rewrite (the feed's `permalink` uses the backend host):

```json
{ "from_host": "wp.example.com", "to_host": "example.com", "strip_trailing_slash": true }
```

`products_path: ""` because the response *is* the product array (no wrapper
object). One call replaces the per-product crawl. **Before switching a working
crawl-all site over, confirm the API returns the full catalogue** — Test
mapping shows the row count; compare it to the site's current offer count. If
the API returns fewer, it's a capped subset — stay on crawl-all.

**Running an extract:**

- **Per-site**: click **Run now** in the Actions column of the
  Extractor Sites list. The button queues an Action Scheduler job and
  returns immediately. Refresh the page after a minute to see the row's
  Last run / Status / Offers count populate. Sites with in-flight
  attempts get a light-blue highlight and a "in flight" status badge.
  Re-clicking **Run now** on a row that's already in flight is safe — as of
  v1.27.0 the run is deduped, so it won't stack a second attempt on top of a
  live one. (Before re-running, the plugin also reaps any dead orphan for that
  site, so a crashed run never blocks a fresh trigger.) If a run looks
  genuinely stuck, see *"Runs stuck at 'in flight'"* below.
- **All enabled sites**: click **Refresh all enabled** at the top.
- **Scheduled**: set the **Scheduled runs** dropdown at the top of the
  Extractor Sites screen to daily / twice daily / weekly. The schedule
  shows the next scheduled run time. WP-Cron handles the trigger; on
  low-traffic sites add an external pinger (see WP-Cron caveat below).
- New offers land in the **Pending Queue** for operator review, same as
  CSV-uploaded offers. Existing offers update in place (see *"What
  re-running does to existing offers"* below for what refreshes vs.
  what stays sticky).

**Viewing run history.** WP Admin → Supplement Compare → **Extractor
Runs** lists the most recent 100 attempts with status badges, durations,
offer counts, and error excerpts. Filter by status (failed-only is the
common operator use). Click any attempt id for the full error log + the
sibling attempts that shared its run_id.

**What re-running does to existing offers.** Every offer is uniquely
identified by the tuple `(merchant, source_product_id, source_variant_id)`.
The database has a UNIQUE constraint on that tuple, so re-running an
extractor against a merchant you've already imported **never creates
duplicates** — it updates the existing row in place. The row's internal
`id` is stable across runs (which is why click counters keep accumulating
correctly).

What every re-run **refreshes** from the merchant:

- Product title, brand, SKU, barcode, source URLs
- Regular price, sale price, current price, on-sale flag, currency
- Stock status
- `last_synced_at` timestamp
- Derived fields (`cost_per_serving`, `cost_per_active_unit`, etc.) —
  recomputed from the new price

What every re-run **leaves alone** (your edits are sticky):

- Canonical product assignment
- Ingredient assignment, form, strength, serving size, standardization %
- Trust signals: third-party tested, COA available, COA URL,
  certifications
- Curation status: `paused`, `rejected`, and `dead` are preserved across
  runs. (Only `pending` / `active` / `needs_review` offers participate
  in the stale-after-this-run sweep.)
- Operator notes and match-confidence overrides

**Stale → prior-status resurrection.** If an offer dropped out of a previous
run (the stale-detector marked it `stale` because the merchant temporarily
delisted it) and then reappears in a later run, the plugin restores it to the
status it held *before* going stale — not blindly to `active`. An offer you had
already approved comes back `active` with no manual re-approval; an offer that
was still in the queue comes back `pending` so it stays under your review.
(Prior to v1.31.0 every returning offer was force-promoted to `active`, which
could auto-publish un-curated offers that never cleared the pending queue.)

**Price-history logging.** When a re-run updates an existing offer's
price or stock, the change is logged to the `price_history` table —
including the old/new effective (current) price as of v1.25.0. The first
public surfacing of that history is the **price-direction indicator** on
the compare table (see §14); the table also remains a foundation for
future analytics or a fuller price-trend chart.

**WP-Cron caveat on low-traffic sites.** Action Scheduler ticks on
visitor requests + WP-Cron. If your site sees hours between visits, a
queued multi-page run will progress slowly. Fix: sign up for a free
heartbeat service (cron-job.org or UptimeRobot) and have it hit
`https://yoursite.com/wp-cron.php` every 5 minutes. Standard workaround
for web-only WP installs.

**Runs stuck at "in flight"** (as of v1.26.0). A run is shown "in flight"
while at least one of its attempts is still open. Normally an attempt
closes itself (complete or failed) when its last page finishes. But if the
host kills the worker mid-run — a PHP timeout or out-of-memory on a big
catalog — the attempt row can be left open with no job behind it, so the
site shows "in flight" forever and the "N attempt(s)" count climbs each
time you re-trigger it.

The plugin now self-heals this:

- A **stale-run reaper** marks such orphaned attempts failed automatically.
  It runs hourly and also sweeps every time you open the Extractor Sites
  screen — so a stuck row usually clears itself the next time you look. A
  run that is genuinely still working (its next page is queued in Action
  Scheduler) is **never** reaped, however long it takes.
- The timeout is configurable: **Settings → _Extractor: stale-run timeout
  (minutes)_** (default 30, range 5–1440). Lower it if you want stuck rows
  cleared faster; raise it if you run very large catalogs on a slow queue.
- To clear orphans immediately, go to **Supplement Compare → Database
  Cleanup → Stuck extractor runs → _Clear stuck runs now_**. It fails every
  dead attempt on the spot and leaves any live run alone. It marks attempts
  failed only — it deletes no offers.

A reaped run shows status `failed` with a "Reaped by the stale-run safety
net…" note. Just hit **Run now** again; if it keeps dying mid-run, the
catalog is likely too large for the host's per-request limits — set up the
5-minute pinger above so the queue drains in smaller bites, or split the
work by running fewer sites at once.

**What can go wrong:**

- *Run completes but pulls **0 offers**, and visiting the site bounces you
  to an "are you 21?" / age-verification landing page* → the merchant is
  behind a **JavaScript age gate** that redirects every server-side request
  to a landing page, so the extractor never sees products. Fix: get past the
  gate in your browser, then copy the verification cookie into the site's
  **Request cookies** field (Extractor Sites → Edit). Steps:
  1. Visit the site in your browser and click through the age gate.
  2. Open DevTools → **Application → Cookies** (Chrome) and find the gate's
     cookie. For the common **Age Gate** WordPress plugin it's named
     `age_gate`. (Alternatively, on the Network tab copy the request
     **Cookie** header.)
  3. Paste it into **Request cookies** as `name=value` (multiple pairs
     separated by `; `), e.g. `age_gate=ab12cd…`, then save and re-run.
  - The cookie's value is usually a hash, so you can't just type
    `age_gate=1` — capture the real value. Age Gate cookies last ~90 days;
    when offers drop back to 0, the cookie has expired — re-capture it.
- *"Site has no merchant linked"* on the row → link a Merchants row in
  the site edit form and re-run.
- *"Auto-detect failed: Shopify, WooCommerce, and generic JSON-LD
  sitemap discovery all failed"* → the site doesn't expose Shopify or
  Woo public APIs AND doesn't publish a discoverable XML sitemap with
  product URLs. If the storefront is a client-rendered single-page app
  (an empty page in "View Source", products appearing only after the JS
  loads), it has no HTML for any of the three handlers to read — use the
  `json` handler instead (see "JSON-API storefronts" in §2). Otherwise
  fall back to the legacy Python extractor and upload its CSV via §3.
- *"JSON handler is not configured…"* or *"JSON endpoint returned no
  products…"* (json only) → the mapping is missing a `list_url`, or
  `list_url`/`products_path` is wrong. Open the site, click **Test
  mapping**, and fix the path the test reports as empty.
- *"PHP \"dom\" / \"simplexml\" extension is not loaded"* (generic only)
  → your host's PHP is missing the standard XML/DOM extensions. Ask
  the host to enable `php-xml`. WordPress itself doesn't require these
  for core functionality, but the generic handler does because it
  parses HTML and XML sitemaps.
- *"Woo probe: not_woo (HTTP 404)"* on a site you know runs Woo →
  the merchant has the **Cart and Checkout Blocks** disabled, which
  also disables the Store API. There's nothing the plugin can do; ask
  the merchant to enable the Blocks bundle, or fall back to the Python
  extractor + CSV upload.
- *"Action Scheduler did not accept the enqueue"* → AS isn't loading.
  Check that `plugin/vendor/action-scheduler/action-scheduler.php`
  shipped with the zip.

**The legacy Python script.** `extractor/aggregate_products.py` is
retained for local-debug use (running extraction on your laptop without
WordPress in the loop). It writes a CSV which you then upload via §3
below. The in-plugin extractor is the canonical path going forward;
Python is the fallback / debug tool.

## 3. Uploading a CSV to WordPress

**Prerequisite:** every `site` URL in the CSV must already exist as an active
merchant (see §6). Imports for merchants that don't exist or are paused will
be rejected before any rows are written.

**WP Admin → Supplement Compare → CSV Imports → Upload CSV.**

1. Pick the CSV produced by `aggregate_products.py`.
2. Optionally tick **Dry run** to validate the file without writing anything.
   The dry-run reports how many rows would insert (new offers) vs. update
   (existing offers) and lists per-row validation errors and unknown
   merchants. No `import_runs` row is created for a dry-run.
3. Click **Run Import**.

A live import does the following, in order:
1. Validates the whole file. Any row-level error fails the whole import —
   nothing is written. Fix the CSV and re-upload.
2. Creates an `import_runs` row (status=`importing`) tagged with the CSV
   `export_run_id` and `exported_at` metadata.
3. For each row: snapshots the raw CSV row into `raw_source_offers` (audit
   trail, never modified), then either inserts a new `normalized_offer`
   (status=`pending`, waiting for operator review) or updates the existing
   one's CSV-direct fields (titles, prices, stock, URLs). Operator-curated
   fields (canonical match, normalized strength, trust signals, notes) are
   never overwritten.
4. Logs any price or stock change to `price_history`.
5. Runs stale detection across the merchants that participated: offers from
   those merchants that weren't seen in this run, with visibility in
   `pending`/`active`/`needs_review`, get flipped to `stale`. Operator-set
   states (paused, rejected, dead) are left alone.
6. Re-appearing offers that were `stale` are restored to the status they held
   before going stale (an already-approved offer returns to `active`; an
   unapproved one returns to `pending`) — never auto-promoted to `active`.
7. Updates the `import_runs` row with counts and `status=complete`.

After import, the screen redirects to the run detail view: per-run metadata,
counts, and any per-row errors that occurred during the write pass.

## 4. Interpreting import errors

There are three categories.

**Fatal — file rejected before parsing.**
- "CSV is empty or has no header row" — file is empty or unreadable.
- "CSV is missing required column(s): X, Y" — the CSV does not match the
  contract in `PROJECTBRIEF.md` §4. Update the Python script's
  `CSV_SCHEMA_VERSION` and regenerate.

**Validation — per-row errors, whole file rejected.**

The validation gate prints each error keyed by 1-indexed spreadsheet row
number (header = row 1):
- `Required column "X" is empty` — the row is missing a value for a
  required field.
- `source must be one of shopify, woocommerce, generic, wix` — the script
  emitted an unknown platform. Bug in the script.
- `on_sale must be true or false` — likewise a script bug; check the
  emit site.
- `stock_status must be one of ...` — script emitted an unknown enum value.
- `regular_price is not a parseable decimal` — non-numeric or malformed
  value in a price column.
- `currency must be a 3-letter ISO 4217 code` — bad currency value.
- `Duplicate (merchant, product_id, variant_id) — first seen at row N` —
  the same natural key appears twice within the same CSV. Likely the
  extractor double-emitted; check that merchant's source pages.
- `No merchant exists with site_url matching "X"` — create the merchant
  first (see §6).
- `Merchant "X" is paused/dead` — the merchant exists but isn't active.
  Resume it (or change status on the merchant edit form) and re-import.

Fix the listed issues in your CSV (or your merchant data), re-upload.

**Runtime — per-row errors during the write pass.**

These appear on the run detail page if any rows errored during DB writes.
Almost always indicates a transient issue (DB connection blip, lock
contention). Re-import the same CSV: the upsert-by-natural-key behavior
makes the operation idempotent, so the already-written rows update
harmlessly and the errored rows insert or update fresh.

## 5. Rolling back a bad import

True transactional rollback is **not** implemented. The price_history table
captures every pricing/stock change, but new-offer creations and stale
flips are not snapshotted, so a wholesale "undo" of an import is not
possible at v1.

Practical recovery options:
- **Wrong merchants in the file:** if the bad rows all belong to one
  merchant, pausing that merchant hides their offers from the public site
  immediately (Phase 8). Re-import a corrected CSV to update them.
- **Bad prices:** the price_history table has each `(old, new)` per change;
  manually `UPDATE` affected `normalized_offers` rows back to their old
  prices. SQL access required.
- **Bad new-offer inserts:** delete the offending rows from
  `normalized_offers`. The raw `raw_source_offers` table preserves the
  imported snapshot for audit.

Full rollback support is in scope for post-1.0.

## 6. Adding a new merchant

A merchant row is created **once per affiliate program** you've joined. The
merchant must exist before its CSV can be imported — Phase 4's importer
matches each CSV row's `site` column to a merchant via `merchants.site_url`.

**WP Admin → Supplement Compare → Merchants → Add New.**

Required fields:
- **Slug** — internal identifier, e.g. `nootropics-depot`. Never shown publicly.
- **Name** — display name on offer cards, e.g. "Nootropics Depot".
- **Site URL** — merchant homepage. This is the natural key the extractor's CSV
  ties to. Whatever you type here is normalized: scheme defaults to `https://`,
  trailing slash stripped. Match it to the URL the Python script crawls.

Other fields:
- **Platform** — `shopify`, `woocommerce`, `generic`, `wix`, or `manual`.
  Informational in Phase 3; will gate import validation later.
- **Default currency** — ISO 4217. Used when this merchant's CSV omits currency.
- **Affiliate URL template** — see below.
- **Coupon code** — see below.
- **Coupon details** — see below.
- **Status** — `active` (default), `paused` (offers hidden, imports rejected),
  `dead` (permanently retired).
- **Notes** — operator-only. Stash affiliate program IDs, network names, contact
  emails, terms-of-service caveats.

### Affiliate URL template

Every Buy Now button goes through `/out/{offer_id}` (Phase 7). The redirect
endpoint substitutes this template at click time. Four patterns are supported
(PROJECTBRIEF.md §5):

| Pattern | Template example |
|---|---|
| Simple query append | `{product_url}?aff=john` |
| Multiple params | `{product_url}?utm_source=affiliate&ref=john` |
| Network redirect | `https://partners.example.com/c/?id=42&u={url_encoded_product_url}` |
| Path-based | `https://merchant.com/ref/john{path}` |

Variables:
- `{product_url}` — the source product URL, verbatim.
- `{url_encoded_product_url}` — same, rawurlencoded. Use this when embedding
  the source URL inside another URL's query parameter.
- `{path}` — path portion only (e.g. `/products/foo`), no scheme/host/query.
- `{handle}` — product slug, from `/products/<handle>` if available.

The engine auto-detects when a `{product_url}` substitution would produce a
double `?` (e.g. template `{product_url}?aff=john` against URL
`https://store.com/p/foo?variant=42`) and flips the appended `?` to `&`.

As of v1.22.1 a template must begin with `http://` or `https://` **or** with
the `{product_url}` placeholder (which supplies the scheme itself). Saving a
template that starts with anything else — `javascript:`, `data:`, or a
protocol-relative `//host/...` — is rejected with "Template must begin with
http:// or https://". This is a safety guard so a Buy button can never emit a
non-web destination.

### Template tester

The edit form has a Template Tester section right under the template field:

1. Type or paste your template in the field above.
2. Paste 1–N example product URLs in the textarea, one per line. Use real URLs
   from this merchant if possible.
3. Click **Test template**. Each input URL becomes one row with the generated
   affiliate URL beside it. Errors (malformed URL, unknown placeholder) show
   inline in red.

The tester runs the exact engine `/out/` will use at click time, so what you
see in the preview is what site visitors get redirected to.

### Coupon code

If the merchant issues an affiliate-program coupon (e.g. "SAVE10"), enter it in
the **Coupon code** field. The code is per-merchant: it applies to every offer
from this merchant and renders in the **Coupon code** column of the public
comparison detail table, between Price and Buy. Leave blank for no code — the
column shows `—` for offers whose merchant has no code set.

The code is informational only — it is not auto-appended to the affiliate
redirect or pre-applied at checkout. Visitors copy it from the table and paste
it on the merchant's cart page. Update or remove it on the merchant edit form
when the promotion ends; the public JSON refreshes on the next data-changed
event (offer save, CSV import, scheduled regenerate, or the manual
**Regenerate now** button on the Settings page).

Per-offer or time-bounded codes aren't supported — the field is a single
string per merchant.

### Coupon details

A free-form short description that displays in the **Coupon details** column
of the public comparison table, immediately after the **Coupon code** column.
Use it to tell visitors what the code actually does — e.g. "10% off your first
order", "15% off, expires Dec 31", "Free shipping on $50+". 255-character cap.

The field is independent of **Coupon code** — either, both, or neither can be
set. Empty cells render as `—` to keep the column rhythm consistent. Update
or remove it on the merchant edit form when the promotion changes; the public
JSON refreshes on save.

### Pause vs. Dead

Use **Pause** when you're temporarily holding off on a merchant — terms
re-negotiation, affiliate program review, anything reversible. Pause = active
offers hidden, imports rejected, data preserved. Click **Resume** on the list
row to flip it back.

Use **Dead** (via the status dropdown on the edit form) for a permanent
retirement — merchant out of business, affiliate program ended. Same effect as
Pause but signals operator intent. Both states are reversible at the data
layer; only your habits distinguish them.

## 7. Adding a canonical ingredient

The canonical ingredient is the compound itself (e.g. "L-Theanine"), not any
particular product form. Populate this database before importing offers —
canonical products and offers both reference ingredients by id.

**Single ingredient:** WP Admin → Supplement Compare → Ingredients → **Add New**.

Required fields:
- **Slug** — URL-safe identifier (e.g. `l-theanine`, `magnesium-glycinate`).
  This is the natural key, including for CSV imports.
- **Name** — display name (e.g. "L-Theanine").

Optional but commonly needed:
- **Aliases** — comma- or pipe-separated alternative names, used for matching
  merchant titles and for visitor search. Include trade names (e.g.
  `Suntheanine` for L-Theanine, `Bacognize` and `Synapsa` for Bacopa).
- **Category** — primary categorization (nootropic, longevity, sports, etc.).
  Cross-category ingredients pick a primary and surface elsewhere via aliases.
- **Default unit** — the unit the cost-per-active-unit math reports in. Most
  compounds: `mg`. Vitamins: `IU` or `mcg`. Probiotics: `billion_cfu`.
- **Elemental %** — for minerals where the listed weight is a salt and the
  active fraction is smaller. E.g. magnesium glycinate is 14.10% elemental
  magnesium. Leave blank for non-mineral compounds.
- **Standardization compound** + **Standardization default %** — for herbal
  extracts. E.g. Bacopa monnieri standardized to 50% bacosides. Note that
  different standardizations of the same compound are different *canonical
  ingredients* per PROJECTBRIEF.md §1 — don't lump 50% bacosides Bacopa with
  20% bacosides Bacopa.

**Bulk import:** Ingredients screen → **Import CSV**. Use `seed-data/ingredients.example.csv`
as a template. Required columns: `slug`, `name`. Rows upsert by slug, so
re-importing the same CSV is safe. Removing a row from the CSV does NOT
retire the ingredient — use the inline Retire action.

**Retire:** the row's Retire button flips status to `retired`. Retired
ingredients stop appearing in the canonical product picker and are excluded
from public site generation, but their data is preserved. Click Restore on a
retired ingredient to bring it back.

## 8. Adding a canonical product

The canonical product is the comparable concept that merchant offers cluster
around. As of v1.1.0 the default is **ingredient + active unit** — one
canonical for "Creatine" (unit g), one for "L-Theanine" (unit mg), etc. —
and form / strength / standardization live on each offer. The comparison
table shows the per-offer total active mass, serving size, # servings,
cost / serving, and cost / active unit, so readers can judge form-specific
tradeoffs directly. Operators can still pin a form or a strength on a
canonical (e.g. "L-Theanine 200mg Capsules") when they want a tighter
landing-page concept; leaving both blank gives one canonical per ingredient.

**Single product:** WP Admin → Supplement Compare → Canonical Products →
**Add New**.

Required fields:
- **Slug** — e.g. `l-theanine-200mg-capsule`.
- **Ingredient** — picker shows only active (non-retired) ingredients.

Optional:
- **Form** — capsule, tablet, softgel, powder, liquid, sublingual, gummy,
  other. Leave blank (the `— Any form —` option) to let one canonical span
  every form for the ingredient. The form of each offer is still recorded
  at the offer level and surfaced on the comparison table.
- **Standardization compound / %** — overrides the ingredient defaults. Useful
  when a canonical pins a non-standard percentage of the same compound for
  all of its offers.
- **Display name** — shown publicly. If blank, the system derives one from
  the ingredient (and form, when set).

- **Pinned strength (optional)** and **Pinned servings/container (optional)** —
  **leave blank in almost all cases.** Active mass per serving normally lives at
  the offer level (set in the pending queue / offer edit form), where it drives
  the per-offer `cost_per_serving` and `cost_per_active_unit`. A blank pin lets
  one canonical group every brand-strength of the ingredient and shows each
  offer's own strength on the comparison table. If you *do* enter a strength
  here, it becomes the product's displayed strength on the public site **and**
  overwrites the per-offer strength of every offer matched to this canonical on
  its next import — so a stray pin can make a product read "100mg" even when its
  only offers are 5mg and 10mg.

  > **Clearing a stale pin.** These fields were hidden from v1.1.1 to v1.19.1,
  > so a canonical created before v1.1.1 (or imported from a CSV that included a
  > `strength_per_serving` value) may carry a pin you can't see. If a product
  > shows a strength on the public site that none of its active offers have,
  > open the canonical, blank the **Pinned strength** field, save, then
  > regenerate the public JSON (Settings → **Regenerate now**, or wait for the
  > next scheduled export). Existing offers keep their own correct strengths —
  > only the canonical-level display changes.
- **SEO indexable** — operator's explicit opt-in for the per-canonical SEO
  page (Phase 10). Phase 10 also requires ≥3 active offers before actually
  indexing.

**Derived fields** (`total_strength`, `active_compound_per_serving`) recompute
on every save per PROJECTBRIEF.md §6 and are displayed read-only on the edit
form. If something looks off there (e.g. `active_compound_per_serving` equals
the strength when you expected it scaled by a percentage), the
standardization or elemental % is misconfigured — fix on the ingredient or
override on this product.

**Bulk import:** Canonical Products screen → **Import CSV**. Template at
`seed-data/canonical-products.example.csv`. Required columns: `slug`,
`ingredient_slug`. Optional: `strength_per_serving` (leave the cell blank
when the canonical groups varying brand strengths), `ingredient_form`,
`servings_per_container`, `standardization_compound`,
`standardization_percentage`, `display_name`, `seo_indexable`, `status`.
The `ingredient_slug` must match an existing canonical ingredient — import
ingredients first.

## 9. Working the pending queue

**WP Admin → Supplement Compare → Pending Queue.** This is the operator's main
daily workflow. Every imported offer arrives here; nothing publishes until
you approve it.

**The 10-second clean-case workflow.** For offers the matcher is confident
about:

1. Sort by confidence (click the column header or set "≥ 0.95" in the filter).
2. Scan the suggested matches. The badge is colour-coded: green ≥ 0.95
   (barcode / brand+SKU), blue ≥ 0.85 (direct canonical lookup), yellow
   0.65–0.85 (weaker peers), gray < 0.65 / no match.
3. Either click the **Approve** button on each row, OR check several rows
   and pick "Approve" from the bulk-action dropdown and click Apply.

**For offers that need a closer look:**

1. Click the title (or "Edit"). The detail view shows raw source data on
   the left, editable normalized fields on the right. Below the source
   panel, expand "Raw CSV row" to see exactly what came in from the
   Python extractor.
2. The most important fields:
   - **Canonical product** — operator-confirmed match. Defaults to the
     matcher's suggestion. Pick a different one from the grouped dropdown
     if it got it wrong, or "— No canonical match —" if no canonical is
     right (you can revisit later).
   - **Ingredient / Form / Total active per container / Servings /
     Active mass per serving / Standardization** — the normalized fact
     set. **Total active per container is the primary input** as of
     v1.15.0: enter it and the comparison table renders Total active and
     Cost / active unit even when Servings per container is blank. When
     you also enter Servings, the form derives Active mass per serving
     from Total ÷ Servings on save. The legacy workflow still works —
     entering Strength + Servings instead of Total computes Total
     downstream via derivations. When you set Canonical product, the
     canonical's values become authoritative (the form makes the offer's
     values match it on save).
3. Trust signals — `Third-party tested`, `COA available`, `COA URL`, and
   `Certifications`. These are operator-set; the CSV never carries them.
   Set them when you've personally verified the brand publishes their
   test results.
4. Click **Save & Approve**. The offer goes active.

**Other workflow states:**
- **Reject** — operator decided this offer doesn't belong (e.g. not actually
  a single-ingredient product). Removed from the public site permanently.
  Re-imports do NOT revive a rejected offer: while the rejected row exists,
  the importer refreshes its price/stock but leaves it rejected and hidden.
  And if you later purge it on the Cleanup screen, the **suppression list**
  (§16, added in v1.23.0) keeps it out of the queue even after the row is gone
  — so the product stays gone whether or not you Clean Up.
- **Pause** — operator wants this offer hidden temporarily without
  rejecting (out-of-stock that's expected to return, brand issue under
  investigation).
- **Defer** — marks `needs_review`. Operator wants to think about this one.
  Stays in the pending queue under the "Needs review" visibility.

## 10. Editing an active offer

**WP Admin → Supplement Compare → Active Offers** lists everything currently
visible on the public site (well, will be once Phase 9 lands). Same edit
form as the pending queue.

Same operator-curated fields are editable. Same trust-signal controls.
Re-imports never overwrite your edits — once you've set a canonical match
or a strength override, that value is sticky.

**Finding un-curated active offers (the canonical filter).** The filter bar
has a **canonical-state** dropdown: *Any canonical state* (default), *No
canonical assigned*, or *Has canonical*. Because approving an offer always
involves assigning its canonical product, **"No canonical assigned" is a
reliable signal that an offer reached the Active list without going through
your normal approval** — e.g. the pre-v1.31.0 stale-restore bug that
auto-promoted un-approved offers (see CHANGELOG [1.31.0]). Filter to *No
canonical assigned*, review the rows, and bulk **Pause** or **Reject** (or
**Defer** to send them back to the pending queue) anything you would not have
approved.

## 11. Pausing or deactivating an offer

From either the **Pending Queue** or **Active Offers** screen:
- Inline **Pause** button on each row → flips visibility to `paused`.
  Hidden from public site. Re-imports cannot un-pause it; the operator
  must explicitly resume.
- Inline **Reject** button → flips visibility to `rejected`. Same effect
  but signals operator intent ("this should never have been here").
- Bulk action dropdown → same options at scale.

**Resume / undelete.** A paused or rejected offer stays in the database
forever. From the edit form's footer the operator can flip it back to
`active` (Save & Approve) or `needs_review` (Save & Defer) at any time.

**Stale offers.** Auto-set by the import pipeline (see §5) when an offer
disappears from a recent CSV. They appear in the pending queue under
`needs_review` semantically — handle them the same way (re-approve,
reject, or pause). When the same offer reappears in a later import the
importer auto-restores it to active.

## 12. Reading the clicks dashboard

**WP Admin → Supplement Compare → Clicks.**

Every Buy Now click on the public site goes through `/out/{offer_id}`,
which logs the click and 302-redirects to the merchant via the affiliate
URL template. The dashboard summarizes that log.

**Time window:** today / last 7 days / last 30 days / all time. The default
is 7 days. Pick the window from the dropdown and click Apply.

**Summary tiles:**
- **Total clicks** — every recorded /out/ hit in the window, including
  bot-suspected.
- **Human clicks** — total minus bot-suspected. This is the number to
  watch for affiliate-program reporting and revenue estimation.
- **Bot-suspected** — clicks the redirect handler flagged as automated.
  Detection rules: known crawler/scraper UA strings, rapid-fire
  (≥ 10 clicks from the same hashed IP within 60s), or no UA at all.

**Top-N tables:**
- **Top offers** — which specific listed offers got the most clicks. Links
  back to the offer edit form so you can spot-check normalized fields if a
  surprising winner shows up.
- **Top merchants** — which affiliate program got the most outbound
  traffic. Useful for prioritizing which merchant relationships to nurture.
- **Top canonical products** — clicks aggregated by the underlying canonical
  (e.g. "L-Theanine 200mg Capsule" totals across every merchant). Tells
  you which compounds visitors actually care about.

**Recent clicks** at the bottom: most recent 50 with referrer, UTM tags,
and bot flag. Useful for spotting bot waves and verifying that real
clicks are landing.

**By default, the top-N tables exclude bot-suspected clicks** so affiliate
revenue projections aren't inflated. Tick "Include bot-suspected" to see
the raw numbers — useful for spotting scraper patterns.

### What's NOT tracked

- **No raw IP addresses.** IPs are SHA-256-hashed with a site-stable salt
  before storage. There's no way to map a hash back to an IP from the
  database.
- **No personally identifying user agent strings.** UAs are also hashed.
  The rapid-fire detection works because the same IP+UA hashes consistently.
- **No on-site analytics.** This dashboard is just outbound clicks. For
  general site traffic use whatever analytics you have wired into the
  WordPress theme.

### Spot-checking a click

From any offer's edit form (Pending Queue or Active Offers → Edit), there's
a **Test Buy Now (/out/N)** button under the source panel. Click it →
opens `/out/{id}` in a new tab → logs a click as you and 302s to the
merchant. Useful for verifying:
- the rewrite rule fires (you get redirected, not 404'd)
- the affiliate URL template substitutes correctly (the destination URL
  has your ref / utm tag)
- the click shows up in the Clicks dashboard within a few seconds

If `/out/{id}` returns a 404 right after plugin activation, deactivate and
reactivate the plugin — that re-flushes the WordPress rewrite cache. If it
keeps 404'ing, your server may be eating the URL before WP sees it (mod_rewrite
disabled, or an Nginx config that doesn't pass everything through `index.php`).

## 13. Regenerating the public JSON manually

The public site (Phase 9, not yet built) will load a single
`public.json` file written to
`wp-content/uploads/supplement-compare/public.json`. The file is
regenerated automatically:
- After **any offer state change** — save, approve, reject, pause,
  defer, bulk action.
- After **every CSV import**.
- After **ingredient or canonical product edits** (display fields
  appear in the JSON).
- On an **hourly cron** as a backup, in case any of the above paths
  fail silently.

Multiple changes within one request coalesce — the regenerate runs once
on PHP shutdown, after the redirect.

**WP Admin → Supplement Compare → Settings → Public JSON export** shows:
- The file path on disk
- The public URL (clickable when the file exists)
- File size + last write time
- Last recorded auto-regenerate timestamp
- Next scheduled cron run

Click **Regenerate now** to force a regenerate immediately. Useful when:
- You edited a canonical product display name and want the JSON to reflect
  it right now (auto-regenerate handles this, but a manual button is
  useful for "did it work?" verification).
- The cron is wedged for any reason and you want to confirm the exporter
  itself still works.
- You changed a staleness threshold in Settings and want the new filter
  applied immediately rather than waiting for the next change.

**What's in the file (PROJECTBRIEF.md §9):**
- `canonical_products[]` — one per canonical that has at least one
  active offer. Includes ingredient summary, form, strength, and rollups
  (lowest_cost_per_active_unit, offer_count).
- `offers[]` — one per active, canonical-matched offer within the hide
  threshold. Includes pricing, derived cost-per-active-unit, trust
  signals, `buy_url: /out/{id}` (NOT a raw affiliate URL),
  `is_stale: true|false` per the warn threshold.

**What's NOT in the file:**
- Raw affiliate URLs or merchant URL templates (only `buy_url: /out/{id}`).
- Source product URLs (the redirect handles the off-site jump).
- Product descriptions, raw_attributes_json, operator notes — internal only.
- Pending, rejected, paused, or unmatched offers.
- Offers older than the hide threshold (default 168h since last_synced_at).

**Troubleshooting:**
- "(no file written yet)" after activating the plugin → expected on a
  fresh install with no approved offers. Approve at least one or run a
  CSV import.
- File exists but the public URL 404s → web server isn't serving
  wp-content/uploads paths. Almost never a problem on standard WP hosting;
  check Apache/Nginx config if it happens.
- "Next scheduled cron" stays blank → wp-cron isn't firing. Add a real
  system cron pointing at `wp-cron.php?doing_wp_cron` and disable
  WordPress's pseudo-cron via `define('DISABLE_WP_CRON', true)` if the
  site has low traffic.

## 14. Placing the public comparison interface

The plugin ships a shortcode that renders the public comparison interface
wherever you embed it.

**Basic usage:** add `[supplement_compare]` to any page or post. The
operator typically dedicates one page (e.g. `/compare/`) and embeds the
shortcode there.

**Variants:**
- `[supplement_compare]` — full app, list view default. Visitors see all
  canonical products with the lowest cost-per-active-unit + merchant count
  per row, can filter and sort, and drill into per-canonical comparison.
- `[supplement_compare canonical="l-theanine-200mg-capsule"]` — starts on
  a specific canonical's detail comparison.
- `[supplement_compare ingredient="L-Theanine"]` — list view pre-filtered
  to one ingredient.

**What renders:**
- Top filter bar (list view): search-ingredient input, "All forms"
  dropdown, "All ingredients" dropdown, and the in-stock-only / 3PT /
  COA checkboxes. Each control is independently toggleable on the
  Settings page:
  - **List view filter controls** — show/hide the search input, the
    form dropdown, and the ingredient dropdown. Useful when the dataset
    is small enough that a search field or one of the dropdowns is
    unnecessary. Pre-filtering via shortcode attributes (e.g.
    `[supplement_compare ingredient="L-Theanine"]`) still applies even
    when the matching control is hidden.
  - **Filter checkboxes** — show/hide in-stock-only, third-party-only,
    COA-only. These appear on both the list and detail filter bars.
    Unchecking one is useful when your dataset doesn't populate that
    field uniformly (e.g. no offers have COAs recorded yet).
  - **Product subhead** — two independent checkboxes:
    - *Detail page subhead* hides the small grey meta-line under the
      product title (`ingredient · category · form · active unit`).
      Applies to both the shortcode-rendered detail view and the
      dedicated `/compare/{slug}/` landing page, so the operator only
      flips one switch.
    - *List row subhead* hides the `ingredient · category` line that
      renders under each product name in the main (list) table.
    Disable either when the product title already communicates enough
    on its own and the extra meta-text is just visual noise.
- **Click-to-sort column headers.** Every numeric and text column on the
  list and detail tables is sortable by clicking its header. First click
  applies the column's natural direction (cost/price ascending — cheapest
  first; total active / servings / merchant count descending — most first;
  text ascending — A→Z); a second click on the same column toggles
  direction. The active column shows a ▲/▼ indicator. Keyboard users can
  Tab to a header and press Enter or Space.
- On the detail (per-canonical) view: a **Show: Cost / Serving | Cost /
  Active Unit** radio toggle above the table (visible only when **Multi
  compare-table view** is checked on the Settings page — see below). Both
  views show the same offer rows; the toggle swaps which columns are
  visible:
  - **Cost / Active Unit** — Merchant · Total active · Cost / active unit
    · Price · Coupon · Buy
  - **Cost / Serving** — Merchant · Serving size · Servings · Cost /
    serving · Price · Coupon · Buy
  The default view loaded on first render is set on the Settings page
  (**Default compare-table view**). When **Multi compare-table view** is
  unchecked, the toggle is hidden and the Default view is the only view
  visitors see.
- **Price-direction indicator (detail view).** To the right of each
  merchant's current price, a small coloured arrow + % change shows which
  way that merchant's price last moved — **green ▼** for a drop, **red ▲**
  for a rise (deliberately buyer's-eye: a price drop is the good outcome).
  It appears only when the price last changed within the window set on the
  Settings page (**Price-direction indicator (days)**, default 30); a price
  that has been flat longer than that, or has no recorded history, shows
  nothing. Set the window to **0** to turn the indicator off entirely. The
  arrow reflects the single most recent change (not a 30-day net move), and
  reads the old/new effective price captured in `price_history`, so it only
  starts appearing once an offer's price has actually moved at least once
  since v1.25.0.
- A list-or-detail table, hash-routed (`#/` for list, `#/canonical/<slug>`
  for detail).
- Below the table: the affiliate disclosure (configurable in Settings) and
  the "Data last updated" timestamp from the JSON's `generated_at`.

**Mobile layout (≤720px):** the comparison tables automatically reflow
so nothing scrolls sideways. Each row becomes a 2-row CSS Grid: the
sortable comparison columns sit under their tappable `<th>` headers on
the top line, and the coupon code, coupon details, and Buy button wrap
onto a second line underneath. Column headers stay visible and
tappable so visitors can still re-sort by Price, Cost / unit, etc. on
their phone. Desktop layout is unchanged. No operator setting controls
this; it's the default frontend behaviour.

**Cache busting:** the shortcode appends `?ver={last-generated timestamp}`
to the JSON URL, so visitors get the freshest data when the JSON
regenerates. The browser caches per its normal rules between regenerations.

**Buy Now buttons** link to `/out/{offer_id}` (Phase 7), which logs the
click and 302-redirects to the merchant via the affiliate URL template.
Buttons get `rel="nofollow sponsored noopener"` per Google's affiliate-
disclosure expectations.

**No data yet?** A fresh install shows "No comparison data is published
yet" until the JSON file has been generated (which requires at least one
approved offer + the exporter to have run, either via auto-invalidation or
the manual Regenerate button in Settings).

## 15. Editing per-canonical-product page content (SEO)

The plugin generates a per-canonical landing page at `/compare/{slug}/`
for every canonical product. The page shows the canonical's display name,
operator-written "SEO content" body, schema.org Product + AggregateOffer
markup, and embeds the [supplement_compare] shortcode for the comparison
table itself.

**Indexability rule.** Per PROJECTBRIEF.md §10, a canonical page indexes
only when BOTH:
- `seo_indexable` is on (operator opt-in on the canonical product form)
- Active offer count ≥ 3 (within the hide-staleness threshold)

When either fails, the page still renders (so visitors can reach it via
internal links) but emits `<meta name="robots" content="noindex,follow">`
and is excluded from `/supcomp-sitemap.xml`.

**Editing the body.**

WP Admin → Supplement Compare → Canonical Products → Edit. Below the
"SEO indexable" checkbox there's an "SEO content" rich-text editor.

**Important — no therapeutic claims.** PROJECTBRIEF.md §7 forbids
therapeutic or comparative health claims in operator-facing copy. Stick
to factual chemistry / composition / form / standardization / unit
discussion. Don't describe a compound as treating, preventing, or
improving any condition. The editor's helper text repeats this.

Indexability status is shown live on the edit form: active offer count,
"Indexes: yes/no" with the reason, and a "View page →" link to preview.

**Schema.org markup.** Every canonical page emits a JSON-LD block with:
- `@type: Product` (name, optional description from seo_content, category)
- `offers: AggregateOffer` (priceCurrency, lowPrice, highPrice,
  offerCount, availability)

The aggregate is computed from active, non-hide-stale offers — same set
the visible comparison table uses.

**Sitemap.**

`https://your-site.com/supcomp-sitemap.xml` lists every canonical
page that satisfies the indexability rule. One `<url>` per canonical
with `<lastmod>` derived from the canonical's `updated_at` and
`<changefreq>daily</changefreq>`.

If your site already has a primary sitemap (Yoast, Rank Math, WordPress
core, etc.), reference `/supcomp-sitemap.xml` from your sitemap index or
submit it directly to Google Search Console — both work.

If `/supcomp-sitemap.xml` returns 404 immediately after activating the
plugin, deactivate/reactivate to re-flush WordPress's rewrite cache.

## 16. Pruning the database (cleanup)

The database accumulates cruft as you reject offers, retire ingredients,
and decide a merchant is gone for good. v1.5.0 ships two complementary
ways to clean up.

**The two-step rule.** Hard-delete only works on rows that are already in
their **soft-trash state**:

| Entity | Soft-trash state | How to reach it |
|---|---|---|
| Offer | `visibility = rejected` or `dead` | Save & Reject in the offer edit form; or `dead` is auto-set by the stale detector |
| Merchant | `status = dead` | Merchants → edit → Status = dead → Save |
| Ingredient | `status = retired` (AND no canonicals referencing it) | Ingredients list → Retire action |
| Canonical Product | `status = retired` | Canonical Products list → Retire action |

If you try to hard-delete a row that isn't in soft-trash state, the
confirmation screen refuses with a clear message. Active and paused rows
are never touched by cleanup tools.

**Per-row delete.** On each entity's list view (and the offer edit
form), rows in soft-trash state get a red **Delete** link. Clicking
opens a confirmation screen showing the exact cascade impact (how many
price-history rows, raw CSV snapshots, click-log rows are affected)
before you commit.

**Bulk cleanup.** WP Admin → Supplement Compare → **Cleanup**. Five
one-shot operations:

- **Rejected offers** — operator said "this should never have been here"
- **Dead offers** — auto-marked stale and aged past the threshold
- **Empty dead merchants** — `status=dead` AND no offers
- **Empty retired canonicals** — `status=retired` AND no offers
- **Empty retired ingredients** — `status=retired` AND no canonicals AND no offers

Each row shows the current eligible count up-front; click **Delete all**
to nuke them in one batch. Same cascade rules apply.

**Cascade behavior** (the same for per-row and bulk):

- `price_history` rows: deleted alongside the offer. Per-offer audit
  data is useless once the offer is gone.
- `raw_source_offers` snapshots: deleted alongside the offer.
- `click_log` rows: **preserved**. The relevant FK (`offer_id`,
  `merchant_id`, or `canonical_product_id`) is set to `NULL` so the
  click stays in your dashboard totals — you just won't be able to
  attribute it back to the specific row that's gone.

**Ingredient deletion has one extra gate**: an ingredient with any
canonical products referencing it cannot be deleted. Retire and delete
those canonicals first. The cleanup screen's "Empty retired ingredients"
count reflects this — only truly-orphan ingredients are listed.

**This is permanent.** No undo. No soft-trash backup. The two-step
workflow (soft-trash → review → purge) is the safety net; once you
press Delete the row is gone. Practical tip: glance at the cleanup
counts on the screen before clicking "Delete all" to make sure the
number matches what you expect.

**Purging a rejected offer adds a suppression (v1.23.0).** When the
cleanup deletes an offer that was in the **rejected** state, it leaves
behind a row on the **Suppression List** (the natural key:
merchant + source product/variant id). On the next extractor run or CSV
import, that product is skipped — it does *not* come back into the
Pending Queue, even though the merchant still lists it. This is what
makes a rejection permanent: without it, Cleanup would delete the only
record keeping the product out, and the next run would re-add it as
pending. Note the scope — only **rejected** offers do this. Purging a
**dead** offer (one that disappeared from the merchant and aged out) does
*not* suppress it; if the merchant re-lists it, it returns as pending for
you to judge fresh.

**Lifting a suppression.** WP Admin → Supplement Compare → **Suppression
List** shows everything currently suppressed, with the product title and
merchant. Use the **search box** at the top (v1.31.2) to filter by product
title, brand, or source product id when the list is long — the term sticks
across pages, and **Clear** resets it. Click **Lift** on a row to remove
it; the product can then return to the Pending Queue on the next import.
The list is view + lift only — entries are added automatically by Cleanup,
not by hand. The
Import screen's run stats include a **Suppressed** count so you can see
how many products a given run skipped.

## 17. The "At a Glance" dashboard widget

When you log into WP Admin, the main **Dashboard** screen shows a
**Supplement Compare — At a Glance** widget (added in v1.30.0). It's a
read-only, current-state summary — nothing to configure, and it never
changes any data. Three blocks:

- **Catalog** — how many live canonical products you have, and how many
  merchants (active, with the total in parentheses). "Live" canonical =
  anything not retired.
- **Live offers** — the number of offers actually shown on the public
  site right now, split into **in stock** / **out of stock** (rare
  statuses — backorder / unavailable / unknown — roll up into an "other"
  line that only appears when non-zero). This is the same set the public
  JSON exports: active, matched to a canonical, from an active merchant,
  and synced recently enough to clear the staleness hide window.
- **Price moves · last N days** — of those live offers, how many changed
  price within the window, broken down **▲ up · ▼ down · — unchanged**.
  These counts use the same logic as the ▲/▼ arrows readers see on the
  site, so the "changed" number equals the number of arrows currently
  showing. **N** is the window from Settings
  (`Price move window (days)`, default 30). If you set that window to 0
  (which disables the on-site indicator), the widget shows
  "Price-move tracking is disabled" instead of zeros.

The widget recomputes every time the Dashboard loads, so it's always
current — there's no cache to refresh.

If the widget isn't visible, it may be toggled off under **Screen
Options** (top-right of the Dashboard). Only users who can manage the
plugin (`manage_options`) see it.

## 18. Troubleshooting

**"CSV is missing required column(s)" on upload.** The Python extractor
output doesn't match the §4 contract. Most common cause: someone has an
older copy of `aggregate_products.py`. Pull the latest from this repo and
regenerate the CSV.

**"No merchant exists with site_url matching X".** The CSV references a
site that has no merchant row, or whose site_url doesn't match what was
typed on the merchant edit form. Check that the merchant's Site URL field
matches the host the script crawled (trailing-slash and case differences
are tolerated; query strings and paths in the merchant URL are not).

**Upload fails silently, returns to upload page.** Usually a PHP
post_max_size or upload_max_filesize limit. The form shows the active max
upload size; if your CSV is larger, increase those limits in php.ini or
contact your host.

**Normalization landed in the wrong field after import.** As of v0.6.0,
import auto-runs four extraction rules (strength, count, form,
standardization) plus an ingredient name/alias matcher and the §7
canonical-product matcher. Mistakes happen — Phase 6's pending queue is
where you correct them. Once you edit a field, re-imports never overwrite
your edit. The matcher learns: as soon as a few offers for a given product
are canonical-matched, future imports of the same product (by barcode, by
brand+SKU, by brand+normalized title) pick up the canonical automatically
at high confidence.

**Import says 0 inserted, 0 updated, N stale.** A merchant was previously
active, has offers, but no rows appeared in this run. Stale detection
flipped them. Either the extractor's CSV is missing that merchant or the
merchant's products genuinely vanished.

**Extractor run fails with "Host resolves to a non-public address" (or
"Only http and https URLs may be fetched").** As of v1.22.0 the in-plugin
extractor refuses to fetch URLs that aren't `http(s)` or that resolve to a
private / loopback / reserved IP — this is the SSRF guard and is working as
intended. It only bites if you point an Extractor Site at a LAN host,
`localhost`, an internal IP, or a `*.local` test store. To extract from a
local development store, expose it on a public hostname (or a tunnel such as
ngrok) and use that URL. Public merchant sites are unaffected.

**Same offer keeps getting `match_confidence` reset to NULL on re-import.**
Expected at v0.5.0 — Phase 5 (normalization + matching) hasn't been built
yet. Matching scores arrive with Phase 5.

**Prices look off after import.** Check the `price_source` column on the
raw row — Woo "fallback_parent_only" rows have a price range, not a
specific price. Variations with `variation_retrieval_status=failed` may
have the parent's price. Phase 5's normalization will surface these
better; for v0.5.0, the operator has to spot them in the pending queue.

**Operator-edited fields got wiped by re-import.** Should not happen by
design — only CSV-direct fields update on re-import. If a curated field
was overwritten, file a bug; `Supcomp_Offers_Repo::update_csv_columns()`
is the responsible code path and it has an explicit allow-list.

---

**Versioning reminder:** every functional change bumps the plugin version (see
PROJECTBRIEF.md §11). The version visible in WP Admin → Plugins is the
canonical "did I upload the right build" signal. Use `scripts/bump-version.sh`.

---

**Pre-release dependency check.** The only bundled third-party library is
Action Scheduler (`plugin/vendor/action-scheduler/`), currently **3.9.3** (see
its `action-scheduler.php` header). Before cutting a release, compare that
version against the upstream releases and security advisories
(<https://github.com/woocommerce/action-scheduler/releases>, WPScan / NVD) and
re-vendor if a security release has shipped. No first-party code change is
needed to update it — replace the `vendor/action-scheduler/` directory.
