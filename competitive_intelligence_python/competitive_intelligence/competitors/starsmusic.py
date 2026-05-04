from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
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


class StarsMusicScraper(CompetitorScraper):
    SEARCH_INPUT_TIMEOUT_MS = 12000
    SEARCH_RESULT_TIMEOUT_MS = 5000

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
        query = facts.name.strip()
        if not query:
            return []

        candidates: list[Candidate] = []
        seen_urls: set[str] = set()

        with browser_context(user_agent=None) as context:
            search_page = context.new_page()
            product_page = context.new_page()

            search_page.goto("https://www.stars-music.fr/", wait_until="domcontentloaded")
            search_page.wait_for_timeout(1000)
            self._accept_cookies(search_page)

            if self._is_cloudflare_page(search_page):
                self._dump_debug(search_page, query, "cloudflare-challenge", product_id=product_id)
                raise RuntimeError("Stars Music Cloudflare challenge encountered.")

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
                    """() => document.querySelectorAll('a.dfd-card-link[href]').length > 0""",
                    timeout=self.SEARCH_RESULT_TIMEOUT_MS,
                )
            except Exception:
                pass

            result_links = search_page.evaluate(
                """() => Array.from(document.querySelectorAll('a.dfd-card-link[href]'))
                    .slice(0, 3)
                    .map((anchor) => ({
                        href: anchor.getAttribute('href') || '',
                        title: (anchor.textContent || '').trim(),
                    }))"""
            )

            for item in result_links:
                href = str(item.get("href") or "").strip()
                title = str(item.get("title") or "").strip()
                if not href:
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
                        title=match.title,
                        source="stars_music_product_page",
                        score=100,
                        matched_query=query,
                    )
                )
                break

        candidates.sort(key=lambda item: item.score, reverse=True)
        return candidates[:5]

    def _wait_for_search_input(self, page, selector: str, timeout_ms: int = 30000):
        attempts = max(1, timeout_ms // 500)
        for _ in range(attempts):
            try:
                count = page.evaluate("selector => document.querySelectorAll(selector).length", selector)
                if count:
                    return page.locator(selector).first
            except Exception:
                pass
            page.wait_for_timeout(500)
        return None

    def _open_product_page_by_url(self, page, url: str, product_id: int | None = None):
        try:
            page.goto(url, wait_until="domcontentloaded")
        except Exception:
            return None

        page.wait_for_timeout(1000)

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
        spec_items = [normalize_text(item.get_text(" ", strip=True)) for item in soup.select("ul.product-specs li.spec-item")]
        ref_norm = normalize_text(facts.supplier_reference)
        matched_ref = False
        if len(spec_items) > 1:
            matched_ref = self._contains_normalized_reference(spec_items[1], ref_norm)
        if not matched_ref and ref_norm:
            matched_ref = any(self._contains_normalized_reference(item, ref_norm) for item in spec_items)
        return StarsMusicMatch(
            url=url,
            title=title.strip() or text.strip(),
            matched_ref=matched_ref,
        )

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
