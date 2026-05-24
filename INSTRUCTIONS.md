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
   - **Merchant** — pick the linked Merchants row. Required for
     `/out/{id}` to fire downstream.
   - **Enabled** — leave checked.

**Running an extract:**

- **Per-site**: click **Run now** in the Actions column of the
  Extractor Sites list. The button queues an Action Scheduler job and
  returns immediately. Refresh the page after a minute to see the row's
  Last run / Status / Offers count populate. Sites with in-flight
  attempts get a light-blue highlight and a "in flight" status badge.
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

**Stale → active resurrection.** If an offer dropped out of a previous
run (the stale-detector marked it `stale` because the merchant temporarily
delisted it) and then reappears in a later run, the plugin automatically
restores it to `active` — no manual re-approval needed.

**Price-history logging.** When a re-run updates an existing offer's
price or stock, the change is logged to the `price_history` table. The
operator-facing surfacing for that history isn't built yet, but the data
is there for future analytics or sale-detection features.

**WP-Cron caveat on low-traffic sites.** Action Scheduler ticks on
visitor requests + WP-Cron. If your site sees hours between visits, a
queued multi-page run will progress slowly. Fix: sign up for a free
heartbeat service (cron-job.org or UptimeRobot) and have it hit
`https://yoursite.com/wp-cron.php` every 5 minutes. Standard workaround
for web-only WP installs.

**What can go wrong:**

- *"Site has no merchant linked"* on the row → link a Merchants row in
  the site edit form and re-run.
- *"Auto-detect failed: Shopify, WooCommerce, and generic JSON-LD
  sitemap discovery all failed"* → the site doesn't expose Shopify or
  Woo public APIs AND doesn't publish a discoverable XML sitemap with
  product URLs. Fall back to the legacy Python extractor against the
  site and upload its CSV via §3.
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
6. Re-appearing offers that were `stale` are restored to `active`.
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
- `source must be one of shopify, woocommerce, generic` — the script
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
- **Platform** — `shopify`, `woocommerce`, `generic`, or `manual`. Informational
  in Phase 3; will gate import validation later.
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

Active mass per serving and servings per container are **not** entered on
the canonical screen as of v1.1.1 — those are per-offer values, set in the
pending queue / offer edit form, and they drive the per-offer
`cost_per_serving` and `cost_per_active_unit`. CSV import still accepts
`strength_per_serving` / `servings_per_container` columns for legacy data;
existing rows keep whatever values they already have.
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
  Re-imports do NOT revive a rejected offer.
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
- A list-or-detail table, hash-routed (`#/` for list, `#/canonical/<slug>`
  for detail).
- Below the table: the affiliate disclosure (configurable in Settings) and
  the "Data last updated" timestamp from the JSON's `generated_at`.

**Mobile layout (≤720px):** the comparison tables automatically collapse
into a stack of cards — one card per product (list view) or per merchant
(detail view) — so visitors never scroll sideways. The primary action
(Compare on list view, Buy Now on detail view) renders as a full-width
tap target at the bottom of each card. Desktop layout is unchanged. No
operator setting controls this; it's the default frontend behaviour.

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

## 17. Troubleshooting

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
