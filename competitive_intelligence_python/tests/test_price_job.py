import contextlib
import io
import unittest
from unittest.mock import patch, MagicMock

import requests

from competitive_intelligence.core.config import Settings
from competitive_intelligence.core.woodbrass_browser import BrowserResponse
from competitive_intelligence.workers import price_job


class PriceJobTest(unittest.TestCase):
    def test_all_competitors_report_every_outcome_even_without_prices(self):
        for competitor_id, domain in enumerate(['woodbrass.com', 'stars-music.fr', 'thomann.fr', 'michenaud.com'], 1):
            with self.subTest(domain=domain):
                api = MagicMock()
                api.fetch_next_final_price_batch.return_value = {
                    'competitor': {'id': competitor_id, 'domain': domain, 'name': domain},
                    'items': [
                        {
                            'id_product': i,
                            'url': f'https://{domain}/products/product-{i}' if competitor_id == 1 else f'https://{domain}/{i}',
                        }
                        for i in range(1, 7)
                    ],
                    'after_id': 6, 'has_more': False,
                }
                def response(status=200):
                    r = requests.Response()
                    r.status_code = status
                    r.url = f'https://{domain}/product'
                    r._content = b'{"variants":[{"id":1,"price":0}]}' if competitor_id == 1 else b'<html></html>'
                    return r
                http = MagicMock()
                challenge = response()
                challenge.headers['cf-mitigated'] = 'challenge'
                http.get.side_effect = [response(), requests.Timeout('timeout'), response(429), response(404), response(), challenge]
                settings = Settings('https://example.test', 'test-token', competitor_id)
                with patch.object(price_job, 'ApiClient', return_value=api), \
                     patch.object(price_job, 'HttpClient', return_value=http), \
                     patch.object(price_job, 'competitor_run_lock', return_value=contextlib.nullcontext()), \
                     patch.object(price_job, '_human_pause'), \
                     patch.object(price_job, '_extract_price', side_effect=[None, 0]), \
                     contextlib.redirect_stdout(io.StringIO()):
                    price_job.run_price_job(settings=settings)
                payload = api.submit_final_prices.call_args.args[0]
                self.assertEqual([], payload['observations'])
                expected = ['price_not_found', 'temporary_error', 'temporary_error']
                if competitor_id != 1:
                    expected.extend(['http_gone', 'price_not_found', 'temporary_error'])
                self.assertEqual(expected, [row['result'] for row in payload['failures']])
                self.assertEqual(competitor_id, payload['competitor_id'])

    def test_stars_structured_price_fallback(self):
        html = '<script type="application/ld+json">{"@type":"Product","offers":{"price":"871.00"}}</script>'
        self.assertEqual(871.0, price_job._extract_stars_price(html))
        self.assertIsNone(price_job._extract_stars_price('<html>Produit indisponible</html>'))

    def test_woodbrass_current_price_overrides_old_price_and_ignores_recommendations(self):
        html = '<span data-wb-bss-price>12,00 €</span><div class="product__block--price"><span data-wb-bss-price>1 819,00 €</span></div><meta itemprop="price" content="1999">'
        self.assertEqual(1819.0, price_job._extract_woodbrass_price(html))
        sale = '<div class="product__block--price"><div class="f-price--on-sale"><span data-wb-bss-price>999,00 €</span><span class="f-price-item--sale"><span data-wb-bss-price>819,00 €</span></span></div></div>'
        self.assertEqual(819.0, price_job._extract_woodbrass_price(sale))
        self.assertIsNone(price_job._extract_woodbrass_price('<span data-wb-bss-price>%%PRICE%%</span>'))
        self.assertEqual(819.0, price_job._extract_woodbrass_price('<meta itemprop="price" content="819.00">'))

    def test_woodbrass_price_job_uses_shopify_json_endpoint(self):
        api = MagicMock()
        api.fetch_next_final_price_batch.return_value = {
            'competitor': {'id': 1, 'domain': 'woodbrass.com', 'name': 'Woodbrass'},
            'items': [{'id_product': 1, 'url': 'https://woodbrass.com/products/test?variant=22'}],
            'after_id': 1,
            'has_more': False,
        }
        response = requests.Response()
        response.status_code = 200
        response.url = 'https://woodbrass.com/products/test.js'
        response._content = b'{"variants":[{"id":11,"price":9900},{"id":22,"price":12345}]}'
        http = MagicMock()
        http.get.return_value = response
        with patch.object(price_job, 'ApiClient', return_value=api), \
             patch.object(price_job, 'HttpClient', return_value=http), \
             patch.object(price_job, 'competitor_run_lock', return_value=contextlib.nullcontext()), \
             contextlib.redirect_stdout(io.StringIO()):
            price_job.run_price_job(settings=Settings('https://example.test', 'test-token', 1))

        self.assertEqual('https://woodbrass.com/products/test.js', http.get.call_args.args[0])
        payload = api.submit_final_prices.call_args.args[0]
        self.assertEqual(123.45, payload['observations'][0]['price'])
        self.assertEqual('woodbrass_shopify_json', payload['observations'][0]['source'])

    def test_woodbrass_price_job_uses_chromium_json_when_residential_proxy_is_configured(self):
        api = MagicMock()
        api.fetch_next_final_price_batch.return_value = {
            'competitor': {'id': 1, 'domain': 'woodbrass.com', 'name': 'Woodbrass'},
            'items': [{'id_product': 1, 'url': 'https://woodbrass.com/products/test'}],
            'after_id': 1,
            'has_more': False,
        }
        browser = MagicMock()
        browser.configured = True
        browser.uses_proxy = True
        browser.get.return_value = BrowserResponse(
            200,
            'https://woodbrass.com/products/test.js',
            '{"variants":[{"id":11,"price":11400}]}',
            {'content-type': 'application/json'},
        )
        http = MagicMock()
        with patch.object(price_job, 'ApiClient', return_value=api), \
             patch.object(price_job, 'HttpClient', return_value=http), \
             patch.object(price_job, 'WoodbrassBrowser', return_value=browser), \
             patch.object(price_job, 'competitor_run_lock', return_value=contextlib.nullcontext()), \
             contextlib.redirect_stdout(io.StringIO()):
            price_job.run_price_job(settings=Settings('https://example.test', 'test-token', 1))

        browser.get.assert_called_once_with('https://woodbrass.com/products/test.js')
        browser.close.assert_called_once()
        payload = api.submit_final_prices.call_args.args[0]
        self.assertEqual(114.0, payload['observations'][0]['price'])
        self.assertEqual('woodbrass_browser_json', payload['observations'][0]['source'])
        self.assertEqual('chromium_proxy', payload['transport'])

    def test_woodbrass_legacy_url_is_rejected_without_network_request(self):
        api = MagicMock()
        api.fetch_next_final_price_batch.return_value = {
            'competitor': {'id': 1, 'domain': 'woodbrass.com', 'name': 'Woodbrass'},
            'items': [{'id_product': 396663, 'url': 'https://www.woodbrass.com/guitare-p395764.html'}],
            'after_id': 396663,
            'has_more': False,
        }
        http = MagicMock()
        with patch.object(price_job, 'ApiClient', return_value=api), \
             patch.object(price_job, 'HttpClient', return_value=http), \
             patch.object(price_job, 'competitor_run_lock', return_value=contextlib.nullcontext()), \
             contextlib.redirect_stdout(io.StringIO()):
            price_job.run_price_job(settings=Settings('https://example.test', 'test-token', 1))

        http.get.assert_not_called()
        payload = api.submit_final_prices.call_args.args[0]
        self.assertEqual([], payload['observations'])
        self.assertEqual('temporary_error', payload['failures'][0]['result'])


if __name__ == '__main__':
    unittest.main()
