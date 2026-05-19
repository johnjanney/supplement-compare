# Supplement Compare — Project Brief

> **Working title:** Supplement Compare
> **Plugin slug:** `supplement-compare`
> **Primary domain:** TBD (placeholder used in code: `example.invalid`)
> **Build environment:** Claude Code CLI running in WSL2 (Ubuntu) on Windows
> **Target deployment:** Self-hosted WordPress site
> **Brief owner:** John (Janney Solutions LLC / Apex Optima)
> **Brief version:** 1.0 (initial)

---

## 1. What this project is

A WordPress-powered affiliate comparison site for **single-ingredient supplements** in three verticals: **nootropics, longevity, and sports nutrition**. The site lets price-conscious buyers compare the same compound across multiple participating merchants on a **cost-per-mg-of-active-compound** basis.

The site does not sell anything. Every product listing links out to a participating merchant via an affiliate URL. The site's value to shoppers is honest, normalized, current price comparison that no single merchant provides and that general retailers (Amazon, iHerb) do not surface clearly.

### The wedge

- **Single-ingredient only.** No blends, no proprietary stacks, no multi-ingredient formulas. The comparison math only works when you can compare one compound at a time.
- **Within-form comparison only.** Magnesium glycinate is not compared to magnesium oxide. L-theanine capsules are not compared to L-theanine powder. The user filters by form first, then sees price-per-mg within that form.
- **Curation, not aggregation.** Every offer goes through a manual approval queue before appearing on the public site. The site's trust signal is that the operator has personally vetted every listed product.
- **Affiliate-relationship-required.** Merchants must have an affiliate program the operator has joined. The data ingestion mechanism (described below) is separate from the affiliate relationship.

### Stakeholders

1. **Site operator** (the user): joins merchant affiliate programs, runs data ingestion, curates the comparison database, owns the site.
2. **Participating merchants:** WooCommerce or Shopify stores in the supplement vertical with affiliate programs the operator has joined.
3. **Site visitors:** price-conscious supplement buyers researching specific compounds.

---

## 2. How data flows through the system

```
┌─────────────────────────────────────────────────────────────────┐
│  External (operator's local machine or scheduled job)           │
│                                                                 │
│  Python script (aggregate_products.py)                          │
│  → fetches product data from merchant Shopify/Woo endpoints     │
│  → emits canonical-schema CSV, one row per variant              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼ (manual upload or scheduled drop)
┌─────────────────────────────────────────────────────────────────┐
│  WordPress (the plugin built by this project)                   │
│                                                                 │
│  CSV Import → raw_source_offers table                           │
│       ↓                                                         │
│  Normalization → parse strength/count/form from raw data        │
│       ↓                                                         │
│  Matching → suggest canonical_product_id with confidence score  │
│       ↓                                                         │
│  Pending Queue → operator reviews, edits, approves, or rejects  │
│       ↓                                                         │
│  Active Offers → become visible on public site                  │
│       ↓                                                         │
│  Static JSON Export → CDN-cacheable payload for the frontend    │
│       ↓                                                         │
│  Frontend (in-memory comparison table, served via theme)        │
│       ↓                                                         │
│  Buy Now → /out/{offer_id} → log click → 302 to affiliate URL   │
└─────────────────────────────────────────────────────────────────┘
```

**Key principle:** the WordPress plugin does no live calls to merchant sites. Everything is fed from CSV imports. The Python script is the only thing that talks to merchants.

---

## 3. Data model

Six core entities. Implementation is custom database tables (not WordPress post meta), because the read patterns require fast filtered/sorted queries that post meta cannot serve at scale.

### 3.1 `merchants`

```
id                       BIGINT, PK
slug                     VARCHAR(64), UNIQUE        e.g. "nootropics-depot"
name                     VARCHAR(255)               e.g. "Nootropics Depot"
site_url                 VARCHAR(255)               the merchant URL (script's natural key)
platform                 ENUM('shopify','woocommerce','generic','manual')
default_currency         CHAR(3)                    ISO 4217, used when CSV doesn't supply
affiliate_url_template   TEXT                       see section 3.7 for templating rules
status                   ENUM('active','paused','dead')
notes                    TEXT
created_at, updated_at   DATETIME
```

### 3.2 `canonical_ingredients`

The compound database. Populated manually before any imports run.

