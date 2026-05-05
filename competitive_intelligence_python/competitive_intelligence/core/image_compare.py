from __future__ import annotations

from io import BytesIO


def compare_image_bytes(source_bytes: bytes, candidate_bytes: bytes, hash_size: int = 8) -> int:
    try:
        from PIL import Image, ImageOps
    except ImportError as exc:  # pragma: no cover - dependency guard
        raise RuntimeError("Pillow is required for image comparison. Install dependencies first.") from exc

    def build_hash(image_bytes: bytes) -> int:
        image = Image.open(BytesIO(image_bytes)).convert("L")
        resampling = getattr(Image, "Resampling", Image)
        resized = ImageOps.fit(image, (hash_size, hash_size), method=getattr(resampling, "LANCZOS", Image.LANCZOS))
        pixels = list(resized.getdata())
        average = sum(pixels) / len(pixels)
        value = 0
        for index, pixel in enumerate(pixels):
            if pixel >= average:
                value |= 1 << index
        return value

    source_hash = build_hash(source_bytes)
    candidate_hash = build_hash(candidate_bytes)
    return (source_hash ^ candidate_hash).bit_count()


def compare_image_urls(http, source_url: str, candidate_url: str) -> int | None:
    source_url = str(source_url or "").strip()
    candidate_url = str(candidate_url or "").strip()
    if not source_url or not candidate_url:
        return None

    try:
        source_response = http.get(source_url)
        source_response.raise_for_status()
        candidate_response = http.get(candidate_url)
        candidate_response.raise_for_status()
        return compare_image_bytes(source_response.content, candidate_response.content)
    except Exception:
        return None
