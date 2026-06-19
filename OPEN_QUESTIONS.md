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

### Q-012: Flagging potential-duplicate orphans (product type changes)

**Status:** watch-only (decided 2026-06-14 — **do not build speculatively**;
design retained below for if it ever bites in production)
**Blocks:** nothing
**Raised:** 2026-06-14
**Last touched:** 2026-06-14

**Decision (2026-06-14):** not building the flag + suppression-category feature.
The original concern — a phantom duplicate on the **public site** from a
simple→variable conversion — is **already fully handled** by existing processes:
the old simple offer stops being emitted the moment the product becomes variable,
so it flips to `stale` on that same import and drops out of public output
automatically; the new variations arrive `pending` and show nothing publicly
until approved. The site goes one-offer → two-approved-offers, never three.

What a flag would have addressed is the smaller, separate matter of **curation
continuity** (the old offer's canonical link / `operator_notes` / approval don't
ride along to the new variation rows, so a couple of fresh `pending` offers get
re-approved). That's *rework*, not a *duplicate* — low frequency, low per-incident
cost — and it doesn't justify a new column + detector + list view + suppression
category in a lean plugin. Same "build it the first time a real merchant trips
it" discipline as Q-006/Q-007/Q-008.

**The one case that could produce a real public duplicate** is *not* the
type-change case: it's the extractor's `fallback_parent_only` path
(`class-extractor-woo.php:213`) — a failed variation fetch emits an empty-variant
parent offer; a later successful run emits the variations; if both got approved
at different times, both could be `active` at once. That's tied to **fetch
failures**, is rare, and is what to actually watch for in production. Revisit
this Q only if that edge (or the curation rework) becomes a recurring annoyance.

**Original analysis retained below for reference.**

When a merchant converts a **simple product to a variable product** (e.g. a
10mg simple product becomes a variable product with 10mg + 20mg variations),
the importer's natural key `(merchant_id, source_product_id, source_variant_id)`
does **not** recognize the change. The old simple offer (empty
`source_variant_id`) and the new variation offers (non-empty `source_variant_id`)
are different rows, so:

- the two variations insert as brand-new `pending` offers, and
- the old simple offer — no longer emitted by the feed — flips to `stale` on
  that same import run (`Supcomp_Stale_Detector::mark_stale`).

Net effect: three DB rows where there should be two. The public site self-corrects
(stale orphan drops out; the variations wait in the pending queue), but the
operator's prior curation on the old offer (canonical link, `operator_notes`,
approval) is **abandoned**, and the orphan silently rots toward `dead` with no
deliberate review. WooCommerce keeps the **same post ID** across the conversion,
so the orphan is reliably identifiable.

**Decided direction (with operator, 2026-06-14):** drop the one-click "merge"
concept (you can't truly unify two natural keys — the feed keeps emitting the
variation key forever, so merge could only mean "copy curation + retire orphan").
Instead, do the lighter, fully-operator-controlled version:

1. **Detect + flag the orphan** with a non-destructive advisory flag (e.g. a
   `rekey_flag` column on `normalized_offers`, importer-written like the existing
   `variation_retrieval_status`). Render a red **"⚠ Potential duplicate"** badge
   wherever the offer appears.
2. **Add a dedicated suppression category.** `Supcomp_Installer::SUPPRESSION_REASONS`
   currently holds exactly one value (`'rejected_cleanup'`). Add
   `'potential_duplicate'` (one-line change) so duplicates filed by the operator
   group **separately from genuine rejects** on the Suppression List screen
   (which today lists flat — add a reason filter/grouping).
3. **Operator flow (no merge):** detector flags orphan → operator sees the badge
   → clicks "Suppress as potential duplicate" → it moves to the suppression list
   under its own category. Nothing auto-changes; the curation gate is preserved.

**Detection signals (score confidence by how many fire):**

- **Signal A (structural, reliable for Woo):** same `(merchant_id,
  source_product_id)` has **both** an empty-variant offer and one or more
  non-empty-variant offers. Timing-independent — works whether the conversion
  and the import land in the same run or weeks apart. A normal always-simple or
  always-variable product never shows both shapes.
