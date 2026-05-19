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
