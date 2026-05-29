# SECURITY-AUDIT.md — Supplement Compare WordPress Plugin

**Audit date:** 2026-05-29
**Auditor:** Automated systematic review (Claude Code), evidence-based, file-by-file
**Plugin version audited:** see `plugin/supplement-compare.php` header (v1.21.1 at time of audit)
**Standards applied:** WordPress Plugin Security Guidelines, OWASP Top 10 (2021), WordPress VIP coding standards

---

## 0. Coverage statement

**First-party code: audited completely (100%).** Every PHP file under `plugin/` outside `plugin/vendor/` was read end-to-end (53 PHP files, ~13,900 lines), plus both JavaScript files (`assets/admin/merchants-preview.js`, `assets/public/frontend.js`) and the bootstrap/uninstall files. Data flow was traced from input source to sink for each handler.

**Vendor code: NOT line-audited; version-identified only.** `plugin/vendor/action-scheduler/` is the WooCommerce Action Scheduler library, **version 3.9.3** (confirmed at `vendor/action-scheduler/action-scheduler.php:8`). Per rules of engagement I did not read its ~120 files line by line; I report it as a bundled dependency and assess it by version (see §4). I did **not** fabricate CVE numbers and could not query a live CVE database from this environment.

**Distinction used throughout:** *Verified* = confirmed by reading the code and tracing data flow. *Inferred* = a pattern-level concern whose exploitability depends on runtime context I could not fully resolve. Each finding is labelled with a Confidence value accordingly.

### Headline

The first-party code is **defensively written and of high quality.** Across all admin screens the pattern is consistent and correct: capability check (`current_user_can`) **and** a specific-action nonce (`check_admin_referer` / `check_ajax_referer`) before every state-changing action; `absint` / `sanitize_key` / `sanitize_text_field` on inputs; `esc_html` / `esc_attr` / `esc_url` / `esc_textarea` at every output. Every `$wpdb` query that carries a non-literal value uses `$wpdb->prepare()` with placeholders; `ORDER BY` is allowlist-mapped; `LIKE` uses `esc_like()`; dynamic `IN()` lists generate matched placeholders. There is **no** `eval`, `create_function`, `assert`, `unserialize`, `extract($_*)`, `system`/`exec`/`shell_exec`, `preg_replace` `/e`, variable-variables, or dynamic-class instantiation from user data anywhere in the first-party tree. No hardcoded secrets, no weak RNG used for security, no `var_dump`/`print_r`/`error_reporting` in production paths. There is exactly one AJAX action and it is privileged + nonce-gated; there are **no** `wp_ajax_nopriv_` handlers and **no** REST routes.

**No Critical findings. No SQL injection. No authentication/authorization bypass. No XSS reachable by an anonymous user.**

The most material issue is **server-side request forgery (SSRF) in the in-plugin extractor** (VULN-001 / VULN-002, both High), which is inherent to the extractor's job of fetching operator-configured merchant URLs but is implemented without any private-network / scheme guard and — worse — recursively follows merchant-supplied sitemap URLs. Everything else is Medium or below.

### Findings at a glance

| ID | Severity | Title | Confidence |
|----|----------|-------|------------|
| VULN-001 | **High** | SSRF: extractor HTTP client fetches operator URLs with no private-IP/scheme guard | High |
| VULN-002 | **High** | SSRF amplification: recursive fetch of merchant-controlled sitemap `<loc>` URLs | High |
| VULN-003 | Medium | Click-out redirect destination not scheme-validated before `wp_redirect` | High (gap) / Medium (exploit) |
| VULN-004 | Medium | No response-size limit on extractor fetch → memory-exhaustion DoS | High |
| VULN-005 | Low | JSON-LD emitted with `JSON_UNESCAPED_SLASHES` allows `</script>` breakout | High |
| VULN-006 | Low | Affiliate-URL template engine performs no output-scheme validation | High |
| VULN-007 | Low | CSV validator accumulates unbounded rows → memory-exhaustion DoS | High |
| VULN-008 | Low | XML entity-expansion exposure parsing merchant sitemaps (`simplexml_load_string`) | Medium |
| VULN-009 | Low | CSV upload has no server-side MIME/extension verification | High |
| VULN-010 | Informational | `client_ip()` trusts spoofable `X-Forwarded-For` (hash-only use) | High |
| VULN-011 | Informational | `current_url()` reflects all `$_GET` keys into the page (output is escaped) | High |
| VULN-012 | Informational | `maybe_upgrade()` runs `dbDelta` on anonymous front-end requests | Medium |
| VULN-013 | Informational | Bundled dependency: Action Scheduler 3.9.3 (no verified CVE; keep current) | High |

