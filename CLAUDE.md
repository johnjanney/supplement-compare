# CLAUDE.md — Working Instructions for Claude Code

This file tells Claude Code how to operate inside this repository. Read it before doing any work.

---

## What this project is

A WordPress plugin (`plugin/`) plus a Python extractor script (`extractor/`) that together form a single-ingredient supplement affiliate comparison site. The authoritative architecture document is `PROJECTBRIEF.md`. **Read it before making any non-trivial change.**

The user is **John**, the site operator. He is technically capable but is not full-time on this codebase — assume he is reviewing your work, not co-writing it line by line. Be explicit about what you've changed and why.

---

## The documentation files — keep them current

These files exist and MUST be kept current as part of the same change that adds the feature they describe. Do not "do it later."

| File | Update when |
|---|---|
| `README.md` | The install/quickstart story changes, or the project's headline changes |
| `INSTRUCTIONS.md` | Any operator-facing workflow changes (a new admin screen, a new way to import, a new failure mode and its fix) |
| `CHANGELOG.md` | Every functional change, **and every repo-level change** (tooling, `.gitignore`, agent instruction files, scripts, CI config). Updates go under `[Unreleased]` until a version bump moves them to a dated section |
| `PROJECTBRIEF.md` | Architectural decisions change. Not updated for routine feature work |
| `OPEN_QUESTIONS.md` | A new question/ambiguity surfaces, or an existing one is resolved. Lighter-weight than the others; no version coupling |

If you add a feature without updating the relevant doc(s), you have not finished the task. State explicitly in your response which docs you updated.

### Repo-level changes: changelog yes, version bump no

Changes that touch the repository rather than the shipped plugin — `.gitignore`,
`scripts/`, `CLAUDE.md`/`AGENTS.md`, editor or CI config — still get a
`CHANGELOG.md` entry under `[Unreleased]`. They do **not** bump the plugin
version, because the version exists to tell John which build he uploaded to
WordPress, and a repo-only change produces no new build. Put them under a
`### Repo` heading in the `[Unreleased]` block so they're easy to separate from
user-facing changes when cutting a release.

Do not silently skip the changelog on the grounds that a change is "just
hygiene." If it landed as a commit, it gets a line.

---

## Versioning — the canonical signal

The plugin version visible in the WordPress admin Plugins list is **how John verifies he uploaded the latest build**. Every functional change bumps the version.

**Four places to update in lockstep:**

1. `plugin/supplement-compare.php` — `* Version: X.Y.Z` in the plugin header docblock
2. `plugin/supplement-compare.php` — `define( 'SUPPLEMENT_COMPARE_VERSION', 'X.Y.Z' );`
3. `CHANGELOG.md` — new dated section
4. `README.md` — "Current version" line

**Use `scripts/bump-version.sh` once it exists.** Until then, update all four manually and double-check before committing.

**Bump rules** (pre-1.0 leniency in effect):
- PATCH (`0.5.0` → `0.5.1`): bug fix, copy change, internal refactor with no external behavior change
- MINOR (`0.5.0` → `0.6.0`): new feature, new admin screen, new build phase completed
- MAJOR (`0.5.0` → `1.0.0`): only at the v1.0 production milestone defined in PROJECTBRIEF.md §16

