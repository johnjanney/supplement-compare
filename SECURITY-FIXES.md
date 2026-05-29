# SECURITY-FIXES.md — Prioritized Remediation Plan

Companion to `SECURITY-AUDIT.md`. Fixes are ordered by **(severity) × (exploitability) × (1 / implementation cost)** and grouped into Immediate / Short-term / Backlog. Patches use WordPress-native functions and match the surrounding code style. Line numbers reference the audited build (v1.21.1).

> **Process note (per CLAUDE.md):** this audit produced report artifacts only — no plugin code was modified, so no version bump or CHANGELOG entry was made. Each fix below is a functional change: when you apply it, bump the version in lockstep (the four places in `CLAUDE.md`) and add a `CHANGELOG.md` entry. The SSRF fixes (Immediate group) are a single security-hardening MINOR bump candidate.

---

## Priority ranking

| Rank | ID | Severity | Exploitability | Cost | Group |
|------|----|----------|----------------|------|-------|
| 1 | VULN-001 + VULN-002 | High | Merchant-content-driven (002) / admin-config (001) | Medium (shared helper) | **Immediate** |
| 2 | VULN-004 | Medium | Merchant-content-driven | Low (one arg) | **Immediate** |
| 3 | VULN-003 | Medium | Conditional (curation-gated) | Low (one line + ingest) | **Immediate** |
| 4 | VULN-005 | Low | Admin-authored, public output | Low (one flag) | Short-term |
| 5 | VULN-006 | Low | Admin-authored, defense-in-depth | Low | Short-term |
| 6 | VULN-008 | Low | Runtime-dependent | Low | Short-term |
| 7 | VULN-007 | Low | Admin-only | Low | Backlog |
| 8 | VULN-009 | Low | Admin-only | Low | Backlog |
| 9 | VULN-011 | Info | Not exploitable (escaped) | Low | Backlog |
| 10 | VULN-010, VULN-012 | Info | n/a | Low | Backlog |
| 11 | VULN-013 | Info | Dependency hygiene | n/a | Backlog (recurring) |

---

## IMMEDIATE — fix before next deploy

These close the SSRF chain (the only High findings) and the directly-related DoS/redirect gaps. They share one new helper, so implement together.

### Step 0 — Add a shared URL-safety helper

New static helper (suggested home: `Supcomp_Extractor_Http`, since both the fetch and the sitemap recursion live in the extractor). Rejects non-http(s) schemes and hosts that resolve to private/loopback/link-local addresses.

```php
// class-extractor-http.php — new method
/**
 * Validate an outbound URL before fetching: http/https only, and the host
 * must not resolve to a private, loopback, or link-local address. Returns
 * true on safe, WP_Error otherwise. Closes the SSRF surface (VULN-001/002).
 */
public static function is_safe_url( $url ) {
    $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
    if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
        return new WP_Error( 'supcomp_bad_scheme', 'Only http/https URLs may be fetched.' );
    }
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! $host ) {
        return new WP_Error( 'supcomp_bad_host', 'URL has no host.' );
    }
    // Resolve and reject private/reserved ranges (covers IPv4; add AAAA if needed).
    $ips = gethostbynamel( $host );
    if ( empty( $ips ) ) {
        return new WP_Error( 'supcomp_unresolvable', 'Host did not resolve.' );
    }
    foreach ( $ips as $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return new WP_Error( 'supcomp_private_ip', 'Host resolves to a non-public address.' );
        }
    }
    return true;
}
```

> Note: `gethostbynamel()` + a separate connect is theoretically DNS-rebinding-able. For most self-hosted WordPress this helper plus `wp_safe_remote_get` is a proportionate mitigation. A fully rebinding-proof fix requires pinning the resolved IP into the request (cURL `CURLOPT_RESOLVE`), which is a larger change — track separately if the threat model warrants it.

### VULN-001 — Harden the extractor fetch

```diff
--- a/plugin/includes/extractor/class-extractor-http.php
+++ b/plugin/includes/extractor/class-extractor-http.php
@@ public static function get( $url, array $args = array() ) {
+        // SSRF guard (VULN-001): reject non-http(s) and private/reserved hosts.
+        $safe = self::is_safe_url( $url );
+        if ( is_wp_error( $safe ) ) {
+            return $safe;
+        }
+
         $last_error = null;
         $max        = max( 0, (int) $args['max_retries'] );
@@
-            $response = wp_remote_get(
+            $response = wp_safe_remote_get(
                 $url,
                 array(
                     'headers'    => $args['headers'],
                     'timeout'    => (int) $args['timeout'],
                     'user-agent' => (string) $args['user_agent'],
-                    'redirection'=> 5,
+                    'redirection'=> 0,                 // VULN-002: do not follow redirects blindly
+                    'limit_response_size' => 16 * MB_IN_BYTES, // VULN-004: cap body size
                 )
             );
```

