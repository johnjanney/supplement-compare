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

## [0.9.0] — 2026-05-19

### Added
- `Supcomp_JSON_Exporter` (`plugin/includes/public/class-json-exporter.php`) — generates the public payload from PROJECTBRIEF.md §9 to `wp-content/uploads/supplement-compare/public.json`. Atomic write via `.tmp` + `rename` so consumers never see a partial file. `mark_dirty()` registers a shutdown hook so multiple state changes within one request coalesce into one regenerate.
- `Supcomp_Offers_Repo::for_export()` — joined query returning active canonical-matched offers within the hide threshold, with merchant + canonical_product + ingredient fields attached. Ordered by canonical id then ascending cost-per-active-unit so per-canonical rollups (`lowest_cost_per_active_unit`, `offer_count`) accumulate in order.
- Staleness handling per PROJECTBRIEF.md §6: offers older than `supcomp_staleness_warn_hours` (default 48) get `is_stale: true`; offers older than `supcomp_staleness_hide_hours` (default 168) are excluded from the payload entirely.
- `do_action('supcomp_data_changed', $context)` fired from every state-change site: offer form save, queue row action, queue bulk action, CSV import end, canonical product save / status / CSV import, ingredient save / status / CSV import. Exporter listens once and calls `mark_dirty()`.
- Hourly cron `supcomp_export_cron` scheduled on activation, unscheduled on deactivation. Backup path when an in-process invalidation misses.
- Public JSON export status section on the Settings page: file path, public URL (clickable when the file exists), size + last write time, last recorded regenerate, next cron tick. "Regenerate now" button.
- `INSTRUCTIONS.md` §13 — how the auto-regenerate works, what the manual button is for, what's in / out of the file, troubleshooting.

### Changed
- `class-activator.php` schedules the cron + leaves initial generation for the first real state change (no offers to publish on a fresh install).
- `class-deactivator.php` unschedules the cron in addition to flushing rewrite rules.
- `plugin/supplement-compare.php` requires `Supcomp_JSON_Exporter` up front so the activator can schedule the cron before plugins_loaded fires.
- `class-plugin.php` `boot()` calls `Supcomp_JSON_Exporter::register_hooks()` which wires the data-changed listener and the cron-hook callback.

### Deprecated
### Removed
### Fixed
### Security
- Payload deliberately excludes raw affiliate URLs, merchant URL templates, source URLs, descriptions, raw_attributes_json, and operator notes (PROJECTBRIEF.md §9 honesty rules). The frontend gets `buy_url: /out/{id}` and never sees the merchant's affiliate redirect target.
- Atomic write prevents a fetcher from reading a half-written file during regenerate.

### Notes
- Cron registration relies on WP's pseudo-cron, which only fires on incoming requests. Low-traffic sites should configure a system cron pointing at `wp-cron.php` to ensure the hourly regenerate actually runs. The in-process invalidation listener is the primary path; cron is the safety net.

---

## [0.8.0] — 2026-05-19

### Added
- `Supcomp_Redirect` (`plugin/includes/public/class-redirect.php`) — registers `^out/(\d+)/?$` rewrite rule, query var `supcomp_out`, and a `template_redirect` handler. On hit: looks up the offer (joined with merchant + canonical), runs bot detection, hashes IP and UA with `wp_salt('auth')`, captures `utm_source/medium/campaign` + `Referer`, records a click_log row, generates the affiliate URL via `Supcomp_Affiliate_URL_Template` (falling back to bare `source_product_url` if no template), and 302-redirects. Raw IPs are never stored.
- Bot detection: curated UA regex (bot/crawler/spider/scraper/wget/curl/libwww/python-requests/headless/puppeteer/playwright + named bots including googlebot/bingbot/facebookexternalhit/etc.) OR rapid-fire (≥ 10 clicks from same hashed IP in 60s).
- `Supcomp_Clicks_Repo` — record_click, recent, count_within / count_bots_within, top_by_offer / top_by_merchant / top_by_canonical, is_rapid_fire. Aggregations exclude bot-suspected by default (operator-flippable). Bot-suspected rows still get stored so the operator can spot scraper patterns.
- Click analytics admin screen — replaces the Phase 1 placeholder. Time-window filter (today / 7d / 30d / all). Three summary tiles. Three top-N tables. Recent-clicks audit at the bottom with referrer + UTM tags + bot flag.
- "Test Buy Now (/out/N)" button on the offer detail/edit form — opens the live redirect in a new tab so the operator can verify the rewrite + template + logging chain without leaving the admin.
- `INSTRUCTIONS.md` §12 — read clicks dashboard, what's tracked (and what isn't), how to spot-check a click, what to do if /out/{id} returns 404.

