import contextlib
import io
import unittest
from unittest.mock import patch, MagicMock

import requests

from competitive_intelligence.core.config import Settings
from competitive_intelligence.workers import price_job


class PriceJobTest(unittest.TestCase):
    def test_all_competitors_report_every_outcome_even_without_prices(self):
        for competitor_id, domain in enumerate(['woodbrass.com', 'stars-music.fr', 'thomann.fr', 'michenaud.com'], 1):
            with self.subTest(domain=domain):
                api = MagicMock()
                api.fetch_next_final_price_batch.return_value = {
                    'competitor': {'id': competitor_id, 'domain': domain, 'name': domain},
                    'items': [{'id_product': i, 'url': f'https://{domain}/{i}'} for i in range(1, 7)],
                    'after_id': 6, 'has_more': False,
                }
                def response(status=200):
                    r = requests.Response()
                    r.status_code = status
                    r.url = f'https://{domain}/product'
                    r._content = b'<html></html>'
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
                self.assertEqual(['price_not_found', 'temporary_error', 'temporary_error', 'http_gone', 'price_not_found', 'temporary_error'],
                                 [row['result'] for row in payload['failures']])
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

    def test_woodbrass_redirect_sends_original_and_resolved_urls_only_for_product_pages(self):
        old_url = 'https://www.woodbrass.com/guitare-p395764.html'
        for target, valid in [
            ('https://woodbrass.com/products/fender-395764', True),
            ('https://woodbrass.com/', False),
            ('https://woodbrass.com/collections/guitares', False),
            ('https://example.test/products/fender-395764', False),
        ]:
            with self.subTest(target=target):
                api = MagicMock()
                api.fetch_next_final_price_batch.return_value = {
                    'competitor': {'id': 1, 'domain': 'woodbrass.com', 'name': 'Woodbrass'},
                    'items': [{'id_product': 396663, 'url': old_url}],
                    'after_id': 396663, 'has_more': False,
                }
                response = requests.Response()
                response.status_code = 200
                response.url = target
                response.history = [requests.Response()]
                response._content = '<span data-wb-bss-price="">819,00 €</span>'.encode()
                response.encoding = 'utf-8'
                http = MagicMock()
                http.get.return_value = response
                with patch.object(price_job, 'ApiClient', return_value=api), \
                     patch.object(price_job, 'HttpClient', return_value=http), \
                     patch.object(price_job, 'competitor_run_lock', return_value=contextlib.nullcontext()), \
                     contextlib.redirect_stdout(io.StringIO()):
                    price_job.run_price_job(settings=Settings('https://example.test', 'test-token', 1))
                payload = api.submit_final_prices.call_args.args[0]
                if valid:
                    observation = payload['observations'][0]
                    self.assertEqual(old_url, observation['url'])
                    self.assertEqual(target, observation['resolved_url'])
                    self.assertEqual(819.0, observation['price'])
                else:
                    self.assertEqual([], payload['observations'])
                    self.assertEqual('price_not_found', payload['failures'][0]['result'])


if __name__ == '__main__':
    unittest.main()