```
id                          BIGINT, PK
slug                        VARCHAR(64), UNIQUE       e.g. "l-theanine"
name                        VARCHAR(255)              e.g. "L-Theanine"
aliases_json                JSON                      ["theanine", "Suntheanine", ...]
category                    ENUM('nootropic','longevity','sports','mineral','vitamin','amino_acid','other')
default_unit                ENUM('mg','mcg','g','IU','billion_cfu')
elemental_percentage        DECIMAL(5,2) NULL         e.g. 14.10 for magnesium glycinate
standardization_compound    VARCHAR(255) NULL         e.g. "bacosides" for bacopa monnieri
standardization_default_pct DECIMAL(5,2) NULL         e.g. 50.00 for "50% bacosides"
status                      ENUM('active','draft','retired')
notes                       TEXT
```

### 3.3 `canonical_products`

The comparable SKU concepts. Each row is a "shape" of product (e.g. "L-Theanine 200mg Capsule"), independent of which merchant sells it.

```
id                          BIGINT, PK
slug                        VARCHAR(128), UNIQUE
ingredient_id               BIGINT, FK → canonical_ingredients
ingredient_form             ENUM('capsule','tablet','softgel','powder','liquid','sublingual','gummy','other')
strength_per_serving        DECIMAL(12,4)            in the ingredient's default_unit
servings_per_container      INT NULL                 NULL if highly variable across offers
total_strength              DECIMAL(14,4) NULL       derived: strength × servings (NULL if servings_per_container is NULL)
standardization_compound    VARCHAR(255) NULL        override of ingredient default
standardization_percentage  DECIMAL(5,2) NULL        override of ingredient default
active_compound_per_serving DECIMAL(12,4) NULL       derived; the field used for cost-per-mg comparison
display_name                VARCHAR(255)             e.g. "L-Theanine 200mg Capsules"
seo_indexable               BOOLEAN                  controlled by offer count rules, see section 5
status                      ENUM('active','draft','retired')
```

### 3.4 `raw_source_offers`

Exactly what came from CSV import. Never published. Never modified after insert. The audit/debug layer.

```
id                       BIGINT, PK
import_run_id            BIGINT, FK → import_runs
merchant_id              BIGINT, FK → merchants
source_platform          VARCHAR(32)
source_product_id        VARCHAR(255)
source_variant_id        VARCHAR(255)
raw_csv_row_json         JSON                    the full CSV row as-imported
imported_at              DATETIME
```

### 3.5 `normalized_offers`

The working table. One row per merchant × variant. This is what the admin curates and what becomes the public site's data source.

```
id                          BIGINT, PK
merchant_id                 BIGINT, FK → merchants
canonical_product_id        BIGINT NULL, FK → canonical_products
source_platform             VARCHAR(32)
source_product_id           VARCHAR(255)
source_variant_id           VARCHAR(255)

-- Identification
product_title               VARCHAR(512)
variant_title               VARCHAR(255)
brand                       VARCHAR(255)
sku                         VARCHAR(255)
barcode                     VARCHAR(64)              UPC/GTIN/EAN when available

-- Normalized facts (operator-editable in admin)
ingredient_id               BIGINT NULL, FK         denormalized for fast filter; redundant with canonical
ingredient_form             VARCHAR(32) NULL
strength_per_serving        DECIMAL(12,4) NULL
strength_unit               VARCHAR(16) NULL
servings_per_container      INT NULL
total_strength              DECIMAL(14,4) NULL       derived
standardization_percentage  DECIMAL(5,2) NULL
active_compound_per_serving DECIMAL(12,4) NULL       derived
active_compound_total       DECIMAL(14,4) NULL       derived; key sort field for site

-- Pricing
regular_price               DECIMAL(10,4) NULL
sale_price                  DECIMAL(10,4) NULL
current_price               DECIMAL(10,4) NULL
on_sale                     BOOLEAN
currency                    CHAR(3)
cost_per_serving            DECIMAL(10,4) NULL       derived
cost_per_active_unit        DECIMAL(12,6) NULL       derived; THE comparison metric

-- Inventory
stock_status                ENUM('in_stock','out_of_stock','backorder','unavailable','unknown')

-- Trust signals (operator-set, not from CSV)
third_party_tested          BOOLEAN
coa_available               BOOLEAN
coa_url                     VARCHAR(512) NULL
certifications_json         JSON                     e.g. ["NSF","USP","Informed_Sport"]

-- URLs
source_product_url          VARCHAR(512)
source_variant_url          VARCHAR(512) NULL        Shopify only
affiliate_url               VARCHAR(512) NULL        derived from merchant template; recomputed on demand

-- State
visibility_status           ENUM('pending','active','paused','rejected','stale','dead','needs_review')
match_confidence            DECIMAL(3,2) NULL        0.00–1.00; see section 3.6
variation_retrieval_status  ENUM('not_applicable','retrieved','failed','fallback_parent_only')
operator_notes              TEXT
last_seen_import_run_id     BIGINT, FK → import_runs
first_seen_at               DATETIME
last_synced_at              DATETIME
updated_at                  DATETIME
```

