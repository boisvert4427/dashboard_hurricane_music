from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import json
import hashlib
import re
from typing import Iterable
from urllib.parse import urljoin, urlparse

from bs4 import BeautifulSoup

from ..core.browser import browser_context
from ..core.normalization import normalize_text
from ..core.scoring import ProductFacts
from .base import Candidate, CompetitorScraper


@dataclass(frozen=True)
class StarsMusicMatch:
    url: str
    title: str
    matched_ref: bool
    price: float | None = None


class StarsMusicScraper(CompetitorScraper):
    SEARCH_INPUT_TIMEOUT_MS = 2500
    SEARCH_RESULT_TIMEOUT_MS = 1500

    def __init__(self, search_url_pattern: str, http, debug: bool = False, debug_dir: str = "debug"):
        super().__init__(search_url_pattern=search_url_pattern, http=http)
        self.debug = debug
        self.debug_dir = Path(debug_dir)

    def parse_results(self, html: str, query: str) -> Iterable[tuple[str, str]]:
        return []

    def search(self, product: dict[str, object]) -> list[Candidate]:
        facts = ProductFacts(
            supplier_reference=str(product.get("supplier_reference") or ""),
            ean=str(product.get("ean") or ""),
            brand=str(product.get("brand") or ""),
            name=str(product.get("name") or ""),
        )
        product_id = int(product["id_product"])
        queries = [facts.name.strip()]
        if not queries:
            return []

        candidates: list[Candidate] = []
        seen_urls: set[str] = set()

        with browser_context(user_agent=None) as context:
            search_page = context.new_page()
            product_page = context.new_page()
            search_page.goto("https://www.stars-music.fr/", wait_until="domcontentloaded")
            search_page.wait_for_timeout(250)
            self._accept_cookies(search_page)

            if self._is_cloudflare_page(search_page):
                self._dump_debug(search_page, "", "cloudflare-challenge", product_id=product_id)
                raise RuntimeError("Stars Music Cloudflare challenge encountered.")

            for query in queries:
                self._dump_search_state(search_page, query, "search-before", product_id=product_id)

                search_input = self._wait_for_search_input(search_page, 'input[name="search-input-textbar"]')
                if search_input is None:
                    self._dump_search_state(search_page, query, "search-input-not-found-state", product_id=product_id)
                    self._dump_debug(search_page, query, "search-input-not-found", product_id=product_id)
                    raise RuntimeError("Stars Music search input was not found on the page.")

                try:
                    search_input.fill(query)
                except Exception:
                    self._dump_debug(search_page, query, "search-input-not-found", product_id=product_id)
                    raise RuntimeError("Stars Music search input was not found on the page.")

                try:
                    search_page.locator(".submitSearch").first.click(force=True)
                except Exception:
                    try:
                        search_input.press("Enter")
                    except Exception:
                        pass

                try:
                    search_page.wait_for_function(
                        """() => document.querySelectorAll('.dfd-card').length > 0""",
                        timeout=self.SEARCH_RESULT_TIMEOUT_MS,
                    )
                except Exception:
                    pass

                result_cards = search_page.evaluate(
                    """() => Array.from(document.querySelectorAll('.dfd-card'))
                        .slice(0, 10)
                        .map((card) => {
                            const anchor = card.querySelector('a[href]');
                            return {
                                href: anchor ? (anchor.getAttribute('href') || '') : '',
                                title: (card.querySelector('.dfd-card-title')?.textContent || card.textContent || '').trim(),
                                text: (card.innerText || '').trim(),
                            };
                        })"""
                )

                if not isinstance(result_cards, list):
                    continue

                for item in result_cards:
                    href = str(item.get("href") or "").strip()
                    title = str(item.get("title") or "").strip()
                    text = str(item.get("text") or "").strip()
                    if not href:
                        continue
                    if href.endswith("/all") or "/all" in href:
                        continue

                    candidate_url = urljoin(search_page.url, href)
                    if not self._is_stars_music_url(candidate_url):
                        continue

                    product_result = self._open_product_page_by_url(product_page, candidate_url, product_id=product_id)
                    if product_result is None:
                        continue

                    match = self._match_product_page(product_result, facts)
                    if not match.matched_ref:
                        continue

                    normalized_url = candidate_url.strip()
                    if normalized_url in seen_urls:
                        continue

                    seen_urls.add(normalized_url)
                    candidates.append(
                        Candidate(
                            id_product=product_id,
                            url=normalized_url,
                            title=match.title or title or text,
                            source="stars_music_product_page",
                            score=100,
                            matched_query=query,
                            price=match.price,
                        )
                    )
                    break

        candidates.sort(key=lambda item: item.score, reverse=True)
        return candidates[:5]

    def _wait_for_search_input(self, page, selector: str, timeout_ms: int = 30000):
        attempts = max(1, timeout_ms // 200)
        for _ in range(attempts):
            try:
                count = page.evaluate("selector => document.querySelectorAll(selector).length", selector)
                if count:
                    return page.locator(selector).first
            except Exception:
                pass
            page.wait_for_timeout(150)
        return None

    def _open_product_page_by_url(self, page, url: str, product_id: int | None = None):
        try:
            page.goto(url, wait_until="domcontentloaded")
        except Exception:
            return None

        page.wait_for_timeout(400)

        if self._is_cloudflare_page(page):
            self._dump_debug(page, url, "cloudflare-challenge", product_id=product_id)
            raise RuntimeError("Stars Music Cloudflare challenge encountered.")

        html = page.content()
        title = page.title() or ""
        text = page.locator("body").inner_text(timeout=2000) if page.locator("body").count() else ""

        if "Page non trouvée" in title or "Il n'y a aucun résultat" in text:
            return None

        return title, html, text, page.url

    def _match_product_page(self, product_page: tuple[str, str, str, str], facts: ProductFacts) -> StarsMusicMatch:
        title, html, text, url = product_page
        soup = BeautifulSoup(html, "html.parser")
        spec_texts = [item.get_text(" ", strip=True) for item in soup.select("ul.product-specs li.spec-item")]
        ref_norm = normalize_text(facts.supplier_reference)
        ean_norm = normalize_text(facts.ean)
        matched_ref = False
        matched_ean = False
        detected_price = self._extract_price(soup)
        spec_sku = self._extract_spec_value(spec_texts, ("sku", "référence", "reference", "ref"))
        spec_ean = self._extract_spec_value(spec_texts, ("ean", "gtin"))

        for script in soup.select('script[type="application/ld+json"]'):
            script_text = script.get_text(" ", strip=True)
            if not script_text:
                continue
            try:
                payload = json.loads(script_text)
            except Exception:
                continue

            if isinstance(payload, dict):
                entries = [payload]
            elif isinstance(payload, list):
                entries = [item for item in payload if isinstance(item, dict)]
            else:
                entries = []

            for entry in entries:
                if not matched_ref:
                    for key in ("mpn", "sku"):
                        value = normalize_text(str(entry.get(key) or ""))
                        if value and ref_norm and self._same_normalized_reference(value, ref_norm):
                            matched_ref = True
                            break
                if not matched_ean:
                    for key in ("gtin13", "gtin", "ean"):
                        value = normalize_text(str(entry.get(key) or ""))
                        if value and ean_norm and self._same_normalized_reference(value, ean_norm):
                            matched_ean = True
                            break

        if not matched_ref and ref_norm and spec_sku:
            matched_ref = self._same_normalized_reference(spec_sku, ref_norm)
        if not matched_ean and ean_norm and spec_ean:
            matched_ean = self._same_normalized_reference(spec_ean, ean_norm)
        return StarsMusicMatch(
            url=url,
            title=title.strip() or text.strip(),
            matched_ref=matched_ref or matched_ean,
            price=detected_price,
        )

    def _extract_price(self, soup: BeautifulSoup) -> float | None:
        selectors = [
            ".product-final-price",
            ".product-final-price .price-decimal",
        ]
        for selector in selectors:
            node = soup.select_one(selector)
            if node is None:
                continue
            price = self._parse_price_text(node.get_text(" ", strip=True))
            if price is not None:
                return price

        for selector, attribute in [
            ('meta[itemprop="price"]', "content"),
            ('meta[property="product:price:amount"]', "content"),
            ('meta[property="og:price:amount"]', "content"),
        ]:
            node = soup.select_one(selector)
            if node is None:
                continue
            value = node.get(attribute)
            if value is None:
                continue
            price = self._parse_price_text(str(value))
            if price is not None:
                return price

        for script in soup.select('script[type="application/ld+json"]'):
            script_text = script.get_text(" ", strip=True)
            if not script_text:
                continue
            try:
                payload = json.loads(script_text)
            except Exception:
                continue

            entries = [payload] if isinstance(payload, dict) else [item for item in payload if isinstance(item, dict)] if isinstance(payload, list) else []
            for entry in entries:
                offers = entry.get("offers")
                if isinstance(offers, dict):
                    price = self._extract_price_from_offer(offers)
                    if price is not None:
                        return price

        return None

    def _extract_price_from_offer(self, offer: dict[str, object]) -> float | None:
        direct_price = self._parse_price_text(str(offer.get("price") or ""))
        if direct_price is not None:
            return direct_price

        price_specification = offer.get("priceSpecification")
        if isinstance(price_specification, dict):
            return self._parse_price_text(str(price_specification.get("price") or ""))

        return None

    def _extract_spec_value(self, spec_items: list[str], labels: tuple[str, ...]) -> str:
        for raw_text in spec_items:
            normalized = normalize_text(raw_text)
            for label in labels:
                prefix = normalize_text(label)
                if not normalized.startswith(prefix):
                    continue
                value = raw_text[len(raw_text.split(" ", 1)[0]):].strip()
                value = re.sub(r"^[\s:|-]+", "", value)
                normalized_value = normalize_text(value)
                if normalized_value:
                    return normalized_value
        return ""

    def _same_normalized_reference(self, value_norm: str, reference_norm: str) -> bool:
        if not value_norm or not reference_norm:
            return False

        value_compact = value_norm.replace(" ", "").replace("-", "")
        reference_compact = reference_norm.replace(" ", "").replace("-", "")
        return value_norm == reference_norm or value_compact == reference_compact

    def _parse_price_text(self, text: str) -> float | None:
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
                if right.isdigit() and len(right) == 3 and len(left) >= 1:
                    numeric = left + right
                else:
                    numeric = left + "." + right if separator == "," else numeric

        try:
            return float(numeric)
        except ValueError:
            return None

    def _contains_normalized_reference(self, text_norm: str, reference_norm: str) -> bool:
        if not text_norm or not reference_norm:
            return False

        pattern = rf"(?<![a-z0-9]){re.escape(reference_norm)}(?![a-z0-9])"
        return re.search(pattern, text_norm) is not None

    def _dump_debug(self, page, query: str, label: str, html_text: str | None = None, product_id: int | None = None) -> None:
        if not self.debug:
            return

        self.debug_dir.mkdir(parents=True, exist_ok=True)
        slug = self._debug_slug(query)
        prefix = f"starsmusic-{label}"
        if product_id is not None:
            prefix = f"{prefix}-id-{product_id}"
        base = self.debug_dir / f"{prefix}-{slug}"

        try:
            page.screenshot(path=str(base.with_suffix(".png")), full_page=True)
        except Exception:
            pass

    def _dump_search_state(self, page, query: str, label: str, product_id: int | None = None) -> None:
        if not self.debug:
            return

        self.debug_dir.mkdir(parents=True, exist_ok=True)
        slug = self._debug_slug(query)
        prefix = f"starsmusic-{label}"
        if product_id is not None:
            prefix = f"{prefix}-id-{product_id}"
        base = self.debug_dir / f"{prefix}-{slug}"

        try:
            page.screenshot(path=str(base.with_suffix(".png")), full_page=True)
        except Exception:
            pass

    def _debug_slug(self, value: str) -> str:
        cleaned = re.sub(r"[^a-zA-Z0-9]+", "-", value.strip()).strip("-")
        if not cleaned:
            cleaned = "query"
        digest = hashlib.sha1(value.encode("utf-8")).hexdigest()[:10]
        return f"{cleaned[:40]}-{digest}"

    def _is_cloudflare_page(self, page) -> bool:
        try:
            title = (page.title() or "").lower()
        except Exception:
            title = ""

        try:
            text = (page.evaluate("() => document.body && document.body.innerText ? document.body.innerText : ''") or "").lower()
        except Exception:
            text = ""

        try:
            url = (page.url or "").lower()
        except Exception:
            url = ""

        signals = [
            "performing security verification",
            "just a moment",
            "verify you are human",
            "cloudflare",
        ]
        return any(signal in title or signal in text or signal in url for signal in signals)

    def _accept_cookies(self, page) -> None:
        candidates = [
            "button:has-text('Fermer')",
            "button:has-text('Accepter')",
            "#iubenda-cs-banner .iubenda-cs-close-btn",
            "#iubenda-cs-banner button",
        ]
        for selector in candidates:
            try:
                locator = page.locator(selector).first
                if locator.count() > 0:
                    locator.click(timeout=1500, force=True)
                    page.wait_for_timeout(300)
                    return
            except Exception:
                continue

    def _is_stars_music_url(self, url: str) -> bool:
        try:
            parsed = urlparse(url)
        except Exception:
            return False
        return "stars-music.fr" in (parsed.netloc or "").lower()
