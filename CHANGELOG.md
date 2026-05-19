# Changelog

All notable changes to Supplement Compare are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html), with
pre-1.0 leniency per [`PROJECTBRIEF.md` §11](PROJECTBRIEF.md).

## [Unreleased]

### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security

---

## [0.5.0] — 2026-05-19

### Added
- `Supcomp_Offers_Repo`, `Supcomp_Import_Runs_Repo`, `Supcomp_Price_History_Repo` (under `plugin/includes/db/`). The offers repo has an explicit allow-list of CSV-touchable columns and a `diff_for_price_history()` helper — operator-curated columns are never overwritten by re-imports.
- `Supcomp_CSV_Validator` — pre-import gate. Checks required columns from PROJECTBRIEF.md §4, per-row enum/decimal/format validity, duplicate natural-key detection within the file, and merchant resolution (unknown merchants surface to the operator with a remediation hint). One row-level error fails the whole file; no partial writes.
- `Supcomp_CSV_Importer` — pipeline orchestrator. Creates the `import_runs` row, snapshots every CSV row into `raw_source_offers` (audit table), then upserts each row into `normalized_offers` by natural key. New offers land in `pending` for Phase 6 operator review. Existing offers update CSV-direct fields and log price/stock diffs to `price_history`. `stale` offers that reappear in a fresh import are restored to `active`.
- `Supcomp_Stale_Detector::mark_stale()` — runs after each import. For merchants in the run, offers with `last_seen_import_run_id` ≠ current and visibility in `{pending, active, needs_review}` flip to `stale`. Operator-set states (paused, rejected, dead) are left alone.
- CSV Imports admin screen: recent-runs history table (counts, status), upload form with dry-run checkbox and max-upload-size hint, per-run detail view with metadata, counts, and error log.
- `INSTRUCTIONS.md` §3 (upload CSV), §4 (interpret errors — three categories: fatal/validation/runtime), §5 (rollback — partial recovery only at v1), §15 (troubleshooting common failure modes).
- `CSV_SCHEMA_VERSION = "1.0"` constant in `aggregate_products.py` for future contract-bump tracking.

