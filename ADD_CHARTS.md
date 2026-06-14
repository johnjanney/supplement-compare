# ADD_CHARTS.md — Build Plan: Public Price-History Chart per Canonical Product

**Status:** deferred / ready to build on demand
**Companion question:** OPEN_QUESTIONS.md → Q-011
**Last updated:** 2026-06-13

This is a pick-up-and-build spec. Every design decision is already made (see
§2). When you're ready, this file should be enough to implement the feature
without re-litigating scope. Line numbers below are **approximate anchors as of
2026-06-13** — grep for the named function/class/CSS-class, don't trust the
number.

---

## 1. Why this is deferred (read first)

The feature is on-brand and worth doing — a factual record of observed prices
is the literal embodiment of the "price ledger, not hype" positioning, it
exposes inflated-"sale" pricing, and supplement buyers are repeat purchasers
who genuinely ask "buy now or wait?"

**But its entire value is contingent on accumulated history depth and on prices
actually moving** — neither of which is established yet:

- Q-002 (first three real merchants) is still open; the site isn't live
  end-to-end. A chart built now plots a near-flat line with 2–3 step changes.
  **A sparse chart is worse than no chart** — it signals "new site, little
  data," eroding the trust it was meant to build.
- Niche supplement merchants often hold prices stable for months (unlike the
  volatile electronics market that makes CamelCamelCamel useful). If the
  underlying prices are flat, the chart adds visual weight and zero info.

**The decisive asymmetry:** `supcomp_price_history` is *already recording*
whether or not the chart exists. **Deferring the UI costs zero history.** So the
rational sequence is: get real merchants live → let history accumulate ~2–3
months → look at the real `price_history` table → if prices move meaningfully,
build this; if they're flat, you've saved yourself the plugin's most complex
front-end feature.

### Build trigger — pull this file out when ALL of these are true

- [ ] At least one real merchant has been importing for ~2–3 months (real,
      not seed, data in `supcomp_price_history`).
- [ ] A spot check of `price_history` shows prices actually *move* for a
      meaningful share of canonicals (not a flat ledger).
- [ ] The core compare experience is validated and you want a credibility
      upgrade, not a core fix.

### Interim option (if you want a trust signal before the full chart)

Instead of a chart, render a one-line factual badge per compare page, e.g.
**"Lowest in 90 days: \$X · current: \$Y"**. It degrades gracefully when data is
thin (one number, never an embarrassing flat line), captures much of the "are
they gaming me?" value, and is a fraction of the build. The full chart becomes a
later upgrade. This badge reuses the same reconstruction logic in §4 (just the
min over the window, no series), so it's a clean stepping stone — not throwaway
work.

### Phase 1 SHIPPED (v1.25.0, 2026-06-13): price-direction indicator