If redirect-following is genuinely needed for some merchants, handle 3xx manually: read the `Location` header, run `self::is_safe_url()` on it, and re-issue — never let the transport follow unvalidated.

### VULN-002 — Validate every sitemap `<loc>` before recursing

```diff
--- a/plugin/includes/extractor/class-extractor-generic.php
+++ b/plugin/includes/extractor/class-extractor-generic.php
@@ private static function parse_sitemap( $xml_text, $trust_all = false ) {
             foreach ( $child_sitemaps as $loc ) {
                 $loc_str = trim( (string) $loc );
-                if ( $loc_str !== '' && ( strpos( $loc_str, 'product' ) !== false || strpos( $loc_str, 'shop' ) !== false ) ) {
+                if ( $loc_str !== ''
+                    && ( strpos( $loc_str, 'product' ) !== false || strpos( $loc_str, 'shop' ) !== false )
+                    && true === Supcomp_Extractor_Http::is_safe_url( $loc_str )    // VULN-002
+                    && self::same_registrable_domain( $loc_str, $base_url )         // see note
+                ) {
                     $response = Supcomp_Extractor_Http::get( $loc_str );
                     if ( ! is_wp_error( $response ) && $response['status'] === 200 ) {
                         $urls = array_merge( $urls, self::parse_sitemap( $response['body'], true ) );
                     }
                 }
             }
```

**Scope note (architectural, ~1–2 hrs):** `parse_sitemap()` currently has no reference to the originating `site_url`, so the `same_registrable_domain()` constraint requires threading the base URL into the method (and adding a recursion-depth parameter, capped at e.g. 2, plus a per-run fetch counter). This is the most involved of the immediate fixes. At minimum apply `is_safe_url()` (the private-IP guard) even before the domain-pinning is wired in — that alone blocks the metadata-endpoint pivot.

### VULN-004 — Response-size cap

Covered by the `limit_response_size` arg added in the VULN-001 diff above. No separate change.

### VULN-003 — Scheme-validate the click-out destination

Sink fix (one line) + ingest hardening (one line):

```diff
--- a/plugin/includes/public/class-redirect.php
+++ b/plugin/includes/public/class-redirect.php
@@
         $destination = self::resolve_affiliate_url( $offer );
-        if ( ! $destination ) {
+        // VULN-003: only ever redirect to an http(s) URL.
+        $destination = $destination ? esc_url_raw( $destination, array( 'http', 'https' ) ) : '';
+        if ( ! $destination ) {
             status_header( 410 );
             nocache_headers();
             wp_die( esc_html__( 'No destination URL is available for this offer.', 'supplement-compare' ), '', array( 'response' => 410 ) );
         }
```

```diff
--- a/plugin/includes/db/class-offers-repo.php
+++ b/plugin/includes/db/class-offers-repo.php   (private static function csv_columns)
-            'source_product_url'          => self::trim_to( self::s( $row, 'source_product_url' ), 512 ),
-            'source_variant_url'          => self::trim_to_nullable( self::s( $row, 'source_variant_url' ), 512 ),
+            'source_product_url'          => self::trim_to( esc_url_raw( self::s( $row, 'source_product_url' ), array( 'http', 'https' ) ), 512 ),
+            'source_variant_url'          => self::trim_to_nullable( esc_url_raw( self::s( $row, 'source_variant_url' ), array( 'http', 'https' ) ), 512 ),
```

---

## SHORT-TERM — this sprint

### VULN-005 — Stop `</script>` breakout in JSON-LD

```diff
--- a/plugin/includes/public/class-canonical-page.php
+++ b/plugin/includes/public/class-canonical-page.php   (schema_jsonld)
-        $json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
+        // VULN-005: keep slash-escaping ON inside an inline <script> so a
+        // literal "</script>" in operator content cannot close the element.
+        $json = wp_json_encode( $payload );
         return $json === false ? '' : $json;
```

(Equivalent alternative: keep `JSON_UNESCAPED_SLASHES` but add `JSON_HEX_TAG`, i.e. `wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )`.)

### VULN-006 — Validate template output scheme

