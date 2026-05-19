# Operator Runbook — Supplement Compare

Day-to-day instructions for the site operator. As features ship, the matching
section below is filled in. Until then, each section reads "TBD".

This file is the operator's reference. Architectural rationale belongs in
[`PROJECTBRIEF.md`](PROJECTBRIEF.md); version history belongs in
[`CHANGELOG.md`](CHANGELOG.md); deferred decisions belong in
[`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).

---

## 1. Installing and updating the plugin

The plugin source lives under `plugin/` in this repo. To install on the live
site:

1. Package the plugin directory as a zip:

   ```bash
   cd plugin && zip -r ../supplement-compare-$(grep -oE "'[0-9]+\.[0-9]+\.[0-9]+'" supplement-compare.php | head -1 | tr -d "'").zip . -x '*.gitkeep' && cd ..
   ```

   (A `scripts/package-plugin.sh` helper that does this with the version baked
   in lands in a later phase per PROJECTBRIEF.md §11. Until then, use the
   one-liner above or zip the directory manually.)

2. In WordPress admin → Plugins → Add New → Upload Plugin, upload the `.zip`.

3. Click **Activate**. Activation runs `Supcomp_Activator::activate`, which
   creates the eight `wp_supcomp_*` tables and seeds default options
   (currency = USD, staleness thresholds 48h / 168h, default affiliate
   disclosure copy).

**Verifying the upload took.** The version shown in WP Admin → Plugins next
to "Supplement Compare" must match the version in `plugin/supplement-compare.php`
and `CHANGELOG.md`. If it doesn't, you uploaded an older build. This is
load-bearing — every functional change bumps the version for exactly this
reason (PROJECTBRIEF.md §11).

**Updating.** Repeat the steps above with a newer `.zip`. WordPress's
"Replace" flow handles overwriting. On boot, `Supcomp_Installer::maybe_upgrade()`
detects a stale schema-version option and re-runs `dbDelta`, which adds any
new columns or indexes without disturbing existing data.

**Deactivation** is safe — it does nothing destructive. **Uninstall**
(clicking "Delete" on the plugin) currently does nothing either; intentional,
because we'd rather leave orphan tables than risk silently wiping operator
curation work. See `plugin/uninstall.php` for the deferred decision.

## 2. Running the Python extractor to produce a CSV

TBD — refer to [`extractor/README.md`](extractor/README.md) for the script
invocation; this section will cover the operator's actual workflow (which
merchants to run against, on what cadence, how to verify output).

## 3. Uploading a CSV to WordPress

TBD — lands with Phase 4 (CSV import pipeline).

## 4. Interpreting import errors

TBD — lands with Phase 4.

## 5. Rolling back a bad import

TBD — lands with Phase 4.

## 6. Adding a new merchant

TBD — lands with Phase 3 (merchant management).

## 7. Adding a canonical ingredient

TBD — lands with Phase 2 (canonical data management).

## 8. Adding a canonical product

TBD — lands with Phase 2.

## 9. Working the pending queue

TBD — lands with Phase 6 (pending queue and approval workflow). Will cover:
review, edit, approve, reject, pause, defer; bulk approval of high-confidence
matches; marking trust signals (third-party tested, COA URL, certifications).

## 10. Editing an active offer

TBD — lands with Phase 6.

## 11. Pausing or deactivating an offer

TBD — lands with Phase 6.

## 12. Reading the clicks dashboard

TBD — lands with Phase 7 (click-out redirect).

## 13. Regenerating the public JSON manually

TBD — lands with Phase 8 (static JSON export).

## 14. Editing per-canonical-product page content (SEO)

TBD — lands with Phase 10 (SEO and per-canonical pages).

## 15. Troubleshooting

TBD — populated as failure modes are encountered. Each entry: symptom,
likely cause, fix.

---

**Versioning reminder:** every functional change bumps the plugin version (see
PROJECTBRIEF.md §11). The version visible in WP Admin → Plugins is the
canonical "did I upload the right build" signal. Use `scripts/bump-version.sh`.