### 3.6 `import_runs`

Each CSV import creates one row. Enables the stale-detection logic.

```
id                       BIGINT, PK
export_run_id            VARCHAR(64)              from the CSV's export_run_id column
exported_at              DATETIME                 from the CSV's exported_at column
imported_at              DATETIME
csv_filename             VARCHAR(255)
row_count                INT
rows_inserted            INT
rows_updated             INT
rows_marked_stale        INT
rows_errored             INT
status                   ENUM('validating','importing','complete','failed','rolled_back')
error_log                TEXT
```

### 3.7 `price_history`

Logged on every price or stock change. Cheap to add now, painful later.

```
id                       BIGINT, PK
offer_id                 BIGINT, FK → normalized_offers
old_regular_price        DECIMAL(10,4) NULL
new_regular_price        DECIMAL(10,4) NULL
old_sale_price           DECIMAL(10,4) NULL
new_sale_price           DECIMAL(10,4) NULL
old_stock_status         VARCHAR(32) NULL
new_stock_status         VARCHAR(32) NULL
import_run_id            BIGINT, FK → import_runs
changed_at               DATETIME
```

### 3.8 `click_log`

Every click on a Buy Now button.

```
id                       BIGINT, PK
offer_id                 BIGINT, FK → normalized_offers (NULL-allowed for forensics if offer deleted)
canonical_product_id     BIGINT NULL
merchant_id              BIGINT NULL
clicked_at               DATETIME
ip_hash                  CHAR(64)                  SHA-256, no raw IPs stored
user_agent_hash          CHAR(64)                  SHA-256
referrer                 VARCHAR(512) NULL
utm_source               VARCHAR(128) NULL
utm_medium               VARCHAR(128) NULL
utm_campaign             VARCHAR(128) NULL
is_bot_suspected         BOOLEAN
```

---

## 4. The CSV contract (Python ↔ WordPress)

The Python script `aggregate_products.py` produces the CSV. The WordPress plugin imports the CSV. The schema below is the **contract between them**. Both sides must agree.

| Column | Required | Notes |
|---|---|---|
| `export_run_id` | yes | UUID-like string identifying the export batch |
| `exported_at` | yes | ISO 8601 UTC timestamp |
| `source` | yes | `shopify` \| `woocommerce` \| `generic` |
| `site` | yes | Merchant URL — natural key matched to `merchants.site_url` |
| `source_product_id` | yes | Platform product ID |
| `source_variant_id` | no | Blank if no variants |
| `product_title` | yes | |
| `variant_title` | no | Blank for single-variant or default-variant products |
| `handle` | no | URL slug |
| `brand` | no | Shopify vendor or Woo Brands/attribute |
| `product_type` | no | |
| `sku` | no | |
| `barcode` | no | UPC/GTIN/EAN |
| `regular_price` | no | Decimal string |
| `sale_price` | no | Decimal string; blank if not on sale |
| `current_price` | no | Decimal string |
| `on_sale` | yes | `true` \| `false` |
| `currency` | no | ISO 4217; if blank, plugin uses merchant's default_currency |
| `currency_minor_unit` | no | e.g. "2" for cents |
| `price_source` | no | `shopify_variant` \| `woo_store_api` \| `woo_variation_api` \| `jsonld` |
| `stock_status` | yes | `in_stock` \| `out_of_stock` \| `backorder` \| `unavailable` \| `unknown` |
| `purchasable` | no | `true` \| `false` \| empty |
| `source_product_url` | yes | |
| `source_variant_url` | no | Shopify only; `{product_url}?variant={variant_id}` |
| `source_created_at` | no | ISO 8601 if available |
| `source_updated_at` | no | ISO 8601 if available |
| `is_variable_parent` | no | `true` only for Woo fallback rows |
| `variation_retrieval_status` | yes | `not_applicable` \| `retrieved` \| `failed` \| `fallback_parent_only` |
| `description` | no | **Internal use only.** Never published. Used for parsing strength/count/form. |
| `raw_attributes_json` | no | JSON blob of platform-specific data |

