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

## [1.30.1] — 2026-06-15

### Changed
- **Price-direction indicator moved to the left of the price.** The ▲/▼ arrow
  and percentage now render *before* the price instead of after it, so the
  price text stays pinned to the right edge of the column and prices remain
  decimal-aligned whether or not a row has an indicator. Previously the
  arrow+% sat to the right and pushed the price leftward, breaking column
  alignment. Frontend-only (`frontend.js` render order + `frontend.css`
  margin); no data, export, or behavior changes.

---

## [1.30.0] — 2026-06-15

### Added
- **"At a Glance" dashboard widget.** A current-state summary on the native
  WordPress dashboard (admin-only, `manage_options`). Shows catalog size (live
  canonical products; active / total merchants), the live offer set with its
  in-stock / out-of-stock split, and a price-move tally for the configured
  window — **N changed (▲ up · ▼ down · — unchanged)**. The price-move counts
  reuse the exact definition behind the public price-direction indicator
  (`Supcomp_Price_History_Repo::price_moves_for_offers`) over the same live
  offer universe (`for_export`-equivalent: active, canonical-matched, fresh,
  active merchant), so the "changed" figure equals the number of ▲/▼ arrows
  readers see on the site. Respects `supcomp_price_move_window_days` (default
  30; a window of 0 shows "tracking disabled" instead of zeros). Read-only;
  computed on render from a handful of COUNT/GROUP BY queries.

---

## [1.29.0] — 2026-06-14

### Added
- **Automatic browser User-Agent fallback on HTTP 403.** When a request is
  refused with a 403 — typically a Cloudflare/WAF rule that blocks the
  extractor's honest `Supcomp-Extractor/…` crawler UA but allows a normal
  browser — the HTTP client retries the request **once** with a browser
  User-Agent before giving up. The retry is immediate (no backoff) and doesn't
  consume a retry-budget slot. Discovered while probing the merchant list:
  example-chems.is returns 403 to the crawler UA but 200 (real products) to a
  browser UA. No configuration needed; covers any future UA-blocked site.