- **Signal B (URL, human-verifiable):** same normalized `source_product_url`
  across the mixed-shape offers. The Woo handler sets `source_product_url` to the
  **parent permalink** for both the simple offer and every variation
  (`class-extractor-woo.php:266` and `:374`), and WooCommerce keeps the same slug
  across the conversion — so this is both a detection signal **and** the field
  the operator eyeballs to confirm "same product." (Variation query args live in
  `source_variant_url`, so the product-URL comparison stays clean.)
- Bonus: Signal A/B also catch the extractor's own `fallback_parent_only`
  orphan (`class-extractor-woo.php:213`) — an empty-variant parent offer left
  stale after a later run successfully fetches variations. Same false-duplicate
  shape, also worth flagging. Hence the label "potential **duplicate**," not
  strictly "type change."

**Staleness-visibility wrinkle (must inform the design):** the orphan is
typically created in the **same import run** that inserts the variations, so by
the time the operator looks it has usually already flipped to `stale`. A `stale`
offer matches **neither** the Active Offers screen (`visibility = active`) nor
the Pending Queue (`visibility IN (pending, needs_review)`) — it is currently
**un-surfaced** in any browsable list until it escalates to `dead` (Cleanup
screen). So:

- The advisory flag/badge must be visible **across statuses, including `stale`**,
  not only on Active Offers.
- This likely wants its **own small "Potential Duplicates" list view**, because
  otherwise the flagged orphan vanishes into the same stale black hole. The real
  value of the feature is surfacing stale orphans for a deliberate
  `potential_duplicate` decision rather than letting them silently age to `dead`.
- Note: a stale offer that reappears in a later import is auto-restored straight
  to `active` (`Supcomp_Offers_Repo::update_csv_columns`), **not** back to
  pending — so it never re-enters the curation queue on its own.

**Part 2 — name / permalink changes WITHOUT a type change (advice captured):**

- **Title change — harmless everywhere.** Matching is never by title; the offer
  re-imports as an in-place UPDATE and `product_title` just refreshes. Non-event.
- **Permalink change — depends on the platform's key anchor:**
  - **WooCommerce** (`source_product_id` = post ID, stable): in-place update,
    `source_product_url` refreshes. No duplicate.
  - **Shopify** (`source_product_id` = numeric product id, stable,
    `class-extractor-shopify.php:124`): same — no duplicate.
  - **Generic JSON-LD** (`source_product_id = $sku !== '' ? $sku : $url`,
    `class-extractor-generic.php:360`): if the product carries a **SKU** → stable
    → fine. If it has **no SKU**, the URL *is* the key, so a permalink change
    **creates an orphan + duplicate** (old URL key goes stale, new URL inserts
    fresh).
- **The hard part:** the generic-no-SKU rename case is **not detectable** by
  Signals A or B — both the product_id and the URL change, so the old and new
  offers look unrelated. Only fuzzy matching (title/brand/strength similarity)
  could link them, which is false-positive-prone.
- **Recommendation:** for the operator's actual merchant mix (mostly Woo/Shopify,
  per the extractor merchant map), renames are a **non-issue** — don't build
  anything. The durable fix for the generic hole is **data hygiene** (ensure
  generic sites expose a SKU so the key stops depending on the URL), not a
  detector. Defer any rename-detection feature until a real merchant trips it.

**Likely code touchpoints (when built):** installer schema (`rekey_flag` column +
`potential_duplicate` in `SUPPRESSION_REASONS`, `SCHEMA_VERSION` bump);
`Supcomp_Rekey_Detector` run from `finalize_run()` after `mark_stale`; a
"Potential Duplicates" list view (or grouped section) that surfaces flagged
offers across statuses including `stale`; badge in Active Offers / pending; a
"Suppress as potential duplicate" action; reason filter on the Suppression List
screen; INSTRUCTIONS §-update (new failure mode + how to act on the flag);
CHANGELOG; MINOR version bump. Build the detection + flag + suppression category
first (immediately useful, low risk); the list view is the piece that makes
stale orphans actually visible.

---

### Q-013: Visitor "Report a Problem" feature

