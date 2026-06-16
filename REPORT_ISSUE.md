# REPORT_ISSUE.md — Build Plan: Visitor "Report a Problem" Feature

**Status:** design captured / not yet scheduled — decisions below are locked;
open questions in §3 must be answered before coding
**Companion question:** OPEN_QUESTIONS.md → Q-013
**Raised:** 2026-06-15
**Last updated:** 2026-06-15
**Version when built:** MINOR bump from current (1.29.0 → **1.30.0**), unless a
patch ships first — never reuse a number; check the header before bumping.

This is a pick-up-and-build spec. The headline decision (§1) and concern
handling (§2) are settled. The data model (§4) and development plan (§5) are
drafted but contingent on the §3 open questions — most importantly **"what does
Suspend actually do?"**, which is load-bearing and must be answered before any
schema lands. Line/file anchors are approximate as of 2026-06-15 — grep for the
named class/function, don't trust the path.

---

## 1. The decision: build "Report a Problem" (option 1), not "Merchant Feedback" (option 2)

A public form that lets visitors flag a problem with a featured merchant or
listing, feeding an operator triage queue with a deliberate **Suspend merchant**
action. **Not** a two-sided feedback system that also collects positive
sentiment.

### Why option 1 over option 2

The deciding factor is **not** the sock-puppet risk John raised (concern B) —
it's **positioning drift against load-bearing rule #3** ("No ratings. The site
doesn't display merchant ratings, doesn't store them, doesn't compare them. The
site is a price ledger, not a review aggregator."):

- A "Merchant Feedback" system that collects positive **and** negative sentiment
  *is* a ratings system — just an internal one. Today it's unpublished, but once
  that data exists, the gravitational pull toward surfacing it (a trust badge, a
  sort key, a column) is exactly the aggregation-creep the brief was written to
  resist. Option 2 builds the substrate for a review aggregator and relies on
  future-restraint not to use it.
- "Report a Problem" has no such pull. It's a **trust-and-safety signal**, not
  sentiment. It feeds exactly one decision — *should the operator keep featuring
  this merchant?* — which **is** the curation-not-aggregation stance the whole
  project rests on. It's additive to the positioning, not in tension with it.

### Scope guardrails (keep it a safety signal, not a review system)

- **No positive path.** No "leave a compliment" option. One direction only.
- **No scoring, no aggregation, no public display.** Reports are operator-only
  triage input. Nothing about reports ever reaches the public JSON or frontend.
  This keeps the feature clear of rules #2 (no merchant descriptions public) and
  #3 (no ratings).
- **No auto-action from volume.** Report count never auto-suspends a merchant
  (mirrors rule #1, no auto-publish — every state change is an operator
  judgment).

---

## 2. Concern handling

**Concern A — "Report Merchant feels negative / hurts reader trust."** This is a
*copy* problem, not a structural one. A flag-a-problem mechanism reads as **more**
trustworthy, not less — it shows the site is maintained and listens. The
negativity comes from the label putting the merchant on trial. Reframe so the
reader is doing the operator a favor:

- Page / CTA: **"Report a Problem"** or **"Flag an Issue"**, never "Report
  Merchant."
- The merchant control is framed **"Which listing?"**, not "Which bad actor?"
- This also matches reality: most real reports are offer-level mechanics (dead
  affiliate link, stale price, merchant out of business), not "this vendor is a
  fraud."

**Concern B — sock-puppet (fake positive) reviews.** Overblown *and* moot under
option 1: there is no positive path to game, and nothing is published. Recording
it here only to mark it **explicitly out of scope** — if a future conversation
proposes adding a positive path, that re-opens rule #3 and this whole decision.

---

## 3. Open questions — answer before coding

These are the real design work; they matter more than the UI. Mirrored as
companion **Q-013** in OPEN_QUESTIONS.md.

### Q-A (LOAD-BEARING): What does "Suspend merchant" actually *do*?

This is the decision hiding in the sketch and it gates the schema. Suspending
presumably pulls **every** offer from that merchant out of the public JSON —
a real curation action with blast radius (it could empty out a canonical's
comparison table if that merchant was the only offer). Settle:

- **Blast radius:** merchant-wide (all offers) — confirmed intent. Acknowledge
  that a canonical can go to zero public offers as a result.
- **Mechanism:** does suspend set a flag on the merchant row that the exporter
  consults (clean, reversible, leaves offer rows intact), or does it cascade to
  offer visibility? **Recommendation: a single `merchants.suspended` flag the
  exporter filters on** — reversible with no data loss, no offer-row churn.
- **Reversibility:** is there an **Un-suspend**? (Recommend yes — suspension is
  a holding state pending operator investigation, not a delete.)
