# Competitive Intelligence Python

Separate Python worker for phase 1 URL Finder.

Responsibilities:

- fetch product batches from Symfony
- run one scraper adapter per competitor
- score candidate URLs
- send candidates back to Symfony
- optionally use OpenAI for Thomann and Michenaud ranking
- scrape prices for final competitor URLs in a separate pass

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
- Thomann currently pauses 2 to 5 seconds between page fetches to reduce burstiness.
- Image URLs are kept only when the resolved final URL still matches the candidate URL.
- OpenAI receives up to 3 candidates per product, but the worker only makes one ranking call per batch.
- The Symfony image review flow now compares photos in OpenAI batches of 10 pairs, compresses images before upload, and flushes persistence after each batch.
- `score < 30` is treated as `not_found`.
- Thomann and Michenaud generally stay `pending` unless the batch marks them high confidence.
- `matched` with high enough score is written as `valid` and upserted into `competitor_url_final`.
- `rejected` does not come back in the batch provider anymore.
- `postponed` stays in validation and is hidden from the main validation list.
- Final prices are captured only from `competitor_url_final` entries and appended to `competitor_url_price_history`.

## Run

### Local Woodbrass test

```bash
cd competitive_intelligence_python
python3 run_woodbrass_test.py
```

This mode:

- does not call PrestaShop
- does not call Symfony
- uses the test reference `014-9052-388`

### Batch worker

```bash
export CI_API_BASE_URL="https://your-domain.example"
export CI_API_TOKEN="change-me-too"
export CI_COMPETITOR_ID="1"
export CI_BATCH_LIMIT="10"
export CI_AFTER_ID="0"
python3 run_batch.py
```

The worker uses a lock file to prevent concurrent runs for the same `competitor_id` / `lang_id` / `shop_id`.
It also records `cloudflare`, `search_input_not_found`, and `not_found` test results back to Symfony.

### Batch launcher from Symfony

The worker is usually triggered by Symfony through:

```text
/api/competitive/run-batch?competitor_id=1&limit=10&after_id=0&lang_id=1&shop_id=1&token=change-me-too
```

That endpoint starts `run_batch.py` in the background.
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
The Symfony search page now shows source thumbnails, rejected URLs, and postponed URLs, and rejected URLs can be revalidated from the search page.
