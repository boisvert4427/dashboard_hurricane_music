from __future__ import annotations

import fcntl
import os
import time
from contextlib import contextmanager
from pathlib import Path

from competitive_intelligence.core.api_client import ApiClient
from competitive_intelligence.core.config import Settings
from competitive_intelligence.core.http_client import HttpClient
from competitive_intelligence.competitors.generic_search import GenericSearchScraper
from competitive_intelligence.competitors.michenaud import MichenaudScraper
from competitive_intelligence.competitors.starsmusic import StarsMusicScraper
from competitive_intelligence.competitors.thomann import NoBrandMatchError, ThomannScraper
from competitive_intelligence.competitors.woodbrass import WoodbrassScraper


@contextmanager
def competitor_run_lock(competitor_id: int, lang_id: int, shop_id: int):
    project_root = Path(__file__).resolve().parent
    lock_dir = project_root / "var" / "lock" / "competitive-intelligence"
    lock_dir.mkdir(parents=True, exist_ok=True)

    lock_path = lock_dir / f"competitor-{competitor_id}-lang-{lang_id}-shop-{shop_id}.lock"
    with lock_path.open("a+") as lock_file:
        try:
            fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise RuntimeError(
                f"batch already running for competitor_id={competitor_id}, lang_id={lang_id}, shop_id={shop_id}"
            ) from exc

        yield


@contextmanager
def global_parallel_slot(project_root: Path, max_parallel: int):
    if max_parallel <= 0:
        yield
        return

    lock_dir = project_root / "var" / "lock" / "competitive-intelligence" / "global-parallel"
    lock_dir.mkdir(parents=True, exist_ok=True)

    handles = []
    try:
        while True:
            for slot in range(1, max_parallel + 1):
                lock_path = lock_dir / f"slot-{slot}.lock"
                lock_file = lock_path.open("a+")
                try:
                    fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    handles.append(lock_file)
                    yield
                    return
                except BlockingIOError:
                    lock_file.close()
                    continue

            time.sleep(1)
    finally:
        for handle in handles:
            try:
                handle.close()
            except Exception:
                pass


def main() -> None:
    settings = Settings.from_env()
    debug_enabled = os.environ.get("CI_DEBUG", "").lower() in {"1", "true", "yes", "on"}
    http = HttpClient(timeout=30)
    api = ApiClient(settings.api_base_url, settings.api_token, http)

    try:
        project_root = Path(__file__).resolve().parent
        with competitor_run_lock(settings.competitor_id, settings.lang_id, settings.shop_id):
            with global_parallel_slot(project_root, settings.max_parallel):
                batch = api.fetch_next_batch(
                    competitor_id=settings.competitor_id,
                    limit=settings.batch_limit,
                    after_id=settings.after_id,
                    lang_id=settings.lang_id,
                    shop_id=settings.shop_id,
                )
                print(
                    {
                        "event": "batch_fetched",
                        "competitor_id": batch["competitor"]["id"],
                        "items": len(batch["items"]),
                        "after_id": batch["after_id"],
                        "has_more": batch["has_more"],
                        "debug": debug_enabled,
                    },
                    flush=True,
                )

                competitor = batch["competitor"]
                search_url_pattern = competitor["search_url_pattern"]
                domain = str(competitor.get("domain") or "").lower()
                competitor_name = str(competitor.get("name") or "").lower()
                if "woodbrass" in domain or "woodbrass" in competitor_name:
                    scraper = WoodbrassScraper(search_url_pattern=search_url_pattern, http=http, debug=debug_enabled)
                elif "stars-music" in domain or "stars music" in competitor_name or "starsmusic" in competitor_name:
                    scraper = StarsMusicScraper(search_url_pattern=search_url_pattern, http=http, debug=debug_enabled)
                elif "thomann" in domain or "thomann" in competitor_name:
                    scraper = ThomannScraper(search_url_pattern=search_url_pattern, http=http, debug=debug_enabled)
                elif "michenaud" in domain or "michenaud" in competitor_name:
                    scraper = MichenaudScraper(search_url_pattern=search_url_pattern, http=http, debug=debug_enabled)
                else:
                    scraper = GenericSearchScraper(search_url_pattern=search_url_pattern, http=http)

                total_candidates = 0
                total_tests = 0
                for product in batch["items"]:
                    product_id = int(product["id_product"])
                    print(
                        {
                            "event": "product_start",
                            "id_product": product_id,
                            "supplier_reference": product.get("supplier_reference"),
                            "name": product.get("name"),
                        },
                        flush=True,
                    )
                    tests: list[dict[str, object]] = []
                    try:
                        candidates = scraper.search(product)
                        print(
                            {
                                "event": "product_search_done",
                                "id_product": product_id,
                                "candidates": len(candidates),
                            },
                            flush=True,
                        )
                    except Exception as exc:
                        if isinstance(exc, NoBrandMatchError):
                            print(
                                {
                                    "event": "product_ignored",
                                    "id_product": product_id,
                                    "reason": str(exc),
                                },
                                flush=True,
                            )
                            continue
                        error_text = str(exc).lower()
                        if "cloudflare" in error_text or "cloudfare" in error_text:
                            test_result = "cloudflare"
                        elif "search input was not found" in error_text:
                            test_result = "search_input_not_found"
                        else:
                            test_result = "not_found"
                        tests.append(
                            {
                                "id_product": product_id,
                                "result": test_result,
                                "message": str(exc),
                            }
                        )
                        print(
                            {
                                "event": "product_error",
                                "id_product": product_id,
                                "error": str(exc),
                            },
                            flush=True,
                        )
                    else:
                        if candidates:
                            best_candidate = candidates[0]
                            if best_candidate.score < 30:
                                tests.append(
                                    {
                                        "id_product": best_candidate.id_product,
                                        "result": "not_found",
                                    }
                                )
                            else:
                                tests.append(
                                    {
                                        "id_product": best_candidate.id_product,
                                        "result": "matched" if best_candidate.score > 90 else "pending",
                                        "url": best_candidate.url,
                                        "competitor_title": best_candidate.title,
                                        "score": best_candidate.score,
                                        "matched_query": best_candidate.matched_query,
                                        "competitor_price": best_candidate.price,
                                    }
                                )
                        else:
                            tests.append(
                                {
                                    "id_product": int(product["id_product"]),
                                    "result": "not_found",
                                }
                            )

                        for candidate in candidates:
                            status = "valid" if candidate.score > 90 else candidate.status
                    total_candidates += len(candidates)
                    total_tests += len(tests)
                    print(
                        {
                            "event": "product_submit",
                            "id_product": product_id,
                            "results": len(candidates),
                            "tests": len(tests),
                        },
                        flush=True,
                    )
                    api.submit_candidates(
                        {
                            "competitor_id": competitor["id"],
                            "tests": tests,
                        }
                    )

            print(
                {
                    "competitor_id": competitor["id"],
                    "batch_items": len(batch["items"]),
                    "candidates": total_candidates,
                    "tests": total_tests,
                    "after_id": batch["after_id"],
                    "has_more": batch["has_more"],
                }
            )
    except RuntimeError as exc:
        print(
            {
                "event": "batch_skipped",
                "reason": str(exc),
            },
            flush=True,
        )


if __name__ == "__main__":
    main()
