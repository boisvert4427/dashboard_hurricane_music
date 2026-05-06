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
4. Python scores candidates and pushes test payloads back to Symfony.
5. Symfony stores the source of truth in:
   - `competitor_url_final`
   - `competitor_url_test_result`
   - `competitor_url_rejected_url`
6. `competitor_url_candidate` is legacy and no longer part of the active workflow.
7. Human validation now works directly on `competitor_url_test_result`.
8. Validation statuses are:
   - `pending`
   - `valid`
   - `rejected`
   - `postponed`
   - `ignored`
9. A running batch is lock-protected per `competitor_id` / `lang_id` / `shop_id` so the cron can skip instead of stacking workers.
10. `run-all` has a global lock too, so only one combined run can start at a time.
11. The batch source is `leo_netrivals_send_feed` in the PrestaShop database, not the `product` table.
12. Next-batch selection excludes products already tested for that competitor, so retries do not recycle the same `id_product`.
13. `rejected` products are no longer re-queued by the batch provider.
14. Current competitors are:
   - `1` = Woodbrass
   - `2` = Stars Music
   - `3` = Thomann
   - `4` = Michenaud
15. Debug mode writes PNG screenshots only under `debug/`.

## Key Routes

- `GET /api/competitive/run-batch`
- `GET /api/competitive/run-all`
- `GET /api/competitive/run-both` (legacy alias)
- `GET /api/competitive/products/next-batch`
- `GET /veille-concurrentielle/validation`
- `GET /veille-concurrentielle/recherche`

## Important Rules

- Phase 1 is URL finding only.
- Prices are now captured for competitors that expose them.
- Do not process all products in one burst.
- Prefer small batches and progressive scheduling.
- Keep test statuses explicit:
  - `matched`
  - `not_found`
  - `cloudflare`
  - `search_input_not_found`
  - `error`
- `score < 30` is written as `not_found`.
- `score >= 90` and `matched` becomes `valid` and is pushed to `competitor_url_final`.
- `validationStatus = pending` is what the validation page shows.
- `postponed` hides the row from the validation list without rejecting it.
- Keep final URLs keyed by PrestaShop `id_product`.
- Keep `competitor_title` as the canonical competitor-side title in test results.
- `title` was removed from `competitor_url_test_result`.
- `competitor_price` is optional and may be null.
- Exclude already tested products from the next batch selection for the same competitor.
- `rejected` does not come back in the batch provider anymore.
- `max_parallel` can be used to cap how many batches run at once across competitors.

## Operational Notes

- The worker must be launched from Symfony via the batch runner or directly from the Python entry point.
- The API requires the competitive token.
- If a site is blocked or returns a challenge, record it as a test result and move on.
- Use debug mode only when diagnosing a specific site or product.
- Debug mode writes PNG artifacts under `debug/`.
- The validation page is paginated and shows the total pending count.
- The home recap is aligned with the validation pending count.

## Code References

- `dashboard/src/Controller/Api/CompetitiveIntelligenceApiController.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveBatchRunner.php`
- `dashboard/src/Service/CompetitiveIntelligence/PrestashopProductBatchProvider.php`
- `competitive_intelligence_python/run_batch.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/woodbrass.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/starsmusic.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/thomann.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/michenaud.py`
