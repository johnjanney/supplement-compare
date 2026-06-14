# Open Questions

A running list of decisions deferred, ambiguities to resolve, and questions that don't block current work but will need answers eventually.

**How to use this file:**

- Add new questions as they come up during the build
- Each question gets a status: `open` | `in-progress` | `resolved` | `wontfix`
- Resolved questions stay in the file for a while (history is useful), then get archived to the bottom or removed
- When a question blocks a specific phase, note which phase
- If a question is resolved by a decision recorded elsewhere (PROJECTBRIEF.md, CHANGELOG.md, a Git commit), link to it

**Relationship to other docs:**

- `PROJECTBRIEF.md` — architectural decisions that are *made*
- `CHANGELOG.md` — changes that have *shipped*
- `OPEN_QUESTIONS.md` (this file) — things still being *decided*

---

## Active questions

### Q-001: Initial canonical ingredient list

**Status:** open (de-risked)
**Blocks:** nothing hard; useful for first-real-merchant onboarding
**Raised:** initial brief
**Last touched:** 2026-05-23

What's the starter set of ingredients to seed before the first real merchant import?

The original framing (~80–150 ingredients across nootropics / longevity / sports) was sized to "block Phase 5 normalization." That framing no longer applies: the normalizer + matcher ship without any seed list, and the Phase 6 pending queue lets the operator grow `canonical_ingredients` organically as offers arrive. A fresh install with zero ingredients still produces a usable pending queue — matches just land in the lower-confidence buckets until the ingredient row exists.

Remaining real question: **what's the minimum starter set so the first three real merchants don't generate a wall of unmatched rows in the queue?** Probably 20–40 ingredients picked from whatever those three merchants actually sell, rather than a comprehensive vertical map. Defer until Q-002 picks the merchants.

**Notes:**
- Cross-category ingredients (taurine, glycine) assign to a primary category; surface via aliases
- The list grows from merchant overlap, not from an upfront catalog

---

### Q-002: First three target merchants

**Status:** open
**Blocks:** v1.0 §16 production-readiness checklist ("three real merchants integrated end-to-end", "operator has run a complete cycle")
**Last touched:** 2026-05-23

Which three merchants are integrated first to validate the full pipeline?

**Suggested in brief:** Nootropics Depot, Pure Nootropics, one longevity-focused merchant (DoNotAge or Renue by Science).

Now that the in-plugin extractor covers Shopify, WooCommerce, and generic JSON-LD (v1.3 → v1.6), platform coverage is no longer the bottleneck. The real questions are operator-side:

- Affiliate relationships in place, or pending?
- Which platform does each merchant run? (determines whether the in-plugin handler covers them or the legacy Python path is needed)
- Any affiliate-program terms that constrain display (price freshness windows, required disclosure copy, etc.)?

This is now the highest-leverage open question — it unblocks the §16 checklist items still ☐'d in the v1.0.0 CHANGELOG entry, and it directly informs Q-001 and Q-004.

---

### Q-004: Staleness thresholds

**Status:** open
**Blocks:** nothing hard (defaults shipped); affects visible trust signal
**Last touched:** 2026-05-23

Confirm or adjust the shipped staleness defaults:

- **`supcomp_staleness_warn_hours = 48`** → offer is visually downgraded with "data may be outdated"
- **`supcomp_staleness_hide_hours = 168`** (7 days) → offer excluded from the public JSON entirely

Both are operator-editable options on the Settings page, so this is a tuning question, not a code question.

Now that WP-Cron scheduling shipped in v1.7.0 (off / daily / twice-daily / weekly), the cadence is operator-chosen. Once Q-002 picks merchants and the operator settles on a schedule, the right thresholds fall out:

- Daily scheduled runs → 48h / 168h works fine
- Twice-daily runs → could tighten to 24h / 72h for a stronger freshness signal
- Weekly runs → 48h is too aggressive; everything would perpetually downgrade. Bump warn to ~192h, hide to ~336h

Resolve once the operator commits to a real cadence after the first merchant goes live.

---

### Q-005: License for the codebase

**Status:** open
**Blocks:** publishing to a public repo; nothing if kept private

What license does this project use?

Options:
- **Private** (no license, all rights reserved) — fine for now since the repo isn't public
- **GPL-2.0+** — required if distributed as a WordPress plugin via the WordPress.org plugin directory (which we may never do, but worth knowing)
- **MIT** — permissive, common for tooling
- **Proprietary** — explicitly all-rights-reserved with a custom notice

Defer until there's a reason to publish.

---

### Q-006: Woo mid-page execution-time cursor

