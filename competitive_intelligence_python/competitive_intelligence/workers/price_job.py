from __future__ import annotations

import fcntl
import os
import random
import re
import time
from contextlib import contextmanager
from dataclasses import replace
from pathlib import Path

import requests
from bs4 import BeautifulSoup

from competitive_intelligence.core.api_client import ApiClient
from competitive_intelligence.core.config import Settings
from competitive_intelligence.core.http_client import HttpClient


@contextmanager
def competitor_run_lock(competitor_id: int):
    project_root = Path(__file__).resolve().parents[2]
    lock_dir = project_root / "var" / "lock" / "competitive-intelligence"
    lock_dir.mkdir(parents=True, exist_ok=True)

    lock_path = lock_dir / f"price-competitor-{competitor_id}.lock"
    with lock_path.open("a+") as lock_file:
        try:
            fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise RuntimeError(f"price batch already running for competitor_id={competitor_id}") from exc

        yield


def run_price_job(competitor_id: int | None = None, *, settings: Settings | None = None) -> None:
    settings = settings or Settings.from_env()
    if competitor_id is not None:
        settings = replace(settings, competitor_id=competitor_id)

    debug_enabled = os.environ.get("CI_DEBUG", "").lower() in {"1", "true", "yes", "on"}
    http = HttpClient(timeout=30)
    api = ApiClient(settings.api_base_url, settings.api_token, http)

    with competitor_run_lock(settings.competitor_id):
        batch = api.fetch_next_final_price_batch(
            competitor_id=settings.competitor_id,
            limit=settings.batch_limit,
            after_id=settings.after_id,
        )
        competitor = batch["competitor"]
        competitor_domain = str(competitor.get("domain") or "").lower()
        competitor_name = str(competitor.get("name") or "").lower()

        observations: list[dict[str, object]] = []
        failures: list[dict[str, object]] = []
        print(
            {
                "event": "batch_fetched",
                "competitor_id": competitor.get("id"),
                "items": len(batch["items"]),
                "after_id": batch["after_id"],
                "has_more": batch["has_more"],
                "debug": debug_enabled,
            },
            flush=True,
        )

        for item in batch["items"]:
            url = str(item.get("url") or "").strip()
            product_id = int(item.get("id_product") or 0)
            if product_id <= 0 or not url:
                continue

            try:
                if "thomann" in competitor_domain or "thomann" in competitor_name:
                    _human_pause()
                response = http.get(url, headers=_page_headers(competitor_domain))
                response.raise_for_status()
                price = _extract_price(competitor_domain, competitor_name, response.text)
            except requests.HTTPError as exc:
                status_code = exc.response.status_code if exc.response is not None else None
                print(
                    {
                        "event": "price_error",
                        "id_product": product_id,
                        "url": url,
                        "http_status": status_code,
                        "error": str(exc),
                    },
                    flush=True,
                )
                if status_code in {404, 410}:
                    failures.append(
                        {
                            "id_product": product_id,
                            "url": url,
                            "http_status": status_code,
                            "error": str(exc),
                        }
                    )
                continue
            except Exception as exc:
                print(
                    {
                        "event": "price_error",
                        "id_product": product_id,
                        "url": url,
                        "error": str(exc),
                    },
                    flush=True,
                )
                continue

            if price is None:
                print(
                    {
                        "event": "price_not_found",
                        "id_product": product_id,
                        "url": url,
                    },
                    flush=True,
                )
                continue

            observations.append(
                {
                    "id_product": product_id,
                    "url": url,
                    "price": price,
                    "source": _price_source_label(competitor_domain, competitor_name),
                }
            )
            print(
                {
                    "event": "price_found",
                    "id_product": product_id,
                    "price": price,
                    "url": url,
                },
                flush=True,
            )

        if observations or failures:
            api.submit_final_prices(
                {
                    "competitor_id": competitor.get("id"),
                    "observations": observations,
                    "failures": failures,
                }
            )
            print(
                {
                    "event": "price_batch_submitted",
                    "competitor_id": competitor.get("id"),
                    "observations": len(observations),
                    "failures": len(failures),
                },
                flush=True,
            )


def _human_pause() -> None:
    time.sleep(random.uniform(2.0, 5.0))


def _page_headers(domain: str) -> dict[str, str]:
    headers = {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/124.0.0.0 Safari/537.36"
        ),
        "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
    }
    if "woodbrass" in domain:
        headers["Referer"] = "https://www.woodbrass.com/"
    elif "thomann" in domain:
        headers["Referer"] = "https://www.thomann.fr/"
    elif "michenaud" in domain:
        headers["Referer"] = "https://www.michenaud.com/"
    elif "stars" in domain:
        headers["Referer"] = "https://www.stars-music.fr/"
    return headers