---

## VULN-001 — SSRF in the extractor HTTP client

- **Severity:** **High**
- **CVSS 3.1 (estimated):** `AV:N/AC:L/PR:H/UI:N/S:C/C:H/I:L/A:L` ≈ 7.2 (High). The scope change (`S:C`) reflects that a successful request reaches the host's internal network / cloud metadata service from the WordPress server. `PR:H` because configuring a site requires `manage_options`; see VULN-002 for the lower-privilege variant.
- **Category:** OWASP A10:2021 – Server-Side Request Forgery. WP classification: unsafe remote request (`wp_remote_get` without URL validation).
- **Location:** `plugin/includes/extractor/class-extractor-http.php:74-82` (the single fetch chokepoint). Source URL stored at `plugin/includes/db/class-extract-sites-repo.php` (`site_url`, sanitized only with `esc_url_raw`).
- **Description:** The extractor's one HTTP entry point uses `wp_remote_get()` — **not** `wp_safe_remote_get()` — with `redirection => 5` and no validation of the target host. WordPress's `wp_remote_get()` does **not** block requests to private/loopback/link-local addresses; only `wp_safe_remote_get()` (which sets `reject_unsafe_urls => true` → `wp_http_validate_url()`) applies any filtering. There is no scheme allowlist and no check against `127.0.0.0/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254.0.0/16` (AWS/GCP/Azure metadata `169.254.169.254`), or IPv6 `::1` / `fd00::/8`. SSL verification is **not** weakened (`sslverify` left at the WP default of `true`, verified — no `sslverify => false` anywhere in first-party code).
- **Proof of exploitability:** An operator (`manage_options`) creates an extract site whose `site_url` is `http://169.254.169.254/latest/meta-data/iam/security-credentials/` (or an internal host). When the scheduler fires — in the **unauthenticated** WP-Cron / Action Scheduler context, with no user in the loop — the server fetches that URL and stores/returns the body. On a cloud host this can disclose IAM credentials; on any host it enables internal port-scanning and access to services that trust the server's source IP. Trigger is gated behind admin configuration, which bounds *who* can set it up, but admin-configured SSRF still matters on multi-admin sites, hardened hosts, and when chained with VULN-002 (where the *merchant*, not the admin, controls the fetched URL).
- **Affected code:**
  ```php
  // class-extractor-http.php:74-82
  $response = wp_remote_get(
      $url,
      array(
          'headers'    => $args['headers'],
          'timeout'    => (int) $args['timeout'],
          'user-agent' => (string) $args['user_agent'],
          'redirection'=> 5,
      )
  );
  ```
- **Recommended fix:**
  1. Switch to `wp_safe_remote_get()` (applies `wp_http_validate_url()`).
  2. Add an explicit scheme allowlist before the call: reject anything where `wp_parse_url( $url, PHP_URL_SCHEME )` is not `http`/`https`.
  3. Because `wp_http_validate_url()` has known gaps (no IPv6 private ranges; DNS-rebinding / decimal-IP / redirect bypasses), resolve the host with `gethostbynamel()` and reject any resolved IP in a private/loopback/link-local range; reject `169.254.169.254` explicitly.
  4. Set `'redirection' => 0` (see VULN-002) so a redirect cannot bounce past the validation.
  Apply the same validation at save time in the extract-sites repo: `esc_url_raw( $url, array( 'http', 'https' ) )` plus the private-IP rejection.
