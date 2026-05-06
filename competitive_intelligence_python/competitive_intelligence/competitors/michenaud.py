from __future__ import annotations

from dataclasses import dataclass
import difflib
import hashlib
import re
from pathlib import Path
from typing import Iterable
from urllib.parse import quote_plus, urljoin

from bs4 import BeautifulSoup

from ..core.normalization import normalize_text, simplify_name
from .base import Candidate, CompetitorScraper


@dataclass(frozen=True)
class MichenaudResult:
    title: str
    url: str
    similarity: int
    price: float | None = None


class MichenaudScraper(CompetitorScraper):
    BASE_URL = "https://www.michenaud.com"

    def __init__(self, search_url_pattern: str, http, debug: bool = False, debug_dir: str = "debug"):
        super().__init__(search_url_pattern=search_url_pattern, http=http)
        self.debug = debug
        self.debug_dir = Path(debug_dir)

    def parse_results(self, html: str, query: str) -> Iterable[tuple[str, str, str, float | None]]:
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
            price = self._extract_price(item)
            if not title:
                title = link.get_text(" ", strip=True)
            yield candidate_url, title, "", price

    def search(self, product: dict[str, object]) -> list[Candidate]:
        product_name = str(product.get("name") or "").strip()
        brand = str(product.get("brand") or "").strip()
        source_price = self._as_float(product.get("source_price"))
        product_id = int(product["id_product"])

        if not product_name:
            return []

        core_name = self._extract_core_name(product_name)
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

        best_result: MichenaudResult | None = None
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

            for url, title, manufacturer, price in parsed_results:
                verified, page_title = self._verify_product_page(url, product, source_price)
                if not verified:
                    continue

                similarity = self._similarity_score(
                    core_name,
                    brand,
                    title or page_title,
                    manufacturer,
                    source_price,
                    price,
                )
                if best_result is None or similarity > best_result.similarity:
                    best_result = MichenaudResult(
                        title=title or page_title,
                        url=url,
                        similarity=similarity,
                        price=price,
                    )
                    best_query = query

        if best_result is None:
            return []

        return [
            Candidate(
                id_product=product_id,
                url=best_result.url.strip(),
                title=best_result.title,
                source="michenaud_search_result",
                score=best_result.similarity,
                matched_query=best_query,
                price=best_result.price,
            )
        ]

    def _verify_product_page(
        self,
        url: str,
        product: dict[str, object],
        source_price: float | None = None,
    ) -> tuple[bool, str]:
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
            return False, ""

        html = response.text
        soup = BeautifulSoup(html, "html.parser")
        page_title = soup.title.get_text(" ", strip=True) if soup.title else ""
        text = normalize_text(soup.get_text(" ", strip=True))
        raw_html = normalize_text(html)

        reference = normalize_text(str(product.get("supplier_reference") or ""))
        ean = normalize_text(str(product.get("ean") or ""))
        if reference and (reference in text or reference in raw_html):
            return True, page_title
        if ean and (ean in text or ean in raw_html):
            return True, page_title

        return False, page_title

    def _extract_title(self, item) -> str:
        title = item.select_one(".grilleDes")
        if title is None:
            return ""
        return title.get_text(" ", strip=True)

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

    def _similarity_score(
        self,
        product_name: str,
        brand: str,
        candidate_title: str,
        manufacturer: str = "",
        source_price: float | None = None,
        candidate_price: float | None = None,
    ) -> int:
        query_tokens = self._meaningful_tokens(product_name, brand)
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

        title_set = set(title_tokens)
        common_tokens = [token for token in query_tokens if token in title_set]
        strong_common_tokens = [
            token for token in common_tokens
            if token not in generic_tokens and len(token) > 3 and not token.isdigit()
        ]
        query_coverage = len(common_tokens) / len(query_tokens)
        title_coverage = len(common_tokens) / len(title_tokens)
        sequence_similarity = difflib.SequenceMatcher(None, " ".join(query_tokens), " ".join(title_tokens)).ratio()

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
            if manufacturer_set.intersection(brand_tokens):
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
            if ratio >= 0.35:
                score -= 30
            elif ratio >= 0.20:
                score -= 15

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
            "v": "5",
            "vi": "6",
            "vii": "7",
            "viii": "8",
        }
        token = token.lower()
        for source, target in replacements.items():
            token = token.replace(source, target)
        return token

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
