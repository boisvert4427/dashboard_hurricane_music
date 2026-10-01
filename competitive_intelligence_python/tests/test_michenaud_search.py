import unittest
from unittest.mock import MagicMock

import requests

from competitive_intelligence.competitors.michenaud import MichenaudScraper


class MichenaudSearchTest(unittest.TestCase):
    def test_search_enriches_objects_instead_of_raw_tuples(self):
        url = 'https://www.michenaud.com/p123/fender-player-stratocaster.php'
        search_html = f'''
            <div class="grilleItem">
                <a class="grilleLink" href="{url}"></a>
                <div class="grilleDes">Fender Player Stratocaster</div>
                <div class="articleMarque">Fender</div>
                <div class="price">699,00 EUR</div>
            </div>
        '''
        product_html = '''
            <title>Fender Player Stratocaster</title>
            <meta property="og:image" content="/images/stratocaster.jpg">
            <nav class="breadcrumb">Guitares &gt; Guitares électriques</nav>
            <div>Référence 0144502506</div>
        '''
        responses = []
        for response_url, html in [(url + '?search', search_html), (url, product_html)]:
            response = requests.Response()
            response.status_code = 200
            response.url = response_url
            response.encoding = 'utf-8'
            response._content = html.encode('utf-8')
            responses.append(response)
        http = MagicMock()
        http.get.side_effect = responses
        scraper = MichenaudScraper(search_url_pattern='', http=http)

        candidates = scraper.search({
            'id_product': 123,
            'name': 'FENDER - PLAYER STRATOCASTER',
            'brand': 'Fender',
            'supplier_reference': '0144502506',
            'source_price': 699,
        })

        self.assertEqual(1, len(candidates))
        self.assertEqual(url, candidates[0].url)
        self.assertEqual('Fender', candidates[0].competitor_brand)
        self.assertEqual(699.0, candidates[0].price)
        self.assertEqual('https://www.michenaud.com/images/stratocaster.jpg', candidates[0].image_url)
        self.assertEqual('Guitares > Guitares électriques', candidates[0].breadcrumb)


if __name__ == '__main__':
    unittest.main()