### Changed
- `Supcomp_Activator::activate` registers the /out/ rewrite rule then calls `flush_rewrite_rules()` so the URL works immediately after activation. `Supcomp_Deactivator::deactivate` flushes again to clean up.
- `plugin/supplement-compare.php` requires `Supcomp_Affiliate_URL_Template`, `Supcomp_Offers_Repo`, `Supcomp_Clicks_Repo`, and `Supcomp_Redirect` up front (the activator needs the redirect class to register its rule before flush; chaining minimal deps from the main plugin file is the simplest way to make activation work on a fresh install before `plugins_loaded` fires). `class-plugin.php` `load_domain()` no longer re-requires those — comments note why.
- `class-plugin.php` `boot()` registers `init` (rewrite rule), `query_vars` (add `supcomp_out`), and `template_redirect` (the click handler) hooks.
- `class-clicks-screen.php` replaced — was a Phase 1 placeholder.

### Deprecated
### Removed
### Fixed
### Security
- IP and UA are SHA-256-hashed with `wp_salt('auth')` as the salt before insert; raw values never reach the database.
- The handler uses `wp_redirect` (not `wp_safe_redirect`) because affiliate URLs are off-site. `nocache_headers()` is sent so intermediate caches don't keep stale destinations. The template engine itself was already capability-checked at the merchant-edit time; click-time application has no user input that can affect routing beyond `offer_id` (clamped to int via query var).
- Rejected offers return HTTP 410 (Gone) rather than 302-ing — an old link to a removed offer shouldn't silently bounce.

### Notes
- No rate limit on /out/. Rapid-fire detection logs bot suspicion but doesn't block, which means a determined scraper can still hit the redirect at whatever rate WordPress can serve it. The merchant's affiliate program would catch that side; for v1 we accept the trade-off and revisit if abuse shows up in the dashboard.

---

## [0.7.0] — 2026-05-19

### Added
- Pending Queue admin screen — full rebuild from the Phase 1 placeholder. Lists offers with visibility in `{pending, needs_review}`, filterable by merchant / ingredient / confidence threshold / has-canonical-toggle / text search. Per-row Approve / Reject / Edit buttons with form-level nonces. Bulk-action dropdown (Approve / Reject / Pause / Defer) over checkboxed rows. Color-coded confidence badge: green ≥ 0.95, blue ≥ 0.85, yellow 0.65–0.85, gray below.
- Active Offers admin screen — same shape, filtered to `visibility=active`. Per-row Pause / Re-review (defer). Default sort by `cost_per_active_unit` so the operator sees the cheap-per-mg leaders first.
- `Supcomp_Offer_Form` shared helper (`plugin/includes/admin/class-offer-form.php`) — side-by-side raw-vs-normalized detail view used by both queue screens. All operator-curated fields editable: canonical_product_id (optgrouped picker), ingredient_id, ingredient_form, strength/unit, servings, standardization_percentage, third_party_tested, coa_available, coa_url, certifications, operator_notes. Action buttons: Save (stay on form), Save & Approve, Save & Pause, Save & Reject, Save & Defer. Recomputes derivations after save.
- Repo extensions: `Supcomp_Offers_Repo::get_with_joins`, `query_for_admin`, `count_for_admin`, `manual_update` (sanitization at the boundary against enum lists and PRODUCT_FORMS), `set_visibility`, `bulk_set_visibility`, `latest_raw_for`, `decode_certifications`. `Supcomp_Canonical_Products_Repo::for_picker` (active+draft list with ingredient joined, optgroup-ready).
- `INSTRUCTIONS.md` §9 (work the pending queue — 10-second clean-case workflow and detail edit), §10 (edit active offer), §11 (pause / reject / resume).

### Changed
- `class-plugin.php` `load_admin()` requires the new `class-offer-form.php` and registers the queue screen's `admin_post_supcomp_offer_row_action` / `_bulk_action` + the offer form's `admin_post_supcomp_save_offer` hooks.
- `class-pending-queue-screen.php` and `class-active-offers-screen.php` replaced — were Phase 1 placeholders.

