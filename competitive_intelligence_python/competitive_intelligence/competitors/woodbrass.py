from __future__ import annotations

from dataclasses import dataclass
import hashlib
import json
from pathlib import Path
import re
from typing import Iterable
from urllib.parse import quote_plus, urljoin, urlparse

from bs4 import BeautifulSoup

from ..core.normalization import normalize_text, simplify_name
from ..core.scoring import ProductFacts
from .base import Candidate, CompetitorScraper


@dataclass(frozen=True)
class WoodbrassMatch:
    url: str
    title: str
    matched_ref: bool
    matched_ean: bool


class WoodbrassScraper(CompetitorScraper):
    SEARCH_INPUT_TIMEOUT_MS = 12000
    SEARCH_RESULT_TIMEOUT_MS = 3000
    ALGOLIA_APP_ID = "F94UOU1BDS"
    ALGOLIA_API_KEY = "215f25fe764a4c9f287e726db062368f"
    ALGOLIA_INDEX = "production_woodbrass_products_fr"

    def __init__(self, search_url_pattern: str, http, debug: bool = False, debug_dir: str = "debug"):
        super().__init__(search_url_pattern=search_url_pattern, http=http)
        self.debug = debug
        self.debug_dir = Path(debug_dir)

    def parse_results(self, html: str, query: str) -> Iterable[tuple[str, str]]:
        # Woodbrass uses a browser-driven path, so this fallback is not used.
        return []

    def search(self, product: dict[str, object]) -> list[Candidate]:
        facts = ProductFacts(
            supplier_reference=str(product.get("supplier_reference") or ""),
            ean=str(product.get("ean") or ""),
            brand=str(product.get("brand") or ""),
            name=str(product.get("name") or ""),
        )
        product_id = int(product["id_product"])
        queries = [
            facts.ean,
            facts.supplier_reference,
            f"{facts.brand} {facts.supplier_reference}".strip(),
            simplify_name(facts.name),
            facts.name,
        ]

        candidates: list[Candidate] = []
        seen_urls: set[str] = set()

        for query in [q for q in queries if q]:
            for hit in self._search_algolia(query):
                url = str(hit.get("url") or "").strip()
                title = str(hit.get("name") or "").strip()
                if not url or not title:
                    continue
                if not self._is_woodbrass_url(url):
                    continue
                if url in seen_urls:
                    continue

                score = self._score_hit(hit, query, facts)
                if score < 90:
                    continue

                verified, page_title, candidate_price = self._verify_product_page(url, hit, facts)
                if not verified:
                    continue

                seen_urls.add(url)
                candidates.append(
                    Candidate(
                        id_product=product_id,
                        url=url,
                        title=page_title or title,
                        source="woodbrass_algolia",
                        score=score,
                        matched_query=query,
                        price=candidate_price,
                    )
                )

        candidates.sort(key=lambda item: item.score, reverse=True)
        return candidates[:5]

    def _search_algolia(self, query: str) -> list[dict[str, object]]:
        payload = {
            "params": f"query={quote_plus(query)}&hitsPerPage=5",
        }
        url = f"https://{self.ALGOLIA_APP_ID}-dsn.algolia.net/1/indexes/{self.ALGOLIA_INDEX}/query"
        try:
            response = self.http.post(
                url,
                json=payload,
                headers={
                    "X-Algolia-API-Key": self.ALGOLIA_API_KEY,
                    "X-Algolia-Application-Id": self.ALGOLIA_APP_ID,
                    "Content-Type": "application/json",
                },
            )
            response.raise_for_status()
        except Exception:
            return []

        try:
            data = response.json()
        except Exception:
            return []

        hits = data.get("hits", [])
        if isinstance(hits, list):
            return [hit for hit in hits if isinstance(hit, dict)]
        return []

    def _score_hit(self, hit: dict[str, object], query: str, facts: ProductFacts) -> int:
        title = str(hit.get("name") or "")
        manufacturer = str(hit.get("manufacturer") or "")
        supplier_reference = str(hit.get("supplier_reference") or "")
        ean = str(hit.get("ean") or "")

        score = 0
        title_norm = normalize_text(title)
        query_norm = normalize_text(query)
        brand_norm = normalize_text(facts.brand)
        name_norm = normalize_text(facts.name)
        supplier_norm = normalize_text(facts.supplier_reference)
        ean_norm = self._normalize_valid_ean(facts.ean)
        manufacturer_norm = normalize_text(manufacturer)
        hit_ean_norm = self._normalize_valid_ean(ean)

        if ean_norm and hit_ean_norm and ean_norm == hit_ean_norm:
            score += 100
        elif ean_norm and len(ean_norm) >= 8 and ean_norm in title_norm:
            score += 95

        if supplier_norm and supplier_norm == normalize_text(supplier_reference):
            score += 70
        elif supplier_norm and supplier_norm in title_norm:
            score += 55

        if brand_norm and brand_norm in title_norm:
            score += 25
        if brand_norm and brand_norm in normalize_text(manufacturer_norm):
            score += 15

        name_tokens = [token for token in name_norm.split() if len(token) > 2]
        matched_tokens = sum(1 for token in name_tokens if token in title_norm)
        if name_tokens:
            score += min(30, matched_tokens * 6)

        if query_norm and query_norm in title_norm:
            score += 20

        accessory_tokens = {
            "case",
            "cover",
            "bundle",
            "pack",
            "sleeve",
            "bag",
            "stand",
            "mount",
            "holder",
            "adapter",
            "protector",
            "protection",
            "decksaver",
            "flight",
            "rack",
            "lid",
        }
        if any(token in title_norm for token in accessory_tokens):
            score -= 35

        if "used" in title_norm or "occasion" in title_norm or "reconditionne" in title_norm or "b stock" in title_norm:
            score -= 50

        return max(0, min(100, int(round(score))))

    def _verify_product_page(self, url: str, hit: dict[str, object], facts: ProductFacts) -> tuple[bool, str, float | None]:
        try:
            response = self.http.get(
                url,
                headers={
                    "User-Agent": (
                        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                        "AppleWebKit/537.36 (KHTML, like Gecko) "
                        "Chrome/124.0.0.0 Safari/537.36"
                    ),
                    "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8",
                    "Referer": "https://www.woodbrass.com/",
                },
            )
            response.raise_for_status()
        except Exception:
            return False, "", None

        html = response.text
        soup = BeautifulSoup(html, "html.parser")
        page_title = soup.title.get_text(" ", strip=True) if soup.title else ""
        body_text = normalize_text(soup.get_text(" ", strip=True))
        title_text = normalize_text(page_title)
        hit_title = normalize_text(str(hit.get("name") or ""))
        hit_brand = normalize_text(str(hit.get("manufacturer") or ""))
        hit_ref = normalize_text(str(hit.get("supplier_reference") or ""))
        hit_ean = self._normalize_valid_ean(str(hit.get("ean") or ""))
        source_ref = normalize_text(facts.supplier_reference)
        source_ean = self._normalize_valid_ean(facts.ean)
        source_brand = normalize_text(facts.brand)
        page_ref = self._extract_reference_label_value(html, soup)
        candidate_price = self._extract_candidate_price(hit, html)

        if source_ref and page_ref and source_ref == page_ref:
            if not source_brand or not hit_brand or source_brand == hit_brand or source_brand in hit_brand or hit_brand in source_brand:
                return True, page_title, candidate_price

        if source_ean and hit_ean and source_ean == hit_ean:
            return True, page_title, candidate_price

        if source_ref and hit_ref and source_ref == hit_ref:
            if not source_brand or not hit_brand or source_brand == hit_brand or source_brand in hit_brand or hit_brand in source_brand:
                return True, page_title, candidate_price

        # Fallback: require the page title itself to carry the expected product signals.
        title_has_ref = bool(source_ref) and source_ref in title_text
        title_has_ean = bool(source_ean) and source_ean in title_text
        title_has_brand = bool(source_brand) and source_brand in title_text
        body_has_brand = bool(source_brand) and source_brand in body_text

        if title_has_ean:
            return True, page_title, candidate_price

        if title_has_ref and (title_has_brand or body_has_brand):
            return True, page_title, candidate_price

        if hit_title and hit_title in title_text and (not source_brand or title_has_brand or body_has_brand):
            return True, page_title, candidate_price

        return False, page_title, candidate_price

    def _extract_candidate_price(self, hit: dict[str, object], html: str) -> float | None:
        for key in ("price", "prix", "sale_price", "price_value"):
            candidate = self._coerce_price(hit.get(key))
            if candidate is not None:
                return candidate

        patterns = [
            r'"price"\s*:\s*"?(?P<value>\d+(?:[.,]\d+)?)',
            r'"salePrice"\s*:\s*"?(?P<value>\d+(?:[.,]\d+)?)',
            r'"price_amount"\s*:\s*"?(?P<value>\d+(?:[.,]\d+)?)',
            r'itemprop="price"\s+content="(?P<value>\d+(?:[.,]\d+)?)"',
        ]
        for pattern in patterns:
            match = re.search(pattern, html, re.I)
            if not match:
                continue
            candidate = self._coerce_price(match.group("value"))
            if candidate is not None:
                return candidate

        return None

    def _coerce_price(self, value: object) -> float | None:
        if value is None:
            return None

        text = str(value).strip()
        if text == "":
            return None

        text = text.replace(" ", "").replace("\xa0", "")
        if "," in text and "." in text:
            if text.rfind(",") > text.rfind("."):
                text = text.replace(".", "").replace(",", ".")
            else:
                text = text.replace(",", "")
        elif "," in text:
            text = text.replace(",", ".")

        if not re.fullmatch(r"\d+(?:\.\d+)?", text):
            return None

        try:
            price = float(text)
        except ValueError:
            return None

        return price if price > 0 else None

    def _normalize_valid_ean(self, value: object) -> str:
        normalized = normalize_text(str(value or ""))
        digits = "".join(ch for ch in normalized if ch.isdigit())
        if digits == "":
            return ""
        if len(set(digits)) == 1 and digits[0] == "0":
            return ""
        if len(digits) not in {8, 12, 13, 14}:
            return ""
        return digits

    def _extract_reference_label_value(self, html: str, soup: BeautifulSoup) -> str | None:
        normalized_html = " ".join(str(html).split())
        patterns = [
            r"Référence\s+marque\s*:\s*</[^>]+>\s*<[^>]+>\s*([^<]+?)\s*<",
            r"Référence\s+marque\s*:\s*([^<\n\r]+)",
            r"Reference\s+marque\s*:\s*([^<\n\r]+)",
        ]

        for pattern in patterns:
            match = re.search(pattern, normalized_html, re.I)
            if match:
                value = normalize_text(match.group(1))
                if value:
                    return value

        for label in soup.find_all(string=re.compile(r"Référence\s+marque\s*:", re.I)):
            text = str(label)
            inline = re.search(r"Référence\s+marque\s*:\s*(.+)$", text, re.I)
            if inline:
                value = normalize_text(inline.group(1))
                if value:
                    return value

            parent = getattr(label, "parent", None)
            if parent is None:
                continue

            next_text = parent.get_text(" ", strip=True)
            next_text = re.sub(r"^.*Référence\s+marque\s*:\s*", "", next_text, flags=re.I)
            value = normalize_text(next_text)
            if value:
                return value

            sibling = parent.find_next_sibling()
            if sibling is not None:
                value = normalize_text(sibling.get_text(" ", strip=True))
                if value:
                    return value

        return None

    def _search_and_extract(self, search_page, product_page, query: str, product_id: int | None = None) -> Iterable[Candidate]:
        selector = "input.ais-SearchBox-input.search-input.keywords"
        search_input = self._wait_for_search_input(search_page, selector, timeout_ms=self.SEARCH_INPUT_TIMEOUT_MS)
        if search_input is None:
            search_input = self._reload_search_page(search_page, selector)
        if search_input is None:
            if self._is_cloudflare_page(search_page):
                self._dump_debug(search_page, query, "cloudflare-challenge", product_id=product_id)
                raise RuntimeError("Woodbrass Cloudflare challenge encountered.")
            self._dump_search_state(search_page, query, "search-input-not-found-state", product_id=product_id)
            self._dump_debug(search_page, query, "search-input-not-found", product_id=product_id)
            raise RuntimeError("Woodbrass search input was not found on the page.")

        try:
            search_input.scroll_into_view_if_needed(timeout=2000)
        except Exception:
            pass
        search_page.wait_for_timeout(150)
        search_input.fill(query)
        search_page.evaluate(
            """selector => {
                const input = document.querySelector(selector);
                if (!input) return;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'a' }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }""",
            selector,
        )

        try:
            search_page.wait_for_function(
                """() => document.querySelectorAll('ol.ais-Hits-list li.ais-Hits-item a.box-product[href], li.ais-Hits-item a.box-product[href]').length > 0""",
                timeout=self.SEARCH_RESULT_TIMEOUT_MS,
            )
        except Exception:
            pass

        result_links = search_page.evaluate(
            """() => Array.from(document.querySelectorAll('ol.ais-Hits-list li.ais-Hits-item a.box-product[href], li.ais-Hits-item a.box-product[href]'))
                .slice(0, 3)
                .map((anchor) => ({
                    href: anchor.getAttribute('href') || '',
                    title: (anchor.querySelector('img[title]')?.getAttribute('title') || anchor.textContent || '').trim(),
                }))"""
        )

        for item in result_links:
            href = str(item.get("href") or "").strip()
            title = str(item.get("title") or "").strip()
            if not href:
                continue

            candidate_url = urljoin(search_page.url, href)
            if not self._is_woodbrass_url(candidate_url):
                continue

            product_result = self._open_product_page_by_url(product_page, candidate_url)
            if product_result is None:
                continue

            page_title, _page_html, _page_text, page_url = product_result
            yield Candidate(
                id_product=0,
                url=page_url,
                title=title or page_title,
                source="woodbrass_product_page",
                score=0,
                matched_query=query,
            )

    def _wait_for_search_input(self, page, selector: str, timeout_ms: int = 30000):
        attempts = max(1, timeout_ms // 500)
        for _ in range(attempts):
            try:
                count = page.evaluate(
                    """selector => document.querySelectorAll(selector).length""",
                    selector,
                )
                if count:
                    return page.locator(selector).first
            except Exception:
                pass
            page.wait_for_timeout(500)
        return None

    def _reload_search_page(self, page, selector: str):
        try:
            page.reload(wait_until="domcontentloaded")
        except Exception:
            return None

        page.wait_for_timeout(500)
        self._accept_cookies(page)
        self._dismiss_overlays(page)

        if self._is_cloudflare_page(page):
            return None

        return self._wait_for_search_input(page, selector, timeout_ms=self.SEARCH_INPUT_TIMEOUT_MS)

    def _dump_debug(self, page, query: str, label: str, html_text: str | None = None, product_id: int | None = None) -> None:
        if not self.debug:
            return

        self.debug_dir.mkdir(parents=True, exist_ok=True)
        slug = self._debug_slug(query)
        prefix = f"woodbrass-{label}"
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
        prefix = f"woodbrass-{label}"
        if product_id is not None:
            prefix = f"{prefix}-id-{product_id}"
        base = self.debug_dir / f"{prefix}-{slug}"

        try:
            page.screenshot(path=str(base.with_suffix(".png")), full_page=True)
        except Exception:
            pass

        try:
            page.evaluate(
                """(selector) => {
                    return {
                        url: window.location.href,
                        title: document.title,
                        inputCount: document.querySelectorAll('input').length,
                        searchSelectorCount: document.querySelectorAll(selector).length,
                    };
                }""",
                "input.ais-SearchBox-input.search-input.keywords",
            )
        except Exception:
            pass

    def _debug_slug(self, value: str) -> str:
        cleaned = "".join(ch if ch.isalnum() else "-" for ch in value).strip("-")
        cleaned = cleaned[:80] if cleaned else "query"
        digest = hashlib.sha1(value.encode("utf-8", errors="ignore")).hexdigest()[:10]
        return f"{cleaned}-{digest}"

    def _accept_cookies(self, page) -> None:
        candidates = [
            "text=ALLOW ALL",
            "text=Allow all",
            "text=Accept all",
            "text=Tout accepter",
            "text=J'accepte",
            "text=Accepter",
            "text=Accepter et fermer",
            "text=Accepter tout",
            "text=Autoriser tous",
            "text=Continuer sans accepter",
            "text=Continue without accepting",
            "button:has-text('Accept all')",
            "button:has-text('ALLOW ALL')",
            "button:has-text('Allow all')",
            "button:has-text('Tout accepter')",
            "button:has-text('J'accepte')",
            "button:has-text('Accepter')",
            "button:has-text('Accepter et fermer')",
            "button:has-text('Accepter tout')",
            "button:has-text('Autoriser tous')",
            "button:has-text('Continuer sans accepter')",
            "button:has-text('Continue without accepting')",
            "[aria-label*='accept' i]",
            "[aria-label*='cookie' i]",
        ]
        for frame in [page] + list(page.frames):
            for selector in candidates:
                try:
                    locator = frame.locator(selector).first
                    if locator.count() > 0:
                        locator.click(timeout=1500)
                        page.wait_for_timeout(1000)
                        return
                except Exception:
                    continue

    def _dismiss_overlays(self, page) -> None:
        candidates = [
            "button:has-text('Accept')",
            "button:has-text('I agree')",
            "button:has-text('OK')",
            "button:has-text('Close')",
            "button:has-text('Fermer')",
            "button:has-text('Non merci')",
            "button:has-text('Je refuse')",
            "button:has-text('Plus tard')",
            "button:has-text('Tout accepter')",
            "button:has-text('Accepter')",
            "button:has-text('Accepter et fermer')",
            "[aria-label='Close']",
            "[aria-label*='close' i]",
            ".cookie button",
            ".modal button",
            ".popup button",
        ]
        for frame in [page] + list(page.frames):
            for selector in candidates:
                try:
                    locator = frame.locator(selector).first
                    if locator.count() > 0:
                        locator.click(timeout=1500)
                        page.wait_for_timeout(300)
                        return
                except Exception:
                    continue

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
            "enable javascript and cookies to continue",
            "performing security verification",
            "just a moment",
            "verify you are human",
            "attention required",
            "access denied",
        ]
        haystack = " ".join([title, text, url])
        return any(signal in haystack for signal in signals)

    def _extract_result_links(self, html: str, base_url: str) -> Iterable[Candidate]:
        soup = BeautifulSoup(html, "html.parser")
        seen: set[str] = set()
        items = soup.select("ol.ais-Hits-list li.ais-Hits-item a.box-product[href]")
        if not items:
            items = soup.select("li.ais-Hits-item a.box-product[href]")

        for anchor in items[:3]:
            href = anchor.get("href") or ""
            if not href:
                continue

            absolute_url = urljoin(base_url, href)
            parsed = urlparse(absolute_url)
            if parsed.netloc and "woodbrass.com" not in parsed.netloc:
                continue
            if absolute_url in seen:
                continue

            title = self._extract_anchor_title(anchor)
            if title == "":
                title = absolute_url

            seen.add(absolute_url)
            yield Candidate(
                id_product=0,
                url=absolute_url,
                title=title,
                source="search_result",
                score=0,
                matched_query="",
            )

    def _extract_link_title(self, link) -> str:
        text = (link.get_attribute("aria-label") or "").strip()
        if text:
            return text

        try:
            text = link.inner_text(timeout=2000).strip()
            if text:
                return text
        except Exception:
            pass

        try:
            title = link.locator("img[title]").first.get_attribute("title")
            if title:
                return title.strip()
        except Exception:
            pass

        return ""

    def _is_woodbrass_url(self, url: str) -> bool:
        parsed = urlparse(url)
        return "woodbrass.com" in parsed.netloc

    def _open_product_page_by_url(self, page, candidate_url: str, product_id: int | None = None):
        try:
            page.goto(candidate_url, wait_until="domcontentloaded", timeout=15000)
            page.wait_for_timeout(1000)
        except Exception:
            return None

        if self._is_cloudflare_page(page):
            return None
        page_url = page.url
        html = page.content()
        soup = BeautifulSoup(html, "html.parser")
        title = soup.title.get_text(" ", strip=True) if soup.title else page_url
        text = soup.get_text(" ", strip=True)
        return title, html, text, page_url

    def _extract_anchor_title(self, anchor) -> str:
        image = anchor.select_one("img[title]")
        if image and image.get("title"):
            return str(image.get("title")).strip()

        title_node = anchor.select_one(".overflowHidden span, .overflowHidden")
        if title_node:
            return title_node.get_text(" ", strip=True)

        brand_node = anchor.select_one(".tca")
        product_node = anchor.select_one(".overflowHidden")
        pieces = [
            brand_node.get_text(" ", strip=True) if brand_node else "",
            product_node.get_text(" ", strip=True) if product_node else "",
        ]
        return " ".join(piece for piece in pieces if piece).strip()

    def _match_product_page(self, product_page: tuple[str, str, str, str], facts: ProductFacts) -> WoodbrassMatch:
        title, html, text, url = product_page
        text_norm = normalize_text(text)
        brand_reference = self._extract_brand_reference(html)
        ref_matches = self._reference_matches(brand_reference, facts.supplier_reference, text_norm)
        ean_norm = normalize_text(facts.ean)

        matched_ref = ref_matches
        matched_ean = bool(ean_norm and ean_norm in text_norm)

        return WoodbrassMatch(
            url=url,
            title=title,
            matched_ref=matched_ref,
            matched_ean=matched_ean,
        )

    def _extract_brand_reference(self, text: str) -> str:
        soup = BeautifulSoup(text, "html.parser")
        label_node = soup.find(string=re.compile(r"Référence marque\s*:"))
        if label_node is not None and getattr(label_node, "parent", None) is not None:
            outer = getattr(label_node.parent, "parent", None)
            if outer is not None:
                direct_children = outer.find_all("span", recursive=False)
                if len(direct_children) >= 2:
                    return direct_children[1].get_text(" ", strip=True)

            sibling = label_node.parent.find_next_sibling("span")
            if sibling is not None:
                return sibling.get_text(" ", strip=True)

        match = re.search(r"Référence marque\s*:\s*([A-Za-z0-9\-\s]+)", text, re.IGNORECASE)
        if match:
            return match.group(1).strip()

        return ""

    def _reference_matches(self, extracted_reference: str, reference: str, text_norm: str) -> bool:
        if not reference:
            return False

        normalized = normalize_text(reference)
        extracted_normalized = normalize_text(extracted_reference)
        compact = normalized.replace(" ", "").replace("-", "")
        extracted_compact = extracted_normalized.replace(" ", "").replace("-", "")

        if normalized == extracted_normalized or compact == extracted_compact:
            return True

        if self._contains_normalized_reference(text_norm, normalized):
            return True

        return False

    def _contains_normalized_reference(self, text_norm: str, reference_norm: str) -> bool:
        if not text_norm or not reference_norm:
            return False

        pattern = rf"(?<![a-z0-9]){re.escape(reference_norm)}(?![a-z0-9])"
        return re.search(pattern, text_norm) is not None
