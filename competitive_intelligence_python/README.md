# Competitive Intelligence Python

Python jobs for competitive intelligence.

Responsibilities:

- fetch product batches from Symfony
- run one scraper adapter per competitor
- score candidate URLs
- send candidates back to Symfony
- optionally use OpenAI for Thomann and Michenaud ranking
- scrape prices for final competitor URLs in a separate pass
- expose explicit job entry points by competitor and task

## Current adapter

- `WoodbrassScraper`
- `StarsMusicScraper`
- `ThomannScraper`
- `MichenaudScraper`
- Woodbrass and Stars rely on a light HTTP search flow and validate the final page by reference / EAN when available.
- Thomann uses the embedded `search.index` payload from the search page and participates in one OpenAI request per batch when available.
- Michenaud uses its search page and participates in one OpenAI request per batch when available.
- Thomann and Michenaud filter candidates by brand before OpenAI ranking.
- Thomann rejects `b-stock`, `b stock`, `bstock`, and `bundle` titles before scoring.
- Thomann and Michenaud capture title, brand, breadcrumb, and price when available.
- Stars Music now also captures product price directly during URL matching when the page is validated.
- Algam is not handled by the Python scrapers: its price is read separately from `tm2dn_site_v3.leo_algamwebstoreprice` and surfaced as a reference in Symfony.
- Thomann URL fetches currently pause 2 to 5 seconds between page fetches to reduce burstiness.
- Thomann price fetches also pause 2 to 5 seconds between requests.
- Image URLs are kept only when the resolved final URL still matches the candidate URL.
- OpenAI receives up to 3 candidates per product, but the worker only makes one ranking call per batch.
- The Symfony image review flow compares photos in OpenAI batches of 10 pairs, compresses images before upload, and flushes persistence after each batch.
- `score < 30` is treated as `not_found`.
- Thomann and Michenaud generally stay `pending` unless the batch marks them high confidence.
- `matched` with high enough score is written as `valid` and upserted into `competitor_url_final`.
- `rejected` does not come back in the batch provider anymore.
- `postponed` stays in validation and is hidden from the main validation list.
- `retry_urls` now prioritises the oldest `not_found` rows by `last_tested_at`.
- Final prices are captured only from `competitor_url_final` entries and appended to `competitor_url_price_history`.
- Final prices now also feed URL-health state back to Symfony on `404/410`.
- After 3 consecutive `404/410`, Symfony removes the final URL and marks the linked test result page status as `gone`.

## Structure

The Python side is split in three layers:

1. Shared worker logic
- `competitive_intelligence/workers/url_job.py`
- `competitive_intelligence/workers/price_job.py`

2. Explicit job entry points by competitor
- `jobs/woodbrass/new_urls.py`
- `jobs/woodbrass/retry_urls.py`
- `jobs/woodbrass/prices.py`
- `jobs/starsmusic/new_urls.py`
- `jobs/starsmusic/retry_urls.py`
- `jobs/starsmusic/prices.py`
- `jobs/thomann/new_urls.py`
- `jobs/thomann/retry_urls.py`
- `jobs/thomann/prices.py`
- `jobs/michenaud/new_urls.py`
- `jobs/michenaud/retry_urls.py`
- `jobs/michenaud/prices.py`

3. Backward-compatible top-level entry points
- `run_batch.py`
- `run_new_urls.py`
- `run_retry_urls.py`
- `run_final_prices.py`

The top-level files remain for compatibility, but the real production entry points are now the explicit scripts under `jobs/<competitor>/`.

The normal production scheduler is the Symfony orchestrator:

```text
/api/competitive/orchestrate?token=change-me-too
```

It is intended to be called once per minute and then chooses one task to start.

## Run

### Local Woodbrass test

```bash
cd competitive_intelligence_python
python3 run_woodbrass_test.py
```

### Shared worker compatibility entry point

```bash
export CI_API_BASE_URL="https://your-domain.example"
export CI_API_TOKEN="change-me-too"
export CI_COMPETITOR_ID="1"
export CI_BATCH_LIMIT="10"
export CI_AFTER_ID="0"
python3 run_batch.py
```

This remains valid, but it is no longer the clearest entry point.

### Explicit job entry points

New URLs for Thomann:

```bash
export CI_API_BASE_URL="https://your-domain.example"
export CI_API_TOKEN="change-me-too"
export CI_BATCH_LIMIT="10"
export CI_AFTER_ID="0"
export CI_LANG_ID="1"
export CI_SHOP_ID="1"
python3 jobs/thomann/new_urls.py
```

Retry old `not_found` rows for Michenaud:

```bash
export CI_API_BASE_URL="https://your-domain.example"
export CI_API_TOKEN="change-me-too"
export CI_BATCH_LIMIT="10"
export CI_AFTER_ID="0"
export CI_LANG_ID="1"
export CI_SHOP_ID="1"
python3 jobs/michenaud/retry_urls.py
```

Price refresh for Woodbrass:

```bash
export CI_API_BASE_URL="https://your-domain.example"
export CI_API_TOKEN="change-me-too"
export CI_BATCH_LIMIT="10"
export CI_AFTER_ID="0"
python3 jobs/woodbrass/prices.py
```

The same pattern exists for all four competitors:

- `jobs/woodbrass/...`
- `jobs/starsmusic/...`
- `jobs/thomann/...`
- `jobs/michenaud/...`

All job scripts still use the same lock discipline:

- URL jobs lock on `competitor_id` / `lang_id` / `shop_id` / `mode`
- price jobs lock on `competitor_id`

URL jobs also record `cloudflare`, `search_input_not_found`, and `not_found` back to Symfony.

### Batch launcher from Symfony

The worker is usually triggered by Symfony through:

```text
/api/competitive/run-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1&token=change-me-too
```

That endpoint now starts the explicit script for the requested competitor and task, for example:

- `jobs/thomann/new_urls.py`
- `jobs/thomann/retry_urls.py`
- `jobs/thomann/prices.py`

Debug mode can be enabled with `debug=1`, which writes screenshots and HTML snapshots to `competitive_intelligence_python/debug/`.

### All competitors

The dashboard also exposes a combined launcher:

```text
/api/competitive/run-all?limit=5&price_limit=10&lang_id=1&shop_id=1&max_parallel=2&token=change-me-too
```

That route starts Woodbrass, Stars Music, Thomann, and Michenaud together.
`run-all` has its own global lock, so two combined runs cannot start at the same time.
`limit` controls the URL matching batch.
`price_limit` controls the final price batch.

`run-all` is still useful for catch-up runs, but the normal daily control path is now the orchestrator plus the admin configuration page.

### Result fields

The worker sends the following test-result fields back to Symfony:

- `competitor_title`
- `competitor_brand`
- `competitor_breadcrumb`
- `competitor_price`
- `matched_query`
- `score`
- `result`
- `validation_status`

The `title` field is no longer stored on `competitor_url_test_result`.

## Current state

The separate Python image repair worker was tested and then rolled back. The active image flow is direct scraping again through the PHP batch launcher, with a file lock and a random pause per fetch.
The Symfony search page now shows source thumbnails, Algam as a standalone reference price, rejected URLs, and postponed URLs, and rejected URLs can be revalidated from the search page.
The orchestrator admin can now:

- tune each task without code
- launch one task manually
- inspect recent logs
- open full logs in the browser
