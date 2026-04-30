from __future__ import annotations

import re
import unicodedata


def normalize_text(value: str) -> str:
    value = unicodedata.normalize("NFKD", value)
    value = value.encode("ascii", "ignore").decode("ascii")
    value = value.lower()
    value = re.sub(r"[^a-z0-9]+", " ", value)
    return re.sub(r"\s+", " ", value).strip()


def simplify_name(value: str) -> str:
    tokens = [token for token in normalize_text(value).split() if len(token) > 2]
    return " ".join(tokens[:8])

