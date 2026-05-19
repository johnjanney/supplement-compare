# Supplement Compare

**Current version:** 0.7.0

A WordPress-powered affiliate comparison engine for single-ingredient
supplements (nootropics, longevity, sports nutrition). Lets price-conscious
buyers compare the same compound across multiple participating merchants on a
normalized **cost-per-mg-of-active-compound** basis.

The site does not sell anything. Every listing links out to a participating
merchant via a tracked affiliate URL. Every offer is reviewed by a human
before going live — this is curation, not aggregation.

## Two halves

| Half | Lives in | Language | Job |
|---|---|---|---|
| Extractor | `extractor/` | Python 3.10+ | Pulls public product data from merchant Shopify / WooCommerce / generic sites; emits a canonical-schema CSV |
| Plugin | `plugin/` | PHP (WordPress) | Imports the CSV, normalizes it, queues offers for operator review, serves the public comparison site |

The plugin **never** talks to merchant sites directly. The CSV is the only
contract between the two halves. See [PROJECTBRIEF.md §4](PROJECTBRIEF.md) for
that contract.

## Quick start

### Extractor

```bash
cd extractor
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python aggregate_products.py https://store.example.com -o products.csv
```

See [`extractor/README.md`](extractor/README.md) for details.

### Plugin

Phase 0 — the plugin is a stub at this version. Installing it does nothing
beyond appearing in the WordPress admin Plugins list. Real installation steps
land with Phase 1 (database scaffolding) and onwards. Watch
[`CHANGELOG.md`](CHANGELOG.md) for what each version actually does.

## Documentation

- [`PROJECTBRIEF.md`](PROJECTBRIEF.md) — architecture, data model, build phases (authoritative)
- [`INSTRUCTIONS.md`](INSTRUCTIONS.md) — operator runbook (CSV imports, curation, troubleshooting)
- [`CHANGELOG.md`](CHANGELOG.md) — version history
- [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md) — deferred decisions and pending ambiguities
- [`CLAUDE.md`](CLAUDE.md) — working instructions for the AI assistant inside this repo

## Status

Pre-1.0. Build phases are sequenced in PROJECTBRIEF.md §8 — each phase
produces a working artifact and bumps the minor version. The current phase is
visible from `CHANGELOG.md` and the plugin header version.

## License

TBD — see [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md) Q-005. The repo is
currently private; license decision is deferred until publication.