```diff
--- a/plugin/includes/class-affiliate-url-template.php
+++ b/plugin/includes/class-affiliate-url-template.php   (validate)
         $unknown = array_diff( $used, self::KNOWN_VARS );
         if ( ! empty( $unknown ) ) { /* … unchanged … */ }
+        // VULN-006: the literal portion of the template must start with http(s).
+        $literal_prefix = strstr( $template, '{', true );
+        $literal_prefix = false === $literal_prefix ? $template : $literal_prefix;
+        if ( '' !== trim( $literal_prefix )
+            && ! preg_match( '#^https?://#i', ltrim( $literal_prefix ) ) ) {
+            return new WP_Error( 'supcomp_bad_template_scheme', __( 'Template must begin with http:// or https://', 'supplement-compare' ) );
+        }
         return true;
```

(The VULN-003 `esc_url_raw` sink fix already neutralizes a malicious template in the redirect path; this adds an authoring-time guard.)

### VULN-008 — Harden sitemap XML parsing

```diff
--- a/plugin/includes/extractor/class-extractor-generic.php
+++ b/plugin/includes/extractor/class-extractor-generic.php   (parse_sitemap)
         libxml_use_internal_errors( true );
-        $root = simplexml_load_string( $xml_text );
+        // VULN-008: block network/external entities; cap input via the
+        // response-size limit in the HTTP client.
+        $root = simplexml_load_string( $xml_text, 'SimpleXMLElement', LIBXML_NONET );
         libxml_clear_errors();
```

---

## BACKLOG — low / informational / dependency hygiene

### VULN-007 — Cap CSV row count

```diff
--- a/plugin/includes/import/class-csv-validator.php
+++ b/plugin/includes/import/class-csv-validator.php   (read loop)
+        $max_rows = (int) apply_filters( 'supcomp_csv_max_rows', 100000 );
         while ( ( $line = fgetcsv( $fh ) ) !== false ) {
+            if ( $row_num > $max_rows ) {
+                $result['errors'][] = sprintf( 'Row limit (%d) exceeded; import aborted.', $max_rows );
+                break;
+            }
             // …
         }
```
Apply the same guard to `class-canonical-csv-importer.php`.

### VULN-009 — Verify CSV MIME server-side

In each upload handler, after the `is_uploaded_file` check:

```php
$check = wp_check_filetype_and_ext( $_FILES['csvfile']['tmp_name'], $filename, array( 'csv' => 'text/csv', 'txt' => 'text/plain' ) );
if ( empty( $check['ext'] ) ) {
    // redirect back with an error notice
}
```

### VULN-011 — Allowlist the return-URL params

Replace `array_merge( array('page'=>…), $_GET )` in `class-pending-queue-screen.php:320` and `class-active-offers-screen.php:213` with an explicit pick of expected keys (`page`, `s`, `merchant_id`, `ingredient_id`, `min_confidence`, `has_canonical`, `orderby`, `order`, `offset`). Removes key-pollution; no behavior change for legitimate use. (Currently not exploitable — output is `esc_attr`'d — so this is hygiene.)

### VULN-010 — Prefer `REMOTE_ADDR`

In `class-redirect.php:169-181`, use `REMOTE_ADDR` unless a trusted-proxy allowlist is explicitly configured. No change if XFF support is required by the operator's hosting.

### VULN-012 — Gate `maybe_upgrade()`

In `class-plugin.php:28`, run `Supcomp_Installer::maybe_upgrade()` on `admin_init` (or behind `is_admin()`) instead of unconditionally on `plugins_loaded`, so anonymous front-end requests skip the option-read/compare. Purely a micro-optimization.

### VULN-013 — Track the Action Scheduler version

Add a recurring pre-release checklist item: compare `vendor/action-scheduler/action-scheduler.php` `Version:` (currently `3.9.3`) against the upstream releases and the WPScan/NVD/GitHub advisory feeds; bump on security releases. No code change today.

---

## Suggested rollout

1. **PR 1 (security MINOR bump):** Step 0 helper + VULN-001/002/003/004. These are the SSRF chain and its blast-radius controls — the only changes that matter before the next public deploy. The sitemap domain-pinning (VULN-002) is the one piece that needs the base-URL threading refactor; ship the `is_safe_url()` guard first if you need to split it.
2. **PR 2 (PATCH):** VULN-005/006/008 — small, self-contained escaping/parsing hardening.
3. **PR 3 (PATCH, opportunistic):** the backlog items, batched.

Each PR: bump version in the four lockstep locations, add the `CHANGELOG.md` entry, and — for PR 1 — note the new SSRF guard in `INSTRUCTIONS.md` (operators may see "Host resolves to a non-public address" errors if they legitimately point the extractor at a LAN test store, which is now blocked by design).