### Deprecated
### Removed
### Fixed
### Security
- All admin_post handlers run `current_user_can('manage_options')` and `check_admin_referer()`. Row actions use per-id nonces (`supcomp_offer_row_action_{id}`) so a stale link can't pop an action on a different offer. Bulk action uses `$wpdb->prepare()` with `%d` placeholders for every id.
- `manual_update` validates canonical_product_id / ingredient_id by absint, enum-like fields against `Supcomp_Installer` constants, COA URL through `esc_url_raw`.

### Notes
- The 10-second pending-queue workflow target depends on real-world matcher hit rates. Built-in rules + the §7 matcher gave the right answer on 20/20 synthetic test offers at v0.6.0, but the live numbers won't be known until John runs his first real merchant CSV through. Watch the confidence-bucket histogram on the queue after the first import — if too many rows land in the gray (no-match) tier, the manual override workflow gets exercised more than the bulk-approve flow, and we revisit operator-editable rules.

---

## [0.6.0] — 2026-05-19

### Added
- Four built-in extraction rules under `plugin/includes/normalization/rules/`:
  - `Supcomp_Strength_Rule` — recognizes `N mg/mcg/g/IU/billion CFU`, spelled-out units, parenthesized strengths.
  - `Supcomp_Count_Rule` — recognizes `N capsules/tablets/softgels/count/ct/servings`, hyphenated counts, `xN` shorthand.
  - `Supcomp_Form_Rule` — keyword search for capsule/tablet/softgel/powder/liquid/sublingual/gummy with order-of-precedence so "softgel" doesn't match as a generic "gel" and "sublingual" wins over "tablet".
  - `Supcomp_Standardization_Rule` — `N% compound` and `standardized to N%` patterns. Guards against "100% organic/vegan/natural" purity-claim false positives.
- `Supcomp_Normalizer::normalize($offer)` — orchestrator. Concatenates variant_title + product_title + description + flattened raw_attributes_json, runs all four rules, matches text against canonical_ingredients (longest-name-first so "L-Theanine" beats "theanine"). Returns flat array of normalized fields.
- `Supcomp_Matcher::match($offer, $normalized)` — implements PROJECTBRIEF.md §7 in confidence order: 1.00 barcode peer → 0.95 brand+SKU peer → 0.85 direct canonical_product lookup → 0.85 brand+normalized-title peer → 0.75 brand+title+strength+count peer → 0.65 title+strength+count peer. `normalize_title()` lowercase / strip punctuation / drop stop tokens (form words, unit words, counting words) per §7.
- `Supcomp_Offer_Derivations::compute($offer, $ingredient)` — pure function computing total_strength / active_compound_per_serving / active_compound_total / cost_per_serving / cost_per_active_unit per PROJECTBRIEF.md §6. Precedence for the active-compound percentage: offer override → ingredient standardization default → ingredient elemental → no scaling.
- `Supcomp_Ingredients_Repo::all_for_matching()` — returns id/name/aliases list, statically cached so the matcher hits the DB once per request regardless of CSV size.
- `Supcomp_Offers_Repo::apply_normalization_and_match()` — writes the operator-curated fields on a fresh offer. When the matcher proposed a canonical_product_id, the canonical's (ingredient_id, form, strength, std%) override the normalizer's guesses.
- `Supcomp_Offers_Repo::apply_derivations()` — writes the derived field set.

### Changed
- `Supcomp_CSV_Importer` now runs normalize+match on every fresh insert and derivations on every insert AND update. Normalize+match deliberately do NOT re-run on updates — operator edits in the (Phase 6) pending queue are sticky. Derivations recompute every time so cost-per-active-unit stays current with price changes.
- `class-plugin.php` `load_domain()` requires the four rule files + the three normalization classes. Import classes are still required after normalization so the importer can name them.

### Deprecated
### Removed
- `plugin/includes/normalization/rules/.gitkeep` — directory now has the four rule classes.

### Fixed
### Security
- All matcher queries use `$wpdb->prepare()`. Title normalization happens in PHP so user-supplied text is never interpolated into SQL.

### Scope cuts
- The Phase 5 brief lists "Attribute-mapping admin UI" and "Per-merchant override rules". Both deferred. Built-in rules cover the common case at v0.6.0 (20/20 pass on a realistic offer harness covering ND, NOW Foods, alias matching, JSON-attribute scanning, false negatives). When a built-in rule misses, Phase 6's pending queue is where the operator corrects the offer's normalized fields manually. Operator-defined rules can come in a later sub-phase only if a real pattern emerges where the operator finds themselves correcting the same merchant's rows repeatedly.

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