A lighter trust signal than either the chart or the badge above shipped first:
a per-offer **price-direction arrow + % change** to the right of each
merchant's current price on the compare table. Green ▼ = price dropped, red ▲ =
price rose (buyer's-eye colours). "Last move" semantics with an operator-set
drop-off window (Settings → _Price-direction indicator (days)_, default 30,
`0` disables). See CHANGELOG [1.25.0] and INSTRUCTIONS §14.

This established the data plumbing the full chart will reuse:
- `price_history` now stores `old_current_price` / `new_current_price` per
  change (effective, sale-aware price) — so the §3 reconstruction no longer has
  to re-derive current_price from regular/sale. **Update §3/§4 accordingly when
  building the chart: the effective-price segments are now read straight from
  the table rather than reconstructed.**
- Schema version is at `10`.
- The per-offer "last move" is a single-row lookup
  (`Supcomp_Price_History_Repo::price_moves_for_offers()`); the chart's
  canonical-level series is the heavier reconstruction still described below.

The full interactive chart (this document's main subject) remains **Phase 2**,
deferred per §1 until real merchants have accumulated history.

---

## 2. Locked design decisions

| # | Decision |
|---|---|
| Audience | **Public-facing**, factual-history framing. No "lowest ever!" hype copy. |
| Scope | **Compare/detail pages only** (`/compare/{slug}/`). **No list-page chart** — a site-wide blended average across different ingredients is statistically meaningless and violates the apples-to-apples (§8) / no-hype positioning. |
| Compute unit | Per canonical, over **that canonical's offers only**. Independent of the table's filters — no live recompute when the reader filters the table. |
| Timeframes | Reader-switchable **90 / 30 / 7-day**. Default **90**. |
| Series | Reader-switchable **average** / **lowest** across the canonical's offers. Default **average**. |
| Single-offer canonicals | avg == lowest; **still show the series selector** (both render the same line). No special-casing. |
| Watch price | **`current_price`** (the effective buyer-facing price the table already shows) — not `regular_price`. |
| Layout | **Option (b):** one graph + two reader toggles (timeframe + series). No side-by-side, no admin layout mode. |
| Charting | **Hand-rolled inline SVG.** No charting library (keeps the plugin dependency-free; no front-end supply-chain surface). |
| Admin control | One site-wide **enable/disable** Settings option. Off → feature not rendered, no JSON bloat. |
| Placement | After the **stats dashboard**, before the **filter bar**. New order: back → title → specs → stats → **chart** → filters → table. Full-width block (like `.supcomp-stats`) → stacks on mobile for free. |
| Retention | **No pruning** for v1. Change-log rows are tiny (one per price *move*, not per import). Revisit only if the table grows large in production. |

---

## 3. The data — what exists, and the one subtlety

The data layer is **already built**. No schema change is required for the chart.

- **`supcomp_price_history`** (`includes/class-installer.php`, ~lines 301–318):
  `offer_id`, `old_regular_price`, `new_regular_price`, `old_sale_price`,
  `new_sale_price`, `old_stock_status`, `new_stock_status`, `import_run_id`,
  `changed_at`. Indexed `(offer_id, changed_at)`.
- Rows are written by the importer only when a price/stock value **changes**:
  `Supcomp_Offers_Repo::diff_for_price_history()` →
  `Supcomp_Price_History_Repo::record_change()`
  (`includes/import/class-csv-importer.php` ~line 188).
- **`supcomp_normalized_offers`** links to canonicals via
  `canonical_product_id` (indexed). The offer row also holds the live
  `current_price`, `regular_price`, `sale_price`, `first_seen_at`,
  `last_synced_at`.

### The subtlety that shapes all the query work

**`price_history` is a change-LOG, not a snapshot-LOG.** A row exists only where
a price *moved*. You therefore cannot `GROUP BY day` and plot. To draw a line
you **reconstruct a step function per offer**, then aggregate across offers:

1. For each offer in the canonical, build its `current_price` timeline:
   - **Seed/baseline:** the offer's *first* state isn't in `price_history` (the
     first history row only appears on the first change). Seed from the offer
     row: price = `current_price` as of `first_seen_at`… **but** note
     `current_price` on the row is the *latest* value, not the first. To get the
     true first price, walk `price_history` for that offer ordered by
     `changed_at ASC` and take the **oldest** row's `old_*` value as the
     starting price; if the offer has **no** history rows, it has held one price
     the whole time → use the current row value flat across the window.
   - Apply each `changed_at` as a step: price holds until the next change.
   - **Watch price = effective price:** model `current_price` (sale price when
     on sale, else regular). The history table stores regular+sale separately;
     reconstruct `current_price` per segment the same way the importer computes
     it (sale_price when present/active, else regular_price). Confirm against
     `update_csv_columns()` so the reconstruction matches how `current_price`
     is derived live.
2. **Sample** every offer's step function at the dates you'll plot (see §5 for
   sampling cadence), giving a price-per-offer matrix over time.
3. **Aggregate** across offers per sample date:
   - average series = mean of offers in-stock-and-priced at that date
   - lowest series = min of the same set
   - Decide how to treat out-of-stock/dead offers in the window (recommended:
     exclude an offer from a date once it goes `dead`/disappears; keep
     `out_of_stock` priced offers or drop them — pick one and document it).

There is already an aggregate helper to model the API after:
`Supcomp_Offers_Repo::aggregate_for_canonical()`
(`includes/db/class-offers-repo.php` ~lines 344–363) computes min/max **now**;
the chart needs the same idea **over time**.

---

## 4. Server-side: reconstruction + payload

### 4a. New repo method (the real work)

Add to `Supcomp_Price_History_Repo`
(`includes/db/class-price-history-repo.php`):

```
series_for_canonical( int $canonical_id, int $window_days ) : array
  → [
      'avg'    => [ ['t' => 'YYYY-MM-DD', 'v' => 19.49], ... ],
      'lowest' => [ ['t' => 'YYYY-MM-DD', 'v' => 17.99], ... ],
      'points' => <int count of underlying price-move events in window>,
    ]
```

- One query to fetch all `price_history` rows for offers under the canonical
  within (and one row immediately *before*) the window — you need the
  pre-window value to know the price on day 0 of the window.
- One query (or reuse) to fetch the offer set + `first_seen_at` for seeding.
- Reconstruction + sampling in PHP (§3). Keep it pure/testable.
- `points` lets the frontend pick the empty/sparse state (see §5).

### 4b. Payload shape — DECIDE THIS at build time (only open implementation Q)

Two options, both viable:

- **(A) Precompute into `public.json`** (recommended default). At export time
  (`Supcomp_JSON_Exporter`, `includes/public/class-json-exporter.php`), attach
  to each canonical: `price_history: { '90': {avg, lowest, points}, '30': {...},
  '7': {...} }`. Keeps the front end dumb, matches the static-JSON architecture,
  no runtime query path. **Cost:** JSON size. Mitigations: series are compact
  (only price-*move* dates, not daily samples); gate the whole block on the
  admin enable toggle; **verify payload size at real catalog scale before
  committing** (e.g. N canonicals × 3 windows × 2 series).
- **(B) On-demand read endpoint.** A REST/admin-ajax route returns
  `series_for_canonical()` when a compare page opens. Keeps `public.json` lean;
  adds a runtime query + a caching concern. Prefer only if (A)'s payload proves
  too heavy.

Recommendation: ship **(A)**; fall back to **(B)** only if measured size hurts.

### 4c. Admin enable/disable option

- Register `supcomp_price_chart_enabled` (bool, default off) alongside the
  existing Settings options. Follow the pattern of an existing frontend toggle
  such as `supcomp_subhead_detail_enabled` for registration, sanitize, and the
  Settings-page field.
- When off: exporter omits the `price_history` block (option A) / endpoint
  returns disabled (option B), and the frontend renders nothing.

---

## 5. Front-end: SVG chart (option b)

All compare-page rendering is **client-side vanilla JS** in
`assets/public/frontend.js` (~924 lines, no framework); data comes from the
static `public.json` fetched on load (~line 92). The detail view renders, in
order: back link (~392) → title (~393) → meta/specs (~420) → **stats**
(`.supcomp-stats`, ~426) → filters (`.supcomp-filters`, ~432) → table (~452).

### Insertion

Between the stats block and the filters block, render a new full-width
container `.supcomp-price-chart` containing:

```
<div class="supcomp-price-chart" [hidden if disabled / no data]>
  <div class="supcomp-chart-controls">
    <!-- timeframe: 90 / 30 / 7  (default 90) -->
    <!-- series:    average / lowest  (default average) -->
  </div>
  <svg class="supcomp-chart-svg" role="img" aria-label="...">...</svg>
  <p class="supcomp-chart-caption">Lowest / Avg current price · last 90 days</p>
</div>
```

### Rendering notes

- Pure SVG: compute min/max of the active series, map to a viewBox, draw a
  `<polyline>` (step or smoothed — step is more honest for a price ledger) plus
  light gridlines + min/max labels. No deps.
- Toggles are local state; switching re-reads the already-loaded
  `price_history[window][series]` and re-renders. No refetch (option A).
- **Filter independence:** the chart reads canonical-level series and does NOT
  recompute when the table filters change. Its placement above the filter bar
  reinforces this — keep the chart's two toggles visually distinct from the
  filter controls so the two clusters aren't confused.
- **Empty/sparse state** (`points` low or 0): render
  *"Not enough price history yet — check back soon"* instead of a misleading
  flat line. Pick a threshold (e.g. `< 2` move events in the window → empty
  state). Finalize the copy at build time.
- **Accessibility:** `role="img"` + `aria-label` summarizing the trend; ensure
  toggles are real `<button role="radio">`/radio inputs like the existing view
  toggle.

### CSS

Add to `assets/public/frontend.css` (~442 lines). Model the container spacing on
`.supcomp-stats` (~lines 30–66). Full-width block; on mobile it stacks above the
table automatically. Ensure the SVG is `width:100%` with a fixed aspect ratio so
it scales down on narrow screens without pushing the table too far below the
fold.

### i18n

Add new strings (toggle labels, caption, empty-state copy) to the `i18n` object
passed via `wp_localize_script()` in
`includes/public/class-shortcode.php` (~lines 106–130).

---

## 6. Touchpoint checklist

- [ ] `includes/db/class-price-history-repo.php` — `series_for_canonical()` +
      reconstruction helpers (the core work).
- [ ] `includes/public/class-json-exporter.php` — attach `price_history` block
      per canonical (option A), gated on the enable option.
- [ ] Settings registration + Settings-page field —
      `supcomp_price_chart_enabled`.
- [ ] `assets/public/frontend.js` — render `.supcomp-price-chart`, SVG draw,
      two toggles, empty state, filter-independence.
- [ ] `assets/public/frontend.css` — chart container + responsive SVG.
- [ ] `includes/public/class-shortcode.php` — new i18n strings.
- [ ] `INSTRUCTIONS.md` — operator section: what the chart is, the enable
      toggle, the "needs accumulated history" caveat.
- [ ] `CHANGELOG.md` — entry under `[Unreleased]`.
- [ ] `PROJECTBRIEF.md` — only if the payload/endpoint choice (4b) is deemed
      architectural; otherwise skip.
- [ ] **Version bump (MINOR — new feature)** in all four lockstep places
      (`supplement-compare.php` header + `SUPPLEMENT_COMPARE_VERSION`,
      `CHANGELOG.md`, `README.md`). Use `scripts/bump-version.sh` if it exists
      by then.

---

## 7. Testing

- **Reconstruction unit tests** (most important — this is the bug-prone part):
  - Offer with zero history rows → flat line at current price across window.
  - Offer with one mid-window change → correct step at `changed_at`.
  - Pre-window change → day-0 of window reflects the value carried in from
    before the window.
  - Sale vs regular → `current_price` reconstruction matches live derivation.
  - Multi-offer canonical → avg and lowest aggregate correctly per sample date.
  - Offer goes `dead`/disappears mid-window → excluded from later dates per the
    documented rule.
- **Single-offer canonical** → avg == lowest, selector still shown, one line.
- **Empty/sparse** → empty-state copy, no flat-line.
- **Admin toggle off** → no chart, no `price_history` block in `public.json`.
- **Payload size** (option A) → measure `public.json` before/after at realistic
  catalog size; confirm acceptable.
- **Mobile** → chart stacks, table still reachable without excessive scroll.

---

## 8. Cross-references

- OPEN_QUESTIONS.md → Q-011 (the scoping conversation + decision trail)
- PROJECTBRIEF.md §6 (cost-per-active-unit math), §8 (apples-to-apples — the
  reason there's no list-page blended chart)
- CLAUDE.md "load-bearing" list: #6 no health claims, factual copy only —
  applies to all chart labels/captions/empty-state copy.
