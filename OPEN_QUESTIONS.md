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

## Resolved questions

### Q-003: Currency policy for v1

**Status:** resolved — **USD only**
**Resolved:** 2026-05-23 (already shipped that way since v0.2.0)

USD is the default for the `supcomp_default_currency` option set on activation. The static JSON payload, the comparison frontend, and the cost-per-active-unit math all assume a single currency; no FX rate handling exists. Merchants whose Store/Shopify endpoints return a different currency code import as-is into the offer row, but the public site renders the operator's configured default currency symbol.

Multi-currency is a deliberate non-goal for the 1.x line. Revisit only if a multi-region expansion becomes a real product goal — at which point it's a Phase-scale change, not a setting flip.

---

## Archived questions

*None yet.*
