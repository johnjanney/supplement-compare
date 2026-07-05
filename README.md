# Supplement Compare

**Current version:** 1.39.0

A WordPress-powered affiliate comparison engine for single-ingredient
supplements (nootropics, longevity, sports nutrition). Lets price-conscious
buyers compare the same compound across multiple participating merchants on a
normalized **cost-per-active-unit** basis.

The site does not sell anything. Every listing links out to a participating
merchant via a tracked affiliate URL. Every offer is reviewed by a human
before going live — this is curation, not aggregation.

## Two halves

| Half | Lives in | Language | Job |
|---|---|---|---|
| Plugin | `plugin/` | PHP (WordPress) | Pulls product data (in-plugin extractor, or CSV import), normalizes it, queues offers for operator review, serves the public comparison site |
| Extractor | `extractor/` | Python 3.10+ | Legacy/local-debug path: pulls public product data from a merchant site and emits a canonical-schema CSV for manual upload |

Two paths feed the same importer, sharing one row schema:

- **In-plugin extractor (canonical, v1.3.0+).** WP Admin → Extractor Sites →
  Run now / WP-Cron schedule. Runs entirely inside WordPress via Action
  Scheduler — the plugin fetches merchant Shopify / WooCommerce / generic
  JSON-LD / JSON-API pages directly (`plugin/includes/extractor/`), with an
  SSRF guard on every outbound request. No Python, SSH, or WP-CLI required —
  this exists specifically so an operator with only web-based WP Admin access
  can refresh product data.
- **Python script (legacy, local-debug only).** `extractor/aggregate_products.py`
  emits a CSV that's uploaded via WP Admin → Import. Retained because it's the
  only path that doesn't require a running WordPress to exercise a merchant's
  endpoints, or for platforms the in-plugin handlers don't yet cover.

Both paths land in the same `Supcomp_CSV_Importer` pipeline — sticky-edit
semantics, stale detection, and the curation queue work identically regardless
of source. See [PROJECTBRIEF.md §2](PROJECTBRIEF.md) for the full data-flow
diagram and §4 for the row schema.

## Quick start

### Plugin (the canonical path)

1. Install and activate `plugin/` on a WordPress 6.4+ / PHP 8.0+ site.
2. **Merchants** → add a merchant, its site URL, and (if applicable) an
   affiliate URL template.
3. **Extractor Sites** → add a site pointed at that merchant, pick a platform
   hint (or `auto`), and click **Run now** (or set a WP-Cron schedule).
4. **Pending Queue** → review, edit, and approve the offers the run produced.
5. Embed `[supplement_compare]` on a page to render the public comparison
   table.

Full operator workflow — including the legacy CSV-upload path, curation
rules, and troubleshooting — is in [`INSTRUCTIONS.md`](INSTRUCTIONS.md).

### Extractor (legacy, local-debug)

```bash
cd extractor
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python aggregate_products.py https://store.example.com -o products.csv
```

Upload the resulting CSV via WP Admin → Import. See
[`extractor/README.md`](extractor/README.md) for details.

## Documentation

- [`PROJECTBRIEF.md`](PROJECTBRIEF.md) — architecture, data model, build phases (authoritative)
- [`INSTRUCTIONS.md`](INSTRUCTIONS.md) — operator runbook (CSV imports, curation, troubleshooting)
- [`CHANGELOG.md`](CHANGELOG.md) — version history
- [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md) — deferred decisions and pending ambiguities
- [`CLAUDE.md`](CLAUDE.md) — working instructions for the AI assistant inside this repo

## Status

All 11 build phases from PROJECTBRIEF.md §8 have shipped — schema, admin
CRUD, import pipeline, curation queue, click tracking, JSON export, public
frontend, per-canonical SEO pages, and the in-plugin extractor. The plugin
version crossed 1.0.0 in May 2026 and has continued climbing with ongoing
feature work (MINOR bumps) since. That said, PROJECTBRIEF.md §16's
"Definition of done (1.0)" checklist — real merchant integration, catalog
depth — still has open items; see [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md)
for what's still pending on the business side versus the code side. The
current version is visible from `CHANGELOG.md` and the plugin header.

## License

Proprietary — all rights reserved. See [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md)
Q-005. The repo is currently private.
