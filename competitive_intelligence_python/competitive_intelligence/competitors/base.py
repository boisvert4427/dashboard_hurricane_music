from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import Iterable
from urllib.parse import quote_plus

from bs4 import BeautifulSoup

from ..core.http_client import HttpClient
from ..core.normalization import simplify_name, normalize_text
from ..core.scoring import ProductFacts, score_candidate


@dataclass(frozen=True)
class Candidate:
    id_product: int
    url: str
    title: str
    source: str
    score: int
    matched_query: str
    status: str = "pending"


class CompetitorScraper(ABC):
    def __init__(self, search_url_pattern: str, http: HttpClient):
        self.search_url_pattern = search_url_pattern
        self.http = http

    def build_search_url(self, query: str) -> str:
        return self.search_url_pattern.format(query=quote_plus(query))

    @abstractmethod
    def parse_results(self, html: str, query: str) -> Iterable[tuple[str, str]]:
        """Return iterable of (url, title) pairs."""

    def search(self, product: dict[str, object]) -> list[Candidate]:
        facts = ProductFacts(
            supplier_reference=str(product.get("supplier_reference") or ""),
            ean=str(product.get("ean") or ""),
            brand=str(product.get("brand") or ""),
            name=str(product.get("name") or ""),
        )
        product_id = int(product["id_product"])

        queries = [
            facts.supplier_reference,
            facts.ean,
            f"{facts.brand} {facts.supplier_reference}".strip(),
            simplify_name(facts.name),
        ]

        seen_urls: set[str] = set()
        candidates: list[Candidate] = []

        for query in [q for q in queries if q]:
            search_url = self.build_search_url(query)
            response = self.http.get(search_url)
            response.raise_for_status()

            for url, title in self.parse_results(response.text, query):
                normalized_url = url.strip()
                if not normalized_url or normalized_url in seen_urls:
                    continue

                score = score_candidate(title, query, facts)
                if "search" in normalize_text(title):
                    score += 5

                seen_urls.add(normalized_url)
                candidates.append(
                    Candidate(
                        id_product=product_id,
                        url=normalized_url,
                        title=title.strip(),
                        source="search_result",
                        score=score,
                        matched_query=query,
                    )
                )

        candidates.sort(key=lambda item: item.score, reverse=True)
        return candidates[:5]

    @staticmethod
    def extract_links(html: str, allowed_domain: str | None = None) -> list[tuple[str, str]]:
        soup = BeautifulSoup(html, "html.parser")
        results: list[tuple[str, str]] = []
        for anchor in soup.select("a[href]"):
            href = anchor.get("href") or ""
            text = anchor.get_text(" ", strip=True)
            if not href or not text:
                continue
            if allowed_domain and allowed_domain not in href:
                continue
            results.append((href, text))
        return results

