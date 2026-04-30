from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .http_client import HttpClient


@dataclass
class ApiClient:
    base_url: str
    token: str
    http: HttpClient

    def _headers(self) -> dict[str, str]:
        return {"X-COMPETITIVE-TOKEN": self.token}

    def fetch_next_batch(self, competitor_id: int, limit: int, after_id: int, lang_id: int, shop_id: int) -> dict[str, Any]:
        response = self.http.get(
            f"{self.base_url}/api/competitive/products/next-batch",
            params={
                "competitor_id": competitor_id,
                "limit": limit,
                "after_id": after_id,
                "lang_id": lang_id,
                "shop_id": shop_id,
            },
            headers=self._headers(),
        )
        response.raise_for_status()
        return response.json()

    def submit_candidates(self, payload: dict[str, Any]) -> dict[str, Any]:
        response = self.http.post(
            f"{self.base_url}/api/competitive/candidates",
            json=payload,
            headers=self._headers(),
        )
        response.raise_for_status()
        return response.json()