**Status:** open (watch-only; surfaces only if it bites)
**Raised:** v1.4.0 CHANGELOG note
**Last touched:** 2026-05-23

On shared hosting with `max_execution_time = 30s`, a Woo page containing many variable products can exceed the PHP timeout because inline variation fetches accumulate (50 variations × ~1.5s = ~75s).

Action Scheduler retries on timeout will re-fetch the page from the start, and idempotency is preserved via the natural-key `(merchant_id, source_product_id, source_variant_id)` lookup — so retries update rather than duplicate. The cost is wasted work, not data corruption.

Fix shape if it surfaces: a soft-deadline cursor that splits mid-page processing across follow-on AS actions (analogous to how the generic JSON-LD handler chunks 10 URLs per tick).

Don't build it speculatively. Build it the first time a real merchant trips it in production.

---

### Q-007: Per-site schedule overrides

**Status:** open (post-1.x)
**Raised:** v1.7.0 / v1.8.0 follow-up
**Last touched:** 2026-05-23

The v1.7.0 scheduler is global: one frequency (off / daily / twice-daily / weekly) applies to all enabled sites.

Genuine use cases for per-site overrides:
- A high-churn flash-sale merchant the operator wants to poll twice daily while the rest run daily
- A large catalog where weekly is fine and daily would burn bandwidth/politeness budget
- A merchant whose rate limits or affiliate terms cap polling frequency

Defer until a real merchant creates a real reason. The global schedule covers the common case.

---

### Q-008: Notification on failed runs

**Status:** open (post-1.x)
**Raised:** v1.8.0 follow-up
**Last touched:** 2026-05-23

Scheduled extractor runs surface failures only on the Extractor Runs admin screen. An operator who isn't actively checking the dashboard won't know a merchant has been failing for days until they happen to look.

Options:
- WP email on first-failure-after-success per site (debounced so a persistently-broken site doesn't spam)
- Daily/weekly digest of failed sites
- Webhook (Slack, Discord) for operators who don't watch email

Defer until the operator runs scheduled extraction in real life for long enough to notice the gap.

---

### Q-010: Coupon code details / description text

**Status:** open (next-session feature)
**Raised:** 2026-05-23
**Last touched:** 2026-05-23

Add a free-text "coupon details" field next to the per-merchant `coupon_code` (added in v1.9.1) so the public comparison table can render context like "10% off" or "$5 off purchases of $50 or more" next to the code itself.

**Ambiguities to resolve before coding:**

- **Shape — one field or two?** Single free-text "details" string is simplest (operator types whatever the merchant told them). A structured pair (discount_kind ∈ {percent, fixed, free_shipping, …} + discount_amount + minimum_purchase) would let the frontend localize and sort, but it's a much bigger surface and most affiliate-program copy doesn't map cleanly. Default recommendation: single `coupon_details VARCHAR(160) NULL`, sanitized identically to `coupon_code`.
- **Display layout.** Next to or below the chip? Same cell? Visible on mobile (the detail table already scrolls horizontally on narrow screens — adding more text per cell risks pushing more columns off-screen)?
- **Length cap.** 160 chars covers "$5 off purchases of $50 or more, first-time customers only, expires 12/31" with room to spare. Don't go longer — long marketing copy belongs on the merchant's site, not in the comparison table.
- **JSON payload key.** `merchant.coupon_details` mirrors `merchant.coupon_code` cleanly.
- **No expiry handling.** Don't build a date-bounded auto-expire — operators already update the code field manually when promos end; details would follow the same workflow.

Code touchpoints will be the same set as v1.9.1's coupon code work: installer schema bump, merchants repo sanitize, merchants admin form, `for_export` join, exporter `nullable_str`, frontend table rendering, CSS, i18n key, INSTRUCTIONS §6 docs, CHANGELOG, version bump.

---

### Q-009: Retiring the legacy Python extractor

**Status:** open (gates v2.0.0)
**Raised:** v1.8.0 CHANGELOG note
**Last touched:** 2026-05-23

The legacy `extractor/aggregate_products.py` is still kept around as the fallback for merchant-endpoint debugging and platforms no in-plugin handler covers. v2.0.0 is reserved for an actual breaking change, and the most likely trigger is formally retiring this path.

Trigger conditions to watch for:
- All real merchants the operator integrates can be reached by an in-plugin handler (Shopify / Woo / generic JSON-LD, or a future handler)
- No active reliance on the Python script for at least one full operator cycle
- Operator confirms they don't want to maintain two ingestion paths

Until then, both paths coexist. The §4 row schema contract keeps them in lockstep — change one emitter, change the other.

---

### Q-011: Public price-trend graphs per canonical product

**Status:** Phase 1 (price-direction arrows) **shipped v1.25.0**; full chart
(Phase 2) still deferred — design locked, **build plan in `ADD_CHARTS.md`**.
Decision (2026-06-13): the lighter per-offer arrow + % indicator shipped first
(it degrades gracefully on sparse data); the full interactive chart waits until
real merchants have accumulated ~2–3 months of `price_history` and prices are
shown to actually move. Data is already being collected, so deferring the chart
costs no history.
**Blocks:** nothing; net-new public-facing feature
**Raised:** 2026-06-13
**Last touched:** 2026-06-13

**Decisions locked with operator (2026-06-13):**

1. Public-facing, factual-history framing (no hype copy).
2. No pruning — `price_history` retained indefinitely; revisit only if the
   table grows large in production.
3. Average **and** lowest series. The series selector is **always shown**,
   including single-offer canonicals where avg == lowest (no special-casing).
4. Site-wide admin enable/disable Settings toggle.
5. **Layout: option (b) only** — a single graph with **two reader toggles**:
   timeframe (90 / 30 / 7-day) and series (average / lowest). No admin
   layout-mode setting; the side-by-side / hybrid options (a)/(c) are dropped.

**Implementation decisions locked (2026-06-13):**

- **Default toggle state on load:** 90-day + average price.
- **Charting approach:** hand-rolled inline SVG (no charting library).
- **Watch price:** `current_price` (the effective buyer-facing price the
  comparison table already surfaces) — not `regular_price`.

- **Scope:** compare/detail pages only. **No list-page chart** (decided
  2026-06-13) — a site-wide blended average across heterogeneous ingredients
  would be statistically meaningless and conflict with the apples-to-apples /
  no-hype positioning. Per-canonical compute over that canonical's offers only,
  independent of the table filters.
- **UI placement:** detail view only (`/compare/{slug}/`), inserted **after the
  stats dashboard, before the filter bar** — new order: title → specs → stats →
  **chart** → filters → table. Rationale: groups with the read-only overview
  band; sits above the filters to signal the chart is canonical-level and
  **not** affected by the table's filters (avg/lowest across all offers, which
  is why filtered live-recompute is explicitly out of scope); its own two
  toggles stay visually separate from the filter controls. Full-width block
  (like `.supcomp-stats`), so it stacks on mobile for free. Not shown on the
  list view (chart is per-canonical).

