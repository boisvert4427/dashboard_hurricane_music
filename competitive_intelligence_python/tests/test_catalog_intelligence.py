from __future__ import annotations

import unittest

from competitive_intelligence.core.catalog_intelligence import OpenAICatalogComparator


class CatalogIntelligenceTest(unittest.TestCase):
    def test_batch_selection_preserves_color_mismatch(self) -> None:
        comparator = OpenAICatalogComparator(api_key="test")

        selections = comparator._to_batch_selections(
            {
                "selections": [
                    {
                        "product_id": 42,
                        "best_index": 1,
                        "confidence": 98,
                        "same_product": False,
                        "color_mismatch": True,
                        "notes": ["VC means Vintage Cherry, while the source is Sunburst."],
                    }
                ]
            }
        )

        self.assertEqual(1, len(selections))
        self.assertTrue(selections[0].color_mismatch)
        self.assertFalse(selections[0].same_product)

    def test_batch_prompt_requires_strict_finish_rejection(self) -> None:
        comparator = OpenAICatalogComparator(api_key="test")
        prompt = comparator._build_batch_selection_prompt(
            [
                {
                    "product": {
                        "id_product": 42,
                        "brand": "Gibson",
                        "name": "ES-335 Vintage Cherry",
                    },
                    "candidates": [
                        {
                            "title": "ES-335 VC",
                            "competitor_brand": "Gibson",
                        }
                    ],
                }
            ]
        )

        self.assertIn("Thomann frequently shortens finish", prompt)
        self.assertIn("color_mismatch=true", prompt)


if __name__ == "__main__":
    unittest.main()
