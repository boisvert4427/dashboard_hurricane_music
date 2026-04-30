from __future__ import annotations

from typing import Iterable

from .base import CompetitorScraper


class GenericSearchScraper(CompetitorScraper):
    def parse_results(self, html: str, query: str) -> Iterable[tuple[str, str]]:
        return self.extract_links(html)

