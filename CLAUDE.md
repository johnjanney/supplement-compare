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
| `CHANGELOG.md` | Every functional change. Updates go under `[Unreleased]` until a version bump moves them to a dated section |
| `PROJECTBRIEF.md` | Architectural decisions change. Not updated for routine feature work |
| `OPEN_QUESTIONS.md` | A new question/ambiguity surfaces, or an existing one is resolved. Lighter-weight than the others; no version coupling |

If you add a feature without updating the relevant doc(s), you have not finished the task. State explicitly in your response which docs you updated.

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
CLAUDE.md           — this file (how to work in the repo)
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
7. **Within-form comparison only.** Different forms (capsule vs powder) are different canonical products. Different standardization percentages are different canonical products.
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

## Python conventions (extractor)

- Python 3.10+ (uses `match`/`case`, PEP 604 union syntax in places)
- Type hints on function signatures
- PEP 8 with 4-space indents
- Dependencies pinned in `extractor/requirements.txt`
- The script must remain runnable as a single-file invocation: `python aggregate_products.py <sites>`

---

## Environment notes

- Development runs under WSL2 (Ubuntu) on Windows
- Plugin is tested on a self-hosted WordPress instance the operator controls
- The Python script runs on the operator's local machine, output piped/uploaded to WordPress
- No CI/CD is configured for v1 — manual build, test, package, and upload

If you're asked to do something that assumes CI exists or that runs in a Linux-only context the user can't replicate in WSL2, flag it.

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
