"""Price and product URL handling for Woodbrass's current storefront."""
from __future__ import annotations

import re
from urllib.parse import parse_qs, urlparse

from bs4 import BeautifulSoup


def is_woodbrass_product_url(url: str) -> bool:
    parsed = urlparse(url)
    return (
        parsed.scheme in {"http", "https"}
        and parsed.hostname in {"woodbrass.com", "www.woodbrass.com"}
        and not parsed.username
        and not parsed.password
        and not parsed.path.lower().endswith(".js")
        and bool(re.fullmatch(r"/products/[^/]+/?|/[^/]*-p\d+\.html", parsed.path))
    )


def woodbrass_product_json_url(url: str) -> str | None:
    parsed = urlparse(url)
    if parsed.hostname not in {"woodbrass.com", "www.woodbrass.com"}:
        return None
    if parsed.path.lower().endswith(".js"):
        return None
    if not re.fullmatch(r"/products/[^/]+/?", parsed.path):
        return None
    return f"https://woodbrass.com{parsed.path.rstrip('/')}.js"


def extract_woodbrass_json_price(payload: object, product_url: str) -> float | None:
    if not isinstance(payload, dict) or not isinstance(payload.get("variants"), list):
        return None
    variants = payload["variants"]
    requested_variant = parse_qs(urlparse(product_url).query).get("variant", [None])[0]
    selected = None
    if requested_variant:
        selected = next(
            (row for row in variants if isinstance(row, dict) and str(row.get("id")) == requested_variant),
            None,
        )
    if selected is None:
        selected = next((row for row in variants if isinstance(row, dict)), None)
    if not isinstance(selected, dict):
        return None
    try:
        cents = float(selected.get("price"))
    except (TypeError, ValueError):
        return None
    return cents / 100 if cents > 0 else None


def extract_woodbrass_display_price(html: str) -> float | None:
    soup = BeautifulSoup(html, "html.parser")
    # Restrict to the main product when present, excluding recommendation prices.
    scope = soup.select_one('.product__block--price') or soup
    sale = scope.select_one('.f-price--on-sale')
    if sale is not None:
        nodes = sale.select('.f-price-item--sale [data-wb-bss-price]')
    else:
        nodes = scope.select('[data-wb-bss-price]')
    for node in nodes:
        if node.find_parent('template') is not None:
            continue
        text = node.get_text(" ", strip=True).replace('€', '').strip()
        numeric = re.sub(r'\s+', '', text)
        if ',' in numeric:
            numeric = numeric.replace('.', '').replace(',', '.')
        if not re.fullmatch(r'\d+(?:\.\d{1,2})?', numeric):
            continue
        price = float(numeric)
        if price > 0:
            return price
    return None
