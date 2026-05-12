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
4. Thomann and Michenaud send one OpenAI request per batch, carrying up to 3 candidates per product for ranking.
5. Thomann / Michenaud candidates are filtered by brand before API scoring.
6. Thomann rejects `b-stock`, `b stock`, `bstock`, and `bundle` titles before scoring.
7. Python pushes test payloads back to Symfony.
8. Symfony stores the source of truth in:
   - `competitor_url_final`
   - `competitor_url_test_result`
   - `competitor_url_rejected_url`
9. `competitor_url_price_history` stores append-only price observations for finals.
10. `competitor_url_candidate` is legacy and no longer part of the active workflow.
11. Human validation now works directly on `competitor_url_test_result`.
12. Validation statuses are:
   - `pending`
   - `valid`
   - `rejected`
   - `postponed`
   - `ignored`
13. A running batch is lock-protected per `competitor_id` / `lang_id` / `shop_id` so the cron can skip instead of stacking workers.
14. `run-all` has a global lock too, so only one combined run can start at a time.
15. The batch source is `leo_netrivals_send_feed` in the PrestaShop database, not the `product` table.
16. Next-batch selection excludes products already tested for that competitor, so retries do not recycle the same `id_product`.
17. `rejected` products are no longer re-queued by the batch provider.
18. Price scraping only runs on `competitor_url_final`, not on search results.
19. `competitor_url_price_history` is the source of truth for price history priority.
20. Current competitors are:
   - `1` = Woodbrass
   - `2` = Stars Music
   - `3` = Thomann
   - `4` = Michenaud
21. Debug mode writes PNG screenshots only under `debug/`.
22. The image repair batch `fix-pending-image-urls` is back on the direct PHP script, with a file lock and a 2-5 second random pause per fetch.
23. Thomann price/image fetches now pause randomly between 2 and 5 seconds before each request.
24. The separate Python image worker was tried and then rolled back; the active flow is direct scraping again.
25. The image review route compares product photos through OpenAI in batches of 10 pairs, compresses images before upload, and flushes persistence after each batch.
26. The competitive search page now shows source images plus separate sections for finals, rejected URLs, and postponed URLs.
27. Rejected URLs can be revalidated from the search page and are pushed back to `valid`.

## Key Routes

- `GET /api/competitive/run-batch`
- `GET /api/competitive/run-all`
- `GET /api/competitive/run-both` (legacy alias)
- `GET /api/competitive/products/next-batch`
- `GET /api/competitive/final-prices/next-batch`
- `POST /api/competitive/final-prices`
- `GET /veille-concurrentielle/validation`
- `GET /veille-concurrentielle/recherche`
- `GET /veille-concurrentielle/validation/image-review`
- `GET /api/competitive/fix-pending-image-urls` (direct PHP batch, lock-protected)

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
- Thomann and Michenaud can use OpenAI for the final choice among the top 3 candidates.
- For Thomann and Michenaud, `score >= 95` can become `matched` automatically in the batch.
- Heuristic flows still use `score >= 90` for `matched`, while Thomann and Michenaud can auto-match at around `95` when OpenAI is enabled.
- `matched` becomes `valid` and is pushed to `competitor_url_final` in Symfony.
- `validationStatus = pending` is what the validation page shows.
- `postponed` hides the row from the validation list without rejecting it.
- The validation page now works in batches of 50 rows, defaults each row to `rejected`, and has a bulk action to switch the whole page to `valid` before sending.
- Keep final URLs keyed by PrestaShop `id_product`.
- Keep `competitor_title` as the canonical competitor-side title in test results.
- Keep `competitor_brand` and `competitor_breadcrumb` when the scraper can provide them.
- Keep `competitor_price` and append it to `competitor_url_price_history` when available.
- `title` was removed from `competitor_url_test_result`.
- `competitor_price` is optional and may be null.
- Exclude already tested products from the next batch selection for the same competitor.
- `rejected` does not come back in the batch provider anymore.
- `max_parallel` can be used to cap how many batches run at once across competitors.
- `run-all` accepts `limit` for matching and `price_limit` for the final price crawl.

## Operational Notes

- The worker must be launched from Symfony via the batch runner or directly from the Python entry point.
- The API requires the competitive token.
- If a site is blocked or returns a challenge, record it as a test result and move on.
- Use debug mode only when diagnosing a specific site or product.
- Debug mode writes PNG artifacts under `debug/`.
- The validation page is paginated in blocks of 50 and shows the total pending count.
- The home recap is aligned with the validation pending count.
- The final price crawl should stay limited and only target finals not yet in `competitor_url_price_history`, then the oldest last-scraped finals.
- For image repair, keep the batch small and respect the direct-scrape lock/pause behavior on Thomann.
- For image review, keep the batch OpenAI size to 10 pairs per request unless the prompt grows too large.

## Code References

- `dashboard/src/Controller/Api/CompetitiveIntelligenceApiController.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveBatchRunner.php`
- `dashboard/src/Service/CompetitiveIntelligence/PrestashopProductBatchProvider.php`
- `competitive_intelligence_python/run_batch.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/woodbrass.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/starsmusic.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/thomann.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/michenaud.py`