**Encoding:** UTF-8, RFC 4180 quoting, LF line endings.

**Stable column order** — the order above is the canonical order. The import validator must accept any subset and any order, but the script SHOULD emit in this order for reviewability.

**Schema versioning:** if this contract changes, bump both:
- The script's `CSV_SCHEMA_VERSION` constant
- The plugin's `MIN_CSV_SCHEMA_VERSION` constant
The plugin rejects imports with a schema_version older than its minimum.

---

## 5. The affiliate URL template

Stored per merchant, applied at offer-display time. Supports four patterns:

| Pattern | Template example | Notes |
|---|---|---|
| Simple query append | `{product_url}?aff=john` | Plugin detects existing `?` and uses `&` |
| Multiple params | `{product_url}?utm_source=affiliate&ref=john` | Same query-detection logic |
| Network redirect | `https://partners.example.com/c/?id=42&u={url_encoded_product_url}` | Useful for affiliate networks |
| Path-based | `https://merchant.com/ref/john{path}` | `{path}` is everything after the merchant domain |

**Template variables:**
- `{product_url}` — the full `source_product_url`
- `{url_encoded_product_url}` — URL-encoded full product URL
- `{path}` — path portion only (no domain, no query string)
- `{handle}` — the product slug if available

**Buy Now links never expose the raw affiliate URL.** The frontend always links to `/out/{offer_id}`. The redirect endpoint:
1. Looks up the offer
2. Logs the click to `click_log`
3. Generates the affiliate URL from the merchant template
4. 302 redirects to it

This keeps affiliate URLs out of the static JSON payload and out of crawlable HTML.

---

## 6. Math: how cost-per-mg is computed

The site's comparison value rests on this calculation being correct and honest.

### Inputs (per offer)

```
strength_per_serving             (mg or other unit)
servings_per_container
standardization_percentage       (NULL if not applicable)
elemental_percentage             (NULL if not applicable; on ingredient, not offer)
current_price
```

### Derivations

```
# Total strength of the named compound across the whole container
total_strength = strength_per_serving × servings_per_container

# Amount of active compound per serving (after standardization or elemental %)
if standardization_percentage is not NULL:
    active_compound_per_serving = strength_per_serving × (standardization_percentage / 100)
elif elemental_percentage is not NULL:
    active_compound_per_serving = strength_per_serving × (elemental_percentage / 100)
else:
    active_compound_per_serving = strength_per_serving

active_compound_total = active_compound_per_serving × servings_per_container

# The two main comparison metrics
cost_per_serving = current_price / servings_per_container
cost_per_active_unit = current_price / active_compound_total
```

### Display rules

- `cost_per_active_unit` is displayed as "Cost per mg [of compound]" — labeled with the active compound name
- Comparison is restricted to **same canonical_product** (which means same ingredient + form + standardization context)
- The site does **not** compare across forms (capsule vs powder) or across standardizations (50% bacosides vs 20% bacosides) by default; these are separate canonical products
- Sort order on the comparison page is `cost_per_active_unit` ascending by default
- If `active_compound_per_serving` is NULL (insufficient data), the offer is excluded from cost-per-mg sort and labeled "data incomplete"

### Honesty rules

- Never compute a "best price" across forms with different bioavailability
- Never present elemental-based and compound-based pricing as directly comparable
- Always show the source numbers (strength, servings, price) alongside the derived per-mg figure
- Stale offers (`last_synced_at` > 48 hours) are visually downgraded; offers > 7 days old are hidden from public site

---

## 7. Match confidence scoring

Used by the matching layer to suggest `canonical_product_id` for new offers. The score appears in the pending queue UI so the operator can fast-approve high-confidence matches.

| Score | Criteria |
|---|---|
| 1.00 | Barcode (UPC/GTIN/EAN) exact match with an existing offer's barcode AND ingredient/form/strength match |
| 0.95 | Brand + manufacturer SKU exact match (offer-to-offer) |
| 0.85 | Brand + normalized product title exact match |
| 0.75 | Brand + normalized title + strength + count match |
| 0.65 | Normalized title + strength + count match (no brand) |
| < 0.65 | No suggested match; manual canonical assignment required |

**Title normalization** for matching: lowercase, strip punctuation, collapse whitespace, remove common stop tokens (`capsules`, `tablets`, `softgels`, `count`, `ct`, `serving`, brand names already extracted into the brand field).

