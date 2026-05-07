from __future__ import annotations

from dataclasses import dataclass
import json
import os
import re
from typing import Any

import requests

from .normalization import normalize_text


@dataclass(frozen=True)
class CatalogProfile:
    brand: str = ""
    model: str = ""
    year: int | None = None
    color: str = ""
    color_family: str = ""
    instrument_type: str = ""
    is_guitar: bool = False


@dataclass(frozen=True)
class CatalogComparison:
    same_product: bool
    confidence: int
    product: CatalogProfile
    candidate: CatalogProfile
    year_mismatch: bool
    color_mismatch: bool
    brand_mismatch: bool
    notes: tuple[str, ...] = ()


@dataclass(frozen=True)
class CatalogCandidateSelection:
    best_index: int
    confidence: int
    same_product: bool
    notes: tuple[str, ...] = ()


COLOR_ALIASES: dict[str, str] = {
    "bk": "black",
    "blk": "black",
    "bl": "blue",
    "wh": "white",
    "wht": "white",
    "rd": "red",
    "gr": "green",
    "gn": "green",
    "gy": "grey",
    "gry": "grey",
    "grey": "grey",
    "sl": "silver",
    "sv": "silver",
    "nat": "natural",
    "n": "natural",
    "sb": "sunburst",
    "tb": "transparent blue",
    "tr": "transparent",
    "tn": "transparent",
    "ch": "cherry",
    "cr": "cream",
    "ca": "candy apple",
    "iv": "ivory",
    "ol": "olive",
    "pk": "pink",
    "or": "orange",
    "pu": "purple",
    "br": "brown",
    "wt": "white",
}

GUITAR_MARKERS = {
    "guitar",
    "guitare",
    "les paul",
    "sg",
    "strat",
    "stratocaster",
    "tele",
    "telecaster",
    "super strat",
    "flying v",
    "explorer",
    "firebird",
    "junior",
    "custom",
    "standard",
    "studio",
    "special",
}


def build_catalog_profile(*, brand: str, product_name: str, candidate_title: str | None = None) -> CatalogProfile:
    title = candidate_title or product_name
    return CatalogProfile(
        brand=normalize_text(brand),
        model=_extract_model_signature(product_name, brand),
        year=_extract_year_hint(product_name),
        color=_extract_color_hint(product_name),
        color_family=_extract_color_family(product_name),
        instrument_type=_extract_instrument_type(title),
        is_guitar=_looks_like_guitar(product_name, title, brand),
    )


