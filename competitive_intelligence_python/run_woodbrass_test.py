from __future__ import annotations

import argparse
import json
from dataclasses import asdict

from competitive_intelligence.competitors.woodbrass import WoodbrassScraper
from competitive_intelligence.core.http_client import HttpClient


def main() -> None:
    parser = argparse.ArgumentParser(description="Read-only Woodbrass EAN search and product barcode verification.")
    parser.add_argument('ean', nargs='?', default='0885978098606')
    args = parser.parse_args()
    scraper = WoodbrassScraper('https://woodbrass.com/search?q={query}', HttpClient())
    candidates = scraper.search({'id_product': 1, 'ean': args.ean})
    print(json.dumps({'ean': args.ean, 'candidates': [asdict(c) for c in candidates]}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
