# Extractor Run Dashboard Fix Plan

**Status:** Planning (no code written yet)
**Owner:** John + Claude Code
**Created:** 2026-06-14
**Related docs:** `PROJECTBRIEF.md` (extractor architecture, Phase 11/F), `INSTRUCTIONS.md` (operator runbook), `CHANGELOG.md`

> Status legend for checklist items below:
> - `[ ]` pending / not started
> - `[~]` in progress
> - `[x]` complete
> - `[-]` dropped / decided against (leave a note saying why)

---

## 1. Problem statement

The **Extractor Sites** admin screen shows several sites stuck as **"in flight"** with
**5–12 "attempt(s)"** each, while other sites show **"complete"**. This makes the
dashboard untrustworthy — the operator cannot tell a genuinely running extraction
from a dead one, and clicking **Run now** to "unstick" a site only makes it worse.

## 2. Root-cause findings (read-only investigation, 2026-06-14)

### Key reconciliation
Action Scheduler is **healthy**, which reframes the whole problem:

| Action Scheduler tab | Count | Meaning |
|---|---|---|
| Completed | 729 (latest **today**) | Queue is draining fine |
| Pending | 2 | Not backed up |
| Failed | 66 (newest **2026‑06‑08**, 6 days stale) | Not currently crashing |

The orphaned "in flight" attempts are dated **2026‑06‑14**, but there are no
matching recent failed/pending AS actions. Therefore the jobs behind them **ran to
completion from AS's perspective** but **never finalized their own `extract_runs`
row**. This is a *finalize-or-fail gap in the plugin*, not a queue/cron starvation
problem.

### What "in flight" / "N attempt(s)" actually mean
- A site shows **"in flight"** when ≥1 row exists in `extract_runs` for it with
  status `pending` or `running` (`plugin/includes/admin/class-extract-sites-screen.php:140-160`).
- **"N attempt(s)"** = count of those open rows. It is **not** pagination or retries.
- **"complete"** is read from `extract_sites.last_run_status` (the last *finished*
  run), which is why finished sites still show yesterday's timestamp.

### Confirmed code gaps
1. **No `try/finally` in `execute_page()`** — `plugin/includes/extractor/class-extractor-worker.php:74-202`.
   Multiple exit paths can return without finalizing or re-enqueuing. An uncaught
   exception/fatal leaves the attempt at `running` while AS marks the action done.
2. **No reaper / stale-run cleanup** — confirmed absent across the whole plugin.
   A row stuck at `running`/`pending` stays that way forever.
3. **No dedupe in `run()`** — `plugin/includes/extractor/class-extractor.php:38-70`
   and `create_attempt()` in `plugin/includes/db/class-extract-runs-repo.php:40-53`.
   Every trigger (manual click, scheduled cron) creates a brand-new attempt per
   site with no check for an already-open attempt → orphans accumulate (the 5–12).
4. **Generic/Wix transient fragility** — `class-extractor-worker.php:434-439, 475-477`.
   Discovered URLs are stored in a per-attempt transient with a 6‑hour TTL; loss
   between pages ends the run early. (Per-attempt key, so cross-attempt collision
   is *not* the issue; expiry/eviction is.)
5. **No dedicated Wix handler** — `wix` and `auto` route through the generic
   JSON-LD engine. Sites like Example Wix (wix) and Example Labs (auto, 0 offers) exercise
   exactly the fragile generic path above.

### Finalization call sites (for reference when fixing)
- `set_complete` / `set_failed` / `set_canceled` — `class-extract-runs-repo.php:67-105`
- `finalize_attempt_complete()` — `class-extractor-worker.php:208-237` (called 152, 201)
- `finalize_attempt_failed()` — `class-extractor-worker.php:244-268` (called 123)
- `fail_attempt()` — `class-extractor-worker.php:270-279` (called 92, 102)

### Design insight driving the plan
A **reaper** closes orphans regardless of which exact path created them. So the
dashboard can be made truthful **without** perfectly enumerating every bug — the
reaper is the safety net; the `try/finally` and dedupe reduce orphans at the source.

## 3. Goals / non-goals

**Goals**
- The Extractor Sites dashboard reflects reality: no permanently-stuck "in flight".
- Orphaned attempts self-heal (get marked `failed`) within a bounded time.
- Re-triggering a site that's already running does not create duplicate attempts.
- The operator can see *why* a run failed without leaving WP Admin.
- The queue drains reliably regardless of visitor traffic.

**Non-goals (deferred — separate effort)**
- Raw throughput / parallelism tuning (Action Scheduler concurrency, batch DB
  inserts, adaptive politeness delay). Hold until the dashboard is trustworthy and
  we can actually measure run durations. Tracked in §7.

## 4. Plan — phased checklist

### Phase 0 — Confirm mechanism (optional, low cost)
- [x] **0a.** Read-only root-cause read (2026-06-14) confirmed the mechanism:
      Action Scheduler healthy (729 completed today, 2 pending, failures 6 days
      stale) yet attempts dated today stuck open → jobs ran/died without closing
      their `extract_runs` row. Causes: no `try/finally` in `execute_page()`, no
      reaper, no dedupe. Reaper-as-safety-net chosen so exact path needn't be
      enumerated per-orphan.