class OpenAICatalogComparator:
    def __init__(
        self,
        api_key: str | None = None,
        model: str | None = None,
        timeout: int = 30,
    ) -> None:
        self.api_key = (api_key or os.environ.get("OPENAI_API_KEY", "")).strip()
        self.model = (model or os.environ.get("CI_OPENAI_MATCH_MODEL", "gpt-5.4-nano")).strip()
        self.timeout = timeout
        self.enabled = bool(self.api_key)
        self._session = requests.Session()

    def compare(
        self,
        *,
        product: dict[str, Any],
        candidate: dict[str, Any],
    ) -> CatalogComparison | None:
        if not self.enabled:
            return None

        payload = self._build_prompt(product, candidate)
        try:
            response = self._session.post(
                "https://api.openai.com/v1/chat/completions",
                headers={
                    "Authorization": f"Bearer {self.api_key}",
                    "Content-Type": "application/json",
                },
                json={
                    "model": self.model,
                    "messages": [
                        {
                            "role": "system",
                            "content": (
                                "You compare music catalog products. "
                                "Return strict JSON only, no markdown, no prose."
                            ),
                        },
                        {
                            "role": "user",
                            "content": payload,
                        },
                    ],
                    "response_format": {
                        "type": "json_schema",
                        "json_schema": {
                            "name": "catalog_comparison",
                            "strict": True,
                            "schema": {
                                "type": "object",
                                "additionalProperties": False,
                                "required": [
                                    "same_product",
                                    "confidence",
                                    "product",
                                    "candidate",
                                    "year_mismatch",
                                    "color_mismatch",
                                    "brand_mismatch",
                                    "notes",
                                ],
                                "properties": {
                                    "same_product": {"type": "boolean"},
                                    "confidence": {"type": "integer", "minimum": 0, "maximum": 100},
                                    "product": self._profile_schema(),
                                    "candidate": self._profile_schema(),
                                    "year_mismatch": {"type": "boolean"},
                                    "color_mismatch": {"type": "boolean"},
                                    "brand_mismatch": {"type": "boolean"},
                                    "notes": {
                                        "type": "array",
                                        "items": {"type": "string"},
                                    },
                                },
                            },
                        },
                    },
                },
                timeout=self.timeout,
            )
            response.raise_for_status()
            data = response.json()
            content = data["choices"][0]["message"]["content"]
            parsed = json.loads(content)
        except Exception:
            return None

        return self._to_comparison(parsed)

    def select_best_candidate(
        self,
        *,
        product: dict[str, Any],
        candidates: list[dict[str, Any]],
    ) -> CatalogCandidateSelection | None:
        if not self.enabled or not candidates:
            return None

        payload = self._build_selection_prompt(product, candidates)
        try:
            response = self._session.post(
                "https://api.openai.com/v1/chat/completions",
                headers={
                    "Authorization": f"Bearer {self.api_key}",
                    "Content-Type": "application/json",
                },
                json={
                    "model": self.model,
                    "messages": [
                        {
                            "role": "system",
                            "content": (
                                "You compare music catalog products. "
                                "Return strict JSON only, no markdown, no prose."
                            ),
                        },
                        {
                            "role": "user",
                            "content": payload,
                        },
                    ],
                    "response_format": {
                        "type": "json_schema",
                        "json_schema": {
                            "name": "catalog_candidate_selection",
                            "strict": True,
                            "schema": {
                                "type": "object",
                                "additionalProperties": False,
                                "required": ["best_index", "confidence", "same_product", "notes"],
                                "properties": {
                                    "best_index": {"type": "integer", "minimum": 0},
                                    "confidence": {"type": "integer", "minimum": 0, "maximum": 100},
                                    "same_product": {"type": "boolean"},
                                    "notes": {
                                        "type": "array",
                                        "items": {"type": "string"},
                                    },
                                },
                            },
                        },
                    },
                },
                timeout=self.timeout,
            )
            response.raise_for_status()
            data = response.json()
            content = data["choices"][0]["message"]["content"]
            parsed = json.loads(content)
        except Exception:
            return None

        return self._to_selection(parsed, len(candidates))

    def _build_prompt(self, product: dict[str, Any], candidate: dict[str, Any]) -> str:
        source_price = self._to_price(product.get("source_price"))
        candidate_price = self._to_price(candidate.get("price"))
        return json.dumps(
            {
                "instructions": [
                    "Compare the source product with the competitor candidate.",
                    "Focus on brand, model, year, color, and instrument type.",
                    "If the source category_path and competitor breadcrumb are available, compare them as a strong contextual signal.",
                    "Treat color differences as a strong negative signal even when titles are nearly identical.",
                    "Treat color abbreviations as meaningful: BK/BLK=black, WH=white, SB=sunburst, TB=transparent blue, NAT=natural, CH=cherry, etc.",
                    "If color differs, do not mark same_product=true unless the color difference is clearly irrelevant or the source has no color signal.",
                    "Treat price as a strong sanity check. If both prices are present and the ratio differs by more than 25%, that is a strong negative signal.",
                    "If both prices are present and the ratio differs by more than 40%, same_product should normally be false unless the candidate is clearly a bundle, pack, multi-unit listing, or accessory that explains the gap.",
                    "A 129 vs 649 price gap is usually not the same product.",
                    "Treat 58 as 1958, 59 as 1959, etc. when the item is a guitar or clearly a vintage instrument.",
                    "Return same_product=true only when the candidate is the same real product, not just a similar family.",
                ],
                "product": {
                    "id_product": product.get("id_product"),
                    "brand": product.get("brand"),
                    "name": product.get("name"),
                    "category_path": product.get("category_path") or product.get("category"),
                    "reference": product.get("supplier_reference"),
                    "ean": product.get("ean"),
                    "source_price": product.get("source_price"),
                },
                "candidate": {
                    "title": candidate.get("title"),
                    "manufacturer": candidate.get("manufacturer"),
                    "breadcrumb": candidate.get("breadcrumb"),
                    "price": candidate.get("price"),
                    "price_ratio": self._price_ratio(source_price, candidate_price),
                    "url": candidate.get("url"),
                },
            },
            ensure_ascii=False,
            indent=2,
        )

    def _build_selection_prompt(self, product: dict[str, Any], candidates: list[dict[str, Any]]) -> str:
        source_price = self._to_price(product.get("source_price"))
        return json.dumps(
            {
                "instructions": [
                    "Compare the source product against all candidate products and select the single best match.",
                    "Focus on brand, model, year, color, and instrument type.",
                    "If the source category_path and competitor breadcrumb are available, compare them as a strong contextual signal.",
                    "Treat color differences as a strong negative signal even when titles are nearly identical.",
                    "Treat color abbreviations as meaningful: BK/BLK=black, WH=white, SB=sunburst, TB=transparent blue, NAT=natural, CH=cherry, etc.",
                    "If color differs, do not choose that candidate unless the color difference is clearly irrelevant or the source has no color signal.",
                    "Treat price as a strong sanity check. If both prices are present and the ratio differs by more than 25%, that is a strong negative signal.",
                    "If both prices are present and the ratio differs by more than 40%, avoid selecting that candidate unless it is clearly a bundle, pack, multi-unit listing, or accessory that explains the gap.",
                    "A 129 vs 649 price gap is usually not the same product.",
                    "Treat 58 as 1958, 59 as 1959, etc. when the item is a guitar or clearly a vintage instrument.",
                    "Return best_index as the zero-based index of the best candidate in the provided array.",
                    "Return same_product=true only when the best candidate is the same real product, not just a similar family.",
                ],
                "product": {
                    "id_product": product.get("id_product"),
                    "brand": product.get("brand"),
                    "name": product.get("name"),
                    "category_path": product.get("category_path") or product.get("category"),
                    "reference": product.get("supplier_reference"),
                    "ean": product.get("ean"),
                    "source_price": product.get("source_price"),
                },
                "candidates": [
                    {
                        "index": index,
                        "title": candidate.get("title"),
                        "manufacturer": candidate.get("manufacturer"),
                        "breadcrumb": candidate.get("breadcrumb"),
                        "price": candidate.get("price"),
                        "price_ratio": self._price_ratio(source_price, self._to_price(candidate.get("price"))),
                        "url": candidate.get("url"),
                    }
                    for index, candidate in enumerate(candidates)
                ],
            },
            ensure_ascii=False,
            indent=2,
        )

    def _profile_schema(self) -> dict[str, Any]:
        return {
            "type": "object",
            "additionalProperties": False,
            "required": ["brand", "model", "year", "color", "color_family", "instrument_type", "is_guitar"],
            "properties": {
                "brand": {"type": "string"},
                "model": {"type": "string"},
                "year": {"anyOf": [{"type": "integer"}, {"type": "null"}]},
                "color": {"type": "string"},
                "color_family": {"type": "string"},
                "instrument_type": {"type": "string"},
                "is_guitar": {"type": "boolean"},
            },
        }

    def _to_comparison(self, data: dict[str, Any]) -> CatalogComparison:
        product = self._to_profile(data.get("product"))
        candidate = self._to_profile(data.get("candidate"))
        return CatalogComparison(
            same_product=bool(data.get("same_product", False)),
            confidence=max(0, min(100, int(data.get("confidence", 0)))),
            product=product,
            candidate=candidate,
            year_mismatch=bool(data.get("year_mismatch", False)),
            color_mismatch=bool(data.get("color_mismatch", False)),
            brand_mismatch=bool(data.get("brand_mismatch", False)),
            notes=tuple(str(item) for item in (data.get("notes") or []) if str(item).strip()),
        )

    def _to_selection(self, data: dict[str, Any], candidate_count: int) -> CatalogCandidateSelection:
        best_index = int(data.get("best_index", 0))
        if candidate_count <= 0:
            best_index = 0
        else:
            best_index = max(0, min(candidate_count - 1, best_index))

        return CatalogCandidateSelection(
            best_index=best_index,
            confidence=max(0, min(100, int(data.get("confidence", 0)))),
            same_product=bool(data.get("same_product", False)),
            notes=tuple(str(item) for item in (data.get("notes") or []) if str(item).strip()),
        )

    def _to_profile(self, data: Any) -> CatalogProfile:
        if not isinstance(data, dict):
            return CatalogProfile()
        year = data.get("year")
        if year is not None:
            try:
                year = int(year)
            except Exception:
                year = None
        return CatalogProfile(
            brand=str(data.get("brand") or "").strip(),
            model=str(data.get("model") or "").strip(),
            year=year if isinstance(year, int) else None,
            color=str(data.get("color") or "").strip(),
            color_family=str(data.get("color_family") or "").strip(),
            instrument_type=str(data.get("instrument_type") or "").strip(),
            is_guitar=bool(data.get("is_guitar", False)),
        )

    def _to_price(self, value: Any) -> float | None:
        if value is None:
            return None
        try:
            text = str(value).strip().replace(",", ".")
            if not text:
                return None
            return float(text)
        except Exception:
            return None

    def _price_ratio(self, source_price: float | None, candidate_price: float | None) -> float | None:
        if source_price is None or candidate_price is None:
            return None
        if source_price <= 0 or candidate_price <= 0:
            return None
        return abs(candidate_price - source_price) / source_price