Still-open: payload shape (precompute into static JSON vs. read endpoint) and
empty/sparse-history copy.

Add a public-facing price-history chart to each canonical product page showing
price movement over time. Operator framing captured during scoping:

- **Public-facing**, not admin-only. A buyer trust signal ("here's how this
  price has actually moved"), which fits the "price ledger, not hype"
  positioning **provided** it stays factual — a literal history of observed
  prices, no editorializing, no "lowest ever!" hype framing.
- **Timeframe selector:** 90 / 30 / 7-day views, reader-switchable.
- **Series selector:** "average price" or "lowest price" across the offers
  under that canonical.
- **Admin enable/disable** toggle (site-wide Settings option) so the graph can
  be turned off without a code change.
- **Admin layout mode** (see options below).

**The data is already being collected — this is a "draw it" task, not a
"start recording" task.** `supcomp_price_history` (installer schema) has logged
old/new `regular_price` + `sale_price` + `stock_status` with a `changed_at`
timestamp and `import_run_id` on every import since it shipped. Offers link to
canonicals via `normalized_offers.canonical_product_id`, indexed
`(canonical_product_id, …)`, so per-canonical rollup is a straightforward join.

**The one real subtlety: `price_history` is a change-log, not a snapshot-log.**
Rows are written only when a price *moves*. So you can't `GROUP BY day` and
plot — you reconstruct a step function per offer ("offer held $19.99 from
`changed_at` A until B"), then sample every offer in the canonical on each
date to compute the avg-line and min-line. Standard price-tracker shape
(CamelCamelCamel-style), just more than a trivial query. Two anchoring details:

- The **baseline** (an offer's first observed price) lives on the offer row
  (`current_price` + `first_seen_at`), not in `price_history` — the first
  history row only appears on the first *change*. Reconstruction must seed from
  the offer's first-seen state.
- **History depth = how long the site has been importing.** Early graphs are
  short and get richer over time. A 90-day view on a 3-week-old site shows
  3 weeks.

**Pruning (operator question):** if the public UI never exposes >90 days, the
table will still accumulate change rows indefinitely (it has no pruning today).
Two defensible positions:

- **Don't prune.** Change-log rows are tiny (one row per price *move*, not per
  import), so even years of low-churn history is cheap. Keeping raw history
  preserves the option to add a 1-year or all-time view later, or to answer
  "was this price ever lower?" Recommended default.
- **Prune > ~100 days** (90 + a margin) via a scheduled cleanup, if storage or
  a "we only retain 90 days" privacy/positioning stance matters. Reversible
  decision either way, but pruning is destructive — favor keeping data until
  there's a concrete reason not to.

Recommendation: **don't prune for v1**; revisit only if the table actually grows
large in production. Decouples the storage decision from the UI decision.

**Layout — decided: option (b).** A single graph with two reader-facing
toggles: timeframe (90 / 30 / 7-day) and series (average / lowest). The
side-by-side (a) and hybrid (c) options are dropped. This is the lightest
build and the same chart works across breakpoints — the only responsive work
is making one chart + its two toggle controls reflow on narrow screens.

**Open sub-questions to settle before building:**

- **Default toggle positions.** What does a reader see on first load?
  Recommendation: **90-day + average price** (widest context, the "typical
  price" series). Reader can switch from there. Confirm before coding.

- **Charting approach.** Inline hand-rolled SVG/`<canvas>` sparkline keeps the
  plugin dependency-free and matches the no-bloat tone; a charting library
  (Chart.js etc.) is faster to build but adds front-end weight and a supply-
  chain surface. Lean SVG given the rest of the plugin's posture.
- **Payload shape.** Does the reconstructed series ship in the static JSON
  payload (precomputed at export time — keeps the front end dumb, bloats the
  JSON), or via a small read endpoint queried on demand (keeps JSON lean, adds
  a runtime query path)? Precomputing 3 windows × 2 series per canonical into
  the JSON is probably fine and stays consistent with the static-JSON
  architecture — verify payload-size impact at real catalog scale.
- **Single-offer canonicals.** Decided: avg == lowest, but the series selector
  is **still shown** (no special-casing) — both options just render the same
  line. Keeps the UI consistent across all canonicals.
- **Empty/sparse history.** A canonical with no price moves yet (only a
  baseline) renders a flat line or an "not enough history yet" state. Pick the
  empty-state copy.
- **Sale vs regular.** Plot `current_price` (the buyer-relevant effective
  price), `regular_price`, or both? `current_price` matches what the
  comparison table already surfaces.

**Likely code touchpoints (rough):** a price-history repo method to fetch +
reconstruct per-canonical series; exporter changes (or a new read endpoint);
one new Settings option (enable/disable); frontend chart render + two toggle
controls + CSS + i18n; INSTRUCTIONS §-update; CHANGELOG; version bump (MINOR —
new feature). PROJECTBRIEF gets a note only if the payload/endpoint decision is
architectural.

---

## Resolved questions

### Q-010: Does a rejection survive Cleanup + re-extraction?

**Status:** resolved — **yes, via a suppression list (v1.23.0)**
**Resolved:** 2026-05-30

Surfaced while tracing the offer lifecycle. `INSTRUCTIONS.md` §9 promised
"re-imports do NOT revive a rejected offer," but that only held while the
rejected row physically survived. Cleanup hard-deletes a rejected offer **and**
its natural-key memory, so the next extractor run that re-saw the product
re-inserted it as `pending` — silently breaking the documented promise and the
curation-not-aggregation principle (§1, §7).

Resolution: an `offer_suppressions` table (PROJECTBRIEF §3.9) keyed on the offer
natural key, written automatically when a **rejected** offer is hard-deleted,
and consulted by the importer before insert. Decisions taken with the operator:

- **Trigger:** automatic on hard-delete of a rejected offer — no new
  pending-queue button.
- **Scope:** rejected only. `dead` offers (disappeared from merchant, aged out
  by the stale detector) are not an operator judgment and may legitimately
  return as pending.
- **Granularity:** exact natural key `(merchant_id, source_product_id,
  source_variant_id)`, matching the importer's existing dedup key.
- **Escape hatch:** a view + lift Suppression List admin screen.

### Q-003: Currency policy for v1

**Status:** resolved — **USD only**
**Resolved:** 2026-05-23 (already shipped that way since v0.2.0)

USD is the default for the `supcomp_default_currency` option set on activation. The static JSON payload, the comparison frontend, and the cost-per-active-unit math all assume a single currency; no FX rate handling exists. Merchants whose Store/Shopify endpoints return a different currency code import as-is into the offer row, but the public site renders the operator's configured default currency symbol.

Multi-currency is a deliberate non-goal for the 1.x line. Revisit only if a multi-region expansion becomes a real product goal — at which point it's a Phase-scale change, not a setting flip.

---

## Archived questions

*None yet.*
