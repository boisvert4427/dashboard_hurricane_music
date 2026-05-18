from __future__ import annotations

from dataclasses import dataclass
import difflib
import hashlib
import json
import random
import re
import time
from pathlib import Path
from typing import Iterable
from urllib.parse import quote_plus, urljoin, urlsplit, urlunsplit

from bs4 import BeautifulSoup

from ..core.catalog_intelligence import _extract_year_hint, _looks_like_guitar
from ..core.normalization import normalize_text, simplify_name
from .base import Candidate, CompetitorScraper


@dataclass(frozen=True)
class ThomannResult:
    title: str
    url: str
    similarity: int
    competitor_brand: str | None = None
    image_url: str | None = None
    breadcrumb: str | None = None
    price: float | None = None
    matched_query: str | None = None


class ThomannScraper(CompetitorScraper):
    SEARCH_RESULT_TIMEOUT_MS = 5000
    BASE_URL = "https://www.thomann.fr"

    def __init__(self, search_url_pattern: str, http, debug: bool = False, debug_dir: str = "debug"):
        super().__init__(search_url_pattern=search_url_pattern, http=http)
        self.debug = debug
        self.debug_dir = Path(debug_dir)

        try:
            import cloudscraper
        except ImportError as exc:  # pragma: no cover - dependency guard
            raise RuntimeError("cloudscraper is required for Thomann scraping. Install dependencies first.") from exc

        self.scraper = cloudscraper.create_scraper(
            browser={"browser": "chrome", "platform": "windows", "desktop": True}
        )
        self.scraper.headers.update(
            {
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
                "Accept-Language": "fr-FR,fr;q=0.9,en;q=0.8,de;q=0.7",
            }
        )

    def parse_results(self, html: str, query: str) -> Iterable[tuple[str, str, str]]:
        payload = self._extract_search_index_payload(html)
        if payload is None:
            return []

        articles = self._extract_articles(payload)
        seen: set[str] = set()

        for article in articles:
            title = self._article_title(article)
            manufacturer = str(article.get("manufacturer") or "").strip()
            relative_link = str(article.get("relativeLink") or article.get("url") or "").strip()
            if not title or not relative_link:
                continue

            candidate_url = urljoin(f"{self.BASE_URL}/", relative_link)
            if not self._is_thomann_url(candidate_url):
                continue
            if candidate_url in seen:
                continue
            seen.add(candidate_url)
            yield candidate_url, title, manufacturer

    def search(self, product: dict[str, object]) -> list[Candidate]:
        product_name = str(product.get("name") or "").strip()
        brand = str(product.get("brand") or "").strip()
        source_price = self._as_float(product.get("source_price"))
        core_name = self._extract_core_name(product_name)
        product_id = int(product["id_product"])
        if not product_name:
            return []

        queries = [
            str(product.get("supplier_reference") or "").strip(),
            str(product.get("ean") or "").strip(),
            core_name,
            simplify_name(core_name),
            product_name,
        ]
        simplified = simplify_name(core_name)
        if simplified and simplified not in queries:
            queries.append(simplified)

        results: list[ThomannResult] = []
        brand_match_found = False

        for query in queries:
            if not query:
                continue
            search_url = f"{self.BASE_URL}/search.html?sw={quote_plus(query)}"
            self._human_pause()
            response = self.scraper.get(search_url, timeout=30)
            if response.status_code >= 400:
                continue

            html = response.text
            if self._is_cloudflare_page(html):
                self._dump_debug_html(query, html, "cloudflare-challenge", product_id=product_id)
                raise RuntimeError("Thomann Cloudflare challenge encountered.")

            if not self._has_search_module(html):
                continue

            parsed_results = list(self.parse_results(html, query))
            if not parsed_results:
                self._dump_debug_html(query, html, "no-results", product_id=product_id)
                continue

            for url, title, manufacturer in parsed_results:
                if self._is_b_stock_title(title):
                    continue
                if not self._brand_matches(brand, manufacturer):
                    continue

                brand_match_found = True
                self._human_pause()
                price, breadcrumb, image_url, final_url = self._extract_product_page_metadata(url)
                if not self._urls_match(url, final_url):
                    continue
                similarity = self._similarity_score(
                    core_name,
                    brand,
                    title,
                    manufacturer,
                    source_price,
                    price,
                    str(product.get("category_path") or product.get("category") or ""),
                    breadcrumb,
                )
                results.append(
                    ThomannResult(
                        title=title,
                        url=url,
                        similarity=similarity,
                        competitor_brand=manufacturer or None,
                        image_url=image_url,
                        breadcrumb=breadcrumb,
                        price=price,
                        matched_query=query,
                    )
                )

        if brand and not brand_match_found:
            return []

        if not results:
            return []

        return [
            Candidate(
                id_product=product_id,
                url=result.url.strip(),
                title=result.title,
                source="thomann_search_result",
                score=result.similarity,
                matched_query=result.matched_query or product_name,
                competitor_brand=result.competitor_brand,
                image_url=result.image_url,
                breadcrumb=result.breadcrumb,
                price=result.price,
            )
            for result in sorted(results, key=lambda item: item.similarity, reverse=True)[:3]
        ]

    def _is_b_stock_title(self, title: str) -> bool:
        title_norm = normalize_text(title)
        return (
            "b-stock" in title_norm
            or "b stock" in title_norm
            or "bstock" in title_norm
            or "bundle" in title_norm
        )

    def _human_pause(self) -> None:
        time.sleep(random.uniform(2.0, 5.0))

    def _extract_search_index_payload(self, html: str) -> list[object] | None:
        marker = "tho.bootstrapModule('search.index',"
        start = html.find(marker)
        if start == -1:
            return None

        array_start = html.find("[", start)
        if array_start == -1:
            return None

        array_end = self._find_matching_json_bracket(html, array_start)
        if array_end == -1:
            return None

        try:
            payload = json.loads(html[array_start : array_end + 1])
        except json.JSONDecodeError:
            return None

        if not isinstance(payload, list):
            return None

        return payload

    def _find_matching_json_bracket(self, text: str, start_index: int) -> int:
        depth = 0
        in_string = False
        escape = False

        for index in range(start_index, len(text)):
            char = text[index]
            if in_string:
                if escape:
                    escape = False
                    continue
                if char == "\\":
                    escape = True
                    continue
                if char == '"':
                    in_string = False
                continue

            if char == '"':
                in_string = True
                continue
            if char == "[":
                depth += 1
                continue
            if char == "]":
                depth -= 1
                if depth == 0:
                    return index

        return -1

    def _extract_articles(self, payload: list[object]) -> list[dict[str, object]]:
        if not payload:
            return []

        root = payload[0]
        if not isinstance(root, dict):
            return []

        articles_settings = root.get("articleListsSettings")
        if not isinstance(articles_settings, dict):
            return []

        articles: list[dict[str, object]] = []
        for key in ("articles", "alternativeArticles"):
            values = articles_settings.get(key)
            if isinstance(values, list):
                for item in values:
                    if isinstance(item, dict):
                        articles.append(item)

        return articles

    def _article_title(self, article: dict[str, object]) -> str:
        for key in ("title", "model", "name"):
            value = str(article.get(key) or "").strip()
            if value:
                return value
        return ""

    def _similarity_score(
        self,
        product_name: str,
        brand: str,
        candidate_title: str,
        manufacturer: str = "",
        source_price: float | None = None,
        candidate_price: float | None = None,
        category_path: str = "",
        breadcrumb: str | None = None,
    ) -> int:
        query_tokens = self._meaningful_tokens(product_name, None)
        title_tokens = self._meaningful_tokens(candidate_title, None)
        manufacturer_tokens = self._meaningful_tokens(manufacturer, None)
        query_hand = self._handedness(query_tokens)
        title_hand = self._handedness(title_tokens)
        generic_tokens = {
            "overdrive",
            "distortion",
            "delay",
            "reverb",
            "chorus",
            "phaser",
            "flanger",
            "pedal",
            "guitar",
            "bass",
            "drum",
            "microphone",
            "headphone",
            "monitor",
            "speaker",
            "controller",
            "keyboard",
            "mixer",
            "case",
            "bag",
            "bundle",
            "pack",
            "set",
            "system",
            "kit",
            "stand",
            "adapter",
            "cover",
            "sleeve",
            "pro",
            "mini",
            "max",
        }

        if not query_tokens or not title_tokens:
            return 0

        query_set = set(query_tokens)
        title_set = set(title_tokens)
        common_tokens = [token for token in query_tokens if token in title_set]
        strong_common_tokens = [
            token for token in common_tokens
            if token not in generic_tokens and len(token) > 3 and not token.isdigit()
        ]

        query_coverage = len(common_tokens) / len(query_tokens)
        title_coverage = len(common_tokens) / len(title_tokens)
        sequence_similarity = difflib.SequenceMatcher(
            None,
            " ".join(query_tokens),
            " ".join(title_tokens),
        ).ratio()

        score = (query_coverage * 45) + (title_coverage * 10) + (sequence_similarity * 10)
        if strong_common_tokens:
            score += min(30, len(strong_common_tokens) * 10)
        elif common_tokens:
            score -= 18
        else:
            score -= 25

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
        if any(token in accessory_tokens for token in title_tokens):
            score -= 35

        brand_tokens = set(normalize_text(brand).split()) if brand else set()
        if manufacturer_tokens and brand_tokens:
            manufacturer_set = set(manufacturer_tokens)
            brand_overlap = manufacturer_set.intersection(brand_tokens)
            if brand_overlap:
                score += 20
            else:
                score -= 20

        product_norm = normalize_text(product_name)
        title_norm = normalize_text(candidate_title)
        if product_norm and title_norm and product_norm in title_norm:
            score += 20

        if not strong_common_tokens and sequence_similarity < 0.55:
            score = min(score, 55)
        elif strong_common_tokens and sequence_similarity < 0.45:
            score -= 10

        if query_hand and title_hand:
            if query_hand == title_hand:
                score += 12
            else:
                score -= 60
        elif query_hand or title_hand:
            score -= 15

        if source_price is not None and candidate_price is not None and source_price > 0 and candidate_price > 0:
            ratio = abs(candidate_price - source_price) / source_price
            if ratio >= 0.50:
                score -= 40
            elif ratio >= 0.35:
                score -= 30
            elif ratio >= 0.20:
                score -= 15

        year_penalty = self._year_mismatch_penalty(product_name, candidate_title, brand, manufacturer)
        if year_penalty:
            score += year_penalty

        return max(0, min(100, int(round(score))))

    def _extract_product_page_metadata(self, url: str) -> tuple[float | None, str | None, str | None, str | None]:
        try:
            response = self.scraper.get(url, timeout=30)
            if response.status_code >= 400:
                return None, None, None, str(response.url or url)
        except Exception:
            return None, None, None, url

        html = response.text
        match = re.search(r'itemprop="price"\s+content="([0-9]+(?:[.,][0-9]+)?)"', html, re.I)
        if not match:
            match = re.search(r'priceContainer".{0,180}?([0-9][0-9 .,\u00A0]*)\s*€', html, re.I | re.S)
        price = None
        if match:
            value = match.group(1).replace("\u00A0", "").replace(" ", "").replace(",", ".")
            try:
                price = float(value)
            except ValueError:
                price = None

        soup = BeautifulSoup(html, "html.parser")
        breadcrumb = self._extract_breadcrumb(html)
        image_url = self._extract_image_url(soup)
        return price, breadcrumb, image_url, str(response.url or url)

    def _extract_image_url(self, soup: BeautifulSoup) -> str | None:
        selectors = [
            'meta[property="og:image"]',
            'meta[name="twitter:image"]',
            'meta[property="og:image:secure_url"]',
            'link[rel="image_src"]',
            'img[itemprop="image"]',
        ]

        for selector in selectors:
            node = soup.select_one(selector)
            if node is None:
                continue

            candidate = node.get("content") or node.get("href") or node.get("src") or node.get("data-src")
            candidate = str(candidate or "").strip()
            if candidate:
                return urljoin(f"{self.BASE_URL}/", candidate)

        for selector in ("img[src]", "source[srcset]"):
            node = soup.select_one(selector)
            if node is None:
                continue

            candidate = node.get("src") or node.get("srcset")
            candidate = str(candidate or "").strip()
            if candidate:
                return urljoin(f"{self.BASE_URL}/", candidate.split(",")[0].split(" ")[0].strip())

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

    def _as_float(self, value: object) -> float | None:
        try:
            if value is None:
                return None
            number = float(value)
            return number if number > 0 else None
        except Exception:
            return None

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
        stopwords = {
            "the",
            "and",
            "for",
            "with",
            "new",
            "series",
            "pack",
            "bundle",
            "set",
        }

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
        if "lh" in tokens:
            return "lh"
        return ""

    def _expand_token(self, token: str) -> list[str]:
        token = self._normalize_variant_token(token)
        expanded = {token}

        split_candidates = re.sub(r"(?<=\D)(?=\d)|(?<=\d)(?=\D)", " ", token).split()
        for part in split_candidates:
            part = self._normalize_variant_token(part)
            if not part:
                continue
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
            "v": "5",
            "vi": "6",
            "vii": "7",
            "viii": "8",
        }
        token = token.lower()
        for source, target in replacements.items():
            token = token.replace(source, target)
        return token

    def _has_search_module(self, html: str) -> bool:
        return "tho.bootstrapModule('search.index'" in html

    def _is_cloudflare_page(self, html: str) -> bool:
        lowered = html.lower()
        return "performing security verification" in lowered or "just a moment" in lowered or "cloudflare" in lowered

    def _dump_debug_html(self, query: str, html: str, label: str, product_id: int | None = None) -> None:
        if not self.debug:
            return

        self.debug_dir.mkdir(parents=True, exist_ok=True)
        slug = self._debug_slug(query)
        prefix = f"thomann-{label}"
        if product_id is not None:
            prefix = f"{prefix}-id-{product_id}"
        base = self.debug_dir / f"{prefix}-{slug}"

        try:
            base.with_suffix(".html").write_text(html, encoding="utf-8")
        except Exception:
            pass

    def _debug_slug(self, value: str) -> str:
        cleaned = "".join(ch if ch.isalnum() else "-" for ch in value).strip("-")
        cleaned = cleaned[:80] if cleaned else "query"
        digest = hashlib.sha1(value.encode("utf-8", errors="ignore")).hexdigest()[:10]
        return f"{cleaned}-{digest}"

    def _is_thomann_url(self, url: str) -> bool:
        return url.startswith(f"{self.BASE_URL}/")

    def _brand_matches(self, source_brand: str, manufacturer: str) -> bool:
        source_tokens = set(self._meaningful_tokens(source_brand, None))
        manufacturer_tokens = set(self._meaningful_tokens(manufacturer, None))
        if not source_tokens or not manufacturer_tokens:
            return True

        return bool(source_tokens.intersection(manufacturer_tokens))

    def _breadcrumb_category_bonus(self, category_path: str, breadcrumb: str | None) -> int:
        if not category_path or not breadcrumb:
            return 0

        source_segments = [segment for segment in self._split_path(category_path) if segment]
        candidate_segments = [segment for segment in self._split_path(breadcrumb) if segment]
        if not source_segments or not candidate_segments:
            return 0

        source_leaf = source_segments[-1]
        candidate_leaf = candidate_segments[-1]
        score = 0

        if source_leaf == candidate_leaf:
            score += 15
        elif source_leaf in candidate_leaf or candidate_leaf in source_leaf:
            score += 10

        source_core = [segment for segment in source_segments if segment not in {"racine", "accueil", "home"}]
        candidate_core = [
            segment
            for segment in candidate_segments
            if segment not in {"racine", "accueil", "home", "category", "categories"}
        ]
        overlap = set(source_core).intersection(candidate_core)
        if overlap:
            score += min(12, len(overlap) * 4)

        return min(score, 25)

    def _split_path(self, value: str) -> list[str]:
        normalized = normalize_text(value)
        if not normalized:
            return []

        parts = re.split(r"\s*>\s*", normalized)
        return [part.strip() for part in parts if part.strip()]

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