### Changed
- **`extractor/aggregate_products.py` brought into alignment with the PROJECTBRIEF.md §4 CSV contract.** Renamed `product_url` → `source_product_url` and `source_modified_at` → `source_updated_at`. Added required column `variation_retrieval_status` (emitted as `not_applicable` for default-variant Shopify and Woo simple products, `retrieved` for actual variant rows, `fallback_parent_only` for the Woo-variations-couldn\'t-be-fetched parent fallback). Added optional columns `source_variant_url` (Shopify variant deeplink, Woo variation permalink with attribute query args), `is_variable_parent` (the Woo fallback row), `price_source` (one of `shopify_variant` / `woo_store_api` / `woo_variation_api` / `jsonld`). Column order in the dataclass now matches the §4 canonical order; the script-only `store_name` extra trails the contract columns and the validator ignores it.
- `class-plugin.php` `load_domain()` now also requires the three new repos and the three new import classes. `class-import-screen.php` registers its `admin_post_supcomp_run_csv_import` handler.
- `class-import-screen.php` replaced — was a Phase 1 placeholder, now has the full import workflow.

### Deprecated
### Removed
### Fixed
- Validator strips a UTF-8 BOM from the first header cell before checking required columns (Excel CSV exports often include one).

### Security
- Upload handler checks `current_user_can( 'manage_options' )` and the nonce, uses `is_uploaded_file()` to verify the file came through a real POST upload, runs `wp_unslash` + `sanitize_file_name` on the filename, and reads the CSV via `fgetcsv` only — never executes file content.
- Stale-detection UPDATE uses `$wpdb->prepare()` with placeholders for every value including the IN-list members.

---

## [0.4.0] — 2026-05-19

### Added
- `Supcomp_Affiliate_URL_Template` (top of `plugin/includes/`) — engine that applies the four template patterns from PROJECTBRIEF.md §5 (`{product_url}`, `{url_encoded_product_url}`, `{path}`, `{handle}`). Handles the "appended `?` becomes `&` when the source URL already has a query string" rule. `validate()` catches unknown placeholder names. `apply()` returns `WP_Error` on malformed input. Same code runs the admin preview now and the `/out/{offer_id}` redirect in Phase 7.
- `Supcomp_Merchants_Repo` (in `plugin/includes/db/`) — full CRUD plus `get_by_site_url()` (case-insensitive, trailing-slash-tolerant) for Phase 4 CSV matching, plus a `normalize_site_url()` helper that defaults to https and strips trailing slashes.
- Merchants admin screen: list (status / platform / search filters), add/edit form with platform dropdown, ISO 4217 currency input, affiliate URL template field with pattern documentation, and an inline Template Tester (paste URLs, click "Test template", get a results table). Pause/Resume inline action; Dead status available in the dropdown but no inline button.
- `wp_ajax_supcomp_preview_affiliate_url` endpoint — handles the tester's POST request. Same engine as production; nonce-checked, capability-checked.
- `plugin/assets/admin/merchants-preview.js` — vanilla JS (no jQuery) that drives the tester. Enqueued only on the merchants new/edit pages.
- `INSTRUCTIONS.md` §6 (add merchant) — full operator procedure including template patterns, variable cheat sheet, and the tester workflow.

### Changed
- `class-plugin.php` `load_domain()` now also requires the affiliate URL template engine and the merchants repo (loaded always — admin-post and admin-ajax handlers need them before the admin context fully settles).
- `class-merchants-screen.php` replaced — was a Phase 1 placeholder, now full CRUD + template tester.

### Deprecated
### Removed
- `plugin/assets/admin/.gitkeep` — directory now has real content.

### Fixed
- `Supcomp_Affiliate_URL_Template::guess_handle_from_path` regex delimiter changed from `#…#` to `~…~`. The `#` delimiter clashed with the literal `#` inside the `[^/?#]+` character class, producing PHP "unknown modifier" warnings (which would have printed every time a merchant lacked a handle context). Caught by a one-off sanity-check harness; 11 engine cases pass clean.

### Security
- AJAX preview endpoint runs `check_ajax_referer` and `current_user_can( 'manage_options' )` before invoking the engine.

---

## [0.3.0] — 2026-05-19

### Added
- `Supcomp_Ingredients_Repo` and `Supcomp_Canonical_Products_Repo` (under `plugin/includes/db/`) — data-access layer for the two canonical tables. Both expose `get`, `get_by_slug`, `query`, `upsert` (by slug, with sanitization at the repo boundary), and `set_status`. Canonical-products repo also has `compute_derived()` and `derive_display_name()`.
- `Supcomp_Canonical_Products_Repo::compute_derived()` implements the math from PROJECTBRIEF.md §6: `total_strength = strength × servings`, `active_compound_per_serving = strength × pct/100` picking standardization% override → ingredient standardization default → ingredient elemental% → plain strength. Recomputed on every save.
- `Supcomp_Canonical_CSV_Importer` (under `plugin/includes/import/`) — bulk import for both tables from a CSV file on disk. Idempotent by slug. Per-row errors captured; one bad row never aborts the whole import.
- Canonical Ingredients admin screen: list (with category/status/search filters), create form, edit form, retire/restore inline action, CSV upload form with post-upload report. Every form has a nonce. Every entry point checks `manage_options`.
- Canonical Products admin screen: same shape as ingredients plus an ingredient picker (active ingredients only), form/strength/servings/standardization inputs, SEO-indexable checkbox, and a derived-fields read-out (total_strength, active_compound_per_serving) on the edit form so the operator can spot misconfigured percentages immediately.
- Six admin_post_* hooks for the canonical screens' POST handlers — save / set_status / import for each of ingredients and canonical products. PRG (POST→redirect→GET) pattern; rich import results stored in a per-user transient.
- `seed-data/ingredients.example.csv` and `seed-data/canonical-products.example.csv` — header rows plus 3-4 illustrative rows demonstrating an amino acid (no scaling), a mineral with an elemental fraction, and an herbal extract with a standardization compound.
- `INSTRUCTIONS.md` §7 (add ingredient) and §8 (add canonical product) — real procedures replacing the Phase 0 "TBD" placeholders.

### Changed
- `class-plugin.php` now splits loading into `load_domain()` (repos + importer, always loaded) and `load_admin()` (admin screens + admin_post hooks, only when `is_admin()`). The new canonical screens' `register_hooks()` methods are called from `load_admin()`.
- `class-ingredients-screen.php` and `class-canonical-products-screen.php` replaced — were Phase 1 placeholders, now full CRUD + CSV import implementations.

### Deprecated
### Removed
- `seed-data/.gitkeep` — directory now has real content.

### Fixed
### Security
- Every admin_post handler runs `current_user_can( 'manage_options' )` and `check_admin_referer()` before touching the database.
- All DB queries use `$wpdb->prepare()`; all form output is escaped via `esc_attr` / `esc_html` / `esc_textarea`; the CSV importer reads via `fgetcsv` and never executes file content.

---

## [0.2.0] — 2026-05-19

### Added
- Plugin bootstrap: `class-plugin.php` boots on `plugins_loaded`, loads textdomain, runs `Supcomp_Installer::maybe_upgrade()` (re-runs `dbDelta` when stored schema version trails the code), and wires admin-only classes when `is_admin()`.
- `Supcomp_Activator` runs the installer + seeds default options on activation; `Supcomp_Deactivator` is intentionally a no-op (deactivation never destroys data); `uninstall.php` is a stub with deferred-decision docblock.
- `Supcomp_Installer` creates all eight tables from PROJECTBRIEF.md §3 via `dbDelta`: `merchants`, `canonical_ingredients`, `canonical_products`, `import_runs`, `raw_source_offers`, `normalized_offers`, `price_history`, `click_log`. ENUM-like columns are `VARCHAR` with allowed values exported as class constants (`MERCHANT_PLATFORMS`, `INGREDIENT_CATEGORIES`, `STOCK_STATUSES`, `VISIBILITY_STATUSES`, etc.). Indexes added for the expected read patterns: pending-queue scans, stale detection, import matching by `(merchant, product, variant)`, public-site filtering by canonical product, cost-per-active-unit sort, click rollups.
- Admin menu: top-level "Supplement Compare" landing on Pending Queue, plus submenus for Active Offers, Import, Merchants, Ingredients, Canonical Products, Clicks, and Settings. Every submenu and screen render() enforces `manage_options`.
- Seven screen placeholder classes (one per file per PROJECTBRIEF.md §10). Each renders a "lands in Phase N" notice via a shared `Supcomp_Admin::render_placeholder()` helper that includes the current plugin version (operator's "did the new build upload" signal).
- Settings page via the WP Settings API: `supcomp_default_currency` (USD), `supcomp_staleness_warn_hours` (48), `supcomp_staleness_hide_hours` (168), `supcomp_affiliate_disclosure` (factual default copy with no health claims). Settings API auto-handles nonces, capability, and option storage.
- `INSTRUCTIONS.md` §1 — real install/update procedure now that the plugin is installable.

### Changed
- `plugin/supplement-compare.php` now requires the bootstrap classes and registers activation, deactivation, and `plugins_loaded` hooks. The Phase 0 placeholder activation function is gone; activation is wired to `Supcomp_Activator::activate`.

### Deprecated
### Removed
### Fixed
### Security
- Every admin-screen render() calls `current_user_can( 'manage_options' )` as defense in depth even though `add_submenu_page` already enforces the capability.

---

## [0.1.0] — 2026-05-19

Initial repository scaffold. No functional features. This version exists so
that subsequent version bumps have something to bump from.

### Added
- `PROJECTBRIEF.md` — architecture, data model, build phases (authoritative reference)
- `CLAUDE.md` — working instructions for the AI assistant
- `OPEN_QUESTIONS.md` — deferred decisions and pending ambiguities
- `README.md`, `INSTRUCTIONS.md`, `CHANGELOG.md` — top-level documentation skeletons
- `plugin/supplement-compare.php` — WordPress plugin main file at v0.1.0 with header, `SUPPLEMENT_COMPARE_VERSION` constant, ABSPATH guard, and placeholder activation hook (no DB work yet)
- `plugin/includes/{admin,import,normalization,public,db}/` — directory skeleton matching PROJECTBRIEF.md §10
- `plugin/assets/{admin,public}/`, `plugin/languages/` — empty subdirs with `.gitkeep`
- `extractor/aggregate_products.py` — Python extractor script (already-working version moved into place)
- `extractor/requirements.txt` — pinned dependencies (`requests`, `beautifulsoup4`)
- `extractor/README.md` — script usage and etiquette notes
- `docs/`, `seed-data/` — placeholders for documentation and seed CSVs
- `scripts/bump-version.sh` — version-bump helper updating the four lockstep locations from PROJECTBRIEF.md §11
- `.gitignore` — WordPress + Python defaults plus WSL Zone.Identifier cruft
