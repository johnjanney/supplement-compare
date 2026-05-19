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