def _extract_year_hint(value: str) -> int | None:
    normalized = normalize_text(value)
    if not normalized:
        return None

    match = re.search(r"\b(19\d{2}|20\d{2})\b", normalized)
    if match:
        year = int(match.group(1))
        if 1900 <= year <= 2099:
            return year

    compact = normalized.replace(" ", "")
    match = re.search(r"(?<!\d)'?([5-9]\d)(?!\d)", compact)
    if match and _looks_like_guitar(normalized):
        return 1900 + int(match.group(1))

    return None


def _extract_instrument_type(value: str) -> str:
    text = normalize_text(value)
    if any(marker in text for marker in ("guitar", "guitare", "les paul", "strat", "tele", "sg", "flying v")):
        return "guitar"
    if "bass" in text:
        return "bass"
    if "amp" in text or "amplifier" in text:
        return "amp"
    if "pedal" in text:
        return "pedal"
    if "keyboard" in text or "piano" in text:
        return "keyboard"
    return ""


def _looks_like_guitar(*values: str) -> bool:
    text = normalize_text(" ".join(values))
    if any(marker in text for marker in GUITAR_MARKERS):
        return True
    return bool(re.search(r"\b(gibson|fender|prs|epiphone|ibanez|yamaha|schecter|musicman)\b", text))