**Status:** open (design captured; **build plan in `REPORT_ISSUE.md`**)
**Blocks:** nothing; net-new public-facing trust feature. Lower priority than
Q-002 (real merchants live) — the form is only useful once there are real
merchants and real visitor traffic to file reports.
**Raised:** 2026-06-15
**Last touched:** 2026-06-15

A public `/report` shortcode lets visitors flag a problem with a featured
merchant/listing, feeding an operator triage queue with a deliberate **Suspend
merchant** action.

**Decided (2026-06-15):** build the one-directional **"Report a Problem"**
approach, **not** a two-sided "Merchant Feedback" system. The deciding factor is
**positioning drift against load-bearing rule #3 (no ratings)** — collecting
positive *and* negative sentiment builds the substrate for an internal review
aggregator even if unpublished. A report flow is a trust-and-safety signal
feeding one curation decision ("keep featuring this merchant?"), which is on-
brand. No positive path, no scoring, no aggregation, no public display of
reports. The sock-puppet concern is moot under this scope (nothing published,
no positive path to game). "Feels negative" is a copy problem — frame as
"Report a Problem" / "Which listing?", not "Report Merchant" / "bad actor."

**Open sub-questions to settle before coding (full detail in `REPORT_ISSUE.md`
§3):**

- **Q-A (LOAD-BEARING): what does "Suspend merchant" actually do?** Gates the
  schema. Recommend a `merchants.suspended` flag the exporter filters on
  (reversible, no data loss); confirm blast radius (merchant-wide; a canonical
  can go to zero public offers), reversibility, audit fields, and whether a
  suspended merchant still imports (recommend yes, just hidden).
- **Q-B:** report target — required merchant + optional offer/listing selector.
- **Q-C:** reason taxonomy (dead link / price mismatch / discontinued / out of
  business / quality / deceptive pricing / other).
- **Q-D:** anti-spam policy — recommend honeypot + per-IP rate limit + nonce for
  v1; CAPTCHA/Akismet only if abused.
