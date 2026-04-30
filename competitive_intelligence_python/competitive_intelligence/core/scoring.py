from __future__ import annotations

from dataclasses import dataclass

from .normalization import normalize_text


@dataclass(frozen=True)
class ProductFacts:
    supplier_reference: str = ""
    ean: str = ""
    brand: str = ""
    name: str = ""


def score_candidate(candidate_title: str, query: str, facts: ProductFacts) -> int:
    score = 0
    title_norm = normalize_text(candidate_title)
    query_norm = normalize_text(query)
    brand_norm = normalize_text(facts.brand)
    name_norm = normalize_text(facts.name)

    if facts.ean and facts.ean in title_norm:
        score += 100
    if facts.supplier_reference and normalize_text(facts.supplier_reference) in title_norm:
        score += 60
    if brand_norm and brand_norm in title_norm:
        score += 30

    name_tokens = [token for token in name_norm.split() if len(token) > 2]
    matched_tokens = sum(1 for token in name_tokens if token in title_norm)
    if name_tokens:
        score += min(30, matched_tokens * 6)

    if any(token in title_norm for token in ("pack", "bundle", "lot")):
        score -= 40
    if any(token in title_norm for token in ("occasion", "reconditionne", "b stock", "bstock", "used")):
        score -= 50

    if query_norm and query_norm in title_norm:
        score += 20

    return score