### Phase 1 — Make the dashboard truthful (self-healing) — **highest priority** ✅ shipped in v1.26.0
- [x] **1a.** `execute_page()` wrapped in `try/catch/finally` with a `$handed_off`
      flag — any exit that didn't enqueue a follow-on page or finalize now fails
      the attempt (and closes its `import_run`). `class-extractor-worker.php`.
- [x] **1b.** Stale-attempt reaper shipped as `Supcomp_Extractor_Reaper`
      (`includes/extractor/class-extractor-reaper.php`). Threshold setting (default
      30 min, range 5–1440), **both triggers** (hourly recurring AS action + throttled
      lazy sweep on Extractor Sites screen load). Dead-vs-live decided by querying AS
      for a live page action per attempt (`Supcomp_Extractor_Worker::live_attempt_ids()`)
      — no schema/heartbeat column needed; slow-but-live runs are never reaped. New
      repo methods `open_attempts_older_than()` / `count_open_attempts()` and
      `Supcomp_Import_Runs_Repo::fail_open_for_export_run()`.
- [x] **1c.** "Clear stuck runs now" action on the Database Cleanup screen (reap with
      threshold 0; live chains still spared). Nonce-protected, `manage_options`.

### Phase 2 — Prevent recurrence — **2a shipped in v1.27.0**
- [x] **2a.** Dedupe guard added in `Supcomp_Extractor::run()`. It first reaps dead
      orphans (`reap(0)`, liveness-guarded) so a crashed run can't wedge a site, then
      skips any site that still has an open (live) attempt. Returns `skipped_in_flight`,
      surfaced in the Extractor Sites admin notice. Applies to manual *and* scheduled
      triggers. `class-extractor.php`, `class-extract-sites-screen.php`.
- [-] **2b.** Harden generic/Wix transient handling (longer TTL / persist URLs on the
      attempt row). **Deferred** — this is the same decision as Q1 (§6), which the
      operator chose to defer. The reaper already closes any run that ends early on a
      lost transient, so it is no longer a correctness risk, only an efficiency one
      (a large generic site may re-discover URLs). Revisit if generic sites prove slow.

### Phase 3 — Visibility — **already satisfied; no code shipped**
- [-] **3a.** Already covered by existing screens. Extractor Runs has status filters,
      a 24h summary, per-attempt platform/duration/offers/error-excerpt, and a detail
      view with the full error log + sibling attempts; Extractor Sites shows each
      site's last error in a red row. Reaped runs surface their failure message there.
      No new code needed.
- [-] **3b.** No code needed — Action Scheduler auto-purges completed/failed actions on
      its built-in ~30-day retention, so the 66 stale failures clear themselves. To
      remove them now: **Tools → Scheduled Actions → Failed → bulk delete**.

### Phase 4 — Reliability (ops, mostly non-code)
- [ ] **4a.** Set up a reliable cron trigger for `wp-cron.php`:
      Hostinger hPanel **Cron Jobs** (preferred) or external pinger (cron-job.org /
      UptimeRobot) every 5 min.
- [ ] **4b.** If a dedicated trigger is in place, set `define('DISABLE_WP_CRON', true);`
      in `wp-config.php` for deterministic, non-double-firing scheduling.

### Phase 5 — Docs & versioning (per `CLAUDE.md`)
- [x] **5a.** `CHANGELOG.md` — dated **[1.26.0]** (PR 1) and **[1.27.0]** (PR 2)
      sections added, both 2026-06-14.
- [x] **5b.** `INSTRUCTIONS.md` — PR 1 added the "Runs stuck at 'in flight'" subsection
      (reaper behavior, stale-run timeout setting, "Clear stuck runs now"); PR 2 updated
      the "Run now" note to reflect the dedupe (re-clicking is now a safe no-op).
- [x] **5c.** Version bumped in lockstep across all four places: **1.25.0 → 1.26.0**
      (PR 1) → **1.27.0** (PR 2). Both MINOR.

## 5. Suggested sequencing / PR boundaries
1. **PR 1 — Self-healing core:** Phase 1 (1a + 1b + 1c) + docs/version. Biggest
   immediate payoff; makes the dashboard usable.
2. **PR 2 — Prevention:** Phase 2 (+ 2b decision) + docs/version.
3. **PR 3 — Visibility & housekeeping:** Phase 3 + docs/version.
4. **Ops task (no PR):** Phase 4 (operator-side cron/pinger setup).

## 6. Open questions / decisions needed
- **Q1 (2b):** Replace the 6‑hour generic/Wix URL transient with persistence on the
  attempt row? More robust for large generic sites, slightly more schema work. —
  *Pending decision.*
- **Q2 (1b):** Reaper staleness threshold default — 30 min? Should it be a Setting?
- **Q3 (1b):** Run the reaper as its own recurring scheduled action, lazily on admin
  page load, or both? (Both is cheapest to make the dashboard self-heal on view.)
