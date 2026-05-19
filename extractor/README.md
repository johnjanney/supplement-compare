# Extractor — `aggregate_products.py`

The Python half of Supplement Compare. Talks to merchant storefronts, emits the
canonical-schema CSV that the WordPress plugin imports. See
[../PROJECTBRIEF.md §4](../PROJECTBRIEF.md) for the full CSV contract.

The script runs on the operator's local machine. **The WordPress plugin never
talks to merchant sites directly** — everything goes through the CSV produced
here.

---

## Requirements

- Python 3.10+
- `requests`, `beautifulsoup4` (see `requirements.txt`)

## Install

```bash
cd extractor
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

## Run

One or more store URLs on the command line:

```bash
python aggregate_products.py https://store-a.com https://store-b.com -o products.csv
```

Or read URLs from a file (one per line, `#` for comments):

```bash
python aggregate_products.py --input stores.txt -o products.csv
```

Optional flags:

| Flag | Default | What |
|---|---|---|
| `--output / -o` | `products.csv` | CSV output path |
| `--input / -i` | — | Text file with one store URL per line |
| `--run-id` | generated UUID | Override `export_run_id` (useful for reruns) |

## How it works

For each site, the script tries three strategies in order and emits whichever
produces results first:

1. **Shopify** — `GET {site}/products.json?limit=250&page=N`
2. **WooCommerce Store API** — `GET {site}/wp-json/wc/store/v1/products?per_page=100&page=N`,
   plus a per-product variations call for variable products
3. **Generic** — pull product URLs from sitemap.xml / product-sitemap.xml and
   parse JSON-LD `Product` schema from each page

One CSV row per variant. Default-variant Shopify products produce one row.
Variable WooCommerce products produce one row per retrievable variation; if the
variations endpoint fails, a single "parent" row is emitted with
`variation_retrieval_status=fallback_parent_only` so the operator can
investigate.

## Etiquette

The script only touches public endpoints and public pages. Before running it
against any site you don't own:

- Check the site's `robots.txt`
- Confirm the merchant's affiliate program terms permit data ingestion of this
  kind
- Keep the default 0.5s delay between requests (don't crank it down)

## Schema

The CSV columns match the contract in PROJECTBRIEF.md §4. The script emits in
that canonical column order. If you change a column name, the plugin will
reject the import — bump `CSV_SCHEMA_VERSION` and the plugin's
`MIN_CSV_SCHEMA_VERSION` in lockstep.
