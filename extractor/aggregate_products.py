"""Aggregate public product data from Shopify / WooCommerce / generic sites to CSV.

One row per variant. Captures sale price, stock status, UPC/GTIN where available,
and raw platform-specific attributes for downstream normalization.

Usage:
    python aggregate_products.py https://store-a.com https://store-b.com -o out.csv
    python aggregate_products.py --input stores.txt -o out.csv

Strategy per site:
    1. Try Shopify  : GET {site}/products.json?limit=250&page=N
    2. Try WooCommerce Store API (public, no auth):
                      GET {site}/wp-json/wc/store/v1/products?per_page=100&page=N
    3. Fallback     : pull product URLs from sitemap.xml / product-sitemap.xml
                      and parse JSON-LD Product schema from each page.

Only public endpoints / public pages are touched. Respect robots.txt manually
before pointing this at a site you don't own.
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import sys
import time
import uuid
from dataclasses import dataclass, asdict
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from typing import Iterator
from xml.etree import ElementTree as ET

import requests
from bs4 import BeautifulSoup
from urllib.parse import urlencode, urlparse, urlunparse, parse_qsl

UA = "Mozilla/5.0 (compatible; product-aggregator/1.0; +https://example.invalid/bot)"
HEADERS = {"User-Agent": UA, "Accept": "application/json, text/html;q=0.9"}
TIMEOUT = 20
SLEEP_BETWEEN_REQUESTS = 0.5
MAX_PAGES = 50
MAX_RETRIES = 2                      # additional attempts after the first
RETRYABLE_STATUSES = {408, 429, 500, 502, 503, 504}
RETRY_AFTER_CAP_SECONDS = 30


STOCK_IN = "in_stock"
STOCK_OUT = "out_of_stock"
STOCK_BACKORDER = "backorder"
STOCK_UNKNOWN = "unknown"


# CSV contract version. Bump whenever a column is added, removed, or its
# semantics change. The plugin's MIN_CSV_SCHEMA_VERSION must move in lockstep
# — see PROJECTBRIEF.md §4.
CSV_SCHEMA_VERSION = "1.0"


@dataclass
class Offer:
    # Fields are listed in the PROJECTBRIEF.md §4 canonical order so the CSV
    # is reviewable. store_name is a script-only extra (operator debug aid);
    # the plugin's validator ignores it.
    export_run_id: str = ""
    exported_at: str = ""

    source: str = ""
    site: str = ""
    source_product_id: str = ""
    source_variant_id: str = ""

    product_title: str = ""
    variant_title: str = ""
    handle: str = ""
    brand: str = ""
    product_type: str = ""

    sku: str = ""
    barcode: str = ""

    regular_price: str = ""
    sale_price: str = ""
    current_price: str = ""
    on_sale: str = ""
    currency: str = ""
    currency_minor_unit: str = ""
    price_source: str = ""

    stock_status: str = ""
    purchasable: str = ""

    source_product_url: str = ""
    source_variant_url: str = ""

    source_created_at: str = ""
    source_updated_at: str = ""

    is_variable_parent: str = ""
    variation_retrieval_status: str = ""

    description: str = ""
    raw_attributes_json: str = ""

    # Script-only extras, not part of the §4 contract.
    store_name: str = ""

    @staticmethod
    def fieldnames() -> list[str]:
        return list(Offer.__dataclass_fields__.keys())


# ---------- Utility helpers ----------

def normalize_site(url: str) -> str:
    if not url.startswith(("http://", "https://")):
        url = "https://" + url
    return url.rstrip("/")


def get(session: requests.Session, url: str, **kwargs) -> requests.Response | None:
    """
    GET with 2 retries on transient failures. Retries on network errors and
    on 408/429/5xx responses; honors Retry-After for 429 (capped). Non-retryable
    responses (4xx other than 408/429) are returned to the caller as-is so
    upstream logic can branch on status_code.
    """
    last_exc: Exception | None = None
    for attempt in range(MAX_RETRIES + 1):
        try:
            r = session.get(url, headers=HEADERS, timeout=TIMEOUT, **kwargs)
        except requests.RequestException as e:
            last_exc = e
            if attempt < MAX_RETRIES:
                time.sleep(2 ** attempt)
                continue
            print(f"  ! request failed {url}: {e}", file=sys.stderr)
            return None

        if r.status_code in RETRYABLE_STATUSES and attempt < MAX_RETRIES:
            delay = 2 ** attempt
            if r.status_code == 429:
                ra = r.headers.get("Retry-After")
                if ra:
                    try:
                        delay = min(int(float(ra)), RETRY_AFTER_CAP_SECONDS)
                    except ValueError:
                        pass
            print(f"  ! {r.status_code} on {url}, retry in {delay}s", file=sys.stderr)
            time.sleep(delay)
            continue

        # Politeness delay only for successful responses — no point pausing
        # after a 404 or other terminal non-success.
        if 200 <= r.status_code < 300:
            time.sleep(SLEEP_BETWEEN_REQUESTS)
        return r

    # Should be unreachable; guard anyway.
    if last_exc:
        print(f"  ! request failed {url}: {last_exc}", file=sys.stderr)
    return None


_HTML_TAG_RE = re.compile(r"<[^>]+>")
_WS_RE = re.compile(r"\s+")


def strip_html(text: str) -> str:
    if not text:
        return ""
    return _WS_RE.sub(" ", _HTML_TAG_RE.sub(" ", text)).strip()


def stringify(value) -> str:
    if value is None:
        return ""
    if isinstance(value, str):
        return value
    if isinstance(value, dict):
        return str(value.get("name") or value.get("@id") or value.get("url") or "")
    if isinstance(value, list):
        return ", ".join(stringify(v) for v in value if v)
    return str(value)


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def json_dump(value) -> str:
    try:
        return json.dumps(value, separators=(",", ":"), ensure_ascii=False, default=str)
    except (TypeError, ValueError):
        return ""


def bool_str(v) -> str:
    if v is True:
        return "true"
    if v is False:
        return "false"
    return ""


def jsonld_type_matches(node_type, target: str) -> bool:
    """JSON-LD @type can be a string or a list of strings."""
    if isinstance(node_type, list):
        return target in node_type
    return node_type == target


# ---------- Shopify ----------

def fetch_shopify_meta(session: requests.Session, site: str) -> tuple[str, str]:
    """
    Shopify's /products.json doesn't include shop name or currency, but
    /meta.json does. Some storefronts return fields at the root; older ones
    nest them under {shop: {...}}. Returns (store_name, currency); each is ""
    if not reachable.
    """
    r = get(session, f"{site}/meta.json")
    if r is None or r.status_code != 200:
        return "", ""
    try:
        meta = r.json()
    except ValueError:
        return "", ""
    if not isinstance(meta, dict):
        return "", ""
    src = meta["shop"] if isinstance(meta.get("shop"), dict) else meta
    return str(src.get("name") or ""), str(src.get("currency") or "")


def try_shopify(session: requests.Session, site: str, run_id: str, exported_at: str) -> list[Offer] | None:
    offers: list[Offer] = []
    saw_any = False
    store_name = ""
    currency = ""

    for page in range(1, MAX_PAGES + 1):
        r = get(session, f"{site}/products.json", params={"limit": 250, "page": page})
        if r is None or r.status_code != 200:
            return None if not saw_any else offers
        try:
            data = r.json()
        except ValueError:
            return None if not saw_any else offers

        batch = data.get("products") or []
        if not batch:
            break
        if not saw_any:
            saw_any = True
            store_name, currency = fetch_shopify_meta(session, site)

        for p in batch:
            offers.extend(_shopify_product_to_offers(p, site, store_name, run_id, exported_at, currency))

        if len(batch) < 250:
            break

    return offers or None


def _shopify_product_to_offers(p: dict, site: str, store_name: str, run_id: str, exported_at: str, currency: str) -> list[Offer]:
    variants = p.get("variants") or []
    handle = p.get("handle", "")
    product_title = p.get("title", "")
    description = strip_html(p.get("body_html", ""))
    vendor = p.get("vendor", "") or ""
    product_type = p.get("product_type", "") or ""
    tags = p.get("tags") or []
    options = p.get("options") or []
    source_created_at = p.get("created_at", "") or ""
    source_updated_at = p.get("updated_at", "") or ""
    source_product_url = f"{site}/products/{handle}" if handle else ""

    raw = {
        "tags": tags if isinstance(tags, list) else [tags],
        "product_type": product_type,
        "options": options,
        "vendor": vendor,
    }

    if not variants:
        return [Offer(
            export_run_id=run_id,
            exported_at=exported_at,
            source="shopify",
            site=site,
            store_name=store_name,
            source_product_id=str(p.get("id", "")),
            product_title=product_title,
            handle=handle,
            brand=vendor,
            product_type=product_type,
            on_sale="false",
            currency=currency,
            currency_minor_unit="2",
            price_source="shopify_variant",
            stock_status=STOCK_UNKNOWN,
            source_product_url=source_product_url,
            source_created_at=source_created_at,
            source_updated_at=source_updated_at,
            variation_retrieval_status="not_applicable",
            description=description,
            raw_attributes_json=json_dump(raw),
        )]

    offers: list[Offer] = []
    for v in variants:
        price = str(v.get("price", "") or "")
        compare_at = str(v.get("compare_at_price", "") or "")
        regular_price, sale_price, on_sale = _shopify_pricing(price, compare_at)

        stock_status = _shopify_stock_status(v)

        variant_title = v.get("title", "") or ""
        # Default-variant Shopify products report "Default Title" — surface as
        # "" so the row looks like a single-variant product downstream.
        is_default_variant = variant_title == "Default Title"
        if is_default_variant:
            variant_title = ""

        variant_raw = dict(raw)
        variant_raw["variant_options"] = {
            f"option{i}": v.get(f"option{i}") for i in range(1, 4) if v.get(f"option{i}")
        }
        # Capture inventory hints used for stock_status, useful downstream.
        variant_raw["inventory"] = {
            "policy": v.get("inventory_policy"),
            "quantity": v.get("inventory_quantity"),
            "available": v.get("available"),
        }

        variant_id = str(v.get("id", ""))
        source_variant_url = (
            f"{source_product_url}?variant={variant_id}"
            if source_product_url and variant_id and not is_default_variant
            else ""
        )

        offers.append(Offer(
            export_run_id=run_id,
            exported_at=exported_at,
            source="shopify",
            site=site,
            store_name=store_name,
            source_product_id=str(p.get("id", "")),
            source_variant_id=variant_id,
            product_title=product_title,
            variant_title=variant_title,
            handle=handle,
            brand=vendor,
            product_type=product_type,
            sku=str(v.get("sku", "") or ""),
            barcode=str(v.get("barcode", "") or ""),
            regular_price=regular_price,
            sale_price=sale_price,
            current_price=price,
            on_sale=on_sale,
            currency=currency,
            currency_minor_unit="2",
            price_source="shopify_variant",
            stock_status=stock_status,
            purchasable=bool_str(v.get("available")),
            source_product_url=source_product_url,
            source_variant_url=source_variant_url,
            source_created_at=source_created_at,
            source_updated_at=source_updated_at,
            variation_retrieval_status="retrieved" if not is_default_variant else "not_applicable",
            description=description,
            raw_attributes_json=json_dump(variant_raw),
        ))

    return offers


def _shopify_pricing(price: str, compare_at: str) -> tuple[str, str, str]:
    if not price:
        return "", "", "false"
    try:
        price_f = float(price)
    except ValueError:
        return price, "", "false"

    if compare_at:
        try:
            compare_f = float(compare_at)
            if compare_f > price_f:
                return compare_at, price, "true"
        except ValueError:
            pass

    return price, "", "false"


def _shopify_stock_status(variant: dict) -> str:
    """
    Map Shopify variant inventory to canonical enum. Backorder when:
      available is true AND inventory_quantity <= 0 AND inventory_policy == "continue".
    """
    available = variant.get("available")
    if available is True:
        qty = variant.get("inventory_quantity")
        policy = variant.get("inventory_policy")
        if isinstance(qty, (int, float)) and qty <= 0 and policy == "continue":
            return STOCK_BACKORDER
        return STOCK_IN
    if available is False:
        return STOCK_OUT
    return STOCK_UNKNOWN


# ---------- WooCommerce (public Store API) ----------

def fetch_woo_store_name(session: requests.Session, site: str) -> str:
    """
    WordPress exposes site name at the REST root: GET /wp-json/ -> {name, ...}.
    Returns "" if not reachable.
    """
    r = get(session, f"{site}/wp-json/")
    if r is None or r.status_code != 200:
        return ""
    try:
        data = r.json()
    except ValueError:
        return ""
    if isinstance(data, dict):
        return str(data.get("name") or "")
    return ""


def try_woocommerce(session: requests.Session, site: str, run_id: str, exported_at: str) -> list[Offer] | None:
    offers: list[Offer] = []
    saw_any = False
    store_name = ""

    for page in range(1, MAX_PAGES + 1):
        r = get(session, f"{site}/wp-json/wc/store/v1/products",
                params={"per_page": 100, "page": page})
        if r is None or r.status_code != 200:
            return None if not saw_any else offers
        try:
            batch = r.json()
        except ValueError:
            return None if not saw_any else offers

        if not isinstance(batch, list) or not batch:
            break
        if not saw_any:
            saw_any = True
            store_name = fetch_woo_store_name(session, site)

        for p in batch:
            offers.extend(_woo_product_to_offers(p, session, site, store_name, run_id, exported_at))

        if len(batch) < 100:
            break

    return offers or None


def _woo_product_to_offers(p: dict, session: requests.Session, site: str, store_name: str,
                            run_id: str, exported_at: str) -> list[Offer]:
    has_options = p.get("has_options", False)
    product_id = str(p.get("id", ""))
    product_title = p.get("name", "") or ""
    handle = p.get("slug", "") or ""
    permalink = p.get("permalink", "") or ""
    description = strip_html(p.get("description", "") or p.get("short_description", "") or "")
    categories = p.get("categories") or []
    tags = p.get("tags") or []
    attributes = p.get("attributes") or []
    prices = p.get("prices") or {}
    minor_unit = prices.get("currency_minor_unit")
    currency_code = prices.get("currency_code", "") or ""

    brand = _woo_extract_brand(p, attributes)
    product_type = ", ".join(c.get("name", "") for c in categories if isinstance(c, dict))

    raw_base = {
        "categories": [c.get("name", "") for c in categories if isinstance(c, dict)],
        "tags": [t.get("name", "") for t in tags if isinstance(t, dict)],
        "attributes": attributes,
        "has_options": has_options,
    }

    if not has_options:
        on_sale_bool = bool(p.get("on_sale", False))
        regular_raw = str(prices.get("regular_price", "") or "")
        sale_raw = str(prices.get("sale_price", "") or "")
        current_raw = str(prices.get("price", "") or "")

        regular_price = _woo_decimal(regular_raw, minor_unit)
        # Only populate sale_price when the merchant flag says a sale is active.
        # Some stores leave a stale sale_price value present when on_sale=false.
        sale_price = (
            _woo_decimal(sale_raw, minor_unit)
            if on_sale_bool and sale_raw and sale_raw != regular_raw
            else ""
        )
        current_price = _woo_decimal(current_raw, minor_unit)

        return [Offer(
            export_run_id=run_id,
            exported_at=exported_at,
            source="woocommerce",
            site=site,
            store_name=store_name,
            source_product_id=product_id,
            product_title=product_title,
            handle=handle,
            brand=brand,
            product_type=product_type,
            sku=str(p.get("sku", "") or ""),
            barcode=_woo_extract_barcode(p),
            regular_price=regular_price,
            sale_price=sale_price,
            current_price=current_price,
            on_sale="true" if on_sale_bool else "false",
            currency=currency_code,
            currency_minor_unit="" if minor_unit is None else str(minor_unit),
            price_source="woo_store_api",
            stock_status=_woo_stock_status(p),
            purchasable=bool_str(p.get("is_purchasable", None)),
            source_product_url=permalink,
            variation_retrieval_status="not_applicable",
            description=description,
            raw_attributes_json=json_dump(raw_base),
        )]

    variation_offers = _woo_fetch_variations(
        session, site, store_name, product_id, p, raw_base, brand, product_type,
        currency_code, minor_unit, run_id, exported_at,
    )
    if variation_offers:
        return variation_offers

    current_raw = str(prices.get("price", "") or "")
    raw_with_range = dict(raw_base)
    raw_with_range["variable_product_fallback"] = True
    raw_with_range["price_range"] = {
        "min": prices.get("price_range", {}).get("min_amount") if isinstance(prices.get("price_range"), dict) else None,
        "max": prices.get("price_range", {}).get("max_amount") if isinstance(prices.get("price_range"), dict) else None,
    }

    return [Offer(
        export_run_id=run_id,
        exported_at=exported_at,
        source="woocommerce",
        site=site,
        store_name=store_name,
        source_product_id=product_id,
        product_title=product_title,
        variant_title="(variations not retrieved)",
        handle=handle,
        brand=brand,
        product_type=product_type,
        sku=str(p.get("sku", "") or ""),
        barcode=_woo_extract_barcode(p),
        current_price=_woo_decimal(current_raw, minor_unit),
        on_sale=bool_str(p.get("on_sale", False)),
        currency=currency_code,
        currency_minor_unit="" if minor_unit is None else str(minor_unit),
        price_source="woo_store_api",
        stock_status=_woo_stock_status(p),
        purchasable=bool_str(p.get("is_purchasable", None)),
        source_product_url=permalink,
        is_variable_parent="true",
        variation_retrieval_status="fallback_parent_only",
        description=description,
        raw_attributes_json=json_dump(raw_with_range),
    )]


def _woo_request_variations(session: requests.Session, url: str, params: dict,
                            strict: bool) -> list | None:
    """GET a variation-list endpoint. Returns the parsed list on success,
    [] on success-but-empty, or None on hard failure (non-200, malformed,
    error envelope, or — when strict — a payload whose first item isn't
    variation-typed)."""
    r = get(session, url, params=params)
    if r is None or r.status_code != 200:
        return None
    try:
        body = r.json()
    except ValueError:
        return None
    if not isinstance(body, list):
        return None
    if not body:
        return []
    if strict:
        first = body[0]
        if not isinstance(first, dict) or first.get("type", "variation") != "variation":
            return None
    return body


def _woo_fetch_variations(session: requests.Session, site: str, store_name: str, product_id: str,
                          parent: dict, raw_base: dict, brand: str, product_type: str,
                          currency_code: str, minor_unit, run_id: str, exported_at: str) -> list[Offer]:
    # Modern Woo (Store API in Woo 8.x+) dropped /products/<id>/variations
    # — it now returns 404. The supported path is a filter on the products
    # list. We try the modern path first and fall back to the legacy path
    # for older stores. The strict guard on the modern path rejects
    # responses whose first item isn't variation-typed, because older Woo
    # versions silently ignore unknown filter params and would return the
    # regular products list instead.
    variations = _woo_request_variations(
        session,
        f"{site}/wp-json/wc/store/v1/products",
        params={"type": "variation", "parent": product_id, "per_page": 100},
        strict=True,
    )
    if variations is None:
        variations = _woo_request_variations(
            session,
            f"{site}/wp-json/wc/store/v1/products/{product_id}/variations",
            params={"per_page": 100},
            strict=False,
        )
    if not variations:
        return []

    parent_title = parent.get("name", "") or ""
    permalink = parent.get("permalink", "") or ""
    handle = parent.get("slug", "") or ""
    description = strip_html(parent.get("description", "") or parent.get("short_description", "") or "")

    offers: list[Offer] = []
    for v in variations:
        v_prices = v.get("prices") or {}
        v_minor = v_prices.get("currency_minor_unit", minor_unit)
        on_sale_bool = bool(v.get("on_sale", False))
        regular_raw = str(v_prices.get("regular_price", "") or "")
        sale_raw = str(v_prices.get("sale_price", "") or "")
        current_raw = str(v_prices.get("price", "") or "")

        regular_price = _woo_decimal(regular_raw, v_minor)
        sale_price = (
            _woo_decimal(sale_raw, v_minor)
            if on_sale_bool and sale_raw and sale_raw != regular_raw
            else ""
        )
        current_price = _woo_decimal(current_raw, v_minor)

        v_attrs = v.get("attributes") or []
        variant_title_parts = []
        for a in v_attrs:
            if isinstance(a, dict):
                val = a.get("value") or a.get("name") or ""
                if val:
                    variant_title_parts.append(str(val))
        variant_title = " / ".join(variant_title_parts)

        variant_raw = dict(raw_base)
        variant_raw["variation_attributes"] = v_attrs

        variant_url = _woo_variation_url(permalink, parent, v)
        offers.append(Offer(
            export_run_id=run_id,
            exported_at=exported_at,
            source="woocommerce",
            site=site,
            store_name=store_name,
            source_product_id=product_id,
            source_variant_id=str(v.get("id", "")),
            product_title=parent_title,
            variant_title=variant_title,
            handle=handle,
            brand=brand,
            product_type=product_type,
            sku=str(v.get("sku", "") or ""),
            regular_price=regular_price,
            sale_price=sale_price,
            current_price=current_price,
            on_sale="true" if on_sale_bool else "false",
            currency=v_prices.get("currency_code", currency_code) or currency_code,
            currency_minor_unit="" if v_minor is None else str(v_minor),
            price_source="woo_variation_api",
            stock_status=_woo_stock_status(v),
            purchasable=bool_str(v.get("is_purchasable", None)),
            source_product_url=permalink,
            source_variant_url=variant_url if variant_url != permalink else "",
            variation_retrieval_status="retrieved",
            description=description,
            raw_attributes_json=json_dump(variant_raw),
        ))

    return offers


def _woo_extract_brand(product: dict, attributes: list) -> str:
    brands = product.get("brands")
    if isinstance(brands, list) and brands:
        first = brands[0]
        if isinstance(first, dict):
            return first.get("name", "") or ""

    for a in attributes or []:
        if isinstance(a, dict):
            name = (a.get("name", "") or "").strip().lower()
            if name == "brand":
                terms = a.get("terms") or []
                if isinstance(terms, list) and terms:
                    t = terms[0]
                    if isinstance(t, dict):
                        return t.get("name", "") or ""
                opts = a.get("options")
                if isinstance(opts, list) and opts:
                    return str(opts[0])

    return ""


def _woo_extract_barcode(product: dict) -> str:
    """
    Newer Woo Store API exposes a top-level `global_unique_id` (GTIN/UPC/EAN).
    The legacy `meta_data` path is not exposed by the public Store API, so we
    don't bother walking it.
    """
    gtin = product.get("global_unique_id")
    return str(gtin) if gtin else ""


def _woo_stock_status(node: dict) -> str:
    is_in_stock = node.get("is_in_stock")
    if is_in_stock is True:
        if node.get("is_on_backorder") is True:
            return STOCK_BACKORDER
        return STOCK_IN
    if is_in_stock is False:
        return STOCK_OUT
    return STOCK_UNKNOWN


_SLUG_RE = re.compile(r"[^a-z0-9]+")


def _slugify(text: str) -> str:
    return _SLUG_RE.sub("-", (text or "").strip().lower()).strip("-")


def _woo_variation_url(permalink: str, parent: dict, variation: dict) -> str:
    """
    Build a variation-selecting URL by appending ?attribute_<key>=<value>
    params to the parent permalink.

    WooCommerce's attribute query key is:
      - `attribute_<taxonomy>` for global attributes (e.g. `attribute_pa_color`)
      - `attribute_<slug-of-name>` for product-local attributes

    The Store API parent product exposes `taxonomy` per attribute; variation
    attributes carry an `id` that matches the parent attribute `id`.
    Returns the parent permalink unchanged if attributes can't be resolved.
    """
    if not permalink:
        return permalink

    v_attrs = variation.get("attributes") or []
    if not v_attrs:
        return permalink

    parent_attrs = parent.get("attributes") or []
    by_id: dict = {}
    by_name: dict = {}
    for pa in parent_attrs:
        if not isinstance(pa, dict):
            continue
        if pa.get("id") is not None:
            by_id[pa["id"]] = pa
        name = (pa.get("name") or "").lower()
        if name:
            by_name[name] = pa

    extra: list[tuple[str, str]] = []
    for va in v_attrs:
        if not isinstance(va, dict):
            continue
        value = va.get("value")
        if not value:
            continue
        parent_attr = None
        if va.get("id") is not None:
            parent_attr = by_id.get(va["id"])
        if parent_attr is None:
            parent_attr = by_name.get((va.get("name") or "").lower())
        taxonomy = (parent_attr or {}).get("taxonomy") if parent_attr else None
        key_part = taxonomy if taxonomy else _slugify(va.get("name") or "")
        if not key_part:
            continue
        extra.append((f"attribute_{key_part}", str(value)))

    if not extra:
        return permalink

    parsed = urlparse(permalink)
    existing = parse_qsl(parsed.query, keep_blank_values=True)
    merged = existing + extra
    return urlunparse(parsed._replace(query=urlencode(merged, doseq=True)))


def _woo_decimal(raw: str, minor_unit) -> str:
    """
    Convert a Woo Store API price to a decimal string.

    Standard Store API returns integer minor units (e.g. "1234" with minor_unit=2
    means 12.34). Some plugins / customized endpoints return decimal strings
    ("12.34") already scaled — in that case we pass through without dividing.
    """
    if not raw:
        return ""
    try:
        n = int(minor_unit) if minor_unit is not None else 2
    except (ValueError, TypeError):
        n = 2
    raw_str = str(raw)
    try:
        d = Decimal(raw_str)
    except (InvalidOperation, ValueError, TypeError):
        return raw_str
    if "." in raw_str:
        # Already a decimal; quantize to n fractional digits.
        return f"{d:.{n}f}"
    return f"{d / (Decimal(10) ** n):.{n}f}"


# ---------- Generic scrape via sitemap + JSON-LD ----------

SITEMAP_CANDIDATES = [
    "/sitemap_products_1.xml",
    "/product-sitemap.xml",
    "/wp-sitemap-posts-product-1.xml",
    "/sitemap.xml",
]

PRODUCT_PATH_HINTS = ("/product", "/products/", "/shop/", "/p/", "/item/", "/dp/")


def discover_product_urls(session: requests.Session, site: str, limit: int = 500) -> list[str]:
    seen: set[str] = set()
    for path in SITEMAP_CANDIDATES:
        r = get(session, site + path)
        if r is None or r.status_code != 200 or "<" not in r.text:
            continue
        trust_all = "product" in path
        urls = parse_sitemap(r.text, session, site, trust_all=trust_all)
        for u in urls:
            if trust_all or any(h in u for h in PRODUCT_PATH_HINTS):
                seen.add(u)
            if len(seen) >= limit:
                break
        if seen:
            break
    return list(seen)[:limit]


def parse_sitemap(xml_text: str, session: requests.Session, site: str, trust_all: bool = False) -> list[str]:
    urls: list[str] = []
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError:
        return urls
    ns = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
    for sm in root.findall("sm:sitemap/sm:loc", ns):
        if sm.text and ("product" in sm.text or "shop" in sm.text):
            r = get(session, sm.text)
            if r and r.status_code == 200:
                urls.extend(parse_sitemap(r.text, session, site, trust_all=True))
    for loc in root.findall("sm:url/sm:loc", ns):
        if loc.text:
            urls.append(loc.text.strip())
    return urls


def fetch_generic_store_name(session: requests.Session, site: str) -> str:
    """
    Pull store name from the homepage: prefer og:site_name, fall back to
    application/ld+json Organization/WebSite name, then <title>. Returns ""
    if the homepage isn't reachable.
    """
    r = get(session, site + "/")
    if r is None or r.status_code != 200:
        return ""
    soup = BeautifulSoup(r.text, "html.parser")
    og = soup.find("meta", attrs={"property": "og:site_name"})
    if og and og.get("content"):
        return og["content"].strip()
    for tag in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(tag.string or "")
        except (ValueError, TypeError):
            continue
        name = _find_org_name(data)
        if name:
            return name
    if soup.title and soup.title.string:
        return soup.title.string.strip()
    return ""


def _find_org_name(node) -> str:
    """Walk JSON-LD looking for an Organization or WebSite name."""
    if isinstance(node, list):
        for item in node:
            n = _find_org_name(item)
            if n:
                return n
    elif isinstance(node, dict):
        if jsonld_type_matches(node.get("@type"), "Organization") or \
                jsonld_type_matches(node.get("@type"), "WebSite"):
            n = node.get("name")
            if n:
                return str(n).strip()
        if "@graph" in node:
            return _find_org_name(node["@graph"])
    return ""


def try_generic(session: requests.Session, site: str, run_id: str, exported_at: str) -> list[Offer] | None:
    urls = discover_product_urls(session, site)
    if not urls:
        return None
    store_name = fetch_generic_store_name(session, site)
    offers: list[Offer] = []
    for url in urls:
        r = get(session, url)
        if r is None or r.status_code != 200:
            continue
        for item in extract_jsonld_products(r.text):
            offers.extend(_jsonld_to_offers(item, site, store_name, url, run_id, exported_at))
    return offers or None


def extract_jsonld_products(html: str) -> Iterator[dict]:
    soup = BeautifulSoup(html, "html.parser")
    for tag in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(tag.string or "")
        except (ValueError, TypeError):
            continue
        yield from walk_for_products(data)


def walk_for_products(node) -> Iterator[dict]:
    if isinstance(node, list):
        for item in node:
            yield from walk_for_products(item)
    elif isinstance(node, dict):
        if jsonld_type_matches(node.get("@type"), "Product"):
            yield node
        if "@graph" in node:
            yield from walk_for_products(node["@graph"])


def _jsonld_to_offers(item: dict, site: str, store_name: str, url: str, run_id: str, exported_at: str) -> list[Offer]:
    name = item.get("name", "") or ""
    description = strip_html(item.get("description", "") or "")
    brand = stringify(item.get("brand"))
    category = stringify(item.get("category"))
    sku_top = str(item.get("sku") or "")
    gtin = (
        item.get("gtin13") or item.get("gtin12") or item.get("gtin14")
        or item.get("gtin8") or item.get("gtin") or ""
    )

    offers_data = item.get("offers")
    if isinstance(offers_data, dict):
        if jsonld_type_matches(offers_data.get("@type"), "AggregateOffer") and isinstance(offers_data.get("offers"), list):
            offer_list = offers_data["offers"]
        else:
            offer_list = [offers_data]
    elif isinstance(offers_data, list):
        offer_list = offers_data
    else:
        offer_list = [{}]

    rows: list[Offer] = []
    for o in offer_list:
        if not isinstance(o, dict):
            continue
        price = str(o.get("price") or o.get("lowPrice") or "")
        currency = str(o.get("priceCurrency") or "")
        avail = str(o.get("availability") or "").lower()
        if "instock" in avail:
            stock = STOCK_IN
        elif "outofstock" in avail:
            stock = STOCK_OUT
        elif "backorder" in avail:
            stock = STOCK_BACKORDER
        else:
            stock = STOCK_UNKNOWN

        sku = str(o.get("sku") or sku_top)

        raw = {
            "jsonld_category": category,
            "jsonld_offer": {k: v for k, v in o.items() if k not in ("price", "priceCurrency", "availability", "sku")},
        }

        rows.append(Offer(
            export_run_id=run_id,
            exported_at=exported_at,
            source="generic",
            site=site,
            store_name=store_name,
            source_product_id=sku or url,
            product_title=name,
            brand=brand,
            product_type=category,
            sku=sku,
            barcode=str(gtin) if gtin else "",
            regular_price=price,
            current_price=price,
            on_sale="false",
            currency=currency,
            price_source="jsonld",
            stock_status=stock,
            source_product_url=url,
            variation_retrieval_status="not_applicable",
            description=description,
            raw_attributes_json=json_dump(raw),
        ))

    return rows


# ---------- Orchestration ----------

def scrape_site(session: requests.Session, site: str, run_id: str, exported_at: str) -> list[Offer]:
    print(f"[{site}] trying Shopify...")
    offers = try_shopify(session, site, run_id, exported_at)
    if offers:
        print(f"  -> Shopify: {len(offers)} variant rows")
        return offers

    print(f"[{site}] trying WooCommerce Store API...")
    offers = try_woocommerce(session, site, run_id, exported_at)
    if offers:
        print(f"  -> WooCommerce: {len(offers)} variant rows")
        return offers

    print(f"[{site}] falling back to sitemap + JSON-LD scrape...")
    offers = try_generic(session, site, run_id, exported_at)
    if offers:
        print(f"  -> Generic: {len(offers)} rows")
        return offers

    print(f"[{site}] no products found")
    return []


def load_sites(args) -> list[str]:
    sites: list[str] = list(args.sites)
    if args.input:
        with open(args.input, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#"):
                    sites.append(line)
    return [normalize_site(s) for s in sites]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Aggregate public product data to CSV, one row per variant.")
    parser.add_argument("sites", nargs="*", help="Store URLs.")
    parser.add_argument("--input", "-i", help="File with one store URL per line.")
    parser.add_argument("--output", "-o", default="products.csv", help="Output CSV path.")
    parser.add_argument("--run-id", help="Override export_run_id (default: generated UUID).")
    args = parser.parse_args(argv)

    sites = load_sites(args)
    if not sites:
        parser.error("no sites provided")

    run_id = args.run_id or f"run_{uuid.uuid4().hex[:12]}"
    exported_at = now_iso()
    print(f"export_run_id = {run_id}")
    print(f"exported_at   = {exported_at}\n")

    session = requests.Session()
    all_offers: list[Offer] = []
    for site in sites:
        try:
            all_offers.extend(scrape_site(session, site, run_id, exported_at))
        except Exception as e:
            print(f"[{site}] error: {e}", file=sys.stderr)

    with open(args.output, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=Offer.fieldnames())
        writer.writeheader()
        for o in all_offers:
            writer.writerow(asdict(o))

    print(f"\nWrote {len(all_offers)} rows to {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