**No auto-publish.** Even confidence 1.00 goes to the pending queue. The operator approves; the system never approves itself. This is core to the curation positioning.

---

## 8. Build phases

Phases are sequenced for end-to-end testability at each step. Do not skip ahead; each phase produces a working, demonstrable artifact.

### Phase 0 — Repo and tooling

- Initialize Git repository
- Create file structure (see section 10)
- Create `README.md`, `INSTRUCTIONS.md`, `CHANGELOG.md` skeletons
- Plugin header at version `0.1.0`
- Verify the Python script (already exists) runs in WSL2 against at least one test merchant

### Phase 1 — Plugin foundation

- Plugin bootstrap (main file, activation/deactivation hooks)
- Database migrations: create all eight tables from section 3
- Admin menu skeleton: top-level "Supplement Compare" menu with submenus for each major area
- Settings page: site-wide config (currency default, staleness threshold hours, affiliate disclosure text)
- Capability check: only users with `manage_options` (configurable later) can access admin
- Nonces on every admin action
- **Deliverable:** installable plugin, admin menu visible, tables created on activation, no functionality yet
- **Version:** 0.1.0 → 0.2.0 on phase completion

### Phase 2 — Canonical data management

- Admin UI for `canonical_ingredients`: list, create, edit, retire
- Admin UI for `canonical_products`: list, create, edit, retire, derive computed fields
- CSV import for canonical ingredients (so the initial ~100-ingredient seed can be bulk-loaded)
- CSV import for canonical products
- **Deliverable:** operator can populate the canonical database before any merchant data is touched
- **Version:** → 0.3.0

### Phase 3 — Merchant management

- Admin UI for `merchants`: list, create, edit, pause
- Affiliate URL template field with live preview (paste a product URL, see what the affiliate URL looks like)
- Affiliate URL template tester (input a few example product URLs, see what gets generated)
- **Deliverable:** merchant records exist; URL templates validated
- **Version:** → 0.4.0

### Phase 4 — CSV import pipeline

- Import manager admin screen with file upload
- CSV validation layer (pre-import; reject malformed files entirely)
- Validation rules: required columns present, enum values valid, decimals parseable, no duplicate (merchant, source_variant_id) within a single import
- Dry-run mode: validate without writing
- On import:
  1. Create `import_run` row
  2. Insert all rows into `raw_source_offers`
  3. For each row, match to existing `normalized_offer` by (merchant_id, source_product_id, source_variant_id)
  4. Insert new ones as `pending`
  5. Update existing ones; log price/stock changes to `price_history`
  6. Mark offers not seen in this run as `stale`
- Import history screen: list of past runs with counts and error details
- **Deliverable:** CSV in, normalized rows out, stale detection working
- **Version:** → 0.5.0

### Phase 5 — Normalization and matching

- Normalization rules engine: parse `description`, `product_title`, `variant_title`, and `raw_attributes_json` to extract strength, count, form, standardization
- Attribute-mapping admin UI: define rules like "if raw attribute 'Dosage' → map to strength", "if title matches regex `(\d+)mg` → strength = $1"
- Per-merchant override rules (some merchants are weird)
- Match confidence scoring per section 7
- Suggested `canonical_product_id` populated for each pending offer
- **Deliverable:** new imports arrive in pending queue with normalized fields and suggested canonical match
- **Version:** → 0.6.0

### Phase 6 — Pending queue and approval workflow

- Pending queue UI: sortable, filterable, bulk-actionable
- Per-offer detail view: side-by-side raw vs normalized, all fields editable, canonical match selector with search
- Actions: Approve, Approve+Edit, Reject, Pause, Defer
- Bulk actions: bulk-approve high-confidence matches (still one-click per batch, no auto-publish)
- Operator can mark trust signals (third_party_tested, COA URL, certifications)
- **Deliverable:** operator can move offers from pending → active in under 10 seconds for clean cases
- **Version:** → 0.7.0

### Phase 7 — Click-out redirect

- `/out/{offer_id}` rewrite rule registered on plugin activation
- Click handler: log to `click_log`, hash IP and UA, generate affiliate URL from template, 302
- Bot detection (basic): obvious bot UAs, rapid-fire from same IP hash, no referrer + no JS evidence
- Admin click analytics screen: clicks per offer, per merchant, per canonical product, per time window
- **Deliverable:** Buy Now buttons work; clicks tracked
- **Version:** → 0.8.0

### Phase 8 — Static JSON export

