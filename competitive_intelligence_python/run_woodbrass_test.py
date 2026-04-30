from __future__ import annotations

import json
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright


def normalize_reference(value: str) -> str:
    return "".join(ch for ch in value.lower() if ch.isalnum())


def main() -> None:
    reference = sys.argv[1] if len(sys.argv) > 1 else "014-9052-388"
    out = Path("debug")
    out.mkdir(exist_ok=True)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        page = browser.new_page(viewport={"width": 1440, "height": 1600})
        page.goto("https://www.woodbrass.com/", wait_until="domcontentloaded", timeout=60000)
        page.wait_for_timeout(2000)

        for selector in [
            "text=ALLOW ALL",
            "text=Allow all",
            "text=Tout accepter",
            "text=Accepter",
            "text=Continuer sans accepter",
            "text=Continue without accepting",
        ]:
            try:
                locator = page.locator(selector).first
                if locator.count() > 0:
                    locator.click(timeout=2000)
                    page.wait_for_timeout(1500)
                    break
            except Exception:
                pass

        selector = "input.ais-SearchBox-input.search-input.keywords"
        page.wait_for_function("selector => document.querySelectorAll(selector).length > 0", arg=selector, timeout=30000)
        search_input = page.locator(selector).first
        search_input.scroll_into_view_if_needed(timeout=5000)
        page.wait_for_timeout(300)
        search_input.fill(reference)
        page.evaluate(
            """selector => {
                const input = document.querySelector(selector);
                if (!input) return;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'a' }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }""",
            selector,
        )
        page.wait_for_timeout(4000)

        search_info = page.evaluate(
            """() => ({
                url: window.location.href,
                title: document.title,
                inputCount: document.querySelectorAll('input').length,
                searchInputCount: document.querySelectorAll('input.ais-SearchBox-input.search-input.keywords').length,
                hitsCount: document.querySelectorAll('li.ais-Hits-item').length,
                bodySnippet: document.body.innerText.slice(0, 1500)
            })"""
        )
        (out / "woodbrass-typed-reference.json").write_text(
            json.dumps(search_info, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        page.screenshot(path=str(out / "woodbrass-typed-reference.png"), full_page=True)

        result_selector = "ol.ais-Hits-list li.ais-Hits-item a.box-product[href], li.ais-Hits-item a.box-product[href]"
        page.wait_for_timeout(1500)
        result_count = page.locator(result_selector).count()
        clicked_product = None
        if result_count > 0:
            first_result = page.locator(result_selector).first
            try:
                with page.expect_navigation(wait_until="domcontentloaded", timeout=15000):
                    first_result.click(timeout=5000)
            except Exception:
                href = first_result.get_attribute("href")
                if href:
                    page.goto(href, wait_until="domcontentloaded", timeout=15000)
            page.wait_for_timeout(1500)

            product_text = page.evaluate(
                """() => document.body.innerText"""
            )
            brand_reference = page.evaluate(
                """() => {
                    const label = Array.from(document.querySelectorAll('span')).find(
                        el => (el.textContent || '').trim() === 'Référence marque :'
                    );
                    if (!label) return '';
                    const outer = label.parentElement;
                    if (!outer) return '';
                    const directSpans = Array.from(outer.children).filter(el => el.tagName === 'SPAN');
                    if (directSpans.length >= 2) {
                        return (directSpans[1].textContent || '').trim();
                    }
                    const sibling = label.nextElementSibling;
                    return sibling ? (sibling.textContent || '').trim() : '';
                }"""
            )
            product_url = page.url
            product_title = page.title()
            ref_norm = normalize_reference(reference)
            text_norm = normalize_reference(product_text)
            ref_with_dash = reference.lower()
            clicked_product = {
                "url": product_url,
                "title": product_title,
                "first_result_count": result_count,
                "brand_reference": brand_reference,
                "reference_exact": reference in product_text,
                "reference_with_dashes": ref_with_dash in product_text.lower(),
                "reference_normalized_match": ref_norm in text_norm,
                "bodySnippet": product_text[:1500],
            }
            page.screenshot(path=str(out / "woodbrass-product-page.png"), full_page=True)
            (out / "woodbrass-product-page.json").write_text(
                json.dumps(clicked_product, ensure_ascii=False, indent=2),
                encoding="utf-8",
            )

        output = {
            "search": search_info,
            "clicked_product": clicked_product,
        }
        print(json.dumps(output, ensure_ascii=False, indent=2))
        browser.close()


if __name__ == "__main__":
    main()
