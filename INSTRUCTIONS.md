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

TBD — lands with Phase 4 (CSV import pipeline).

## 4. Interpreting import errors

TBD — lands with Phase 4.

## 5. Rolling back a bad import

TBD — lands with Phase 4.

## 6. Adding a new merchant

TBD — lands with Phase 3 (merchant management).

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

TBD — lands with Phase 6 (pending queue and approval workflow). Will cover:
review, edit, approve, reject, pause, defer; bulk approval of high-confidence
matches; marking trust signals (third-party tested, COA URL, certifications).

## 10. Editing an active offer

TBD — lands with Phase 6.

## 11. Pausing or deactivating an offer

TBD — lands with Phase 6.

## 12. Reading the clicks dashboard

TBD — lands with Phase 7 (click-out redirect).

## 13. Regenerating the public JSON manually

TBD — lands with Phase 8 (static JSON export).

## 14. Editing per-canonical-product page content (SEO)

TBD — lands with Phase 10 (SEO and per-canonical pages).

## 15. Troubleshooting

TBD — populated as failure modes are encountered. Each entry: symptom,
likely cause, fix.

---

**Versioning reminder:** every functional change bumps the plugin version (see
PROJECTBRIEF.md §11). The version visible in WP Admin → Plugins is the
canonical "did I upload the right build" signal. Use `scripts/bump-version.sh`.