**Never reuse a version number.** If you bumped to 0.6.0 yesterday and need another change today, bump to 0.6.1 (patch) or 0.7.0 (if it's another feature).

---

## Where things live

```
PROJECTBRIEF.md     — architecture (authoritative)
CLAUDE.md           — this file (how to work in the repo; applies to all agents)
AGENTS.md           — pointer to this file for Codex; holds no rules of its own
README.md           — front door
INSTRUCTIONS.md     — operator runbook
CHANGELOG.md        — version history
OPEN_QUESTIONS.md   — deferred decisions and pending ambiguities

plugin/             — the WordPress plugin (PHP)
extractor/          — the Python CSV extractor script
docs/               — supplementary technical documentation
seed-data/          — initial canonical ingredients and products as CSV
scripts/            — repo tooling (version bump, packaging)
```

Detailed structure is in PROJECTBRIEF.md §10.

---

## Working principles

### Read before you write

Before editing or creating non-trivial code, view:
- `PROJECTBRIEF.md` — for architecture and data model
- The existing file you're modifying — never edit blind
- Related files in the same module (an offers-repo change probably touches the import pipeline)

### Documentation discipline

Treat documentation updates as part of the change, not as cleanup. A pull request that adds a CSV import endpoint without updating `INSTRUCTIONS.md` to explain how to use it is incomplete.

### No real merchant domains or brand names in anything committed to git

Extractor Sites config necessarily references real third-party merchant websites — that's operational data, lives in the WordPress database, and is unaffected by this rule.

But Markdown docs (`CHANGELOG.md`, `OPEN_QUESTIONS.md`, `INSTRUCTIONS.md`, `PROJECTBRIEF.md`, this file), code comments, and commit messages are different: they're permanently committed to git history. History is expensive to scrub after the fact — a rewrite requires a force-push across every branch and tag, and even then GitHub retains merged-PR diffs forever, independent of branch history.

When writing anything that will be committed — changelog entries, worked examples, open-questions notes, code comments describing a merchant-specific quirk — never name the real domain or brand. Use a placeholder (`example.com`, `example-shop.com`, an invented name) and describe the underlying *pattern* instead (e.g., "a headless WooCommerce store whose Store API leaks a staging hostname," not the merchant's actual name). If unsure whether a domain is safe to name, treat it as unsafe.

### Match depth to complexity

Most tasks are not architectural. A copy fix is a copy fix. A new admin screen is a real change. Calibrate ceremony accordingly.

### When uncertain, ask one question — do not guess

If a task is genuinely ambiguous (e.g., "add a new column to the offers table" without saying which column or what semantics), ask one clarifying question before proceeding. Do not invent semantics that will have to be unwound later.

### Surface concerns

If a request would conflict with the brief, the data model, or the curation-not-aggregation principle, raise it before implementing. The brief reflects decisions made deliberately over many conversations; surface conflicts rather than silently working around them.

### Honesty about state

If something is implemented, say so. If something is mocked, stubbed, or only partially working, say that. Do not present scaffolding as a finished feature.

---

## Things that are load-bearing — don't change without checking

Some decisions look like preferences but are actually load-bearing. Surface a question before changing any of these:

1. **No auto-publish.** Every offer goes through the pending queue. Even confidence 1.00. This is the curation positioning, not a UX detail.
2. **No merchant descriptions on the public site.** Descriptions are imported for parsing only and never appear in the public JSON or frontend.
3. **No ratings.** The site doesn't display merchant ratings, doesn't store them, doesn't compare them. The site is a price ledger, not a review aggregator.
4. **No exact stock quantity.** Status only (`in_stock`/`out_of_stock`/etc.), never counts.
5. **No raw affiliate URLs in the static JSON.** Buy buttons go through `/out/{offer_id}`.
6. **No therapeutic or comparative health claims** in any operator-facing template, default copy, or example data. Only factual chemistry/composition language.
7. **Canonical product = ingredient (+ active unit) by default.** As of v1.1.0 a canonical groups all forms and brand strengths of one ingredient unless the operator explicitly pins a form or strength on the canonical row. Cost-per-active-unit is the apples-to-apples metric across forms of the same compound; the table surfaces the per-offer form, total active unit, serving size, and # servings so readers can judge form-specific tradeoffs themselves. Standardization context still belongs at the offer level (or as an optional canonical override). Don't reintroduce "different forms = different canonicals" as a hard rule.
8. **Cost-per-active-unit, not cost-per-mg-of-compound,** when standardization or elemental percentage matters. The math is in PROJECTBRIEF.md §6.

If a feature request implies changing one of these, surface it before implementing.

---

## Build phases

PROJECTBRIEF.md §8 defines a phased build sequence (Phase 0 through Phase 10). Each phase produces a working artifact and bumps the minor version.

**Do not skip ahead.** If you're in Phase 4 (CSV import) and a Phase 7 thought (`/out/{offer_id}`) arises, note it and continue with Phase 4 work. The phases are sequenced for end-to-end testability.

When uncertain which phase a task belongs to, check the brief.

---

## WordPress conventions

- Table prefix: `{wpdb->prefix}supcomp_`
- Class prefix: `Supcomp_`
- Function prefix: `supcomp_`
- Text domain: `supplement-compare`
- All DB queries use `$wpdb->prepare()`
- All admin actions use nonces (`wp_verify_nonce`)
- All admin pages check capabilities (`current_user_can( 'manage_options' )`)
- All user input sanitized; all output escaped

These are not negotiable — WordPress security and ecosystem norms.

---

## Python conventions (legacy extractor)

As of v1.8.0 (Phase 11/F per PROJECTBRIEF.md §8), the Python script
`extractor/aggregate_products.py` is **legacy**. The canonical refresh
path is the in-plugin extractor (Shopify / Woo / generic JSON-LD
handlers under `plugin/includes/extractor/`), triggered from WP Admin →
Extractor Sites. The Python script is kept around because it's the only
way to exercise merchant endpoints without a running WordPress in the
loop — useful for debugging a merchant's response shape, or for
ingesting from a platform that no in-plugin handler covers yet.

When you DO touch it:

- Python 3.10+ (uses `match`/`case`, PEP 604 union syntax in places)
- Type hints on function signatures
- PEP 8 with 4-space indents
- Dependencies pinned in `extractor/requirements.txt`
- The script must remain runnable as a single-file invocation: `python aggregate_products.py <sites>`
- If you change the CSV column set, change `Supcomp_Extractor_Offer::fieldnames()` in lockstep (PROJECTBRIEF.md §4 — same schema, two emitters)

---

## Environment notes

- Development runs under WSL2 (Ubuntu) on Windows
- Plugin is tested on a self-hosted WordPress instance the operator controls
- **Operator has web-only access** to the WP install — no SSH, no WP-CLI, no host-level cron. The in-plugin extractor architecture (Phase 11) exists for exactly this constraint
- Action Scheduler ticks on visitor traffic + WP-Cron. For reliable scheduled runs on low-traffic sites, the operator uses an external pinger (cron-job.org or UptimeRobot) hitting `/wp-cron.php` every 5 minutes
- The legacy Python script runs on the operator's local machine when needed; output uploads as CSV via WP Admin
- No CI/CD is configured — manual build, test, package, and upload via `scripts/package-plugin.sh`

If you're asked to do something that assumes SSH / WP-CLI / host-level cron, flag it. If you're asked to do something that assumes CI exists or that runs in a Linux-only context the user can't replicate in WSL2, flag it.

---

## Use the user's memory

Memory entries indicate John works in WSL2 with Claude Code, has Notion MCP integrations, and has done significant work on WordPress plugins and Claude Skills. Assume technical fluency. Don't over-explain WordPress or Claude Code basics. Do explain non-obvious architectural choices for this specific project.

---

## When the user pastes a long document

If John pastes a critique, assessment, or revised spec from another model or another conversation, treat it as input to be evaluated — not a directive to be executed wholesale. Agree with what's right, push back on what's not, and surface conflicts with PROJECTBRIEF.md before incorporating changes.

---

## What "done" looks like for a task

A task is done when:

1. Code works as specified
2. Affected documentation files are updated
3. Version bumped if it's a functional change
4. CHANGELOG entry written under `[Unreleased]` (or moved to a dated section if this was a release)
5. Response to the user states clearly what was changed and what files were touched
6. Any deviation from the original request or from the brief is called out

If you can't check all six, the task isn't done.