- Generation job: scan `normalized_offers` where status = `active`, build the public payload
- Payload shape (section 9)
- Cache file written to `wp-content/uploads/supplement-compare/public.json`
- Cache invalidation: regenerate on approval, edit, or any visibility change; on a hourly cron as backup
- Stale offers (>48h since `last_synced_at`) get a `is_stale=true` flag; offers >7 days are excluded entirely
- **Deliverable:** a real JSON file with real data, fetchable via HTTP
- **Version:** → 0.9.0

### Phase 9 — Public frontend

- Shortcode `[supplement_compare]` renders the comparison interface
- Single-page React-or-vanilla-JS app that loads the JSON, hydrates into an in-memory table
- Default view: list of canonical products with lowest cost-per-mg and merchant count
- Detail view: comparison table for one canonical product across all merchants
- Filters: form, ingredient, merchant, stock status, trust signals, price range
- Sort: cost-per-mg (default), price, brand, merchant, recency
- Search: ingredient name with alias matching
- Affiliate disclosure: prominent on every page with comparison content
- "Data last updated" timestamp on every offer row
- **Deliverable:** site visible to public, comparison works end-to-end
- **Version:** → 0.10.0

### Phase 10 — SEO and per-canonical pages

- One indexable page per `canonical_product` where `seo_indexable=true` and offer count >= 3
- Page content: factual reference (the ingredient, the form, the standardization concept) — operator-editable rich text per canonical product
- Comparison table embedded
- Schema.org `Product` + `AggregateOffer` markup
- Automatic `noindex` on canonical pages that drop below the offer-count threshold
- XML sitemap generation
- **Deliverable:** site is SEO-viable
- **Version:** → 1.0.0 (production-ready milestone)

### Post-1.0 phases (not in scope for initial build)

- Authenticated API access (Shopify custom apps, Woo REST API keys) as a CSV-skipping alternative path
- Per-canonical-product editorial content workflow
- Email alerts for price drops
- Subscription/saved-search features for visitors
- Multi-currency support
- Internationalization

---

## 9. Static JSON payload shape

The contract between the WordPress plugin and the public frontend.

```json
{
  "schema_version": "1.0",
  "generated_at": "2026-05-18T19:32:00Z",
  "canonical_products": [
    {
      "id": 42,
      "slug": "l-theanine-200mg-capsule",
      "display_name": "L-Theanine 200mg Capsules",
      "ingredient": { "id": 7, "name": "L-Theanine", "category": "nootropic" },
      "form": "capsule",
      "strength_per_serving": 200,
      "strength_unit": "mg",
      "standardization_compound": null,
      "standardization_percentage": null,
      "lowest_cost_per_active_unit": 0.0042,
      "active_unit_label": "mg",
      "offer_count": 5
    }
  ],
  "offers": [
    {
      "id": 1881,
      "canonical_product_id": 42,
      "merchant": { "id": 3, "slug": "nootropics-depot", "name": "Nootropics Depot" },
      "brand": "Nootropics Depot",
      "product_title": "L-Theanine 200mg Capsules",
      "variant_title": "60 Count",
      "current_price": 14.99,
      "regular_price": 14.99,
      "sale_price": null,
      "on_sale": false,
      "currency": "USD",
      "servings_per_container": 60,
      "strength_per_serving": 200,
      "active_compound_per_serving": 200,
      "active_compound_total": 12000,
      "cost_per_serving": 0.2498,
      "cost_per_active_unit": 0.001249,
      "stock_status": "in_stock",
      "third_party_tested": true,
      "coa_available": true,
      "coa_url": "https://...",
      "certifications": [],
      "buy_url": "/out/1881",
      "last_synced_at": "2026-05-18T08:00:00Z",
      "is_stale": false
    }
  ]
}
```

**Note:** no raw affiliate URLs. No product descriptions. No merchant API tokens. Nothing internal.

---

## 10. Repository structure

