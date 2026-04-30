from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import requests


@dataclass
class HttpClient:
    timeout: int = 30

    def __post_init__(self) -> None:
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "CompetitiveIntelligenceBot/1.0",
        })

    def get(self, url: str, **kwargs: Any) -> requests.Response:
        return self.session.get(url, timeout=self.timeout, **kwargs)

    def post(self, url: str, **kwargs: Any) -> requests.Response:
        return self.session.post(url, timeout=self.timeout, **kwargs)

