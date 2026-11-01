import unittest
from score import score

class ScorerTest(unittest.TestCase):
    def setUp(self):
        self.item = {"name": "Rice", "price": 50, "is_veg": True, "variations": []}
        self.fixture = {"items": [self.item]}

    def run_score(self, items):
        return score(self.fixture, {"fixture": "unit", "categories": [{"items": items}], "elapsed_seconds": 0})

    def test_exact_match(self):
        self.assertEqual([], self.run_score([self.item])["field_errors"])

    def test_duplicate_is_not_hidden_by_name_lookup(self):
        self.assertEqual(["rice"], self.run_score([self.item, self.item])["duplicates"])

    def test_wrong_price_is_reported(self):
        result = self.run_score([dict(self.item, price=51)])
        self.assertEqual("price", result["field_errors"][0]["field"])

    def test_missing_item_is_reported(self):
        self.assertEqual(["rice"], self.run_score([])["missing"])

if __name__ == "__main__":
    unittest.main()
