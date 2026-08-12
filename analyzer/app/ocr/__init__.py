"""Optical character recognition for scanned documents (CLAUDE.md 16, 27)."""

from app.ocr.engine import (
    OcrEngine,
    OcrResult,
    OcrUnavailableError,
    TesseractEngine,
    get_ocr_engine,
)

__all__ = [
    "OcrEngine",
    "OcrResult",
    "OcrUnavailableError",
    "TesseractEngine",
    "get_ocr_engine",
]