def _extract_color_hint(value: str) -> str:
    text = normalize_text(value)
    tokens = text.split()
    if not tokens:
        return ""

    for token in reversed(tokens):
        if token in COLOR_ALIASES:
            return COLOR_ALIASES[token]

    color_words = {
        "black",
        "white",
        "red",
        "blue",
        "green",
        "yellow",
        "orange",
        "pink",
        "purple",
        "brown",
        "grey",
        "gray",
        "silver",
        "gold",
        "natural",
        "sunburst",
        "cherry",
        "ivory",
        "transparent",
        "cream",
        "burgundy",
        "wine",
        "candy",
    }
    found = [token for token in tokens if token in color_words]
    if found:
        return " ".join(found[-2:])

    return ""


def _extract_color_family(value: str) -> str:
    color = _extract_color_hint(value)
    if not color:
        return ""
    if "sunburst" in color or "burst" in color:
        return "sunburst"
    if "transparent" in color:
        return "transparent"
    if color in {"black", "white", "red", "blue", "green", "yellow", "orange", "pink", "purple", "brown", "grey", "gray", "silver", "gold", "natural", "cream", "ivory"}:
        return color
    return color.split()[0]


def _normalize_model_token(token: str) -> str:
    replacements = {
        "mkii": "mk2",
        "mkiii": "mk3",
        "mkiv": "mk4",
        "ii": "2",
        "iii": "3",
        "iv": "4",
        "vii": "7",
        "viii": "8",
    }
    token = token.lower()
    for source, target in replacements.items():
        token = token.replace(source, target)
    return token


def _extract_model_signature(value: str, brand: str | None = None) -> str:
    normalized = normalize_text(value)
    if not normalized:
        return ""

    brand_tokens = set(normalize_text(brand or "").split()) if brand else set()
    stopwords = {"the", "and", "for", "with", "new", "series", "pack", "bundle", "set"}
    tokens: list[str] = []
    for token in normalized.split():
        if token in brand_tokens:
            continue
        if token in stopwords:
            continue
        if len(token) == 1 and token not in {"m", "v", "s", "x", "l"}:
            continue
        token = _normalize_model_token(token)
        token = re.sub(r"(?<=\D)(?=\d)|(?<=\d)(?=\D)", "", token)
        if token:
            tokens.append(token)
    return "".join(tokens)