```
supplement-compare/
├── PROJECTBRIEF.md                  this file
├── CLAUDE.md                        Claude Code working instructions
├── README.md                        what this repo is, how to install
├── INSTRUCTIONS.md                  operator runbook (CSV imports, curation, etc.)
├── CHANGELOG.md                     versioned changelog
├── .gitignore
├── .editorconfig
│
├── plugin/                          the WordPress plugin
│   ├── supplement-compare.php       main plugin file with header
│   ├── uninstall.php
│   ├── includes/
│   │   ├── class-plugin.php
│   │   ├── class-activator.php
│   │   ├── class-deactivator.php
│   │   ├── class-installer.php      table migrations
│   │   ├── admin/
│   │   │   ├── class-admin.php
│   │   │   ├── class-merchants-screen.php
│   │   │   ├── class-ingredients-screen.php
│   │   │   ├── class-canonical-products-screen.php
│   │   │   ├── class-import-screen.php
│   │   │   ├── class-pending-queue-screen.php
│   │   │   ├── class-active-offers-screen.php
│   │   │   ├── class-clicks-screen.php
│   │   │   └── views/               admin templates
│   │   ├── import/
│   │   │   ├── class-csv-validator.php
│   │   │   ├── class-csv-importer.php
│   │   │   └── class-stale-detector.php
│   │   ├── normalization/
│   │   │   ├── class-normalizer.php
│   │   │   ├── class-matcher.php
│   │   │   └── rules/               extraction rules (regex sets etc.)
│   │   ├── public/
│   │   │   ├── class-public.php
│   │   │   ├── class-shortcode.php
│   │   │   ├── class-redirect.php   /out/{id} handler
│   │   │   └── class-json-exporter.php
│   │   └── db/
│   │       ├── class-merchants-repo.php
│   │       ├── class-ingredients-repo.php
│   │       ├── class-canonical-products-repo.php
│   │       ├── class-offers-repo.php
│   │       ├── class-import-runs-repo.php
│   │       ├── class-price-history-repo.php
│   │       └── class-clicks-repo.php
│   ├── assets/
│   │   ├── admin/                   admin CSS/JS
│   │   └── public/                  frontend bundle for the comparison table
│   └── languages/
│
├── extractor/                       the Python script
│   ├── aggregate_products.py        (already exists; will be refined)
│   ├── requirements.txt
│   └── README.md                    script-specific usage
│
├── docs/
│   ├── csv-schema.md                the contract (mirrors section 4)
│   ├── data-model.md                ER diagram + table notes
│   ├── normalization-rules.md       what extracts what
│   └── operator-workflow.md         day-in-the-life
│
├── seed-data/
│   ├── ingredients-nootropics.csv
│   ├── ingredients-longevity.csv
│   ├── ingredients-sports.csv
│   └── canonical-products-seed.csv
│
└── scripts/
    ├── bump-version.sh              version bump helper (see section 11)
    └── package-plugin.sh            produces installable .zip
```

---

## 11. Versioning policy

**Semantic Versioning** (`MAJOR.MINOR.PATCH`) with pre-1.0 leniency.

| Bump | When |
|---|---|
| MAJOR | Breaking change to the CSV schema, database schema (without migration), or public JSON shape after 1.0 |
| MINOR | New feature, new admin screen, new normalization capability, new build phase completed |
| PATCH | Bug fix, copy change, internal refactor with no external behavior change |

**Pre-1.0** (current state): bump MINOR on every phase completion (section 8). Bump PATCH for in-phase fixes. Breaking changes are allowed without MAJOR bumps until 1.0 ships.

**Version locations that must stay synchronized:**

1. **Plugin header** in `plugin/supplement-compare.php`:
   ```php
   /**
    * Plugin Name: Supplement Compare
    * Version: 0.1.0
    * ...
    */
   ```
2. **Plugin constant** `SUPPLEMENT_COMPARE_VERSION` defined in the main file
3. **`CHANGELOG.md`** has an entry for the new version
4. **`README.md`** "Current version" line at the top

**The plugin header version is the canonical "did I upload the right one" signal** in the WordPress admin. Every release MUST bump it. Never reuse a version number.

**Build helper** (`scripts/bump-version.sh`): given `--major`, `--minor`, or `--patch`, updates all four locations atomically and creates a Git tag `v{version}`.

**Installable package** (`scripts/package-plugin.sh`): produces `supplement-compare-{version}.zip` for upload via WordPress admin → Plugins → Add New → Upload Plugin.

---

## 12. Documentation requirements

Four documents that must exist and be kept current. Update them as part of the same change that introduces the feature they describe — never after the fact.

### 12.1 `README.md`

The front door. Audience: a developer (or future-you) opening the repo cold.

Must contain:
- Project name, one-line description
- Current version badge/line
- What the project is, in two paragraphs
- Quick start: install plugin, install Python deps, run first import
- Link to `INSTRUCTIONS.md` for operator workflows
- Link to `PROJECTBRIEF.md` for architecture
- Link to `CHANGELOG.md`
- License (TBD — pick before going public)

### 12.2 `INSTRUCTIONS.md`