- **Q4 (4a):** Confirm whether Hostinger hPanel cron is available on this plan; if
  so prefer it over an external pinger.

## 7. Deferred: performance levers (separate future effort)
Only after the dashboard is trustworthy and run durations are measurable:
- Action Scheduler concurrency bump (`action_scheduler_queue_runner_concurrent_batches`
  1 → 3) — cautious on shared hosting (DB connections, CPU).
- Longer per-batch time limit so more work happens per tick.
- Batch DB inserts (currently one INSERT/UPDATE per product row).
- Per-host adaptive politeness delay (currently fixed 0.5s).

## 8. Status log
*(append dated notes as work proceeds)*
- **2026‑06‑14** — Plan created after read-only root-cause investigation. No code
  written. Awaiting go-ahead on Phase 1 and decisions in §6.
- **2026‑06‑14** — Decisions resolved: stale-run timeout **30 min as a Settings
  option**; **both triggers** (scheduled + lazy-on-load); Q1 (transient → row
  persistence) deferred to Phase 2.
- **2026‑06‑14** — **PR 1 (Phase 1) implemented and shipped as v1.26.0.** Files:
  `class-extractor-worker.php` (try/finally + `live_attempt_ids()` + public
  transient-key helper), new `class-extractor-reaper.php`, `class-extract-runs-repo.php`
  (+2 methods), `class-import-runs-repo.php` (+`fail_open_for_export_run`),
  `class-plugin.php` (load + register), `class-extract-sites-screen.php` (lazy reap),
  `class-cleanup-screen.php` (Clear stuck runs), `class-settings.php` (timeout setting),
  `supplement-compare.php` (version), `CHANGELOG.md`, `README.md`, `INSTRUCTIONS.md`.
  All files `php -l` clean. **No schema migration** (reaper uses AS liveness, not a
  heartbeat column). Next: Phase 2 (dedupe guard) when ready.
- **2026‑06‑14** — **PR 2 (Phase 2a) implemented and shipped as v1.27.0.** Dedupe
  guard in `Supcomp_Extractor::run()` (reap-then-skip-live), `skipped_in_flight`
  surfaced in the Extractor Sites notice, INSTRUCTIONS "Run now" note corrected.
  Files: `class-extractor.php`, `class-extract-sites-screen.php`,
  `supplement-compare.php`, `CHANGELOG.md`, `README.md`, `INSTRUCTIONS.md`. `php -l`
  clean. 2b deferred (same call as Q1). Phase 1 + 2a now cover the dashboard fully:
  orphans self-heal AND can't re-accumulate. Remaining: Phase 3 (visibility),
  Phase 4 (operator pinger/cron).
- **2026‑06‑14** — **Phase 4 done** (operator): Hostinger hPanel cron runs
  `wget -q -O /dev/null "https://example.com/wp-cron.php?doing_wp_cron"` every 5 min.
  Queue confirmed draining live (pending backlog cleared; runs moving
  pending→running→complete with offers/platform populating). See [[extractor-cron-pinger]].
- **2026‑06‑14** — **PR #3 merged to `main`** (`5eb88c3`); `v1.27.0` tagged; branch
  deleted. **Phase 3 found already satisfied** by existing admin screens + AS
  auto-retention — no code shipped. **Project complete.** Separately diagnosed
  **Example Labs = WooCommerce site behind a JS age-verification gate** (edge-redirects
  uncredentialed requests to /landing-page/; verification cookie set client-side post-
  click). Our fix works (it completes cleanly, 0 offers = access not bug). A general
  fix (per-site request-cookie field) is NEW scope, decision pending — not in this plan.
- **2026‑06‑14** — Operator chose to build the per-site cookie field. **Shipped as
  v1.28.0** (separate from this plan): optional "Request cookies" field on Extractor
  Sites → injected at the HTTP `get()` chokepoint → covers all handlers; schema
  bump 10→11 (`request_cookies` column, auto-migrated). Probed Example Labs: confirmed
  the "Age Gate" WP plugin, cookie `age_gate`, 90-day lifetime, hashed value (must
  be browser-captured). Mechanism validated (edge gate is cookie-conditional);
  full end-to-end needs John's real captured cookie. Files: installer, extract-sites
  repo, extractor-http, worker, extract-sites-screen, version + CHANGELOG + README +
  INSTRUCTIONS. `php -l` clean.
- **2026‑06‑14** — Probed all 16 merchants. **14 work with no cookie** (Woo Store API
  or Shopify endpoint answers a plain request; several "age gates" are browser-only
  overlays). Two exceptions: **example-chems.is** blocks the crawler UA (403) but allows
  a browser UA → shipped **v1.29.0**: HTTP client auto-retries a 403 once with a
  browser User-Agent (`class-extractor-http.php`, no schema/config). **example-labs.com**
  is a hard target (REST API 401-locked + edge age-gate); `age_gate=21` does not
  bypass — deprioritized. Action for operator: remove the bogus Example Labs cookie; set
  no cookies on the other sites. `php -l` clean.
