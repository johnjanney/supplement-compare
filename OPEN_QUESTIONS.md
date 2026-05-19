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

**Status:** open
**Blocks:** Phase 2 (Canonical data management) seed-data, Phase 5 (Normalization)
**Raised:** initial brief

What are the actual ~80–150 ingredients to seed across the three verticals (nootropics, longevity, sports nutrition)?

Needs to be enumerated as CSV files (`seed-data/ingredients-*.csv`) before the Phase 2 seed import is useful. For each ingredient: slug, name, aliases, category, default unit, elemental percentage (if applicable), standardization compound + default percentage (if applicable).

**Notes:**
- Cross-category ingredients (taurine, glycine) exist in multiple verticals — assign to a primary category, surface via aliases/tags
- The list doesn't need to be complete on day one; can grow as merchants are added

---

### Q-002: First three target merchants

**Status:** open
**Blocks:** Phase 4 (CSV import) end-to-end validation

Which three merchants are integrated first to validate the full pipeline?

**Suggested in brief:** Nootropics Depot, Pure Nootropics, one longevity-focused merchant (DoNotAge or Renue by Science).

Open subquestions:
- Does the operator have affiliate relationships with these merchants already, or pending?
- Do their public Shopify/Woo endpoints expose enough variant data for the script's current extractor logic?
- Any affiliate-program terms that constrain how their data is displayed (e.g., price freshness windows)?

---

### Q-003: Currency policy for v1

**Status:** open
**Blocks:** Phase 4 (CSV import default currency handling)

USD only for v1, or support multi-currency from the start?

Default assumption in brief: USD only. This simplifies:
- The static JSON payload (no currency conversion logic)
- The frontend comparison view (no "prices may vary by currency" caveats)
- The cost-per-mg math (no FX rates to muddy the comparison)

Multi-currency adds complexity proportional to: number of currencies × number of merchants × number of canonical products. Easy to defer; harder to retrofit if added later. Confirm USD-only is acceptable.

---

### Q-004: Staleness thresholds

**Status:** open
**Blocks:** Phase 8 (JSON export rules), Phase 9 (frontend display)

Confirm or adjust the staleness defaults from PROJECTBRIEF.md §8:

- **48 hours** since `last_synced_at` → offer is visually downgraded on the public site ("data may be outdated")
- **7 days** since `last_synced_at` → offer is excluded from the public JSON entirely

Considerations:
- If imports happen weekly (manual CSV uploads on a schedule), 48h is too aggressive — most offers would be perpetually "downgraded"
- If imports happen daily, 48h works
- The cadence determines the threshold; the threshold determines the visible trust signal

Resolve once the operator commits to an import cadence.

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

## Resolved questions

*None yet.*

---

## Archived questions

*None yet.*