The operator runbook. Audience: the site operator (currently the user) doing day-to-day work.

Must contain:
- How to run the Python script and produce a CSV
- How to upload a CSV via the WordPress admin
- How to work the pending queue (review, approve, reject, edit)
- How to add a new merchant
- How to add a new canonical ingredient and canonical product
- How to interpret import errors
- How to roll back a bad import
- How to manually edit an active offer
- How to mark an offer as featured or deactivate it
- How to read the clicks dashboard
- How to regenerate the public JSON manually
- Troubleshooting: common failure modes

Sections added as features ship in each phase.

### 12.3 `CHANGELOG.md`

Strict format following [Keep a Changelog](https://keepachangelog.com/) conventions, in reverse chronological order.

```markdown
# Changelog

All notable changes to Supplement Compare are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security

## [0.1.0] — 2026-MM-DD

### Added
- Initial plugin scaffold
- Database tables for ...
- ...
```

Every PR (or every coherent commit when working solo) updates `[Unreleased]`. Version bumps move `[Unreleased]` content into a new dated version section.

### 12.4 `PROJECTBRIEF.md`

This file. The authoritative architectural reference. Updated when architectural decisions change. Not updated for every feature.

---

## 13. Conventions and standards

### WordPress

- Plugin follows the [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/) conventions
- Database table prefix: `{wpdb->prefix}supcomp_` (e.g. `wp_supcomp_merchants`)
- Function/class prefix: `Supcomp_` for classes, `supcomp_` for functions
- Text domain: `supplement-compare`
- All admin pages use WordPress nonces and capability checks
- All database queries use `$wpdb->prepare()`
- All user input is sanitized; all output is escaped

### Code style

- PHP: PSR-12 where compatible with WordPress (WordPress allows snake_case function names; honor that)
- Python: PEP 8, type hints where useful (the script already uses them)
- 4-space indents in both
- Inline comments for non-obvious logic; class-level docblocks for every class

### Git

- `main` is always shippable (i.e., the plugin builds and activates)
- Feature work on branches named `phase-N-description` or `fix-description`
- Commit messages: imperative ("Add merchant URL template tester", not "Added")
- Tag every version bump as `v{version}`

### Testing

- For Phase 0–3: manual testing acceptable
- For Phase 4 onwards: PHPUnit tests for the import pipeline, normalization, matching, and JSON export
- The Python script: pytest tests against fixture HTML/JSON responses

---

## 14. What this brief is NOT

This brief deliberately excludes:

- **Visual design** — out of scope for v1; default WordPress admin styling is fine; public frontend uses minimal CSS until a designer is brought in
- **Hosting setup** — assumed to be operator-provided self-hosted WordPress on a standard LAMP/LEMP stack
- **Affiliate program signup workflow** — operator handles this out-of-band with each merchant
- **Marketing, SEO content writing, social presence** — separate work streams
- **Multi-operator / role-based permissions** — single operator for v1; capability checks use `manage_options`
- **GDPR cookie-consent UI** — adds in production; not in initial scope (no PII collected anyway, but cookies for analytics may need consent)
- **Internationalization** — strings should use `__()` / `_e()` for future-proofing, but no translations shipped in v1

---

## 15. Open questions

Deferred decisions and pending ambiguities are tracked in `OPEN_QUESTIONS.md`.

At the time of this brief, the active questions blocking Phase 5 are:

- Initial canonical ingredient list (~80–150 ingredients)
- First three target merchants
- Currency policy (USD-only assumption)
- Staleness thresholds (48h / 7d defaults)

See `OPEN_QUESTIONS.md` for current status, ownership, and resolution history.

---

## 16. Definition of done (1.0)

Version 1.0.0 ships when:

- [ ] All 10 phases complete and demonstrable
- [ ] Three real merchants integrated end-to-end
- [ ] At least 50 canonical products with at least one active offer each
- [ ] Public frontend renders comparison correctly on desktop and mobile
- [ ] Click tracking confirmed working with real affiliate URLs
- [ ] `README.md`, `INSTRUCTIONS.md`, `CHANGELOG.md`, `PROJECTBRIEF.md`, `OPEN_QUESTIONS.md` all current
- [ ] Plugin installs cleanly from a freshly-built `.zip` on a fresh WordPress install
- [ ] Operator has run a complete cycle (script → CSV → import → curate → publish → click) without developer intervention

After 1.0, version bumps follow stricter SemVer (breaking changes require MAJOR).