- **Q-E:** optional (never required) reporter email; PII retention.
- **Q-F:** notification on new report (reuse Q-008's decision).
- **Q-G:** report lifecycle statuses (`new → reviewing → actioned → dismissed`).

**Architecture note:** the public submit handler + shortcode must load on
frontend requests and must not depend on admin-only classes (see the
admin/frontend class-loading boundary). Suspend is the only path from this
feature to public output — exporter exclusion is load-bearing (rule #5).

**Likely code touchpoints (when built):** `supcomp_reports` table + merchant
suspend columns + `SCHEMA_VERSION` bump; `Supcomp_Reports_Repo`; Reports queue
admin screen; merchant-profile reports section + suspend/un-suspend button;
exporter exclusion for suspended merchants; `[supcomp_report_form]` shortcode +
public submit handler with abuse hardening; notification; INSTRUCTIONS §-update;
CHANGELOG; MINOR version bump (1.29.0 → 1.30.0).

---

### Q-014: 500-URL discovery cap for large crawl-all catalogs

**Status:** open (low priority)
**Blocks:** nothing today; the v1.32.0 "Crawl all sitemap URLs" feature works
within the cap.
**Raised:** 2026-06-17
**Last touched:** 2026-06-17

The generic handler caps discovery at `URL_DISCOVERY_CAP` (500) URLs per run —
shared by the normal path-hint path and the new crawl-all path. In crawl-all
mode the cap counts *every* sitemap URL (products + non-products that slip past
the denylist), so a headless catalog with a large sitemap can exhaust the cap
before reaching all products. As of v1.32.0 hitting the cap is logged via
`error_log`, but the run still truncates silently from the operator's point of
view.

Real for: a crawl-all site whose sitemap exceeds ~500 entries. None of the
current 16 sites are close (example.com is ~120). Options when it
matters: (a) raise the cap for crawl-all sites; (b) tighten the denylist /
add a per-site allow-pattern to cut non-product URLs before they count against
the cap; (c) surface a visible admin warning (not just `error_log`) when the
cap is hit. Defer until a real site needs it.

### Q-015: Headless-WooCommerce backends leak staging hostnames in product URLs

**Status:** resolved (v1.34.0) — the per-site URL-rewrite feature (fix option 1
below) shipped. Set a **Product URL rewrite** rule on the affected site
(`from_host`/`to_host` + optional path-prefix swap + `strip_trailing_slash`);
the worker rewrites `source_product_url`/`source_variant_url` after any handler
runs, only when the host matches. The example-shop interim workaround (buy link →
shop page) can be replaced with a rule like
`{"from_host":"example-shop-xzkhb.wpcomstaging.com","to_host":"www.example-shop.com","from_path_prefix":"/product/","to_path_prefix":"/products/","strip_trailing_slash":true}`.
The generic staging-host *guard* (option 2) was not built — the rewrite makes it
unnecessary for known sites; revisit only if an unknown site silently leaks a
staging host. Kept below for history.

**Blocks:** nothing today — interim workaround keeps the affected merchant
publishable
**Raised:** 2026-06-18
**Last touched:** 2026-06-18

A new merchant (example-shop.com) imported with product URLs pointing at a
**staging host** rather than the live storefront. Recorded:
`https://example-shop-xzkhb.wpcomstaging.com/product/tesamorelin/` — public:
`https://www.example-shop.com/products/tesamorelin`.

**Diagnosis (confirmed against live data 2026-06-18):** example-shop is a
**headless WooCommerce store** — a Next.js frontend at `www.example-shop.com`
consuming a WooCommerce backend hosted on **WordPress.com** (`*.wpcomstaging.com`
is WordPress.com's auto-assigned install hostname). The backend's `home_url()`
was never re-pointed off the staging domain. Our extractor classified the site
as Woo and hit the Store API on the www host (which works:
`www.example-shop.com/wp-json/wc/store/v1/products` returns valid JSON), but every
`permalink` field is built from `home_url()`, so it comes back with the staging
host **and** Woo's default `/product/` (singular) base. The Woo handler records
the permalink verbatim (`class-extractor-woo.php:208`) — no host rewriting
anywhere in the pipeline. **Not a bug in our code:** the extractor faithfully
recorded what the merchant's API published; the merchant is leaking its
WordPress.com staging hostname.

**Why a naive host swap doesn't work:** the path bases also differ — backend
`/product/{slug}/` vs. the public Next.js `/products/{slug}`. A host-only
rewrite yields a 404. The **slug is preserved** across both, so a per-site rule
(host → public host, base `/product/` → `/products/`, drop trailing slash) would
reliably reconstruct the live URL for this merchant — but it's per-site config,
not a generic transform.

**Interim workaround (operator, 2026-06-18):** point the affected merchant's
buy link at the **main shop/all-products page** via Merchant settings rather
than per-offer deep links. Keeps the merchant publishable without shipping
staging URLs to the public site (load-bearing rule #5 — no raw/garbage URLs on
the static site; staging URLs are untrustworthy and may disappear).

**Fix options when this recurs / is worth building:**

1. **Per-site public-URL rewrite (recommended).** Optional override on the
   `extract_sites` row: a host rewrite + path-base map applied to
   `source_product_url` after the handler runs. Opt-in, so normal sites are
   unaffected. Reliable here because slugs match across backend/frontend.
2. **Generic staging-host guard.** Detect known backend/staging hosts
   (`*.wpcomstaging.com`, and analogous patterns) and **hold/flag** those offers
   in the pending queue with a warning instead of silently importing a staging
   URL. Safe default for any future merchant with the same leak; doesn't fix the
   URL, just stops bad ones reaching public output.
3. **Both** — guard as a safety net, rewrite as the per-site fix.

Keep the Woo handler either way: the Store API is the right source for
price/stock; only the URL is wrong. Switching this merchant to the generic
JSON-LD path (crawling the Next.js frontend) is worse — headless frontends
typically render prices client-side, so the clean Store API data would be lost.

Defer the build until a second merchant trips the same leak or example-shop needs
per-offer deep links (same "build it when a real merchant trips it" discipline
as Q-006/Q-007/Q-008). Related: Q-007 (per-site overrides) — if that ships, the
rewrite map could live alongside per-site schedule overrides. As of v1.33.0
there's now a natural home for it: `extract_sites.settings_json` (see Q-016).

---

### Q-016: JSON-API handler — follow-ups after the v1.33.0 ship

**Status:** open (handler shipped; one cleanup + one scope call + one ops check
remain — the URL-rewrite loose end was closed in v1.34.0, see Q-015)
**Blocks:** nothing
**Raised:** 2026-06-18
**Last touched:** 2026-06-19

v1.33.0 added the config-driven JSON-API extractor handler
(`platform_hint = json`) for client-rendered SPA storefronts, plus the
per-site `settings_json` bag. Remaining loose ends:

1. **Cursor pagination not implemented.** The handler ships `none` and `page`
   modes. Cursor/`next`-token feeds (where each response carries the URL or
   token for the following page) need cross-page state threading like the
   generic handler's URL transient. Build it when a real SPA merchant paginates
   that way; until then the validator rejects any mode but `none`/`page`.
2. **Legacy-column drop.** `platform_hint`, `request_cookies`, and
   `crawl_all_sitemap_urls` are dual-written into `settings_json` and read
   through `Supcomp_Extract_Sites_Repo::settings()` (bag wins, column is
   fallback). A future schema bump should drop the three columns and the
   dual-write once every reader goes through the accessor — confirm the admin
   list/form direct column reads are migrated first. (The per-site URL-rewrite
   map that was floated here shipped in v1.34.0 — see Q-015.)
3. **Ops: switch example.com from crawl-all to `json`.** v1.34.0 makes
   this viable — it's a headless-Woo SPA with a public `/api/products` feed and
   the backend-host URL leak is now fixable via the URL rewrite (worked example
   + mapping in INSTRUCTIONS §2 "JSON-API storefronts"). **Gate:** the feed
   returns a fixed 49 products and ignores pagination params — before flipping
   it over, confirm 49 matches the site's current crawl-all offer count. If
   crawl-all pulls meaningfully more, the API is a capped subset; stay on
   crawl-all. Same pattern likely applies to example-shop (Q-015) — worth checking
   whether it exposes an equivalent `/api/products` route.
4. **Scope: research peptides.** example-peptides.com (the SPA that motivated this
   handler) sells **injectable research peptides** (BPC-157, etc.), each
   carrying a "not intended to treat… any disease" disclaimer — the
   research-chemical category, arguably outside the single-ingredient *oral
   supplement* positioning. The operator is using example-peptides to test v1.33.0; the
   capability is built regardless. Open question: do peptides/injectables stay
   in scope as a permanent vertical, or was this a test target only? Decide
   before onboarding peptide merchants for real. (robots.txt note for whoever
   picks this up: example-peptides's is self-contradictory — `Content-Signal:
   search=yes,ai-train=no`, then `Allow: /` immediately followed by
   `Disallow: /`. Our use is price-search indexing.)

---

### Q-017: Etsy as a supported merchant platform (specific Etsy shops)

**Status:** open (feasibility only — no decision, no build planned)
**Blocks:** nothing
**Raised:** 2026-06-19
**Last touched:** 2026-06-19

Could we add Etsy to the supported platforms so the operator can feature
specific Etsy shops? Feasibility was scoped (no code).

**Plumbing is cheap.** The extractor's per-platform handler pattern (static
class exposing `fetch_store_meta()` + `fetch_page()` → `{rows, batch_size,
status, http_status}`) makes a new `platform_hint = 'etsy'` a well-trodden
add. Four registration points: `Supcomp_Installer::EXTRACT_SITE_PLATFORM_HINTS`
(the enum — repo sanitize already validates against it),
`Supcomp_Extractor_Worker::detect_and_fetch_first_page()` (page-1 dispatch),
`fetch_subsequent_page()` + `pagination_for()` (page 2+), and the admin
`<select>` in `class-extract-sites-screen.php`.

**The fetch strategy is the whole decision.** Etsy is unlike Shopify/Woo —
there's no public per-shop JSON endpoint to sniff (no `products.json`, no Store
API). Two viable paths:

- **Option A — Official Etsy Open API v3 (recommended).** `getListingsByShop`
  returns structured price, currency, and quantity — the clean analog to the
  Shopify/Woo structured-API handlers. Cost: the operator registers a free Etsy
  app for an API key (`x-api-key` header), and a handler must carry that auth
  header (the `json` handler doesn't pass custom auth headers today). Subject to
  Etsy API rate limits and ToS. This is also the safer-positioning route.
- **Option B — JSON-LD scraping (no key).** Etsy listing pages carry
  `Product`/`Offer` JSON-LD, so the generic engine *could* parse them, but
  (1) discovery doesn't fit — the generic handler finds URLs via root
  `sitemap.xml`, which doesn't exist per-shop on Etsy; and (2) Etsy runs
  aggressive anti-bot (Cloudflare-class), making it a likely **hard target**
  (example-labs-tier, per the extractor merchant map).

**Constraints to honor either way:** the Etsy API returns exact stock
*quantity*, which must collapse to status-only per load-bearing rule #4 (no
exact stock quantity) — trivial mapping (`quantity > 0 → in_stock`). Etsy's ToS
on automated access is stricter than a typical Shopify store, which is a second
reason to prefer the API route.

**Recommendation if pursued:** Option A. The gate is whether the operator will
register for an Etsy API key — that single choice separates "robust handler"
from "fragile scrape." Deferred as feasibility-only; revisit if/when Etsy shops
become a real onboarding target.

---

### Q-018: Amazon as a supported merchant platform

**Status:** open (feasibility only — leaning **no** under current architecture;
no build planned)
**Blocks:** nothing
**Raised:** 2026-06-19
**Last touched:** 2026-06-19

Can we add an Amazon store? Feasibility was scoped (no code). Unlike Q-017
(Etsy), the blocker here is **not** the fetch mechanics — it's that Amazon's
program terms collide with this project's load-bearing architecture.

**Why Amazon is different from every other platform.** Shopify / Woo / generic /
Etsy-API all share the assumption that we fetch prices on our schedule, write
them into **static JSON**, and serve them with a **staleness window** (warn 48h,
hide 168h — Q-004). That model is specifically disallowed for Amazon:

1. **Only sanctioned path is PA-API 5.0, and it's gated.** No public Amazon JSON
   to hit. The Product Advertising API requires an **approved Amazon Associate**
   account; access is revoked without ~3 qualifying sales every 180 days, and
   new accounts are throttled hard (~1 req/sec, 8,640/day). The handler can't
   even be exercised without an active, sales-producing Associate account.
2. **Price-display terms break the static-JSON + staleness model.** The
   Associates Operating Agreement / PA-API license require displayed prices to
   come from PA-API, be shown near-real-time (generally no price older than
   ~24h), and carry the retrieval timestamp + "price may vary" disclaimers. We
   deliberately serve 48h–168h-old prices as a republished static file — a
   direct conflict, not a tuning knob.
3. **Price history (Q-011) is almost certainly off-limits for Amazon.** Same
   terms prohibit storing/displaying historical Amazon prices. The shipped
   price-direction arrows and the planned trend chart can't legally include
   Amazon offers (CamelCamelCamel operates under a special relationship most
   sites can't replicate).
4. **Scraping is a non-starter.** One of the hardest anti-bot targets there is,
   *and* a flat ToS violation — so the generic JSON-LD escape hatch doesn't
   apply.
5. **"A store" is ambiguous on a marketplace.** Amazon is a seller storefront
   *or* a set of ASINs *or* search results. PA-API fetches by ASIN or keyword
   search; clean per-seller-storefront enumeration isn't really exposed. Even
   the "specific store" framing would need redefinition (probably: a curated
   ASIN list).

**Positioning angle.** Single-ingredient supplements on Amazon are noisy —
multiple sellers per ASIN, buy-box price swings, gray-market listings — which
cuts against the apples-to-apples, curated-not-aggregated stance. Not a blocker
alone, but it compounds the above.

**Recommendation:** do **not** add Amazon as a platform under the current
architecture. The plumbing is the easy 10%; the program terms conflict with
three load-bearing things — static-JSON price serving, the staleness windows
(Q-004), and price history (Q-011). If Amazon offers were ever genuinely wanted,
the honest minimum is a **separate live PA-API path** that fetches at render
time and obeys the 24h / timestamp / disclaimer rules — a different architecture
from everything else here, scoped only to Amazon, and contingent on an active
Associate account. Revisit only if that tradeoff becomes worth it.

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