- **Tombstone / audit:** record who/when/why suspended (operator note +
  timestamp) so the action is auditable.
- **Interaction with the extractor:** does a suspended merchant still get
  *imported* (data kept fresh, just hidden) or skipped entirely? Recommend
  **still import, just exclude from public output** — so un-suspending is
  instant and doesn't wait for the next run.

### Q-B: Report target — merchant, or merchant + optional offer?

Framed merchant-level, but the most *actionable* reports are offer-level ("price
is wrong," "buy button dead"). **Recommendation:** required merchant selector +
**optional** offer/listing selector beneath it, so a report is directly triagable
instead of "something's wrong with this vendor, go find it." Decide whether the
offer list is filtered to the selected merchant (yes) and how it's labeled
(canonical name + form/strength).

### Q-C: Reason taxonomy

Should map to actions the operator would actually take. Draft set:

- Broken / dead affiliate link
- Price doesn't match the merchant's site
- Out of stock long-term / product discontinued
- Merchant appears to be out of business
- Product quality or authenticity concern
- Deceptive or hidden pricing (e.g. surprise fees at checkout)
- Other (+ required free text)

Keep it short and operational. Confirm the list before coding (it's a constant,
cheap to change later, but the column stores the chosen key).

### Q-D: Abuse / anti-spam policy (see §6 — mandatory, not optional)

What's acceptable: honeypot only, or honeypot + per-IP rate limit, or add a
CAPTCHA/Akismet? **Recommendation:** honeypot + per-IP rate limit + nonce for
v1; reserve CAPTCHA/Akismet for if it actually gets abused. Decide retention of
any IP captured for rate-limiting (recommend: hash or short-TTL, don't store raw
IP long-term).

### Q-E: Reporter contact

Collect an **optional** email so the operator can follow up? (Recommend
optional, never required — required contact suppresses legitimate reports and
creates a PII obligation.) If collected, it's PII — note retention.

### Q-F: Notification

Does a new report email the operator, or only surface in the admin queue? Ties
into the same gap as Q-008 (failed-run notifications). **Recommendation:** reuse
whatever Q-008 lands on; for v1 a simple debounced WP email on new report is
fine, queue is the source of truth.

### Q-G: Report lifecycle / statuses

Mirror the offer queue pattern: `new → reviewing → actioned → dismissed`?
Decide the minimal status set and whether dismissed reports are kept (recommend
keep, for pattern-spotting across reports).

---

## 4. Data model (draft — contingent on §3)

WordPress conventions per CLAUDE.md: `{wpdb->prefix}supcomp_` tables,
`$wpdb->prepare()` everywhere, sanitize-in / escape-out.

### New table: `{prefix}supcomp_reports`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PK` | |
| `merchant_id` | `BIGINT UNSIGNED NOT NULL` | FK → merchants; the reported merchant (Q-A/Q-B) |
| `offer_id` | `BIGINT UNSIGNED NULL` | optional reported listing (Q-B); FK → normalized_offers |
| `reason_code` | `VARCHAR(40) NOT NULL` | one of the Q-C taxonomy keys (incl. `other`) |
| `reason_text` | `TEXT NULL` | free-text body; **required when** `reason_code = 'other'` |
| `reporter_email` | `VARCHAR(190) NULL` | optional, Q-E; PII — retention per Q-D/Q-E |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'new'` | Q-G lifecycle |
| `operator_note` | `TEXT NULL` | triage note written in admin |
| `created_at` | `DATETIME NOT NULL` | submission time |
| `updated_at` | `DATETIME NOT NULL` | last status/note change |
| `submitted_ip_hash` | `CHAR(64) NULL` | hashed IP for rate-limit/abuse only (Q-D); never raw |

Indexes: `(merchant_id, status)`, `(status, created_at)`, `(offer_id)`.

### Merchant table change (gated on Q-A)

If Suspend is a merchant flag (recommended), add to the merchants table:

| Column | Type | Notes |
|---|---|---|
| `suspended` | `TINYINT(1) NOT NULL DEFAULT 0` | exporter filters suspended merchants out of public JSON |
| `suspended_at` | `DATETIME NULL` | audit |
| `suspended_reason` | `VARCHAR(255) NULL` | operator note at suspend time |

**Schema-version bump** in `Supcomp_Installer` (`SCHEMA_VERSION`) so the new
table + columns install on upgrade. Reason taxonomy lives as a PHP constant
(like `Supcomp_Installer::SUPPRESSION_REASONS`), not a DB lookup table.

### Exporter contract (gated on Q-A)

The static JSON exporter must **exclude offers belonging to a suspended
merchant**. This is the one place the feature touches public output — verify it
in lockstep with the suspend action so a suspended merchant can never leak into
the published payload.

---

## 5. Development plan

Sequenced for end-to-end testability (admin side first so reports have somewhere
to land before the public form goes live).

1. **Schema + installer** — `supcomp_reports` table, merchant suspend columns
   (Q-A), `SCHEMA_VERSION` bump, reason-taxonomy constant. *No behavior yet.*
2. **Reports repo** — `Supcomp_Reports_Repo`: insert (used by the public
   handler), list/filter by status+merchant, update status + operator note.
   `$wpdb->prepare()` throughout.
3. **Admin: Reports queue** — a dedicated triage screen modeled on the pending
   offers queue (the natural pattern here): list, filter by status/merchant,
   open a report, set status, add a note. Source of truth for triage.
4. **Admin: merchant-profile section** — on the existing merchant admin page,
   show a recent-reports section + a count badge, and the **Suspend / Un-suspend
   merchant** button with a confirmation dialog (blast-radius warning per Q-A).
   Both surfaces, not either/or — the queue catches reports for merchants you're
   not currently looking at; the profile gives context when you are.
5. **Suspend action + exporter wiring** (Q-A) — implement the merchant flag, the
   confirm-gated toggle, audit fields, and the exporter exclusion. Test that a
   suspended merchant's offers vanish from the JSON and return on un-suspend.
6. **Public shortcode** — `[supcomp_report_form]` for the `/report` page:
   merchant `<select>` (active merchants only), optional offer `<select>` (Q-B),
   reason `<select>` (Q-C) with conditional required free-text for "other",
   optional reporter email (Q-E), paragraph field. Reader-friendly copy per §2.
7. **Public submit handler + abuse hardening** (Q-D) — nonce verify, honeypot,
   per-IP rate limit, full sanitize, insert via the repo, success/error states.
   **Must be loaded on frontend requests** — see §7.
8. **Notification** (Q-F) — debounced WP email on new report (or whatever Q-F
   decides).
9. **Docs + version** — INSTRUCTIONS.md (operator runbook: the `/report` page,
   the triage queue, what Suspend does), CHANGELOG.md under `[Unreleased]`,
   README current-version line, version bump to 1.30.0 in all four lockstep
   places. PROJECTBRIEF.md gets a note **only if** the suspend/exporter
   interaction is deemed architectural (likely a §3 data-model addition).
   Resolve / close Q-013 in OPEN_QUESTIONS.md.

---

## 6. Abuse hardening — mandatory, the biggest real cost

A public, unauthenticated form on a WordPress site **will** be spammed and can be
weaponized by a competitor merchant filing false reports. Non-negotiable for v1:

- **Nonce** on submit (`wp_verify_nonce`) — though nonces are weak against
  determined bots on a public form, still required.
- **Honeypot** hidden field — cheap, catches naive bots.
- **Per-IP rate limit** — throttle submissions per IP per window (store a hashed
  IP or a transient counter, not raw IP long-term).
- **No PII beyond optional email** — and that retained per Q-E.
- **Sanitize in / escape out** — every field; `reason_code` validated against the
  taxonomy allow-list (reject unknown keys), text fields length-capped.
- **Escalation path (only if abused):** CAPTCHA or Akismet integration. Don't
  build speculatively (same discipline as Q-006/Q-007/Q-008).

This is the single biggest implementation cost — bigger than the UI.

---

## 7. Architecture gotchas

- **Frontend class-loading boundary.** The public submit handler and shortcode
  **must not** depend on admin-only classes, and must be registered on
  **frontend** requests (not gated behind `is_admin()`). Calling an admin-only
  class from a shortcode/handler fatal-errors the public site. (See memory:
  *Admin/frontend class loading boundary*.) Keep the reports repo and the
  submit handler in code that loads on every request; keep the queue UI and
  suspend action in admin-only code.
- **Suspend touches public output.** This is the only path from this feature to
  the published JSON — treat the exporter exclusion as load-bearing and test it
  directly (rule #5 territory: nothing about a suspended merchant should appear
  publicly).
- **Phase placement.** A public-facing trust feature with an admin queue is past
  the Phase 0–11 build sequence in PROJECTBRIEF §8 (the site is in the
  post-1.x, real-merchant-onboarding band). It doesn't jump any unfinished
  phase, but confirm it's not competing for priority with Q-002 (getting the
  first three real merchants live) — a report form is only useful once there are
  real merchants and real visitors to file reports.

---

## 8. Build trigger

Lower urgency than getting real merchants live (Q-002). Pull this file out when:

- [ ] Real merchants are live and the public site has real traffic (someone to
      file reports).
- [ ] The §3 open questions — especially **Q-A (what Suspend does)** — are
      answered.
- [ ] The operator wants a maintained-and-listening trust signal, not a core
      pipeline fix.
