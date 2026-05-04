# Agent Guide

## Project Shape

- Symfony is the main application and dashboard.
- Competitive intelligence is a dedicated section inside the same site.
- Python is a separate worker for URL finding only.
- Do not put Python code inside Symfony services or controllers beyond process orchestration.

## Current Competitive Intelligence Flow

1. Symfony selects products from PrestaShop by batch.
2. Symfony starts the Python worker.
3. Python searches competitor sites for candidate URLs.
4. Python scores candidates and pushes test/candidate payloads back to Symfony.
5. Symfony stores results in:
   - `competitor_url_candidate`
   - `competitor_url_final`
   - `competitor_url_test_result`
6. Human validation remains separate.
7. A running batch is lock-protected per `competitor_id` / `lang_id` / `shop_id` so the cron can skip instead of stacking workers.
8. The batch source is `leo_netrivals_send_feed` in the PrestaShop database, not the `product` table.
9. Current competitors are:
   - `1` = Woodbrass
   - `2` = Stars Music
10. Debug mode writes PNG screenshots only under `debug/`.

## Key Routes

- `GET /api/competitive/run-batch`
- `GET /api/competitive/run-both`
- `GET /api/competitive/products/next-batch`
- `POST /api/competitive/candidates`
- `POST /api/competitive/candidates/{id}/status`

## Important Rules

- Phase 1 is URL finding only.
- Do not scrape prices yet.
- Do not process all products in one burst.
- Prefer small batches and progressive scheduling.
- Keep test statuses explicit:
  - `matched`
  - `not_found`
  - `cloudflare`
  - `search_input_not_found`
  - `error`
- Keep final URLs keyed by PrestaShop `id_product`.
- Exclude `not_found`, `cloudflare`, and `search_input_not_found` from the next batch selection.
- `max_parallel` can be used to cap how many batches run at once across competitors.

## Operational Notes

- The worker must be launched from Symfony via the batch runner or directly from the Python entry point.
- The API requires the competitive token.
- If a site is blocked or returns a challenge, record it as a test result and move on.
- Use debug mode only when diagnosing a specific site or product.
- Debug mode writes PNG artifacts under `debug/`.

## Code References

- `dashboard/src/Controller/Api/CompetitiveIntelligenceApiController.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveBatchRunner.php`
- `dashboard/src/Service/CompetitiveIntelligence/PrestashopProductBatchProvider.php`
- `competitive_intelligence_python/run_batch.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/woodbrass.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/starsmusic.py`
