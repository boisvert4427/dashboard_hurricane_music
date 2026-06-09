# Agent Guide

## Project Shape

- Symfony is the main application and dashboard.
- Competitive intelligence is a dedicated section inside the same site.
- Python is a separate worker layer for URL finding and final price scraping.
- Do not put Python scraping logic inside Symfony services or controllers beyond orchestration and persistence.

## Current Competitive Intelligence Flow

1. Symfony selects or identifies one eligible task.
2. Symfony starts one explicit Python job for one competitor and one task.
3. Python searches competitor sites for candidate URLs or refreshes final prices.
4. Thomann and Michenaud send one OpenAI request per batch, carrying up to 3 candidates per product for ranking.
5. Thomann / Michenaud candidates are filtered by brand before API scoring.
6. Thomann rejects `b-stock`, `b stock`, `bstock`, and `bundle` titles before scoring.
7. Python pushes test payloads or price observations back to Symfony.
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
13. A running batch is lock-protected per task.
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
23. Thomann URL fetches pause randomly between 2 and 5 seconds.
24. Thomann price fetches also pause randomly between 2 and 5 seconds.
25. The image review route compares product photos through OpenAI in batches of 10 pairs, compresses images before upload, and flushes persistence after each batch.
26. The competitive search page now shows source images plus separate sections for finals, rejected URLs, and postponed URLs.
27. The search page also allows adding one URL manually per product and competitor.
28. Manual URL insertion now attempts a direct page-price scrape and writes to:
   - `competitor_url_test_result`
   - `competitor_url_final`
   - `competitor_url_price_history`
29. The search page also shows Algam as a reference price block, pulled from `tm2dn_site_v3.leo_algamwebstoreprice`, even when no competitor title or URL exists.
30. Rejected URLs can be revalidated from the search page and are pushed back to `valid`.
31. Postponed URLs can also be manually validated from the search page.
32. Price workers now track repeated `404/410` failures on `competitor_url_final`.
33. After 3 consecutive `404/410`, the final URL is removed and the linked test result is marked `competitor_page_status = gone`.
34. The orchestrator is now the normal entry point for production scheduling:
   - `GET /api/competitive/orchestrate`
35. The orchestrator admin page exposes one task row per competitor and per job:
   - `new_urls`
   - `retry_urls`
   - `prices`
36. `retry_urls` now always prioritises the oldest `not_found` rows by `last_tested_at`, not by `id_product`.
37. The orchestrator admin page can manually start one task with `Lancer 1 fois`.
38. The orchestrator admin page exposes readable logs in the browser and falls back to `last_log_file` when the task is not in the recent-log index anymore.
39. The price cockpit now exists at:
   - `GET /veille-concurrentielle/prix`
40. The trusted-gap cockpit now exists at:
   - `GET /veille-concurrentielle/prix/ecarts-fiables`
41. The home recap now includes `postponed` and `rejected`, so `Total` better matches the true `competitor_url_test_result` stock.

## Key Routes

- `GET /api/competitive/orchestrate`
- `GET /api/competitive/run-batch`
- `GET /api/competitive/run-new-urls`
- `GET /api/competitive/run-retry-urls`
- `GET /api/competitive/run-all`
- `GET /api/competitive/run-both` (legacy alias)
- `GET /api/competitive/products/next-batch`
- `GET /api/competitive/final-prices/next-batch`
- `POST /api/competitive/final-prices`
- `GET /veille-concurrentielle/validation`
- `GET /veille-concurrentielle/recherche`
- `GET /veille-concurrentielle/prix`
- `GET /veille-concurrentielle/prix/ecarts-fiables`
- `GET /veille-concurrentielle/validation/image-review`
- `GET /veille-concurrentielle/orchestrateur`
- `GET /veille-concurrentielle/orchestrateur/log/{filename}`
- `GET /api/competitive/fix-pending-image-urls` (direct PHP batch, lock-protected)

## Important Rules

- Phase 1 is URL finding only.
- Orchestration is minute-based and chooses one task at a time.
- Prices are captured only on final URLs.
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
- For Thomann and Michenaud, high-confidence matches can become `matched` automatically in the batch.
- `matched` becomes `valid` and is pushed to `competitor_url_final` in Symfony.
- `validationStatus = pending` is what the validation page shows.
- `postponed` hides the row from the validation list without rejecting it.
- The validation page now works in batches of 50 rows, defaults each row to `rejected`, and has a bulk action to switch the whole page to `valid` before sending.
- The search page supports direct manual URL insertion; validate domain consistency before trusting user input.
- Keep final URLs keyed by PrestaShop `id_product`.
- Keep `competitor_title` as the canonical competitor-side title in test results.
- Keep `competitor_brand` and `competitor_breadcrumb` when the scraper can provide them.
- Keep `competitor_price` and append it to `competitor_url_price_history` when available.
- `title` was removed from `competitor_url_test_result`.
- `competitor_price` is optional and may be null.
- Exclude already tested products from the next batch selection for the same competitor.
- `rejected` does not come back in the batch provider anymore.
- `run-all` accepts `limit` for matching and `price_limit` for the final price crawl.
- Task interval defaults are:
  - `new_urls` = 12h
  - `retry_urls` = 12h
  - `prices` = 1 minute

## Operational Notes

- The preferred production trigger is now the orchestrator URL called every minute.
- The API requires the competitive token.
- If a site is blocked or returns a challenge, record it as a test result and move on.
- Use debug mode only when diagnosing a specific site or product.
- Debug mode writes PNG artifacts under `debug/`.
- The validation page is paginated in blocks of 50 and shows the total pending count.
- The home recap is aligned with the validation pending count.
- The final price crawl should stay limited, prioritize finals not yet in `competitor_url_price_history`, then the oldest last-scraped finals, and wrap around instead of stopping at the highest `id`.
- For Stars Music, URL matching now also needs to propagate product price when the product page is successfully matched.
- Repeated `404/410` on final prices should be treated as URL health degradation, not as immediate hard deletion on first failure.
- For image repair, keep the batch small and respect the direct-scrape lock/pause behavior on Thomann.
- For image review, keep the batch OpenAI size to 10 pairs per request unless the prompt grows too large.

## Code References

- `dashboard/src/Controller/Api/CompetitiveIntelligenceApiController.php`
- `dashboard/src/Controller/CompetitiveIntelligenceController.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveBatchRunner.php`
- `dashboard/src/Service/CompetitiveIntelligence/FinalPriceBatchRunner.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveOrchestratorService.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveOrchestratorConfigStorage.php`
- `dashboard/src/Service/CompetitiveIntelligence/CompetitiveTaskLogService.php`
- `dashboard/src/Service/CompetitiveIntelligence/PrestashopProductBatchProvider.php`
- `dashboard/src/Service/CompetitiveIntelligence/FinalUrlPriceBatchProvider.php`
- `competitive_intelligence_python/competitive_intelligence/workers/url_job.py`
- `competitive_intelligence_python/competitive_intelligence/workers/price_job.py`
- `competitive_intelligence_python/jobs/thomann/new_urls.py`
- `competitive_intelligence_python/jobs/thomann/retry_urls.py`
- `competitive_intelligence_python/jobs/thomann/prices.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/woodbrass.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/starsmusic.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/thomann.py`
- `competitive_intelligence_python/competitive_intelligence/competitors/michenaud.py`
