"""Price and product URL handling for Woodbrass's current storefront."""
from __future__ import annotations

import re
from urllib.parse import urlparse

from bs4 import BeautifulSoup


def is_woodbrass_product_url(url: str) -> bool:
    parsed = urlparse(url)
    return (
        parsed.scheme in {"http", "https"}
        and parsed.hostname in {"woodbrass.com", "www.woodbrass.com"}
        and not parsed.username
        and not parsed.password
        and bool(re.fullmatch(r"/products/[^/]+/?|/[^/]*-p\d+\.html", parsed.path))
    )


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