- **Confidence:** **High** (data flow read and confirmed; the absence of any host validation is verified).

---

## VULN-002 — SSRF amplification via recursive sitemap `<loc>` fetch

- **Severity:** **High**
- **CVSS 3.1 (estimated):** `AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:L/A:L` ≈ 8.3 (High). `PR:L` rather than `PR:H` because, once an operator adds a *legitimate* merchant site, the **merchant's server response** — not the admin — controls which URL is fetched next. A malicious or compromised merchant feed therefore drives the SSRF without further admin action.
- **Category:** OWASP A10:2021 – SSRF. WP classification: unvalidated recursive remote request.
- **Location:** `plugin/includes/extractor/class-extractor-generic.php:140-145` (also the discovered product URLs fetched at `class-extractor-generic.php:292-296`).
- **Description:** `parse_sitemap()` reads `<loc>` values directly out of the merchant's sitemap XML and fetches them recursively via `Supcomp_Extractor_Http::get( $loc_str )` whenever the loc string contains the substring `"product"` or `"shop"`. The fetched URL is entirely merchant-controlled, inherits VULN-001's lack of host validation, and the recursion has no depth cap or global fetch budget (only an output-count cap, `URL_DISCOVERY_CAP`, applied *after* fetching).
- **Proof of exploitability:** A merchant the operator has legitimately added (or any party who can MITM/poison DNS for that merchant's host) returns a sitemap-index containing `<loc>http://169.254.169.254/latest/meta-data/...product...</loc>`. The substring filter passes (`product`), and the server fetches the metadata endpoint in the unauthenticated cron context. A nested chain of sitemap-index documents also produces unbounded recursive fetches (request amplification / worker exhaustion).
- **Affected code:**
  ```php
  // class-extractor-generic.php:140-145
  if ( $loc_str !== '' && ( strpos( $loc_str, 'product' ) !== false || strpos( $loc_str, 'shop' ) !== false ) ) {
      $response = Supcomp_Extractor_Http::get( $loc_str );
      if ( ! is_wp_error( $response ) && $response['status'] === 200 ) {
          $urls = array_merge( $urls, self::parse_sitemap( $response['body'], true ) );
      }
  }
  ```
- **Recommended fix:** Before fetching any `<loc>`: require `http`/`https`, reject private/loopback/link-local IPs (the VULN-001 helper), **and** constrain the host to the same registrable domain as the configured `site_url` (the only legitimate case for a store's own sitemap). Add a recursion-depth cap (e.g. 2) and a global per-run fetch budget.
- **Confidence:** **High** (verified by reading `parse_sitemap` and the HTTP client).

---

## VULN-003 — Click-out redirect destination not scheme-validated before `wp_redirect`

- **Severity:** **Medium**
- **CVSS 3.1 (estimated):** `AV:N/AC:L/PR:N/UI:R/S:C/C:N/I:L/A:N` ≈ 4.7 (Medium), conditional — see exploitability.
- **Category:** OWASP A01:2021 – Broken Access Control (open redirect) / CWE-601. WP classification: `wp_redirect` of an unvalidated stored destination.
- **Location:** `plugin/includes/public/class-redirect.php:100-111`. Destination originates from `source_product_url`, stored without URL sanitization in `plugin/includes/db/class-offers-repo.php:506` (`csv_columns()`), optionally transformed by the affiliate-template engine (VULN-006).
- **Description:** The anonymous `/out/{offer_id}` handler resolves a destination from the stored `source_product_url` (and merchant template) and passes it to `wp_redirect()` with **no** `esc_url_raw()` and **no** scheme allowlist. `wp_redirect()` runs `wp_sanitize_redirect()` internally (strips control characters) but does **not** restrict scheme or host — that is `wp_safe_redirect()`'s job, and the code deliberately uses `wp_redirect()` because affiliate links are off-site (comment at `class-redirect.php:107-108`).
- **Proof of exploitability:** This is **not** a classic request-time open redirect — the destination is not taken from the query string; `offer_id` is integer-cast and the offer must exist and be visible. Exploitation requires a malicious destination to already be **stored**, which can happen via VULN-002 (a malicious merchant feed sets `source_product_url` to an attacker phishing host) followed by the offer surviving the pending-queue curation gate. The "no auto-publish" curation rule (every offer is operator-reviewed) is the mitigating control; an operator who approves without scrutinizing each URL would publish a `/out/{id}` link on their own domain that redirects visitors to an arbitrary external site (phishing / affiliate-cloaking). A `javascript:`/`data:` scheme in a `Location` header is **not** executed by browsers, so this is an open-redirect-to-arbitrary-host issue, not stored XSS.
- **Affected code:**
  ```php
  // class-redirect.php:100-111
  $destination = self::resolve_affiliate_url( $offer );
  if ( ! $destination ) { status_header( 410 ); /* ... */ }
  nocache_headers();
  wp_redirect( $destination, 302, 'Supplement-Compare' );
  exit;
  ```
  ```php
  // class-offers-repo.php:506 — no URL sanitization on ingest
  'source_product_url' => self::trim_to( self::s( $row, 'source_product_url' ), 512 ),
  ```
- **Recommended fix:** Sanitize at the sink with an explicit scheme allowlist: `$destination = esc_url_raw( $destination, array( 'http', 'https' ) );` and 410 if it comes back empty. Additionally sanitize on ingest — run `esc_url_raw( …, array('http','https') )` on `source_product_url` in `csv_columns()` (mirroring how `coa_url` is already handled at `class-offers-repo.php:249`).
- **Confidence:** **High** that the sanitization gap exists; **Medium** on exploitability (depends on a malicious URL reaching storage and passing curation).

---

## VULN-004 — No response-size limit on extractor fetch (memory-exhaustion DoS)

- **Severity:** **Medium**
- **CVSS 3.1 (estimated):** `AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:N/A:H` ≈ 6.5 (Medium).
- **Category:** OWASP A05:2021 – Security Misconfiguration / CWE-400 Uncontrolled Resource Consumption.
- **Location:** `plugin/includes/extractor/class-extractor-http.php:74-110`, body read at line 109; parsed at `class-extractor-http.php:139` (`json_decode`) and `class-extractor-generic.php` (`simplexml_load_string` / `DOMDocument::loadHTML`).
- **Description:** No `limit_response_size` argument is passed to `wp_remote_get`, so a hostile or compromised merchant endpoint can return an arbitrarily large body that is fully buffered into memory and then JSON/XML/HTML-parsed, exhausting PHP memory and killing the cron worker (and potentially the PHP-FPM pool).
- **Proof of exploitability:** A merchant server (the URL operator-configured, content merchant-controlled — same trust boundary as VULN-002) responds to `/products.json` or a sitemap with a multi-gigabyte body. The unauthenticated cron worker OOMs. No authentication is needed on the attacker's side once a site is configured.
- **Affected code:**
  ```php
  // class-extractor-http.php:74-82 — no limit_response_size
  $response = wp_remote_get( $url, array( 'headers' => …, 'timeout' => …, 'redirection' => 5 ) );
  // …:109
  'body' => wp_remote_retrieve_body( $response ),
  ```
- **Recommended fix:** Add `'limit_response_size' => 16 * MB_IN_BYTES` (or similar) to the request args; the cURL transport honors it and aborts oversized transfers. Optionally gate parsing on the `Content-Type` header (`application/json` for `get_json`, `text/html`/`application/xml` for the generic handler) before decoding.
- **Confidence:** **High** (verified absent).

---

## VULN-005 — JSON-LD emitted with `JSON_UNESCAPED_SLASHES` permits `</script>` breakout

- **Severity:** **Low**
- **CVSS 3.1 (estimated):** `AV:N/AC:L/PR:H/UI:R/S:C/C:L/I:L/A:N` ≈ 4.5 (Low/Medium boundary) — bounded by requiring operator-authored content.
- **Category:** OWASP A03:2021 – Injection (XSS) / CWE-79. WP classification: improper output escaping in an inline `<script>` context.
- **Location:** `plugin/includes/public/class-canonical-page.php:87` (echo) and `:221` (encode). Payload values from `$canonical->display_name` (line 193) and `$canonical->seo_content` (line 198), both operator-authored.
- **Description:** `schema_jsonld()` builds the payload with `wp_json_encode( $payload, JSON_UNESCAPED_SLASHES )` and echoes it raw inside `<script type="application/ld+json">…</script>` in `wp_head`. Without `JSON_UNESCAPED_SLASHES`, `/` is encoded as `\/`, so a literal `</script>` in operator content becomes the harmless `<\/script>`. **With** the flag, `/` is left intact, so a `</script>` sequence in `display_name` or `seo_content` closes the script element early and any following markup is parsed as HTML → stored XSS on a public page.
- **Proof of exploitability:** A user with `manage_options` (the only role that edits canonical products) sets `display_name` to `Magnesium</script><script>alert(document.cookie)</script>`. The JSON-LD breaks out and the injected script executes for every public visitor of that canonical page. Because only a full admin can author the field today, this is effectively self-XSS / defense-in-depth — but the output is **public**, so it becomes materially more serious if a lower-privileged editor role is ever granted canonical editing.
- **Affected code:**
  ```php
  // class-canonical-page.php:85-88
  $jsonld = self::schema_jsonld( $canonical, $ingredient, $aggregate );
  if ( $jsonld ) {
      echo '<script type="application/ld+json">' . $jsonld . '</script>' . "\n";
  }
  // …:221
  $json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
  ```
- **Recommended fix:** Drop `JSON_UNESCAPED_SLASHES` for the inline-`<script>` payload (so `</script>` cannot survive), or add `JSON_HEX_TAG` to the flags, or `str_replace( '</', '<\/', $jsonld )` before echo. The static-file exporter's use of `JSON_UNESCAPED_SLASHES` is fine (not an inline `<script>` context); this fix is specific to the in-`<head>` block.
- **Confidence:** **High** (verified by reading both the encode and the echo).

---

## VULN-006 — Affiliate-URL template engine performs no output-scheme validation

- **Severity:** **Low**
- **Category:** OWASP A03:2021 – Injection (defense-in-depth) / CWE-20. 
- **Location:** `plugin/includes/class-affiliate-url-template.php:44-77` (`apply`) and `:83-107` (`validate`).
- **Description:** `apply()` performs placeholder substitution with `strtr()` — **safe**: no `eval`, no `preg_replace` `/e`, no callback, so there is no code-injection primitive (verified). However, `validate()` checks only that placeholder *names* are known (`{product_url}`, `{path}`, etc.); it never validates the *resulting* scheme. A template such as `javascript:foo//{product_url}` produces a `javascript:`-scheme string that then becomes the redirect destination (VULN-003) and the `href` of front-end buy buttons.
- **Proof of exploitability:** Requires `manage_options` to set a malicious template. In the redirect path the `javascript:` URL is inert (Location header). In the front-end (`frontend.js`), `buy_url` is `/out/{id}` (safe — the template output never reaches the client `href` directly), so there is no current execution path; this is defense-in-depth against future use of the raw template output.
- **Affected code:**
  ```php
  // class-affiliate-url-template.php:73-76
  $result = strtr( $template, $vars );
  $result = self::fix_query_separator( $template, $product_url, $result );
  return $result; // no scheme check on the produced URL
  ```
- **Recommended fix:** Have `validate()` reject templates whose literal prefix is not `http://`/`https://`, and/or scheme-check `apply()`'s output. The VULN-003 sink fix (`esc_url_raw(..., ['http','https'])` before redirect) also neutralizes this in the redirect path.
- **Confidence:** **High** (substitution verified safe; scheme check verified absent).

---

## VULN-007 — CSV validator accumulates unbounded rows (memory-exhaustion DoS)

- **Severity:** **Low**
- **Category:** CWE-400 Uncontrolled Resource Consumption.
- **Location:** `plugin/includes/import/class-csv-validator.php:115-181`; `plugin/includes/import/class-canonical-csv-importer.php:179-192`.
- **Description:** Both CSV readers loop `fgetcsv` with no upper bound, accumulating every data row into an in-memory array before processing. A very large uploaded CSV exhausts PHP memory.
- **Proof of exploitability:** Requires `manage_options` + a valid nonce (upload handlers are correctly gated). Blast radius is therefore an admin uploading (or being tricked into uploading) an oversized file; it fails the import rather than corrupting data. Limited, hence Low.
- **Affected code:**
  ```php
  // class-csv-validator.php (read loop)
  while ( ( $line = fgetcsv( $fh ) ) !== false ) {
      // …
      $result['rows'][ $row_num ] = $row; // unbounded accumulation
  }
  ```
- **Recommended fix:** Enforce a max row count (e.g. 100k) and bail with a clear error; the importer already supports batched ingestion (`begin_run` / `ingest_rows_into_run`), so the validator is the only unbounded accumulator. Rely on WP `upload_max_filesize` / `post_max_size` for the byte ceiling.
- **Confidence:** **High** (cap verified absent).

---

## VULN-008 — XML entity-expansion exposure parsing merchant sitemaps

- **Severity:** **Low**
- **Category:** OWASP A05:2021 / CWE-776 (XML entity expansion) / CWE-611 (XXE).
- **Location:** `plugin/includes/extractor/class-extractor-generic.php:126-131` (`simplexml_load_string`).
- **Description:** Merchant-controlled sitemap XML is parsed with `simplexml_load_string()` without explicitly passing `LIBXML_NONET` or disabling entity loading. On libxml ≥ 2.9 / PHP ≥ 8, external entity loading is disabled by default, so XXE is largely mitigated by the runtime — but the code does not enforce it, and "billion-laughs" internal entity expansion is not categorically blocked by version defaults. `DOMDocument::loadHTML()` (used elsewhere) uses the HTML parser, which does not process DTD entities — low risk.
- **Proof of exploitability:** A merchant server returns a sitemap with a nested-entity payload; on a permissively-configured libxml build this expands to exhaust memory/CPU in the cron worker. Modern defaults mitigate; hence Low / Medium confidence.
- **Affected code:**
  ```php
  // class-extractor-generic.php:126-127
  libxml_use_internal_errors( true );
  $root = simplexml_load_string( $xml_text );
  ```
- **Recommended fix:** Pass `LIBXML_NONET` explicitly (block external fetches), do **not** pass `LIBXML_NOENT`, and bound input size via VULN-004's `limit_response_size`. On older libxml, wrap the parse with `libxml_disable_entity_loader( true )`.
- **Confidence:** **Medium** (runtime-dependent).

---

## VULN-009 — CSV upload has no server-side MIME/extension verification

- **Severity:** **Low**
- **Category:** OWASP A04:2021 / CWE-434 (unrestricted upload) — partial.
- **Location:** `plugin/includes/admin/class-import-screen.php:224-233`; `class-canonical-products-screen.php:486-491`; `class-ingredients-screen.php:380-385`.
- **Description:** All three upload handlers correctly gate on `is_uploaded_file( $_FILES[...]['tmp_name'] )` and pass only the PHP-provided tmp path forward (never a path derived from `$_FILES['name']`), so there is **no** path-traversal / LFI and nothing is moved into a web-served directory. There is no MIME/extension enforcement (the `accept=".csv"` attribute is client-only); content validity is enforced downstream by the CSV validator/importers, which fail closed on non-CSV input.
- **Proof of exploitability:** Requires `manage_options` + nonce. A non-CSV upload is read and rejected by the parser; it is never executed. Risk is low.
- **Affected code:**
  ```php
  // class-canonical-products-screen.php:486-491
  if ( empty( $_FILES['csvfile']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csvfile']['tmp_name'] ) ) { /* … */ }
  $report = Supcomp_Canonical_CSV_Importer::import_canonical_products( $_FILES['csvfile']['tmp_name'] );
  ```
- **Recommended fix:** Optional hardening — call `wp_check_filetype_and_ext()` or verify the `finfo` MIME is `text/csv` / `text/plain` before parsing.
- **Confidence:** **High** (no traversal / web-root write present; verified).

---

## VULN-010 — `client_ip()` trusts spoofable `X-Forwarded-For`

- **Severity:** **Informational**
- **Category:** CWE-348 Use of Less Trusted Source.
- **Location:** `plugin/includes/public/class-redirect.php:169-181`.
- **Description:** `client_ip()` returns the leftmost `HTTP_X_FORWARDED_FOR` value, which any client can set. The value is used **only** as input to a salted SHA-256 (`ip_hash`) for rapid-fire bot detection and click dedup; it never reaches a SQL string or an output sink (verified — the click insert goes through the prepared `Supcomp_Clicks_Repo`). The code comments acknowledge the trade-off.
- **Impact:** An attacker can rotate spoofed IPs to evade rapid-fire throttling and fragment click-dedup. No injection.
- **Recommended fix:** Prefer `REMOTE_ADDR` unless a trusted-proxy allowlist is configured. No security change strictly required.
- **Confidence:** **High.**

---

## VULN-011 — `current_url()` reflects all `$_GET` keys into the page

- **Severity:** **Informational**
- **Category:** CWE-79 (reflected XSS) — **not** exploitable as written.
- **Location:** `plugin/includes/admin/class-pending-queue-screen.php:320-323`; `plugin/includes/admin/class-active-offers-screen.php:213-216`.
- **Description:** `array_merge( array('page'=>…), $_GET )` pulls every query param and feeds it to `add_query_arg`. `array_map('sanitize_text_field', …)` sanitizes the *values* but not the *keys*. The result is emitted only inside `value="<?php echo esc_attr( self::current_url() ); ?>"` — `esc_attr` neutralizes attribute/HTML breakout, so there is no XSS. Flagged because the unfiltered-`$_GET`-into-`add_query_arg` pattern is the classic WP reflected-XSS source and relies entirely on the output escaping staying correct.
- **Recommended fix:** Build the return URL from an explicit allowlist of expected params (`page`, `s`, `merchant_id`, `ingredient_id`, `offset`, …) rather than the whole `$_GET`, removing key-pollution entirely.
- **Confidence:** **High** (output is escaped; verified not exploitable).

---

## VULN-012 — `maybe_upgrade()` runs `dbDelta` on anonymous front-end requests

- **Severity:** **Informational**
- **Category:** Robustness / performance, not a vulnerability.
- **Location:** `plugin/includes/class-installer.php:110-114`, invoked unconditionally on `plugins_loaded` from `class-plugin.php:28`.
- **Description:** `get_option( SCHEMA_OPTION ) !== self::SCHEMA_VERSION` triggers a full `dbDelta` run when versions differ, in the public request path. `dbDelta` is idempotent and data-independent, so this is safe — noted only because privileged schema work executes on uncontrolled requests.
- **Recommended fix:** Optional — gate behind `is_admin()` or a `did_action` guard. No security action needed.
- **Confidence:** **Medium.**

---

## VULN-013 — Bundled dependency: Action Scheduler 3.9.3

- **Severity:** **Informational**
- **Category:** OWASP A06:2021 – Vulnerable and Outdated Components.
- **Location:** `plugin/vendor/action-scheduler/` (version `3.9.3`, confirmed at `vendor/action-scheduler/action-scheduler.php:8`).
- **Description:** Action Scheduler is the well-maintained WooCommerce/Automattic job-queue library, here at 3.9.3 (a recent release). I did **not** line-audit the ~120 vendor files, and I did **not** query a live CVE database from this environment, so I make **no** claim of a specific CVE and have **not** invented one. As of the assistant's knowledge cutoff I am not aware of an unpatched high-severity CVE in this version, but this must be confirmed against a live source.
- **Recommended fix:** Periodically reconcile the bundled version against the upstream release/security notes (https://github.com/woocommerce/action-scheduler) and bump when security releases ship. Verify current CVE status via the WPScan / NVD / GitHub advisory databases before each plugin release.
- **Confidence:** **High** (version identification); CVE status: **not verified** (no live lookup performed).

---

## 5. Positive confirmations (invariants verified, no finding)

These were checked specifically and are **correctly** implemented:

- **No SQL injection anywhere.** Every `$wpdb` call carrying a non-literal value uses `$wpdb->prepare()`; the only string fragments interpolated into SQL are `$wpdb->prefix`-derived table names and hardcoded literal clause snippets. ORDER BY is allowlist-mapped (`class-offers-repo.php:159-168`, `class-merchants-repo.php`); LIKE uses `esc_like()` (`class-offers-repo.php:144`); dynamic `IN()` lists generate matched placeholders (`class-offers-repo.php:307`, `class-stale-detector.php:39-54`). *Verified.*
- **Auth + nonce on every state-changing action.** All ~15 admin POST/admin-post handlers verify `current_user_can` and a **specific** nonce action string before mutating; status changes use id-bound per-row nonces. *Verified (full table in the cluster audit).*
- **No unauthenticated handlers.** Exactly one AJAX action (`wp_ajax_supcomp_preview_affiliate_url`, `class-merchants-screen.php:31`), privileged + nonce-gated; **no** `wp_ajax_nopriv_`, **no** `register_rest_route`, **no** `__return_true` permission callbacks. *Verified.*
- **No dangerous sinks.** No `eval` / `create_function` / `assert` / `unserialize` / `extract($_*)` / `system` / `exec` / `shell_exec` / `popen` / `preg_replace` `/e` / `$$` / `new $var` / variable `include` from user input in first-party code. `raw_attributes_json` is parsed with `json_decode(..., true)` (no object injection) with recursion depth capped at 6. *Verified by grep + read.*
- **No hardcoded secrets, no weak security RNG.** No API keys/passwords/tokens; the only RNG is `random_bytes(6)` for a non-security run-id label. No `md5`/`sha1` for password-like secrets. *Verified.*
- **No info disclosure.** No `error_reporting`, `ini_set`, `var_dump`, `print_r`, `var_export` in first-party code. *Verified.*
- **ABSPATH guard on every file;** `uninstall.php` correctly guards on `WP_UNINSTALL_PLUGIN` and is a deliberate no-op. *Verified.*
- **Deletion service** (cascade hard-delete) has both call sites (`class-deletion-admin.php`, `class-cleanup-screen.php`) enforcing cap + nonce; all queries prepared; ids `absint`'d; no IDOR (single-operator model); dynamic table names from `$wpdb->prefix` only. *Verified.*
- **Project security invariants honored:** public JSON exporter emits `buy_url` = `/out/{id}` and explicitly excludes affiliate URLs, source URLs, `description`, `raw_attributes_json`, `operator_notes`, ratings, and stock counts (documented at `class-json-exporter.php:17-20` and confirmed in `offer_entry`/`canonical_entry`). `frontend.js` escapes every JSON-derived value via `escapeHtml`/`escapeAttr` before `innerHTML`; no `document.write`/`insertAdjacentHTML`/jQuery `.html()`. `merchants-preview.js` escapes all sinks. *Verified.*
- **Class-loading boundary** (per the project memory note) is respected: front-end-reachable classes (`Supcomp_Redirect`, `Supcomp_Json_Exporter`, `Supcomp_Shortcode`, `Supcomp_Deletion_Service`) load in `load_domain()`, admin-only classes load behind `is_admin()`. *Verified.*

---

## 6. Methodology notes

- Files were clustered and each cluster read end-to-end with a security-specific lens (input handling, output escaping, SQLi, authz/CSRF, file ops, remote requests, deserialization/codegen, secrets, crypto, XSS, capability bypass, hook security, dependencies). Every Critical/High candidate was re-read directly by the lead auditor at the cited line numbers before inclusion.
- Severity uses CVSS 3.1 reasoning; vectors are given for High and above and estimated for others. Where exploitability depends on runtime context (user role reaching a path, what content reaches storage), this is stated rather than assumed worst- or best-case.
- No CVE numbers are asserted. The single vendor dependency is identified by version with an explicit "not verified against a live CVE source" caveat.