### Notes
- Probe of the 16 configured merchants found **14 work with no special config**
  (their Woo Store API / Shopify endpoint answers a plain server request; the
  age gates on several are browser-only overlays that don't block the API).
  example-chems.is needed this UA fallback. example-labs.com remains a hard
  target (it locks its WordPress REST API with a 401 *and* edge-gates pages) —
  the per-site cookie (v1.28.0) can only help its generic-scrape path, if its
  product pages expose JSON-LD.

---

## [1.28.0] — 2026-06-14

### Added
- **Per-site "Request cookies" field on Extractor Sites.** An optional Cookie
  header sent with every request the extractor makes to that site. The motivating
  case: merchants behind a JavaScript **age-verification gate** (e.g. the "Age
  Gate" WordPress plugin) edge-redirect every uncredentialed request to a landing
  page, so a server-side fetch never sees products and the run completes with 0
  offers. Pasting the verification cookie captured from a browser (e.g.
  `age_gate=<value>`) lets the extractor through. Sent at the single HTTP
  chokepoint so it covers sitemap discovery, `products.json`, the Woo Store API,
  and product-page fetches alike; cleared between attempts so it never leaks
  across sites sharing a queue-runner process. An explicit `Cookie` header passed
  by a handler still wins.
  - **Schema:** `SCHEMA_VERSION` 10 → 11 adds the nullable `request_cookies`
    column to `extract_sites` (applied automatically via `dbDelta` on upgrade —
    no manual migration).

### Notes
- This addresses the Example Labs (example-labs.com) "0 offers" case observed
  after v1.27.0 — a WooCommerce store behind the Age Gate plugin. The run was
  already *completing cleanly* (the v1.26.0 reaper/finalize work); this lets it
  actually pull offers. The Age Gate cookie is persistent (~90 days) but
  re-capture is needed when it expires.

---

## [1.27.0] — 2026-06-14

Extractor run-dashboard fix, part 2 of the
[`EXTRACTOR_RUN_DASHBOARD_FIX_PLAN.md`](EXTRACTOR_RUN_DASHBOARD_FIX_PLAN.md)
(Phase 2 — prevent recurrence). v1.26.0 made stuck runs self-heal; this stops
them stacking up in the first place.

### Added
- **Dedupe guard on `Supcomp_Extractor::run()`.** A site that already has a
  *live* run in flight is now skipped instead of having a duplicate attempt
  queued on top of it — so "Run now" (or a scheduled fire landing while the
  previous one is still going) can no longer pile up the 5–12 attempts that
  made the dashboard unreadable. To make sure only genuinely live runs block a
  re-trigger, `run()` first reaps dead orphans (the liveness-guarded reaper
  spares anything with a queued Action Scheduler action), so a crashed run
  never wedges a site out of being re-run. `run()` now returns
  `skipped_in_flight`, surfaced in the Extractor Sites admin notice ("N sites
  already had a run in flight and were skipped" / "No new run queued — already
  in flight").

### Changed
- Re-clicking **Run now** on an in-flight site is now a safe no-op (deduped)
  rather than queuing another attempt. `INSTRUCTIONS.md` updated to match.

---

## [1.26.0] — 2026-06-14

Extractor run-dashboard fix, part 1 of the
[`EXTRACTOR_RUN_DASHBOARD_FIX_PLAN.md`](EXTRACTOR_RUN_DASHBOARD_FIX_PLAN.md)
(Phase 1 — self-healing). Background: several Extractor Sites were stuck
showing **"in flight"** with 5–12 "attempt(s)" even though Action Scheduler's
queue was healthy (jobs completing, ~nothing pending/failed). Root cause: an
extractor attempt's `extract_runs` row was only ever closed on the worker's
happy path — a hard PHP fatal (host timeout / out-of-memory) mid-run left the
row `running`/`pending` forever, there was no reaper to clean it up, and every
re-trigger created a fresh attempt, so orphans piled up. This release makes the
dashboard self-healing.

### Added
- **Stale-run reaper (`Supcomp_Extractor_Reaper`).** Fails any open extractor
  attempt that is both (a) older than a configurable threshold and (b) has no
  live Action Scheduler page action still queued for it. The live-action check
  is what distinguishes a *dead* chain (orphan → reap) from a *slow-but-live*
  one (still paginating → leave alone), so a long legitimate run is never
  killed no matter how long it takes. Runs on two triggers: an hourly recurring
  Action Scheduler action, and a throttled lazy sweep whenever the Extractor
  Sites screen is loaded (so the dashboard self-heals on view). When the reaper
  closes an attempt it also fails the orphaned `import_run` (matched via
  `export_run_id`) and drops the generic-handler URL transient.
- **New Settings option: _Extractor: stale-run timeout (minutes)_** (default
  **30**, range 5–1440). Controls the reaper threshold. Lives next to the
  staleness thresholds on the Settings page.
- **"Clear stuck runs now" action on the Database Cleanup screen.** Shows the
  current open-attempt count and, on confirm, reaps every orphaned (dead)
  attempt immediately regardless of age. In-flight chains with a live queued
  job are still spared. Marks attempts failed; deletes no offers.

### Fixed
- **Extractor attempts can no longer get stuck "in flight".** `execute_page()`
  is now wrapped in `try/catch/finally`: any uncaught error, or any code path
  that exits without handing off to a follow-on page or finalizing, now fails
  the attempt (closing its `import_run`) instead of orphaning the row. The
  reaper backstops the one case `finally` can't cover — a hard process kill.

---

## [1.25.0] — 2026-06-13

### Added
- **Price-direction indicator on the compare table.** To the right of each
  merchant's current price on the per-canonical detail view, a small coloured
  arrow + % change shows which way that merchant's price last moved — **green
  ▼** for a drop, **red ▲** for a rise (inverted from stock-market convention
  because, for a buyer, a price drop is the good outcome). The arrow shape
  carries the meaning so the signal still reads without colour, and a
  screen-reader-only label ("price down 4%") backs the glyph. The visible cell
  shows only the arrow and the percentage — no dates or narrative text.
  - **"Last move" semantics with a drop-off window.** The indicator reflects
    an offer's single most recent effective-price change, shown only when that
    change happened within an operator-set number of days. Prices that have
    been flat longer than the window — or have no recorded history — show
    nothing.
  - **New Settings option: _Price-direction indicator (days)_** (default
    **30**, editable, `0` disables the feature entirely). Lives next to the
    staleness thresholds on the Settings page.

### Changed
- **`price_history` now snapshots the effective (current) price on every logged
  change.** Two columns were added — `old_current_price` / `new_current_price`
  — and a `current_price` change now independently triggers a history row (it
  previously keyed on regular/sale/stock only). This is what lets the public
  site render a price-direction signal without re-deriving past prices.
  Schema version bumped to `10`; the change applies automatically via the
  `dbDelta` upgrade path on the next admin page load. Rows written before this
  release have NULL current-price columns and are skipped by the indicator —
  it lights up as fresh history accrues.

### Notes
- Pre-launch caveat: like any history-based signal, the indicator only appears
  once an offer's price has actually moved at least once since this release.
  See `ADD_CHARTS.md` (Phase 1) for the rationale and the relationship to the
  deferred full price-trend chart.

---

## [1.24.2] — 2026-06-11

### Changed
- **Desktop stat dashboard now renders as horizontal bands instead of
  squares.** The desktop stat tiles previously used a square (`aspect-ratio:
  1 / 1`) layout with the label stacked above the value. They now match the
  mobile render — a horizontal rectangle with the label on the left and the
  big number on the right — but stay side-by-side across the row rather than
  stacking. CSS-only change (`frontend.css`); the mobile (≤720px) render is
  unchanged.

---

## [1.24.1] — 2026-06-08

### Fixed
- **Comparison view now scrolls to the top on navigation.** Clicking a
  **Compare** link or **← Back to all products** swaps the table via hash
  routing, which previously left the visitor's scroll position unchanged —
  stranding them in the middle of a freshly-rendered (often shorter) view. The
  frontend now scrolls the app container to the top of the viewport on view
  navigation (and on browser back/forward). Hooked to the `hashchange` event
  only, so search/filter/sort re-renders deliberately stay where the visitor is
  scrolled. Instant jump (like a `#top` anchor); targets the `#supcomp-app`
  container rather than absolute top, so page chrome above an embedded shortcode
  isn't scrolled past. No payload or PHP changes (`frontend.js` only).

---

## [1.24.0] — 2026-06-08

### Added
- **Stats dashboard above the comparison table.** Three at-a-glance stat
  boxes now sit above the search/filter controls on both the list view and
  each product's detail view: **Products** (distinct canonical products),
  **Offers** (active offers), and **Merchants** (merchants with active
  offers). The numbers are reactive — they recompute from the currently
  matching offer set as the visitor searches and filters, so they always
  describe what's on screen. On the detail view the boxes scope to the one
  canonical being viewed (Products reads 1 while any offer matches, 0 once
  filters empty the table). Square boxes sit side-by-side on desktop and
  stack into compact full-width bands on mobile. Implemented entirely in the
  existing no-build frontend (`frontend.js` / `frontend.css`); labels added
  to the shortcode i18n strings. No new payload fields — values derive from
  the data the frontend already loads.

---

## [1.23.0] — 2026-05-30

### Added
- **Suppression list — rejections now survive Cleanup.** New
  `supcomp_offer_suppressions` table plus a **Supplement Compare → Suppression
  List** admin screen. When a *rejected* offer is hard-deleted (Cleanup →
  "Rejected offers", or a single-row delete of a rejected offer), its natural
  key `(merchant_id, source_product_id, source_variant_id)` is recorded. The
  importer (`Supcomp_CSV_Importer::ingest_rows_into_run`) consults the list
  before inserting and skips suppressed products entirely — no raw snapshot, no
  normalized row — so a re-extracted product can no longer walk back into the
  Pending Queue. The screen is view + lift: lifting a suppression lets the
  product return as `pending` on the next import.
- **Import runs now report a "Suppressed" count.** New `rows_suppressed` column
  on `import_runs`, surfaced on the Import screen list and run detail.

### Changed
- Scope is deliberately narrow: only operator-**rejected** offers auto-suppress
  on hard-delete. `dead` offers (absent from the merchant, aged out by the
  stale detector — never an operator judgment) are not suppressed and may
  legitimately return as pending.

### Fixed
- **Closed the rejection-permanence gap.** `INSTRUCTIONS.md` promised
  "re-imports do NOT revive a rejected offer," but that only held while the
  rejected row survived. Running Cleanup hard-deleted the row and its
  natural-key memory, so the next extractor run re-inserted the product as
  pending. The suppression list makes the documented promise true in all paths.

### Notes
- Schema bumps `SCHEMA_VERSION` 8 → 9; applied automatically via the existing
  idempotent `dbDelta` / `maybe_upgrade()` path on the next admin load.

---

## [1.22.2] — 2026-05-29

### Security
- **CSV imports are bounded and content-checked.** Both CSV readers
  (`Supcomp_CSV_Validator`, `Supcomp_Canonical_CSV_Importer`) now abort above
  a configurable row cap (`MAX_DATA_ROWS = 100000`, filter
  `supcomp_csv_max_rows`) to bound a memory-exhaustion DoS, and reject an
  upload whose real content sniffs as HTML / PHP / script / executable via
  `Supcomp_CSV_Validator::reject_unsafe_upload()` (lenient — all text/CSV
  variants pass; only genuinely dangerous content is blocked).
- **Admin list-screen return URLs no longer reflect arbitrary query keys.**
  `current_url()` on the pending-queue and active-offers screens now preserves
  only an allowlist of known filter/sort/pagination params instead of echoing
  every `$_GET` key, removing the query-key-pollution vector (output was
  already escaped, so this is hardening, not a live fix).

### Changed
- **`X-Forwarded-For` trust is now filterable.** `Supcomp_Redirect::client_ip()`
  still defaults to trusting the leftmost `X-Forwarded-For` (correct for sites
  behind a reverse proxy / CDN), but operators not behind a trusted proxy can
  return `false` from the new `supcomp_trust_forwarded_for` filter to use
  `REMOTE_ADDR` and defeat throttle-evasion via a forged header. The IP is only
  salted-hashed for bot/dedup detection; it never reaches a query or output.
- **Schema-upgrade check skipped on anonymous front-end requests.**
  `Supcomp_Installer::maybe_upgrade()` now runs only in `is_admin()` or cron
  context (the operator updates the plugin from wp-admin, so upgrades still
  fire immediately on update), removing a per-request option read from the
  public hot path.

### Documentation
- `INSTRUCTIONS.md`: note the new CSV row cap / content rejection, the affiliate
  template scheme rule, and a pre-release dependency-check step for the bundled
  Action Scheduler (3.9.3).

---

## [1.22.1] — 2026-05-29

### Security
- **JSON-LD can no longer be broken out of with `</script>`.** The schema.org
  Product/AggregateOffer block on canonical pages is emitted inline in
  `wp_head`; it now encodes with `JSON_HEX_TAG` (in addition to
  `JSON_UNESCAPED_SLASHES`), so a literal `</script>` in operator-authored
  `display_name` / `seo_content` is escaped as a unicode sequence and cannot
  close the `<script>` element and inject markup on the public page. Closes a
  stored-XSS-via-admin-content vector (defense-in-depth; the field is
  operator-authored today).
- **Affiliate URL templates are scheme-validated on save.**
  `Supcomp_Affiliate_URL_Template::validate()` now requires the literal text
  before the first `{placeholder}` to begin with `http://` or `https://`
  (templates that start with the `{product_url}` placeholder are still allowed,
  since that placeholder supplies the scheme). This rejects `javascript:`,
  `data:`, and protocol-relative `//host` templates at authoring time, on top
  of the v1.22.0 redirect-sink `esc_url_raw()` guard.

---

## [1.22.0] — 2026-05-29

### Security
- **Extractor SSRF hardening (was: unauthenticated server-side request
  forgery via configured/merchant URLs).** Every outbound fetch now passes
  through a single guard, `Supcomp_Extractor_Http::is_safe_url()`, which
  rejects non-`http(s)` schemes and any host that resolves to a private,
  loopback, reserved, or link-local address — closing access to the cloud
  metadata endpoint (`169.254.169.254`) and internal services. The fetch
  also switched from `wp_remote_get()` to `wp_safe_remote_get()`
  (`reject_unsafe_urls`, which validates redirect targets too) and added a
  `limit_response_size` cap (32 MB, filterable via
  `supcomp_extractor_max_response_bytes`) to bound a memory-exhaustion DoS
  from a hostile endpoint. Because the guard sits at the single chokepoint
  in `get()`, it covers the initial sitemap fetch, recursive sitemap
  `<loc>` fetches, and product-page fetches alike.
  - *Note:* if you point the extractor at a LAN/localhost test store, fetches
    now fail by design with "Host resolves to a non-public address." See
    `INSTRUCTIONS.md`.
- **Recursive sitemap parsing constrained.** `parse_sitemap()` now caps
  sitemap-index recursion depth (`SITEMAP_MAX_DEPTH = 2`) and only follows a
  child sitemap `<loc>` on the same host (or a subdomain) as the configured
  site, so a merchant sitemap can no longer steer the server to an arbitrary
  third-party host or amplify into an unbounded fetch loop. Sitemap XML is
  parsed with `LIBXML_NONET` to block external-entity network fetches.
- **Click-out redirect destination is scheme-validated.** `/out/{offer_id}`
  now runs the resolved destination through
  `esc_url_raw( $url, array( 'http', 'https' ) )` before `wp_redirect()`; a
  non-http(s) or malformed destination yields a 410 instead of a hostile
  `Location` header. Source product/variant URLs are additionally
  scheme-sanitized on CSV/extractor ingest in `Supcomp_Offers_Repo`.

---

## [1.21.1] — 2026-05-28

### Added
### Changed
### Deprecated
### Removed
### Fixed
- **Frontend search field — focus no longer drops after one keystroke.**
  `restoreInputFocus()` had been left as a no-op (comment-only body), so each
  keystroke triggered a full `innerHTML` rerender that destroyed the focused
  `<input>` and left the user unable to type a second character. The handler
  now captures the focused field's identity and caret position before the
  rerender and reapplies both after, so typing into the Search box (and any
  future re-rendered control) works continuously on desktop and mobile.
- **Frontend search field — no longer renders ~12em tall on mobile.** The
  desktop `.supcomp-search` rule sets `flex: 1 1 12em` so the field grows to
  fill horizontal space; on mobile the `.supcomp-filters` container switches
  to `flex-direction: column`, which made the `12em` basis apply to *height*
  and produced a giant empty box (see `screenshots/search-not-working.jpeg`).
  Mobile rule now resets the input to natural height (`flex: 0 0 auto`) and
  lets it span the column width via `width: 100%; box-sizing: border-box`.
### Security

---

## [1.21.0] — 2026-05-26

### Added
- **Wix as a selectable Merchant Platform / extractor platform hint.** `wix`
  now appears in the Merchants "Platform" field and the Extractor Sites
  "Platform hint" dropdown. Pinning `wix` runs the existing generic JSON-LD
  engine but skips the Shopify/Woo probes and labels the run and its offers
  `source = wix`. Mechanically a Wix store is a generic JSON-LD site, so
  `auto` already discovers Wix sites via its generic step (labeling them
  `generic`, since auto can't prove a site is Wix); the `wix` pin is for when
  the operator already knows the platform. Adapted from the tested
  `aggregate_products-add-wix.py`.

### Changed
- **Generic JSON-LD handler hardened for Wix-style output (benefits all
  generic/`auto` sites).** Two always-on robustness improvements ported from
  the Python extractor:
  - **Case-insensitive JSON-LD key lookups.** Wix emits non-standard
    capitalization (`Offers`, `Availability`, `Seller`, …). Offer field reads
    (`offers`, `price`/`lowPrice`, `priceCurrency`, `availability`, `sku`) now
    match keys regardless of case. Strictly a superset of the prior exact-match
    behavior, so it cannot change parsing of spec-compliant sites.
  - **Store-name fallback.** When the homepage returns a generic default
    (`My Site`, a stock WordPress title, blank), the handler now mines the
    first product page's JSON-LD for an `offer.seller.name` / `brand.name`
    instead of leaving the store name empty. Only fires when the homepage name
    is generic, so normal sites pay no extra request.
- `wix` added to the merchant-platform, extractor-platform-hint, and CSV
  `source` allow-lists.

### Deprecated
### Removed
### Fixed
### Security

---

## [1.20.0] — 2026-05-25

### Added
- **Canonical edit screen re-surfaces "Pinned strength (optional)" and "Pinned servings/container (optional)" fields.** Hidden since v1.1.1, these schema columns kept being honored by the public JSON export and the import-time canonical override even though no admin UI exposed them. They are now editable, with **blank writing `NULL`** so an operator can clear a stale pin. The default and recommended state is blank (the canonical groups every brand-strength of the ingredient and the table shows each offer's own strength).
### Changed
### Deprecated
### Removed
### Fixed
- **Fixed a stale pinned canonical strength leaking onto the public site as a phantom product strength.** A canonical row carrying a `strength_per_serving` value (from a pre-v1.1.1 edit or a CSV import) was exported by `class-json-exporter.php` as the product's displayed strength even when every active offer underneath had a different strength — e.g. a product shown as "100mg" while its only offers were 5mg and 10mg. The value was invisible in the admin (the form had dropped the field) and un-clearable (the save path fell back to the stored value for any field the form didn't submit), so the operator had no way to remove it without database access. Re-surfacing the field (see Added) makes such pins visible and clearable; clear the field and regenerate the public JSON to drop the phantom strength.
### Security

---

## [1.19.1] — 2026-05-24

### Changed
- **Mobile layout switched from stacked cards to a 2-row CSS Grid** so the sortable column headers stay tappable. The 1.19.0 stacked-card layout visually hid `<thead>`, which broke click-to-sort on phones. The replacement keeps `<thead>` visible: at ≤720px each row becomes a 2-row CSS Grid where row A holds the sortable comparison columns under their `<th>` headers, and row B holds coupon code | coupon details | Buy underneath. Visitors can still re-sort by tapping a column header (Price, Cost / mg, etc.), the Buy button stays reachable without horizontal scrolling, and the desktop layout is unchanged.
  - The detail table now gets a `supcomp-cols-4` or `supcomp-cols-5` class from `frontend.js` so the grid sizes correctly to whichever compare view (cost-per-active vs cost-per-serving) is active.
  - Row-B `<th>` cells (Coupon code, Coupon details, Buy) are hidden on mobile — they aren't sortable and the values below them are self-explanatory.
  - The list view becomes a 3-column grid (product, lowest cost, merchants) with the Compare button wrapping to a full-width centred CTA on row 2.
  - Removed the 1.19.0 `data-label` attribute injection and visually-hidden `<thead>` styling; neither is needed in the new layout.

---

## [1.19.0] — 2026-05-24

### Changed
- **Mobile comparison tables now render as stacked cards** instead of horizontally-scrolling tables. At viewport widths ≤720px both the list view and the per-canonical detail view collapse each `<tr>` into a card with the merchant/product name as the title, the data fields shown as label:value rows, and the primary action (Compare on list view, Buy Now on detail view) rendered as an edge-to-edge tappable target at the bottom of the card. Nothing scrolls sideways and the Buy button is always reachable without horizontal panning.
  - Implementation is CSS-only at the breakpoint; desktop layout is unchanged. Column labels are injected via `td::before { content: attr(data-label); }` from `data-label` attributes added to each data cell in `frontend.js`. The `<thead>` is visually hidden but kept in the accessibility tree.
  - Action cells carry a `supcomp-cell-actions` class so the Compare link and Buy Now button can span the card width and centre their child for thumb-friendly tap targets.
  - Affects both `[supplement_compare]`-rendered tables and the PHP-rendered `/compare/{slug}/` SEO landing page (which embeds the same shortcode).
  - *Superseded by 1.19.1 — the visually-hidden `<thead>` removed click-to-sort on phones.*

---

## [1.18.0] — 2026-05-24

### Added
- **New Settings field: "Product subhead."** Two independent checkboxes let the operator hide the small grey meta-line of text on the public site.
  - **Detail page subhead** (`supcomp_subhead_detail_enabled`, default `true`) controls the `ingredient · category · form · active unit` line that renders under the product title on the canonical detail view. Applies to both the JS-rendered shortcode detail view (`#/canonical/{slug}`) and the PHP-rendered `/compare/{slug}/` SEO landing page, so the operator only flips one switch.
  - **List row subhead** (`supcomp_subhead_list_enabled`, default `true`) controls the `ingredient · category` line that renders under each product name in the main (list) table.
  - Wired through a new nested `supcompFrontend.subheads` object so booleans survive `wp_localize_script` unchanged (top-level scalar booleans get string-coerced — see 1.16.1).

---

## [1.17.0] — 2026-05-24

### Added
- **New Settings field: "List view filter controls."** Three independent checkboxes let the operator hide the search input, the "All forms" dropdown, and the "All ingredients" dropdown from the main (list) table's filter bar.
  - New options `supcomp_filter_search_enabled`, `supcomp_filter_form_enabled`, `supcomp_filter_ingredient_enabled` (all boolean, default `true` — preserves prior behavior on upgrade).
  - Wired through the existing nested `supcompFrontend.filters` object so they survive `wp_localize_script` as real booleans (top-level scalar booleans get string-coerced — see 1.16.1).
  - The detail (per-canonical) view is unaffected; these controls only exist on the list view.
  - Shortcode pre-filter attributes (`[supplement_compare ingredient="L-Theanine"]`) still apply even when the matching control is hidden — the state survives, the visitor just can't change it from the bar.

---

## [1.16.1] — 2026-05-24

### Fixed
- **Unchecking "Multi compare-table view" in Settings still left the radio toggle visible above the detail table.** Cause: `wp_localize_script` coerces top-level scalar values to strings before JSON-encoding them — PHP `(bool) true` arrives in JavaScript as the string `"1"`, `(bool) false` as `""`. The frontend's `config.multiCompareViewEnabled !== false` test was therefore always true (neither string strict-equals the boolean `false`). Fix: use a truthy check (`!!config.multiCompareViewEnabled`) in `frontend.js`, which correctly resolves `""` → false and `"1"` → true. Nested array values (like `filters.inStockOnly`) are unaffected by this quirk because `wp_localize_script` only string-coerces top-level scalars; the rest goes through `wp_json_encode` with types preserved.

---

## [1.16.0] — 2026-05-24

### Added
- **New Settings checkbox: "Multi compare-table view."** Controls whether the per-canonical detail view exposes the `Show: Cost / Serving | Cost / Active Unit` radio toggle to visitors. New option `supcomp_multi_compare_view_enabled` (boolean, default `true` — preserves prior behavior on upgrade).
  - **Checked (default):** the toggle renders above the detail table and visitors can flip between the two column sets. The **Default compare-table view** setting selects which view loads first.
  - **Unchecked:** the toggle is hidden and visitors only ever see the view selected in **Default compare-table view** — locking the site to a single column set. Useful when one comparison axis (e.g. cost per active unit) is the only one that matters for the operator's audience and the alternate view would be noise.
  - Wired through `wp_localize_script` as `supcompFrontend.multiCompareViewEnabled`; the frontend hides the radio group and ignores any stray `data-role="detail-view"` change events when the flag is `false`.

---

## [1.15.0] — 2026-05-24

### Changed
- **Total active / container is now the primary operator input, with the derivation direction flipped.** Previously the offer-detail form treated **Active mass / serving** as the canonical input and computed **Total active / container** as `strength × servings`. This meant that any offer the operator hadn't pinned a Servings / container value on showed nothing in the public comparison table's Total active and Cost / active unit columns. The Woo variations fix in v1.14.1/.2 made the problem visible: variants now arrived correctly, but those whose merchant page didn't surface a serving count rendered as empty cells. v1.15.0 inverts the relationship:
  - New stored column `total_active_per_container DECIMAL(14,4) NULL` on `wp_supcomp_normalized_offers` (schema version 7 → 8; dbDelta adds the column on upgrade, no data migration required).
  - In `Supcomp_Offer_Derivations::compute()`, when `total_active_per_container` is set it becomes authoritative: `total_strength` = total, `active_compound_total` = total × standardization%, `active_compound_per_serving` = active_total ÷ servings (when servings present). `cost_per_active_unit` = price ÷ active_compound_total — works with only Total + price, no servings needed. When `total_active_per_container` is null, the legacy `strength × servings` path runs unchanged so existing offers don't regress.
  - The offer-detail form reorders to put **Total active / container** first (with the unit selector inline), Servings / container second (labeled optional), Active mass / serving third (kept as a legacy/alternative input). The inline JS recalc swaps direction — typing Total + Servings derives Strength; typing Strength + Servings still derives Total as a fallback for the legacy workflow. The save-side `resolve_total_active_input()` persists `total_active_per_container` as a real column and clears `strength_per_serving` when only Total is entered (so the per-serving columns are honestly blank rather than showing a stale value).
- **Extractor behavior is unchanged.** The strength rule continues to extract per-serving mass values from titles (e.g., "20 mg per capsule" → `strength_per_serving = 20`). Auto-imported offers whose merchant page didn't surface a serving count still need the operator to enter Total in the form to populate the comparison table — the system won't guess a per-serving value to be a per-container value. The operator-form change makes that one-field fix safe and effective even when Servings stays blank.

### Notes
- **Migration impact:** None for stored data. Existing offers keep their `strength_per_serving` / `servings_per_container` values and continue to compute `active_compound_total` via the legacy path. The new column starts null on every existing row and only populates when an operator edits the offer with a value in the new field.
- **`csv_columns()` was intentionally NOT extended** with `total_active_per_container`. The extractor pipeline (CSV import, in-plugin extractor, legacy Python script) still doesn't auto-populate it. Operator edits stay sticky across re-imports per the upsert contract documented in `INSTRUCTIONS.md` §2.

---

## [1.14.2] — 2026-05-23

### Fixed
- **Every variant of a strength-typed variable product got the same `strength_per_serving`.** When a Woo variable product varied by dose (e.g. 10 MG / 20 MG / 50 MG), every variant was normalized to the same mg figure — usually the one stated in the parent's description with positive context ("each capsule contains X mg"). Cause: `Supcomp_Normalizer::collect_text()` concatenates `variant_title` + `product_title` + `description` + flattened raw attributes into one blob, then `Supcomp_Strength_Rule` scores all mass matches in the blob and picks the highest-scoring one. A bare "20 MG" in `variant_title` scored 0 (no nearby per-serving language), so it lost to the parent's description match that scored +10 — and since every variant shared the parent's description, every variant got the same wrong mg. Fix: in the normalizer, try the strength rule on `variant_title` alone first; if it returns a mass match, that's authoritative and the full-text scan is skipped. Variants with non-strength `variant_title` (e.g. "Small / Blue") still fall through to the full-text scan unchanged. Only affects offers normalized after the upgrade — existing variants in the DB keep their previously-stored mg until re-normalized (reject + cleanup + re-extract is the simplest re-trigger).

---

## [1.14.1] — 2026-05-23

### Fixed
- **WooCommerce variation extraction silently missing on Woo 8.x+.** The Woo Store API in Woo 8.x dropped the per-product `/products/<id>/variations` endpoint, which now returns 404. The in-plugin Woo handler treated that 404 as "no variations" and fell back to emitting a single parent row with `variation_retrieval_status=fallback_parent_only` — operators saw the parent listed once, with a price range in the raw payload, but the actual variations never made it into the offers table. Fix: try the supported endpoint first (`/products?type=variation&parent=<id>`, which returns full variation objects with prices, attributes, and pre-built `?attribute_pa_*=` permalinks), and fall back to the legacy path for older stores. A strict guard rejects older Woo versions that silently ignore unknown filter params and would return the regular products list instead. Mirrored in the legacy Python script (`extractor/aggregate_products.py`) for parity per CLAUDE.md "same schema, two emitters."

---

## [1.14.0] — 2026-05-23

### Changed
- **Click-to-sort column headers replace the sort dropdown.** Sortable headers on both the list view (Product, Lowest cost / active unit, Merchants) and the per-canonical detail view (Merchant, Total active / Serving size / Servings / Cost / serving / Cost / active unit, Price) now sort on click. The first click on a column applies that column's natural direction — cost and price ascending (cheapest first), total active / servings / merchant count descending (most first), text ascending (A→Z) — and subsequent clicks on the same column toggle direction. The active column shows a small ▲/▼ indicator and gets `aria-sort` set for assistive tech. Headers are keyboard-accessible (Tab to focus, Enter or Space to activate). Empty cells stay at the bottom of the table regardless of direction — flipping ascending → descending doesn't drag nulls to the top.
- **Stand-alone Sort dropdowns removed** from both filter bars. The list-view "Recently updated" sort option is dropped (no visible column to anchor it to); the data is still available in the JSON for any operator-side tooling that needs it. Six now-unused i18n keys (`sortBy`, `sortCostPerActive`, `sortPrice`, `sortTotalActive`, `sortMerchant`, `sortRecency`) are removed.

---

## [1.13.0] — 2026-05-23

### Added
- **Per-filter enable/disable toggles on the Settings page.** A new **Filter checkboxes** fieldset under Settings exposes three boolean options (`supcomp_filter_in_stock_enabled`, `supcomp_filter_third_party_enabled`, `supcomp_filter_coa_enabled`, all default-on) that gate whether the matching checkbox renders on the public comparison filter bar (both list and detail views). Disabling a filter hides its checkbox entirely so the bar stays tidy when the underlying field isn't populated across your dataset (e.g. unticking "COA available only" when no offer has a COA recorded). State is read by the shortcode and forwarded to the frontend via `wp_localize_script` under a new `filters` key.

### Changed
- **Detail-view sort: "Brand" → "Total active."** The per-canonical comparison-table sort dropdown drops the alphabetical-brand option in favor of a numeric **Total active** sort (descending — most active first, nulls last) on the offer's `active_compound_total` field. The new option matches the apples-to-apples framing of the comparison: with brand removed from public columns in earlier releases, sorting by brand offered little to visitors, whereas sorting by total active mass per container surfaces the offers that pack the most compound into a single unit. The list-view sort menu is unchanged. New i18n key `sortTotalActive` replaces `sortBrand`.

---

## [1.12.0] — 2026-05-23

### Added
- **Click-to-copy coupon codes.** The coupon chip on the public comparison table is now a `<button>` instead of a static `<code>`. Clicking (desktop) or tapping (mobile) writes the code to the clipboard and flashes the chip green with **Copied!** for 1.5 seconds before restoring the code. Keyboard-accessible (Tab + Enter / Space). Uses `navigator.clipboard.writeText` on secure contexts; falls back to a hidden-textarea + `document.execCommand('copy')` shim for older browsers or http-only setups. If both paths fail (no permission, no support), the code text gets selected so visitors can copy it manually with Ctrl/Cmd-C. Hover and `:focus-visible` styling tells users the chip is interactive without changing the existing dashed-border aesthetic. Two new i18n keys: `couponCopyHint` ("Click to copy", used as the button's `title` and `aria-label`) and `couponCopied` ("Copied!").

### Notes
- The clipboard write happens on user-initiated click, which satisfies the `clipboard-write` permission requirement on every browser that ships the modern API. No prompt for visitors.
- Visual feedback timer is per-button — clicking multiple chips in quick succession works without interference.

---

## [1.11.3] — 2026-05-23

### Changed
- **Friendlier display label for the `billion_cfu` unit.** Probiotic canonicals now show **B CFU** in compare headers, cells, subtitles, and the list view's "Lowest cost / active unit" column, instead of the raw schema code `billion_cfu` ("Total B CFU", "Cost / B CFU", "5 B CFU"). Implemented as a one-line `UNIT_DISPLAY_OVERRIDES = { billion_cfu: 'B CFU' }` map in `frontend.js`, applied via a new `displayUnit()` helper at every render site. The stored schema value is unchanged — this is presentation-only.
- **Subtitle magnitude/unit spacing.** Single-word units stay compact ("200mg", "5g") to match the existing pattern. Multi-word display labels get a leading space ("5 B CFU") so the magnitude doesn't read as part of the unit string.

---

## [1.11.2] — 2026-05-23

### Changed
- **Compare table headers now name the active unit.** "Total active" → "Total mg" / "Total mcg" / "Total g" / "Total IU" / etc., and "Cost / active unit" → "Cost / mg" / "Cost / mcg" / etc., using the canonical's `strength_unit` (falling back to the ingredient's `default_unit`). Same data, just a clearer header that tells the visitor what unit they're looking at without scanning the row. Two new i18n format keys (`totalUnitColumn` = `Total %s`, `costPerUnitColumn` = `Cost / %s`); the old static keys (`totalActiveColumn`, `costPerActiveColumn`) remain as fallbacks for canonicals with no unit set.

---

## [1.11.1] — 2026-05-23

### Fixed
- **Frontend "critical error" on `/compare` and any page using `[supplement_compare]`.** v1.11.0's shortcode enqueue called `Supcomp_Settings::sanitize_compare_view()` to normalize the default-view option, but `Supcomp_Settings` is loaded only inside `Supcomp_Plugin::load_admin()` — so every non-admin pageview hitting the shortcode died with `Uncaught Error: Class "Supcomp_Settings" not found in .../class-shortcode.php:107`. The whitelist check is now inlined in the shortcode so the frontend has no admin-side dependency.

---

## [1.11.0] — 2026-05-23

### Added
- **Compare-table view-mode toggle.** Two-radio control above the compare table lets visitors flip the column set between two presentations of the same offer rows:
  - **Cost / Serving** — Merchant · Serving size · Servings · Cost / serving · Price · Coupon code · Coupon details · Buy
  - **Cost / Active Unit** — Merchant · Total active · Cost / active unit · Price · Coupon code · Coupon details · Buy

  Same underlying data, same row ordering — just a visibility filter on the columns. Default view is configurable via a new **Default compare-table view** radio on the Settings page (option `supcomp_default_compare_view`, sanitized to the two allowed values, default `cost_per_active_unit`). Toggle state is per-pageload (not persisted across visits); the operator-chosen default is what loads every time.
- **New i18n keys** for the toggle: `viewModeLabel`, `viewCostPerServing`, `viewCostPerActive`.

### Notes
- The existing in-stock / 3PT / COA filters and the sort dropdown were kept where they were — the toggle is additive. If those should go away in favor of the radios alone, that's a separate change.
- Sort still operates on `cost_per_active_unit` by default regardless of view, since the data is computed either way. Adding a `cost_per_serving` sort option (so sort tracks view) is a small follow-up if it turns out to matter in practice.

---

## [1.10.1] — 2026-05-23

### Changed
- **Plugin header — Author and License updated.** `Author` is now `Cornflower` (was `Janney Solutions LLC`) and `License` is now `Proprietary` (was `TBD`). No functional change; both fields surface on the WP Admin Plugins list.

---

## [1.10.0] — 2026-05-23

### Added
- **Merchant coupon details field.** New `coupon_details VARCHAR(255) NULL` column on `merchants` (schema bump `6` → `7`, dbDelta-applied on next page load). Free-form short description of what the coupon does — e.g. "10% off your first order", "15% off, expires Dec 31". Operator inputs it on the merchant add/edit form directly under the **Coupon code** field; sanitized via `sanitize_text_field` and clamped to 255 chars at the repo boundary. Surfaced in the public JSON under `merchant.coupon_details` (null when unset) and rendered in a new **Coupon details** column on the public comparison detail table, immediately after **Coupon code**. New i18n key `couponDetailsColumn`.

### Changed
- **`# Servings` column header → `Servings`.** Drops the leading `#` to reclaim horizontal space on the public comparison detail table. The column still shows servings-per-container counts; only the header label changed. The i18n key remains `numServingsColumn` so any operator override survives the rename.
- **USD prices render as `$12.99` instead of `USD 12.99`.** `formatPrice()` now maps known ISO 4217 codes (USD → $, EUR → €, GBP → £, JPY → ¥) to symbols; unknown codes still render as `CODE 12.99` so multi-currency support isn't silently broken. Applies to the Price column, Cost / serving, Cost / active unit, and the strikethrough "was" price on sale rows.

---

## [1.9.1] — 2026-05-23

### Fixed
- **Merchant edits now refresh the public JSON immediately.** `Supcomp_Merchants_Screen::handle_save()` and `handle_status()` now fire `supcomp_data_changed`, matching the pattern used by every other admin save handler (canonical products, ingredients, offers, deletion). Before this fix, editing a merchant's coupon code, display name, or status left the public `public.json` carrying the old values until an unrelated data-changed event happened to fire. Flagged as a known limitation in the v1.9.0 entry below; the lag is now gone. Note: merchant rows aren't joined into the public payload directly — they're projected into each offer's `merchant.{slug,name,coupon_code}` block — so the refresh works by regenerating the whole payload, same as every other state change.

---

## [1.9.0] — 2026-05-23

### Added
- **Merchant coupon code field.** New `coupon_code VARCHAR(64) NULL` column on `merchants` (schema bump `5` → `6`, dbDelta-applied on next page load). Operator inputs it on the merchant add/edit form between the affiliate URL template and the template tester; sanitized via `sanitize_text_field` and clamped to 64 chars at the repo boundary. Optional — leaving it blank means no code displays for that merchant's offers.
- **Coupon Code column on the public comparison detail table.** Inserted between Price and Buy: `Merchant · Total active · Serving size · # Servings · Cost / serving · Cost / active unit · Price · Coupon Code · Buy`. Codes render as monospace inside a dashed-border chip so they read as "copy this string", not as a price; missing codes render as `—` to keep the column rhythm consistent. New i18n key `couponCodeColumn`.
- **Coupon code surfaced in the public JSON payload** under each offer's `merchant.coupon_code` (null when unset). `Supcomp_Offers_Repo::for_export` joins `m.coupon_code AS merchant_coupon_code`; the exporter passes it through `nullable_str` so the frontend sees `null` rather than `""` for blanks.

### Notes
- The code is per-merchant (one code applies to every offer from that merchant), not per-offer. If an operator runs merchant-wide promo codes — the common affiliate-program case — that's the right shape. Per-offer or time-bounded codes would need a different model and aren't in scope here; raise it as a follow-up if a real merchant program requires them.
- No data migration. Existing merchant rows pick up the new column as `NULL` on dbDelta; offers regenerate into the next public JSON with `coupon_code: null` until the operator edits each merchant.
- `mark_dirty()` does **not** fire on a merchant save right now, so the public JSON keeps the prior (empty) coupon code until the next data-changed event (offer save, import, scheduled regenerate, or the manual "Regenerate now" button). Hooking merchant saves into `supcomp_data_changed` is the right fix if operators end up frustrated by the lag — flagged here rather than built speculatively.

---

## [1.8.0] — 2026-05-23

### Changed
- **Phase F cutover — the architecture brief is now consistent with what the plugin actually does.** PROJECTBRIEF.md §2 (data flow), §4 (row schema), and §8 (build phases) had described the Python script as the sole ingestion path. Six versions of in-plugin extractor work later (v1.3.0 → v1.7.0), that's no longer true — the operator-facing canonical path is WP Admin → Extractor Sites → Run now / scheduled WP-Cron, and Python is the legacy fallback for debugging or unsupported platforms. The brief now reflects that.
  - **§2 (data flow)** — diagram redrawn with two ingestion paths (A: in-plugin extractor, B: legacy Python CSV upload) both landing in the same `Supcomp_CSV_Importer` pipeline. "Key principle" reworded: the importer doesn't care where rows came from; sticky-edit semantics, stale detection, and the curation queue work identically regardless of source.
  - **§4 (row schema)** — title changed from "The CSV contract (Python ↔ WordPress)" to "The row schema (extractor → importer contract)" since the contract is now between two emitters (PHP `Supcomp_Extractor_Offer` value object and Python `Offer` dataclass) and one consumer (the importer). Intro paragraph rewritten to acknowledge both.
  - **§8 (build phases)** — new Phase 11 entry summarizing the v1.3.0 → v1.8.0 in-plugin extractor work with sub-phase breakdown (A foundation → B Shopify → C Woo → D generic JSON-LD → E admin UX → F cutover). v1.5.0 hard-delete + Cleanup also flagged as ridealong from the same band. "Post-1.0 phases" section renamed to "Post-1.x" and expanded with the genuinely-still-open follow-ups (Woo mid-page execution-time cursor, per-site schedule overrides, email alerts, etc.). v2.0.0 explicitly reserved for a future actual breaking change (e.g. retiring the legacy Python path).
- **CLAUDE.md** updated:
  - "Python conventions (extractor)" section retitled "Python conventions (legacy extractor)" with a paragraph explaining when to still touch it (merchant-endpoint debugging, unsupported platforms) and the schema-lockstep requirement.
  - "Environment notes" expanded to document the operator's web-only access constraint (no SSH, no WP-CLI, no host-level cron) — that's the load-bearing constraint that Phase 11 exists to satisfy, and future architectural decisions should respect it. Added the external-pinger guidance for WP-Cron reliability on low-traffic sites.
- **`extractor/README.md`** legacy framing left as it was in v1.3.0 (it already calls itself legacy as of that version) — no rewrite needed.

### Notes
- **Version is 1.8.0, not 2.0.0.** Standard semver post-1.0: MAJOR is for breaking changes; the cutover doesn't break anything (Python extractor still works, CSV upload still works, no external API removed). v2.0.0 is reserved for an actual breaking change — most likely the eventual retirement of the legacy Python path, once enough merchants are covered by in-plugin handlers that nobody's reaching for it.
- This is the planned end of the Phase 11 sub-series. No further extractor work is scheduled until a real operational need surfaces (e.g. the Woo mid-page cursor flagged in v1.4.0's CHANGELOG note if it actually bites in production).

---

## [1.7.0] — 2026-05-23

### Added
- **Extractor Runs history screen** at WP Admin → Supplement Compare → Extractor Runs. One row per (run_id, site) attempt, most recent 100. Columns: attempt id, site label, platform used, status (color-coded badge), started_at, duration (live for in-flight), offer count, trigger source, error excerpt. Filter tabs for all / running / pending / complete / failed / canceled. 24-hour status summary at the top. Click an attempt id to open a detail page with the full error log + sibling-attempts list (the other site attempts that shared the same run_id).
- **Scheduled extractor runs** via WP-Cron. New "Scheduled runs" section at the top of the Extractor Sites screen with frequency picker: off / daily / twice daily / weekly. Saving the schedule reconciles the WP-Cron event (idempotent — runs on every plugins_loaded). When active, the screen shows the next scheduled run time in human-readable form ("in 5 hours") plus the absolute UTC timestamp.
- **`Supcomp_Extractor_Scheduler`** class — single source of truth for the schedule option, hook registration, and reconciliation. `fire()` is the WP-Cron callback; it just delegates to `Supcomp_Extractor::run( [], 'schedule' )` so scheduled and manual runs go through the same orchestrator + worker. WP-Cron caveats called out in the UI: low-traffic sites should add an external pinger (cron-job.org / UptimeRobot hitting `/wp-cron.php` every 5 minutes) for reliable timing.
- **In-flight indicators on the Extractor Sites list.** Sites with currently-pending or running attempts get highlighted (light-blue row background) and the Status column shows "in flight" + the open-attempt count instead of the stale `last_run_status`. Operators see immediately which sites are queued/running vs. truly done.
- **Repo query extensions**: `Supcomp_Extract_Runs_Repo::recent_with_sites()` (joined select for the history screen), `open_attempts_by_site()` (in-flight indicator backing), `counts_by_status()` (24-hour summary).

### Notes
- Phase F (v2.0.0 cutover — PROJECTBRIEF rewrite for in-plugin extractor as canonical, `extractor/` directory archived to legacy README) is the only remaining milestone before v2.0.0.

---

## [1.6.0] — 2026-05-22

### Added
- **In-plugin extractor — generic JSON-LD fallback (Phase D, the third and final platform handler).** Sites that don't expose Shopify or Woo APIs but DO publish schema.org Product JSON-LD in their pages are now scrape-able directly from WP Admin.
  - **`Supcomp_Extractor_Generic`** — port of `try_generic` + helpers (`extractor/aggregate_products.py:797-994`). Two-step model: discover product URLs from one of four sitemap candidates (`/sitemap_products_1.xml`, `/product-sitemap.xml`, `/wp-sitemap-posts-product-1.xml`, `/sitemap.xml`, in order), then fetch each URL and extract every `<script type="application/ld+json">` containing an `@type=Product` node (including descents into `@graph` arrays).
  - **Chunked execution**: each Action Scheduler tick processes 10 product URLs. The full URL list (capped at 500 per attempt) is discovered once on page 1 and persisted in a transient (`supcomp_extract_urls_{attempt_id}`, 6-hour TTL) so follow-on pages slice into it without re-discovering. Transient is cleaned up on attempt complete / failed / canceled.
  - **JSON-LD heterogeneity**: handled. `@type` can be a string or a list of strings; `offers` can be a single Offer, an AggregateOffer with nested `offers[]`, or a bare list. GTIN priority: `gtin13` → `gtin12` → `gtin14` → `gtin8` → `gtin`. `availability` parsed as substring match against `instock` / `outofstock` / `backorder` (case-insensitive, ignoring the schema.org URL prefix).
  - **HTML parsing via `DOMDocument` + `DOMXPath`**. BeautifulSoup permissiveness replaced with `libxml_use_internal_errors(true)`. UTF-8 forced via prepended `<?xml encoding="utf-8"?>` so non-ASCII characters survive. Sitemap parsing uses `SimpleXMLElement` with explicit namespace registration. Both `dom` and `simplexml` extensions are now soft requirements; the handler refuses with a clear operator message if either is missing instead of crashing with "Class DOMDocument not found".
  - **Worker cascade extended**: `auto` now tries Shopify → Woo → generic in order. `generic` as a pinned hint also supported. The detect-and-fetch-first-page logic discovers URLs, fetches store meta (og:site_name → JSON-LD Organization/WebSite name → `<title>` fallback), and ingests the first chunk in one call. Follow-on pages dispatch via `state['platform_used']`.
  - **`dependencies_ok()`** static check on the handler. The worker calls it before attempting generic; if the host doesn't have the `dom` or `simplexml` extension, the cascade skips generic with a soft failure rather than throwing.

### Notes
- Smoke testing this round was limited to fixture-based parser tests (real-world JSON-LD HTML structures: single Offer, AggregateOffer with nested offers, `@graph` wrapping, `@type` as list, no JSON-LD, JSON-LD with no Product). Live-site probing was attempted against major retailers (Best Buy, Target, B&H) but those sites either block automated traffic or have sitemaps too large to probe inside a sensible timeout. The handler will get its real workout when an operator points it at an actual merchant target.
- Three platform handlers are now in place. Phase E (admin UX polish — per-run history screen, scheduling controls, "Run now" per site with progress indicators) is next at v1.7.0. Phase F (PROJECTBRIEF cutover + extractor/ directory archival) lands the v2.0.0 milestone.

---

## [1.5.0] — 2026-05-22

### Added
- **Hard-delete + cleanup tools.** The database accumulated rejected offers, retired ingredients, and dead merchants over time with no way to actually purge them — this release adds two complementary cleanup surfaces.
  - **Per-row "Delete permanently"** links on the Merchants, Ingredients, Canonical Products, and Offer-edit screens. Visible only when the row is in its soft-trash state (offer: `visibility ∈ {rejected, dead}`; merchant: `status=dead`; ingredient/canonical: `status=retired`). Clicking opens a shared confirmation page showing the exact cascade impact (counts of price-history rows, raw CSV snapshots, click-log rows that will be affected) before the operator commits.
  - **Cleanup admin sub-page** at WP Admin → Supplement Compare → Cleanup. Five bulk operations, each showing the current eligible-row count up-front: delete rejected offers, delete dead offers, delete empty dead merchants, delete empty retired canonicals, delete empty retired ingredients ("empty" = no offers/canonicals reference the row). Confirmation dialog before running.
  - **Hybrid cascade model** (operator-chosen): `price_history` and `raw_source_offers` are deleted alongside the offer (per-offer audit trail — useless once the offer is gone). `click_log` is **preserved**: the relevant FK (`offer_id`, `merchant_id`, `canonical_product_id`) is set to `NULL` instead of cascade-delete, so historical click totals stay intact in the dashboard.
  - **State gates enforced in `Supcomp_Deletion_Service`**, not just the UI. Direct calls (e.g. from WP-CLI or REST handlers) get the same refusal. Ingredient deletion specifically refuses while any canonical product still references it — the operator must retire+delete those first so cascades are explicit and visible.
  - **`Supcomp_Deletion_Admin`** shared confirmation screen routes all four entity types through one POST handler. Returns the operator to the source list with `supcomp_notice=deleted_hard` or `delete_refused` (with the service's error message). Global `admin_notices` hook surfaces these uniformly across all admin pages.

### Notes
- v1.5.0 reorders the extractor phase plan: Phase D (generic JSON-LD fallback) shifts to v1.6.0, Phase E to v1.7.0, Phase F cutover to v2.0.0.
- This is hard-delete only — no soft-trash recovery. Operators who reject + immediately purge cannot un-reject. The two-step workflow (soft-trash first → review the cleanup screen counts → purge) is the safety net.

---

## [1.4.0] — 2026-05-22

### Added
- **In-plugin extractor — WooCommerce (Phase C).** WP Admin can now refresh offers from Woo stores in addition to Shopify. Generic JSON-LD fallback remains for Phase D.
  - **`Supcomp_Extractor_Woo`** — port of `try_woocommerce` (`extractor/aggregate_products.py:418-794`). Calls the public `/wp-json/wc/store/v1/products` endpoint at `per_page=100`, inline-fetches `/products/{id}/variations` for variable products, and falls back to a single parent row with `variation_retrieval_status=fallback_parent_only` if the variations endpoint fails. Brand resolution walks `product.brands[0].name`, then attributes-named-`brand` (terms first, then options). Barcode is `global_unique_id` (GTIN/UPC/EAN) only — the legacy `meta_data` path is not on the public Store API.
  - **Price decoding** mirrors `_woo_decimal`: Woo Store API prices arrive as minor-unit integers (e.g. `"3900"` with `currency_minor_unit=2` → `"39.00"`); plugin-customized endpoints sometimes return already-decimal strings, which we detect by the presence of `"."` in the raw value and pass through quantized.
  - **on_sale gating**: `sale_price` is only populated when `on_sale=true` AND the raw sale value differs from `regular_price`. Suppresses stale sale prices that some stores leave on the row after a promotion ends.
  - **Variation URL synthesis** is a careful port of `_woo_variation_url`. Builds `?attribute_<taxonomy>=<value>` query params by matching variation attributes back to parent attribute taxonomies (by id first, then by lowercased name). Uses manual query-string assembly instead of `http_build_query` so duplicate keys serialize correctly (`http_build_query` would emit `attr[0]=...` for duplicates, which WooCommerce's permalink resolver doesn't honor).
  - **Worker cascade**: `Supcomp_Extractor_Worker` now runs page-1 detection across platforms based on `platform_hint`. `auto` tries Shopify first, then Woo. `shopify` / `woocommerce` pins to one. The handler that wins page 1 is recorded in `state['platform_used']` and reused for follow-on pages so the cascade only runs once per attempt. Pagination ceiling is per-platform (Shopify: 250/page × 50 pages; Woo: 100/page × 50 pages).
  - Admin screen description copy refreshed: "Phase C (v1.4.0): Shopify and WooCommerce are supported. Generic JSON-LD lands in Phase D."
  - Smoke-tested against `https://woocommerce.com` (which runs the Store API for their own product catalog): 100 products fetched, all 31 schema fields populated, prices decoded correctly from minor-unit integers, stock status derived correctly. Variation paths are 1:1 ports from working Python; first-run against an operator's real Woo merchant will exercise them in production.

### Notes
- Per-page execution-time risk on shared hosting: when a Woo page contains many variable products, inline variation fetches accumulate. 50 variations × (1s fetch + 0.5s politeness) = 75s, well over typical PHP `max_execution_time = 30s`. AS retries on timeout will re-fetch the page from the start; idempotency is preserved via the natural-key `(merchant_id, source_product_id, source_variant_id)` lookup, so retries update rather than duplicate. If a real merchant trips this, the right fix is a soft-deadline cursor that splits mid-page processing across follow-on AS actions — flagged as a v1.4.x follow-up if it surfaces in practice.

---

## [1.3.0] — 2026-05-21

### Added
- **In-plugin extractor — Shopify scraping live (Phase B).** Operator can now refresh Shopify-store offers from WP Admin without needing local Python, SSH, or WP-CLI. Web-only WordPress install is sufficient. WooCommerce handler lands in Phase C; generic JSON-LD fallback in Phase D.
  - **Vendored Action Scheduler 3.9.3** (GPLv3, github.com/woocommerce/action-scheduler) at `plugin/vendor/action-scheduler/`. AS uses its own version-arbitration shim, so co-existing with WooCommerce or other AS consumers is safe — highest version wins. Adds ~900KB to the plugin zip; AS creates its own tables on first load.
  - **`Supcomp_Extractor_Shopify`** — direct PHP port of `try_shopify` (Python `aggregate_products.py:237-415`). Same pagination ceiling (50 pages × 250 products), same pricing semantics (`compare_at_price > price` = on sale), same stock derivation (available + qty≤0 + policy=continue = backorder), same Default-Title variant collapse.
  - **`Supcomp_Extractor_Worker`** — Action Scheduler callback for the `supcomp_extract_page` hook. One AS action per platform page; the worker chains follow-on pages to itself rather than enqueueing all pages up front. Each action stays small enough to finish inside the host's PHP `max_execution_time` budget. State (page number, accumulated counts, store meta) passes through AS args.
  - **Per-attempt lifecycle**: `extract_runs` rows transition pending → running → complete | failed | canceled. `extract_sites` rows update last_run_at, last_run_status, last_offer_count, last_error after each attempt so the admin screen renders health at a glance.
  - **Importer refactor**: `Supcomp_CSV_Importer::ingest_rows()` decomposed into `begin_run()` + `ingest_rows_into_run()` + `finalize_run()`, plus a `record_batch_counts()` helper. Multi-page imports now produce one `import_run` row across all pages (instead of one per page), and stale detection is correctly deferred to the final page. CSV admin upload path unchanged — it still calls the convenience `ingest_rows()` wrapper which composes the three primitives.
  - **Admin: "Run now" + "Refresh all enabled" buttons** on the Extractor Sites screen. "Run now" queues a single AS action for one site; "Refresh all enabled" fans out one per enabled site. Both return immediately — the actual scraping happens out-of-request via AS's queue runner.
  - **Failure surface**: any site without a linked Merchant fails the attempt immediately with a clear operator-facing error ("link a merchant before re-running") rather than producing offers whose /out/{id} redirect can't fire. Sites whose Shopify probe returns 4xx fail with a "Phase B only supports Shopify; Woo lands in Phase C" message so the operator knows what to expect.

### Notes
- WP-Cron reliability on low-traffic sites: Action Scheduler ticks on every visitor request (and also via WP-Cron). A personal hobby site with hours between visits will see slow run completion. Mitigation: an external heartbeat service (cron-job.org, UptimeRobot) hitting `yoursite.com/wp-cron.php` every 5 minutes keeps the queue moving. This is the standard workaround for web-only WP installs.
- The legacy `extractor/aggregate_products.py` is retained for local-debug use; the new in-plugin path is the canonical one going forward. Phase F (v2.0.0) will document the cutover formally.

---

## [1.2.0] — 2026-05-21

### Added
- **Total active / container** input on the offer edit form (pending queue + active offers). Live bidirectional calculator: fill any two of {active mass per serving, servings per container, total active per container} and the third auto-fills as you type. On save, if only the Total Active is filled along with one other field, the canonical stored fields (`strength_per_serving` / `servings_per_container`) are back-computed before derivations run. The Total Active value itself is never persisted as its own column — it remains the derived `total_strength` per PROJECTBRIEF §6 — but operators can now enter the per-container number when that's what's printed on the label and let the system derive per-serving.

### Changed
- **Strength-extraction regex now scores by context** (`plugin/includes/normalization/rules/class-strength-rule.php`). Previously the rule took the leftmost `(mg|mcg|g|iu)` match in `variant_title + product_title + description + raw_attributes_json`, which caused container-total figures like "12,000mg total per bottle" to land in `strength_per_serving` whenever they preceded the per-serving mention in the text. The cascading effect through `class-offer-derivations.php` made `cost_per_active_unit` roughly `servings`× too low and sorted bogus offers to the top of comparison pages. The new rule looks at a 25-character window around each candidate, +10 for nearby per-serving anchors ("per serving", "per capsule", "each tablet", "1 scoop", …) and −10 for container-total anchors ("total", "per bottle", "net wt", "bottle contains", …). Strongly-negative matches (score ≤ −5) are dropped outright so the operator-facing pending-queue value defaults to blank rather than to a wrong number; positive matches win over neutral ones; ties broken by leftmost. The "billion CFU" and "milligrams"/"micrograms"/"grams" word-form paths are unchanged. No data migration — re-imports do not re-run normalization on existing offers (`class-csv-importer.php:122-124`), so this only affects offers imported after the upgrade or operators who clear and re-process specific rows.
- **Admin offer form label** "Strength / serving" renamed to **"Active mass / serving"**. The DB column (`strength_per_serving`) and POST field name are unchanged — display label only. Rationale: the previous label collided with the supplement-facts term "Serving Size" (which means *capsule count per serving*, not active mass), and the field name now matches the existing derived column `active_compound_per_serving` so operators reading the form see one coherent vocabulary. The public comparison table's "Serving Size" column (frontend i18n) is left unchanged for now — the public-side label is a separate decision and will be revisited in v1.3.0 alongside the CSV column-mapping work.

### Fixed
- **Container-total contamination of `cost_per_active_unit`** (root cause described under "Changed" above). Offers imported under v1.1.x whose `strength_per_serving` was populated from a "total per bottle" mention in the description need to be re-checked in the pending queue / active offers — they will still show the wrong values until an operator opens the row and saves (which reruns derivations off the corrected inputs). To find candidates: filter Active Offers by suspiciously low `cost_per_active_unit` (orders of magnitude below peer offers for the same canonical) or by `total_strength` that is implausibly large relative to typical doses for the ingredient.

---

## [1.1.2] — 2026-05-21

### Fixed
- **Comparison page subtitle no longer shows "0mg".** Legacy canonical rows created before v1.0.2 still carry `strength_per_serving = 0` (the old `NOT NULL DEFAULT 0` schema), and both the JS detail-view subtitle and the `/compare/{slug}/` PHP meta line were rendering that as e.g. "0mg". Both renderers now treat null / empty / zero as "no pinned strength" and surface just the active unit (e.g. "mg") instead. The JS renderer was also rewritten to build the subtitle as a list of bits joined by ` · ` so empty fields don't leave dangling separators. No data migration — the fix is display-only, so old `0` values stay in place for any future operator who wants to backfill them.

---

## [1.1.1] — 2026-05-21

### Removed
- **Canonical product admin screen** no longer surfaces `strength_per_serving` or `servings_per_container`. The two input rows are gone from the edit/add form, the "Strength" and "Active/serving" columns are gone from the list table, and the read-only "Derived fields" row is gone. The schema columns remain (CSV import still accepts them, existing rows keep their values), but the admin screen now treats those values as per-offer concerns — they drive `cost_per_serving` / `cost_per_active_unit` at the offer level.

### Fixed
- `Supcomp_Canonical_Products_Repo::upsert` now preserves the stored `strength_per_serving`, `servings_per_container`, and `standardization_percentage` when editing an existing canonical whose form payload omits them. Without this guard the new strength-less admin form would have zeroed-out `total_strength` and `active_compound_per_serving` on every save for legacy rows that still carry strength values.

---

## [1.1.0] — 2026-05-21

### Changed
- **Canonical product is now ingredient-level by default.** A canonical's `ingredient_form` is also optional (was `NOT NULL DEFAULT 'capsule'`, now `NULL`). When both form and strength are blank on a canonical, that canonical groups every offer for the ingredient — different forms, different brand strengths, different standardization contexts. Operators can still pin a form or a strength on the canonical when they want a tighter comparison concept (e.g. "L-Theanine 200mg Capsules"). Schema bump to `4`; dbDelta relaxes the column on next page load. PROJECTBRIEF.md §3.3, §6 display rules, and CLAUDE.md item 7 updated to match — the prior "within-form comparison only" rule is no longer load-bearing.
- **Comparison table re-columned.** The detail-view table is now: Merchant · Total Active · Serving Size · # Servings · Cost / Serving · Cost / Active Unit · Price · Buy. The Stock column was removed; out-of-stock / unknown offers render `—` in the Buy column instead of a Buy button. New i18n keys: `totalActiveColumn`, `servingSizeColumn`, `numServingsColumn`, `costPerServingColumn`. The JSON exporter already emitted `active_compound_total` and `cost_per_serving`, so no exporter/schema changes were needed on the data side — only frontend rendering.
- **Admin canonical form** gains a top-of-page note explaining the new ingredient-level model and a `— Any form (canonical spans all forms) —` option in the Form select. `derive_display_name` falls back to just the ingredient name when both form and strength are blank.
- **Matcher** gains a 0.55-confidence ingredient-only fallback: when no higher-tier match succeeds and the ingredient is identified, the matcher suggests the ingredient's "unpinned" canonical (the one where both `ingredient_form` and `strength_per_serving` are NULL) if exactly one such canonical exists. This makes the new model work end-to-end with the existing pending-queue workflow.

---

## [1.0.2] — 2026-05-21

### Changed
- Canonical product `strength_per_serving` is now **optional**. The schema column moves from `NOT NULL DEFAULT 0` to `NULL`; the admin form drops the `required` attribute and re-labels the field to explain that brands shipping varying strengths can share one canonical. Per-offer `strength_per_serving` continues to drive the `cost_per_active_unit` math (PROJECTBRIEF.md §6 inputs are at the offer level), so leaving the canonical blank does not break cost-per-mg comparisons. Schema version bumped to `3`; dbDelta will relax the column on next activation / page load.
- When the matcher links an offer to a canonical whose `strength_per_serving` is NULL, the offer's normalizer-derived strength is **kept** instead of being overwritten with NULL. Previously the canonical's strength always won; with optional canonical strength, only an explicit canonical strength overrides the offer.
- Canonical-products list "Strength" column and the offer-form canonical picker label now render `—` / drop the strength fragment when the canonical has no strength set.

### Fixed
- **Editing a canonical product no longer creates a duplicate row.** The save handler previously dropped the `id` before calling `Supcomp_Canonical_Products_Repo::upsert`, and the repo decided insert-vs-update by looking up the row by `slug`. Changing the slug during edit (or any sanitize-time slug normalization mismatch) caused a new row to be inserted while the original was orphaned. The handler now passes `id` through; the repo prefers id-based update when editing and falls back to slug-based upsert only on the new-row / CSV-import path. A new-slug-conflict check returns an operator-facing error instead of silently merging into another row.

---

## [1.0.1] — 2026-05-20

### Added
- `scripts/package-plugin.sh` — produces an installable `supplement-compare-X.Y.Z.zip` at the repo root. Reads version from the plugin header, stages `plugin/` under a `supplement-compare/` top-level directory (matches the WP-expected install path `wp-content/plugins/supplement-compare/`), strips `.gitkeep` / `Zone.Identifier` / `.DS_Store`, and writes the zip. Cross-checks that the header version and `SUPPLEMENT_COMPARE_VERSION` constant agree — refuses to build on mismatch. Filed against PROJECTBRIEF.md §11 which explicitly named the script.

### Changed
- `INSTRUCTIONS.md` §1 install procedure now points at `scripts/package-plugin.sh` instead of the manual one-liner. The one-liner stays as a fallback (operator might not have zip installed) but the script is the documented path.

### Deprecated
### Removed
### Fixed
### Security

---

## [1.0.0] — 2026-05-19

### Added
- **Production milestone — 1.0.0.** All 10 phases from PROJECTBRIEF.md §8 are now implemented.
- `Supcomp_Canonical_Page` (`plugin/includes/public/class-canonical-page.php`) — registers the `^compare/([^/]+)/?$` rewrite rule and renders a per-canonical landing page inside the active theme. Page body: H1 = display_name, optional operator-written SEO content (wp_kses_post on output), schema.org Product + AggregateOffer JSON-LD in `<head>`, and the [supplement_compare canonical=slug] shortcode for the live comparison table. Operator-only "Edit canonical" + indexability-status link when `current_user_can('manage_options')`.
- Indexability rule (PROJECTBRIEF.md §10): a canonical page emits `<meta name="robots" content="noindex,follow">` unless BOTH `seo_indexable=true` AND active offer count ≥ 3 (where "active" excludes offers older than the hide threshold). The page still renders for not-indexable canonicals so the operator can link to it internally.
- `Supcomp_Sitemap` (`plugin/includes/public/class-sitemap.php`) — serves `/supcomp-sitemap.xml`. Lists exactly the canonicals that satisfy the indexability rule, with `<lastmod>` from `canonical_products.updated_at` and `<changefreq>daily</changefreq>`.
- Schema bump: `SCHEMA_VERSION` `'1'` → `'2'` with a new `seo_content LONGTEXT NULL` column on `canonical_products`. `maybe_upgrade()` picks it up via dbDelta on the next page load after a plugin update.
- Canonical Products edit form: a `wp_editor()`-backed "SEO content" field below the SEO indexable checkbox. Helper copy: factual chemistry / composition only, no therapeutic claims (PROJECTBRIEF.md §7 load-bearing). Live indexability preview ("Active offers: N · Indexes: yes/no") with the gating reason and a "View page →" link to the rendered `/compare/{slug}/`.
- `Supcomp_Offers_Repo::count_active_for_canonical($id, $hide_threshold)` and `Supcomp_Offers_Repo::aggregate_for_canonical($id, $hide_threshold)` — the SEO threshold check + the schema.org AggregateOffer's `lowPrice` / `highPrice` / `offerCount` / `availability` numbers.
- `INSTRUCTIONS.md` §15 — editing per-canonical SEO content, indexability rule, schema markup, sitemap consumption.

### Changed
- `class-plugin.php` `boot()` registers init / query_vars / template_redirect / template_include hooks for the new canonical page + sitemap routes.
- `class-activator.php` registers all three rewrite rules (out, compare, sitemap) before `flush_rewrite_rules()`.
- `plugin/supplement-compare.php` requires `Supcomp_Canonical_Products_Repo`, `Supcomp_Ingredients_Repo`, `Supcomp_Canonical_Page`, and `Supcomp_Sitemap` up front (the activator needs them for rewrite registration before plugins_loaded fires).
- `Supcomp_Canonical_Products_Repo::sanitize()` accepts `seo_content` and runs it through `wp_kses_post` to strip JS / tracking / unsafe tags while keeping basic HTML.
- `INSTRUCTIONS.md` Troubleshooting renumbered §15 → §16 to make room for the new SEO section.

### Deprecated
### Removed
### Fixed
### Security
- `seo_content` sanitized via `wp_kses_post` on save AND on output (defense in depth) — operator-written rich text can't smuggle `<script>` even if a future bug skipped the input sanitization.
- Schema.org JSON-LD goes through `wp_json_encode` (well-formed) and the description field is `wp_strip_all_tags`'d + truncated to 500 chars so it can't smuggle markup into the JSON-LD block.

### Production-readiness milestone (PROJECTBRIEF.md §16)
All 10 phases complete and demonstrable at the code level. The §16 "Definition of done" checklist:
- ✅ All 10 phases complete and demonstrable
- ☐ Three real merchants integrated end-to-end (operator workstream)
- ☐ At least 50 canonical products with at least one active offer each (operator workstream)
- ☐ Public frontend renders comparison correctly on desktop and mobile (needs live test)
- ☐ Click tracking confirmed working with real affiliate URLs (needs live test)
- ✅ `README.md`, `INSTRUCTIONS.md`, `CHANGELOG.md`, `PROJECTBRIEF.md`, `OPEN_QUESTIONS.md` all current
- ☐ Plugin installs cleanly from a freshly-built `.zip` on a fresh WordPress install (needs live test)
- ☐ Operator has run a complete cycle (script → CSV → import → curate → publish → click) without developer intervention (needs operator workstream)

After 1.0, version bumps follow stricter SemVer per PROJECTBRIEF.md §11 — breaking changes require MAJOR.

---

## [0.10.0] — 2026-05-19

### Added
- `[supplement_compare]` shortcode (`plugin/includes/public/class-shortcode.php`). Renders a placeholder div; the JS asset fetches the public JSON and hydrates it into an in-memory comparison table. Enqueue is gated on shortcode presence — pages without it don't ship the JS / CSS. Supports `canonical="slug"` (start on a specific canonical's detail) and `ingredient="name"` (pre-filter the list) attributes.
- `plugin/assets/public/frontend.js` — ~400-line vanilla JS app. No build step. Hash routing (`#/` for list, `#/canonical/{slug}` for detail). List view groups offers by canonical and computes lowest_cost_per_active_unit + merchant count from FILTERED offers (so filters interact correctly). Detail view sorts all offers for one canonical. Filters: search, form, ingredient, in-stock-only, third-party-only, COA-only. Sort by cost-per-active-unit (default), price, brand, merchant, recency.
- `plugin/assets/public/frontend.css` — minimal styling, mobile-responsive (filters stack vertically, table scrolls horizontally on narrow screens).
- Affiliate disclosure rendered on every comparison page from the `supcomp_affiliate_disclosure` option. "Data last updated" stamp from the JSON's `generated_at`. Stale offers dim with a "data may be outdated" inline note. Buy Now buttons link to `/out/{id}` with `rel="nofollow sponsored noopener"`.
- `INSTRUCTIONS.md` §14 — placing the shortcode, variants, what renders, cache-busting behavior.

### Changed
- `class-plugin.php` `boot()` calls `Supcomp_Shortcode::register()` so the shortcode is registered on both front-end and back-end requests.
- `plugin/supplement-compare.php` requires the shortcode class up front (it's lightweight and used on every front-end page).
- `INSTRUCTIONS.md` Troubleshooting renumbered from §15 to §16 to make room for the new shortcode section.

### Deprecated
### Removed
### Fixed
### Security
- All user-supplied data (CSV-derived titles, merchant names, ingredient names) is HTML-escaped in the JS before innerHTML write. URL attributes go through a separate escapeAttr() helper.
- Buy Now anchors get `rel="nofollow sponsored noopener"` per Google's affiliate-disclosure expectations.

### Notes
- Alias matching in search isn't shipped — the §9 JSON payload deliberately omits aliases (they're internal). Search currently hits product_title, brand, canonical display_name, ingredient.name. Adding aliases to the payload is a one-line repo + exporter change if it turns out visitors search by alternate names.
- No price-range slider; it's a planned filter from the brief but the basic in-stock + trust + form + ingredient set covers the common case. Easy to add when there's a real signal that visitors filter by price.

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
