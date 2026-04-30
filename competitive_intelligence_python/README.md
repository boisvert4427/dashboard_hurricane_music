# Competitive Intelligence Python

Separate Python worker for phase 1 URL Finder.

Responsibilities:

- fetch product batches from Symfony
- run one scraper adapter per competitor
- score candidate URLs
- send candidates back to Symfony

## Current adapter

- `WoodbrassScraper`
- it searches using the competitor search URL
- it inspects the first product links
- it opens the product pages and verifies the supplier reference or EAN before saving

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
