from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import requests


@dataclass
class HttpClient:
    timeout: int = 30
    proxy_url: str | None = None

    def __post_init__(self) -> None:
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "CompetitiveIntelligenceBot/1.0",
        })
        if self.proxy_url:
            self.session.proxies.update({
                "http": self.proxy_url,
                "https": self.proxy_url,
            })

    def get(self, url: str, **kwargs: Any) -> requests.Response:
        return self.session.get(url, timeout=self.timeout, **kwargs)

    def post(self, url: str, **kwargs: Any) -> requests.Response:
        return self.session.post(url, timeout=self.timeout, **kwargs)
