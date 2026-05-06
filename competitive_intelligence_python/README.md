# Competitive Intelligence Python

Separate Python worker for phase 1 URL Finder.

Responsibilities:

- fetch product batches from Symfony
- run one scraper adapter per competitor
- score candidate URLs
- send candidates back to Symfony

## Current adapter

- `WoodbrassScraper`
- `StarsMusicScraper`
- `ThomannScraper`
- `MichenaudScraper`
- Woodbrass and Stars rely on a light HTTP search flow and validate the final page by reference / EAN when available.
- Thomann uses the embedded `search.index` payload from the search page and scores the closest title.
- Michenaud uses its search page and validates the final page by reference / EAN when available.
- Thomann and Michenaud also capture price when available.
- `score < 30` is treated as `not_found`.
- `matched` with score high enough is written as `valid` and upserted into `competitor_url_final`.
- `rejected` does not come back in the batch provider anymore.
- `postponed` stays in validation and is hidden from the main validation list.

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
/api/competitive/run-all?limit=5&lang_id=1&shop_id=1&max_parallel=2&token=change-me-too
```

That route starts Woodbrass, Stars Music, Thomann, and Michenaud together.
`run-all` has its own global lock, so two combined runs cannot start at the same time.

### Result fields

The worker sends the following test-result fields back to Symfony:

- `competitor_title`
- `competitor_price`
- `matched_query`
- `score`
- `result`
- `validation_status`

The `title` field is no longer stored on `competitor_url_test_result`.
