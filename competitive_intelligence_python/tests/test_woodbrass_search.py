import unittest
from unittest.mock import MagicMock

import requests

from competitive_intelligence.competitors.woodbrass import WoodbrassScraper


class WoodbrassSearchTest(unittest.TestCase):
    def setUp(self):
        self.http = MagicMock()
        self.scraper = WoodbrassScraper('legacy-unused', self.http)
        self.product = {'id_product': 396663, 'ean': '0885978098606', 'supplier_reference': '014-0540-500'}
        self.url = 'https://woodbrass.com/products/fender-395764'

    def response(self, payload, url):
        response = MagicMock()
        response.status_code = 200
        response.url = url
        response.json.return_value = payload
        return response

    def setup_search(self, barcode='0885978098606', variants=None):
        hits = {'resources': {'results': {'products': [{'url': '/products/fender-395764?_pos=1'}]}}}
        details = {'title': 'Player II HSS', 'vendor': 'Fender', 'featured_image': '//woodbrass.com/image.jpg',
                   'variants': variants or [{'id': 123, 'barcode': barcode, 'price': 81900}]}
        self.http.get.side_effect = [self.response(hits, 'https://woodbrass.com/search/suggest.json'),
                                     self.response(details, self.url + '.js')]

    def test_search_by_ean_validates_product_barcode_and_price_units(self):
        self.setup_search()
        result = self.scraper.search(self.product)
        self.assertEqual(1, len(result))
        self.assertEqual(self.url, result[0].url)
        self.assertEqual(819.0, result[0].price)
        self.assertEqual('woodbrass_ean', result[0].source)
        self.assertEqual(self.product['ean'], self.http.get.call_args_list[0].kwargs['params']['q'])
        self.assertEqual(100, result[0].score)

    def test_wrong_or_missing_barcode_is_not_a_match(self):
        for barcode in ['0711106164151', '']:
            with self.subTest(barcode=barcode):
                self.setup_search(barcode=barcode)
                self.assertEqual([], self.scraper.search(self.product))

    def test_no_ean_does_not_fall_back_to_reference(self):
        self.assertEqual([], self.scraper.search({**self.product, 'ean': ''}))
        self.http.get.assert_not_called()

    def test_upc_equivalence_and_correct_variant(self):
        self.setup_search(variants=[{'id': 1, 'barcode': '1111111111111', 'price': 50000},
                                    {'id': 2, 'barcode': '885978098606', 'price': 81900}])
        result = self.scraper.search(self.product)
        self.assertEqual(self.url + '?variant=2', result[0].url)
        self.assertEqual(819.0, result[0].price)

    def test_search_failure_is_not_silently_a_missing_product(self):
        self.http.get.side_effect = requests.Timeout('search timed out')
        with self.assertRaises(requests.Timeout):
            self.scraper.search(self.product)

    def test_external_result_is_ignored(self):
        self.http.get.return_value = self.response({'resources': {'results': {'products': [
            {'url': 'https://other.test/products/fender'}]}}}, 'https://woodbrass.com/search/suggest.json')
        self.assertEqual([], self.scraper.search(self.product))
        self.assertEqual(1, self.http.get.call_count)


if __name__ == '__main__':
    unittest.main()
