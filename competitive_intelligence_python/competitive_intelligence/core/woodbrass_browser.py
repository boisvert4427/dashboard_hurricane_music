from __future__ import annotations

import json
import os
from dataclasses import dataclass, field

import requests
from playwright.sync_api import Browser, BrowserContext, Playwright, sync_playwright


@dataclass
class BrowserResponse:
    status_code: int
    url: str
    text: str
    headers: dict[str, str]
    history: list[object] = field(default_factory=list)

    def raise_for_status(self) -> None:
        if self.status_code < 400:
            return
        error = requests.HTTPError(f"{self.status_code} response for {self.url}")
        error.response = self  # type: ignore[assignment]
        raise error

    def json(self) -> object:
        return json.loads(self.text)


class WoodbrassBrowser:
    """Persistent Chromium session used for Woodbrass price pages."""

    def __init__(self) -> None:
        self.direct_enabled = os.environ.get("WOODBRASS_BROWSER_ENABLED", "").lower() in {
            "1", "true", "yes", "on",
        }
        self.host = os.environ.get("WOODBRASS_PROXY_HOST", "").strip()
        self.port = os.environ.get("WOODBRASS_PROXY_PORT", "").strip()
        self.username = os.environ.get("WOODBRASS_PROXY_USERNAME", "").strip()
        self.password = os.environ.get("WOODBRASS_PROXY_PASSWORD", "").strip()
        self.chromium_path = os.environ.get("WOODBRASS_CHROMIUM_PATH", "/usr/bin/chromium").strip()
        self._playwright: Playwright | None = None
        self._browser: Browser | None = None
        self._context: BrowserContext | None = None

    @property
    def configured(self) -> bool:
        return self.direct_enabled or bool(self.host and self.port)

    @property
    def uses_proxy(self) -> bool:
        return bool(self.host and self.port)

    def get(self, url: str) -> BrowserResponse:
        context = self._ensure_context()
        page = context.new_page()
        try:
            response = page.goto(url, wait_until="domcontentloaded", timeout=45_000)
            if response is None:
                raise RuntimeError(f"Chromium returned no response for {url}")
            body = response.body()
            return BrowserResponse(
                status_code=response.status,
                url=page.url,
                text=body.decode("utf-8", errors="replace"),
                headers=response.all_headers(),
            )
        finally:
            page.close()

    def close(self) -> None:
        if self._context is not None:
            self._context.close()
            self._context = None
        if self._browser is not None:
            self._browser.close()
            self._browser = None
        if self._playwright is not None:
            self._playwright.stop()
            self._playwright = None

    def _ensure_context(self) -> BrowserContext:
        if not self.configured:
            raise RuntimeError("Woodbrass residential proxy is not configured")
        if self._context is not None:
            return self._context

        proxy: dict[str, str] | None = None
        if self.host and self.port:
            proxy = {"server": f"http://{self.host}:{self.port}"}
            if self.username:
                proxy["username"] = self.username
            if self.password:
                proxy["password"] = self.password

        self._playwright = sync_playwright().start()
        self._browser = self._playwright.chromium.launch(
            executable_path=self.chromium_path,
            headless=True,
            proxy=proxy,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )
        self._context = self._browser.new_context(
            locale="fr-FR",
            timezone_id="Europe/Paris",
            viewport={"width": 1365, "height": 768},
        )
        self._context.route("**/*", self._route_request)
        return self._context

    @staticmethod
    def _route_request(route: object) -> None:
        request = route.request  # type: ignore[attr-defined]
        if request.resource_type in {"image", "media", "font"}:
            route.abort()  # type: ignore[attr-defined]
            return
        route.continue_()  # type: ignore[attr-defined]