def _price_source_label(domain: str, name: str) -> str:
    if "woodbrass" in domain or "woodbrass" in name:
        return "woodbrass_html"
    if "thomann" in domain or "thomann" in name:
        return "thomann_html"
    if "michenaud" in domain or "michenaud" in name:
        return "michenaud_html"
    if "stars" in domain or "stars" in name:
        return "stars_html"
    return "generic_html"


def _extract_price(domain: str, name: str, html: str) -> float | None:
    if "woodbrass" in domain or "woodbrass" in name:
        price = _extract_woodbrass_price(html)
        if price is not None:
            return price

    if "thomann" in domain or "thomann" in name:
        price = _extract_thomann_price(html)
        if price is not None:
            return price

    if "stars" in domain or "stars" in name:
        price = _extract_stars_price(html)
        if price is not None:
            return price

    if "michenaud" in domain or "michenaud" in name:
        price = _extract_michenaud_price(html)
        if price is not None:
            return price

    price = _extract_meta_price(html)
    if price is not None:
        return price

    return _extract_fallback_price(html)


def _extract_woodbrass_price(html: str) -> float | None:
    soup = BeautifulSoup(html, "html.parser")
    selectors = [
        "div.fwb.fs40.fs30-md.fs28-sm.lh1",
        ".fwb.fs40.fs30-md.fs28-sm.lh1",
        ".col-20.wsnw",
    ]
    for selector in selectors:
        node = soup.select_one(selector)
        if node is None:
            continue
        price = _parse_price_text(node.get_text(" ", strip=True))
        if price is not None:
            return price
    return None


def _extract_thomann_price(html: str) -> float | None:
    soup = BeautifulSoup(html, "html.parser")
    selectors = [
        "div.price.fx-text.fx-text--no-margin",
        "div.price",
        ".price.fx-text",
    ]
    for selector in selectors:
        node = soup.select_one(selector)
        if node is None:
            continue
        price = _parse_price_text(node.get_text(" ", strip=True))
        if price is not None:
            return price

    match = re.search(r'itemprop="price"\s+content="([0-9]+(?:[.,][0-9]+)?)"', html, re.I)
    if match:
        return _parse_price_text(match.group(1))
    return None


def _extract_stars_price(html: str) -> float | None:
    soup = BeautifulSoup(html, "html.parser")
    selectors = [
        ".product-final-price",
        ".product-final-price .price-decimal",
    ]
    for selector in selectors:
        node = soup.select_one(selector)
        if node is None:
            continue
        price = _parse_price_text(node.get_text(" ", strip=True))
        if price is not None:
            return price
    return None


def _extract_michenaud_price(html: str) -> float | None:
    soup = BeautifulSoup(html, "html.parser")
    selectors = [
        "span.price",
        ".price",
    ]
    for selector in selectors:
        node = soup.select_one(selector)
        if node is None:
            continue
        price = _parse_price_text(node.get_text(" ", strip=True))
        if price is not None:
            return price
    return None


def _extract_meta_price(html: str) -> float | None:
    soup = BeautifulSoup(html, "html.parser")
    meta_selectors = [
        ('meta[itemprop="price"]', "content"),
        ('meta[property="product:price:amount"]', "content"),
        ('meta[property="og:price:amount"]', "content"),
    ]
    for selector, attribute in meta_selectors:
        node = soup.select_one(selector)
        if node is None:
            continue
        value = node.get(attribute)
        if value is not None:
            price = _parse_price_text(str(value))
            if price is not None:
                return price
    return None


def _extract_fallback_price(html: str) -> float | None:
    match = re.search(r"([0-9][0-9 .,\u00A0]*)\s*€", html, re.I)
    if match:
        return _parse_price_text(match.group(1))
    return None


def _parse_price_text(text: str) -> float | None:
    cleaned = text.replace("\u00A0", " ").replace("eur", "").replace("€", "").strip()
    match = re.search(r"([0-9][0-9\s.,]*)", cleaned)
    if not match:
        return None

    numeric = re.sub(r"\s+", "", match.group(1))
    if not numeric:
        return None

    if "," in numeric and "." in numeric:
        last_comma = numeric.rfind(",")
        last_dot = numeric.rfind(".")
        decimal_sep = "," if last_comma > last_dot else "."
        thousands_sep = "." if decimal_sep == "," else ","
        numeric = numeric.replace(thousands_sep, "")
        numeric = numeric.replace(decimal_sep, ".")
    elif numeric.count(",") > 1:
        numeric = numeric.replace(",", "")
    elif numeric.count(".") > 1:
        numeric = numeric.replace(".", "")
    else:
        separator = "," if "," in numeric else "." if "." in numeric else None
        if separator is not None:
            left, right = numeric.split(separator, 1)
            # `1.770 €` or `1,770 €` is a thousands format, not a decimal price.
            if right.isdigit() and len(right) == 3 and len(left) >= 1:
                numeric = left + right
            else:
                numeric = left + "." + right if separator == "," else numeric

    try:
        return float(numeric)
    except ValueError:
        return None


def main() -> None:
    run_price_job()


if __name__ == "__main__":
    main()
