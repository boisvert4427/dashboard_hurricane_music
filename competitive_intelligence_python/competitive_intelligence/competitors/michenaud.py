from __future__ import annotations

from dataclasses import dataclass
import difflib
import hashlib
import re
from pathlib import Path
from typing import Iterable
from urllib.parse import quote_plus, urljoin, urlsplit, urlunsplit

from bs4 import BeautifulSoup

from ..core.catalog_intelligence import _extract_year_hint, _looks_like_guitar
from ..core.normalization import normalize_text
from .base import Candidate, CompetitorScraper


@dataclass(frozen=True)
class MichenaudResult:
    title: str
    url: str
    similarity: int
    competitor_brand: str | None = None
    image_url: str | None = None
    breadcrumb: str | None = None
    price: float | None = None


class MichenaudScraper(CompetitorScraper):
    BASE_URL = "https://www.michenaud.com"

    def __init__(self, search_url_pattern: str, http, debug: bool = False, debug_dir: str = "debug"):
        super().__init__(search_url_pattern=search_url_pattern, http=http)
        self.debug = debug
        self.debug_dir = Path(debug_dir)

    def parse_results(self, html: str, query: str) -> Iterable[tuple[str, str, str, float | None, str | None]]:
        soup = BeautifulSoup(html, "html.parser")
        seen: set[str] = set()

        for item in soup.select("div.grilleItem"):
            link = item.select_one("a.grilleLink[href]")
            if link is None:
                continue

            href = (link.get("href") or "").strip()
            if not href:
                continue

            candidate_url = urljoin(f"{self.BASE_URL}/", href)
            if not self._is_michenaud_url(candidate_url):
                continue
            if candidate_url in seen:
                continue
            seen.add(candidate_url)

            title = self._extract_title(item)
            manufacturer = self._extract_manufacturer(item)
            price = self._extract_price(item)
            image_url = self._extract_image_url(item)
            if not title:
                title = link.get_text(" ", strip=True)
            yield candidate_url, title, manufacturer, price, image_url

    def search(self, product: dict[str, object]) -> list[Candidate]:
        product_name = str(product.get("name") or "").strip()
        brand = str(product.get("brand") or "").strip()
        source_price = self._as_float(product.get("source_price"))
        product_id = int(product["id_product"])

        if not product_name:
            return []

        core_name = self._extract_core_name(product_name)
        queries = [product_name]

        results: list[MichenaudResult] = []
        best_query = product_name

        for query in queries:
            if not query:
                continue

            search_url = f"{self.BASE_URL}/page/search.php?isAsearch=1&searchtext={quote_plus(query)}"
            response = self.http.get(search_url)
            if response.status_code >= 400:
                continue

            html = response.text
            if self._is_no_result_page(html):
                continue

            parsed_results = list(self.parse_results(html, query))
            if not parsed_results:
                continue

            for url, title, manufacturer, price, image_url in parsed_results:
                similarity = self._similarity_score(
                    core_name,
                    brand,
                    title,
                    manufacturer,
                    source_price,
                    price,
                    None,
                )
                results.append(
                    MichenaudResult(
                        title=title,
                        url=url,
                        similarity=similarity,
                        competitor_brand=manufacturer or None,
                        image_url=image_url,
                        breadcrumb=None,
                        price=price,
                    )
                )
                if not best_query:
                    best_query = query

        if not results:
            return []

        enriched_results: list[MichenaudResult] = []
        for result in parsed_results:
            _, page_title, breadcrumb, page_image_url, final_url = self._verify_product_page(result.url, product, source_price)
            if not self._urls_match(result.url, final_url):
                continue

            enriched_results.append(
                MichenaudResult(
                    title=result.title or page_title,
                    url=result.url,
                    similarity=result.similarity,
                    competitor_brand=result.competitor_brand,
                    image_url=page_image_url or result.image_url,
                    breadcrumb=breadcrumb,
                    price=result.price,
                )
            )
            if len(enriched_results) >= 3:
                break

        return [
            Candidate(
                id_product=product_id,
                url=result.url.strip(),
                title=result.title,
                source="michenaud_search_result",
                score=result.similarity,
                matched_query=best_query,
                competitor_brand=result.competitor_brand,
                image_url=result.image_url,
                breadcrumb=result.breadcrumb,
                price=result.price,
            )
            for result in enriched_results
        ]

    def _verify_product_page(
        self,
        url: str,
        product: dict[str, object],
        source_price: float | None = None,
    ) -> tuple[bool, str, str | None, str | None, str | None]:
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
                    "Referer": self.BASE_URL + "/",
                },
            )
            response.raise_for_status()
        except Exception:
            return False, "", None, None, None

        html = response.text
        soup = BeautifulSoup(html, "html.parser")
        page_title = soup.title.get_text(" ", strip=True) if soup.title else ""
        text = normalize_text(soup.get_text(" ", strip=True))
        raw_html = normalize_text(html)
        breadcrumb = self._extract_breadcrumb(html)
        image_url = self._extract_image_url(soup)

        reference = normalize_text(str(product.get("supplier_reference") or ""))
        ean = normalize_text(str(product.get("ean") or ""))
        if reference and (reference in text or reference in raw_html):
            return True, page_title, breadcrumb, image_url, str(response.url or url)
        if ean and (ean in text or ean in raw_html):
            return True, page_title, breadcrumb, image_url, str(response.url or url)

        return False, page_title, breadcrumb, image_url, str(response.url or url)

    def _extract_title(self, item) -> str:
        title = item.select_one(".grilleDes")
        if title is None:
            return ""
        return title.get_text(" ", strip=True)

    def _extract_manufacturer(self, item) -> str:
        manufacturer = item.select_one(".articleMarque")
        if manufacturer is None:
            return ""
        return manufacturer.get_text(" ", strip=True)

    def _extract_price(self, item) -> float | None:
        price_el = item.select_one(".price")
        if price_el is None:
            return None

        text = normalize_text(price_el.get_text(" ", strip=True)).replace("eur", "").strip()
        match = re.search(r"([0-9]+(?:[.,][0-9]+)?)", text)
        if not match:
            return None
        try:
            return float(match.group(1).replace(",", "."))
        except ValueError:
            return None

    def _extract_image_url(self, node) -> str | None:
        selectors = [
            'img#photoCover[src]',
            'img#photoCover[data-src]',
            '#photoCover[src]',
            '#photoCover[data-src]',
            '#photoCover img[src]',
            '#photoCover img[data-src]',
            'meta[property="og:image"]',
            'meta[name="twitter:image"]',
            'img[src]',
            'img[data-src]',
            'img[data-original]',
        ]

        for selector in selectors:
            element = node.select_one(selector) if hasattr(node, "select_one") else None
            if element is None:
                continue

            candidate = element.get("content") or element.get("src") or element.get("data-src") or element.get("data-original")
            candidate = str(candidate or "").strip()
            if candidate:
                return urljoin(f"{self.BASE_URL}/", candidate)

        return None

    def _urls_match(self, expected_url: str, final_url: str | None) -> bool:
        if not expected_url or not final_url:
            return False

        return self._normalize_url(expected_url) == self._normalize_url(final_url)

    def _normalize_url(self, url: str) -> str:
        parts = urlsplit(url)
        path = parts.path or '/'
        if path != '/' and path.endswith('/'):
            path = path.rstrip('/')
        return urlunsplit((
            parts.scheme.lower(),
            parts.netloc.lower(),
            path,
            parts.query,
            '',
        ))

    def _extract_breadcrumb(self, html: str) -> str | None:
        soup = BeautifulSoup(html, "html.parser")
        selectors = [
            "nav.breadcrumb",
            "nav[aria-label*='breadcrumb' i]",
            ".breadcrumb",
            ".breadcrumbs",
            "ol.breadcrumb",
            "ul.breadcrumb",
        ]

        for selector in selectors:
            node = soup.select_one(selector)
            if node is None:
                continue

            text = node.get_text(" > ", strip=True)
            text = re.sub(r"\s*(?:>+|»|/|›)\s*", " > ", text)
            parts = [part.strip() for part in re.split(r"\s*>\s*", text) if part.strip()]
            cleaned = [part for part in parts if len(part) > 1]
            if cleaned:
                return " > ".join(cleaned)

        return None

    def _similarity_score(
        self,
        product_name: str,
        brand: str,
        candidate_title: str,
        manufacturer: str = "",
        source_price: float | None = None,
        candidate_price: float | None = None,
        breadcrumb: str | None = None,
    ) -> int:
        brand_norm = normalize_text(brand)
        manufacturer_norm = normalize_text(manufacturer)
        product_norm = normalize_text(product_name)
        title_norm = normalize_text(candidate_title)

        query_signature = self._canonical_model_signature(product_name, brand)
        title_signature = self._canonical_model_signature(candidate_title, manufacturer or brand)

        score = 0

        if brand_norm and manufacturer_norm:
            if brand_norm == manufacturer_norm or brand_norm in manufacturer_norm or manufacturer_norm in brand_norm:
                score += 20
            else:
                score -= 10

        if query_signature and title_signature:
            if query_signature == title_signature:
                score += 55
            else:
                signature_ratio = difflib.SequenceMatcher(None, query_signature, title_signature).ratio()
                score += int(round(signature_ratio * 35))
                if signature_ratio < 0.45:
                    score -= 10
        elif query_signature or title_signature:
            score -= 8

        if product_norm and title_norm and product_norm in title_norm:
            score += 10

        if brand_norm and title_norm and brand_norm in title_norm:
            score += 5

        accessory_tokens = {
            "case",
            "decksaver",
            "sleeve",
            "bag",
            "cover",
            "foam",
            "bundle",
            "pack",
            "set",
            "stand",
            "mount",
            "holder",
            "protector",
            "protection",
            "universal",
            "flight",
            "rack",
            "adapter",
            "pedalboard",
        }
        title_tokens = set(title_norm.split())
        if any(token in accessory_tokens for token in title_tokens):
            score -= 35

        if source_price is not None and candidate_price is not None and source_price > 0 and candidate_price > 0:
            ratio = abs(candidate_price - source_price) / source_price
            if ratio <= 0.05:
                score += 10
            elif ratio <= 0.15:
                score += 5
            elif ratio >= 0.50:
                score -= 35
            elif ratio >= 0.35:
                score -= 25
            elif ratio >= 0.20:
                score -= 15

        year_penalty = self._year_mismatch_penalty(product_name, candidate_title, brand, manufacturer)
        if year_penalty:
            score += year_penalty

        breadcrumb_bonus = self._breadcrumb_bonus(product_name, breadcrumb)
        if breadcrumb_bonus:
            score += breadcrumb_bonus

        return max(0, min(100, int(round(score))))

    def _extract_core_name(self, product_name: str) -> str:
        if not product_name:
            return ""
        parts = re.split(r"\s*[-–—]\s*", product_name, maxsplit=1)
        if len(parts) == 2 and parts[1].strip():
            return parts[1].strip()
        return product_name.strip()

    def _meaningful_tokens(self, value: str, brand: str | None) -> list[str]:
        normalized = normalize_text(value)
        normalized = re.sub(r"\b(left\s+handed|left\s+hand|left-handed|gaucher|gauche|lh)\b", "lh", normalized)
        normalized = re.sub(r"\b(right\s+handed|right\s+hand|right-handed|droitier|droite|rh)\b", " ", normalized)

        tokens = [token for token in normalized.split() if token]
        if not tokens:
            return []

        brand_tokens = set(normalize_text(brand or "").split()) if brand else set()
        stopwords = {"the", "and", "for", "with", "new", "series", "pack", "bundle", "set"}

        meaningful: list[str] = []
        for token in tokens:
            if token in brand_tokens:
                continue
            if token in stopwords:
                continue
            if len(token) == 1 and token not in {"m", "v", "s", "x", "l"}:
                continue
            meaningful.extend(self._expand_token(token))
        return meaningful

    def _handedness(self, tokens: list[str]) -> str:
        return "lh" if "lh" in tokens else ""

    def _expand_token(self, token: str) -> list[str]:
        token = self._normalize_variant_token(token)
        expanded = {token}

        split_candidates = re.sub(r"(?<=\D)(?=\d)|(?<=\d)(?=\D)", " ", token).split()
        for part in split_candidates:
            part = self._normalize_variant_token(part)
            if part:
                expanded.add(part)

        return [part for part in expanded if part]

    def _normalize_variant_token(self, token: str) -> str:
        replacements = {
            "mkii": "mk2",
            "mkiii": "mk3",
            "mkiv": "mk4",
            "ii": "2",
            "iii": "3",
            "iv": "4",
            "vi": "6",
            "vii": "7",
            "viii": "8",
        }
        token = token.lower()
        for source, target in replacements.items():
            token = token.replace(source, target)
        return token

    def _canonical_model_signature(self, value: str, brand: str | None = None) -> str:
        normalized = normalize_text(value)
        if not normalized:
            return ""

        brand_tokens = set(normalize_text(brand or "").split()) if brand else set()
        stopwords = {"the", "and", "for", "with", "new", "series", "pack", "bundle", "set"}

        tokens: list[str] = []
        for token in normalized.split():
            if token in brand_tokens:
                continue
            if token in stopwords:
                continue
            tokens.append(self._normalize_variant_token(token))

        signature = "".join(token for token in tokens if token)
        return signature

    def _breadcrumb_bonus(self, product_name: str, breadcrumb: str | None) -> int:
        if not breadcrumb:
            return 0

        product_norm = normalize_text(product_name)
        breadcrumb_norm = normalize_text(breadcrumb)
        if not product_norm or not breadcrumb_norm:
            return 0

        if product_norm in breadcrumb_norm or breadcrumb_norm in product_norm:
            return 15

        product_segments = [segment for segment in re.split(r"\s*>\s*", product_norm) if segment]
        breadcrumb_segments = [segment for segment in re.split(r"\s*>\s*", breadcrumb_norm) if segment]
        if not product_segments or not breadcrumb_segments:
            return 0

        overlap = set(product_segments).intersection(breadcrumb_segments)
        if not overlap:
            return 0

        return min(20, len(overlap) * 5)

    def _year_mismatch_penalty(self, product_name: str, candidate_title: str, brand: str, manufacturer: str) -> int:
        source_year = self._extract_year_hint(product_name)
        candidate_year = self._extract_year_hint(candidate_title)
        if source_year is None or candidate_year is None:
            return 0

        if source_year == candidate_year:
            return 0

        if not self._looks_like_guitar(product_name, candidate_title):
            return 0

        penalty = -25
        brand_norm = normalize_text(brand)
        manufacturer_norm = normalize_text(manufacturer)
        if "gibson" in brand_norm or "gibson" in manufacturer_norm:
            penalty = -40

        return penalty

    def _extract_year_hint(self, value: str) -> int | None:
        normalized = normalize_text(value)
        if not normalized:
            return None

        match = re.search(r"\b(19\d{2}|20\d{2})\b", normalized)
        if match:
            year = int(match.group(1))
            if 1900 <= year <= 2099:
                return year

        compact = normalized.replace(" ", "")
        match = re.search(r"(?<!\d)'?([5-9]\d)(?!\d)", compact)
        if match and self._looks_like_guitar(normalized):
            return 1900 + int(match.group(1))

        return None

    def _looks_like_guitar(self, *values: str) -> bool:
        text = normalize_text(" ".join(values))
        guitar_markers = {
            "guitar",
            "guitare",
            "electric guitar",
            "electric guitare",
            "les paul",
            "sg",
            "strat",
            "stratocaster",
            "tele",
            "telecaster",
            "super strat",
            "flying v",
            "explorer",
            "firebird",
            "junior",
            "custom",
            "standard",
            "studio",
            "special",
        }
        if any(marker in text for marker in guitar_markers):
            return True
        return bool(re.search(r"\b(gibson|fender|prs|epiphone|ibanez|yamaha|schecter|musicman)\b", text))


    def _as_float(self, value: object) -> float | None:
        try:
            if value is None:
                return None
            number = float(value)
            return number if number > 0 else None
        except Exception:
            return None

    def _is_no_result_page(self, html: str) -> bool:
        lowered = html.lower()
        return "aucun résultat" in lowered or "aucun resultat" in lowered

    def _is_michenaud_url(self, url: str) -> bool:
        return url.startswith(f"{self.BASE_URL}/p")
