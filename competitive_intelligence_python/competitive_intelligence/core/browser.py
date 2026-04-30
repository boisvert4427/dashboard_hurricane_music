from __future__ import annotations

from contextlib import contextmanager


@contextmanager
def browser_context(user_agent: str | None = None):
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:  # pragma: no cover - dependency guard
        raise RuntimeError(
            "playwright is required for browser-based scrapers. Install dependencies first."
        ) from exc

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        context_kwargs = {
            "viewport": {"width": 1440, "height": 1600},
        }
        if user_agent:
            context_kwargs["user_agent"] = user_agent
        context = browser.new_context(**context_kwargs)
        try:
            yield context
        finally:
            context.close()
            browser.close()
