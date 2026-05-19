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
   cd plugin && zip -r ../supplement-compare-$(grep -oE "'[0-9]+\.[0-9]+\.[0-9]+'" supplement-compare.php | head -1 | tr -d "'").zip . -x '*.gitkeep' && cd ..
   ```

   (A `scripts/package-plugin.sh` helper that does this with the version baked
   in lands in a later phase per PROJECTBRIEF.md §11. Until then, use the
   one-liner above or zip the directory manually.)

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

## 2. Running the Python extractor to produce a CSV

TBD — refer to [`extractor/README.md`](extractor/README.md) for the script
invocation; this section will cover the operator's actual workflow (which
merchants to run against, on what cadence, how to verify output).

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

The canonical product is the specific shape of product (e.g. "L-Theanine 200mg
Capsules") — independent of which merchant sells it. Each canonical product
is what multiple merchant offers cluster around for comparison.

**Single product:** WP Admin → Supplement Compare → Canonical Products →
**Add New**.

Required fields:
- **Slug** — e.g. `l-theanine-200mg-capsule`.
- **Ingredient** — picker shows only active (non-retired) ingredients.
- **Strength per serving** — in the ingredient's default unit.

Optional:
- **Form** — capsule, tablet, softgel, powder, liquid, sublingual, gummy,
  other. Within-form comparison only is load-bearing: capsules are never
  compared to powder.
- **Servings per container** — leave blank if servings vary across merchant
  variants (then per-container math is computed per-offer instead).
- **Standardization compound / %** — overrides the ingredient defaults. Useful
  when a specific product uses a non-standard percentage of the same compound.
- **Display name** — shown publicly. If blank, the system derives one as
  `{ingredient name} {strength}{unit} {form-plural}`.
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
`ingredient_slug`, `strength_per_serving`. The `ingredient_slug` must match
an existing canonical ingredient — import ingredients first.

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
   - **Ingredient / Form / Strength / Servings / Standardization** — the
     normalized fact set. When you set Canonical product, the canonical's
     values become authoritative (the form makes the offer's values
     match it on save).
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

TBD — lands with Phase 7 (click-out redirect).

## 13. Regenerating the public JSON manually

TBD — lands with Phase 8 (static JSON export).

## 14. Editing per-canonical-product page content (SEO)

TBD — lands with Phase 10 (SEO and per-canonical pages).

## 15. Troubleshooting

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
