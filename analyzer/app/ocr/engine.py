"""OCR engines.

OCR is off by default and stays off unless two things are true: it is enabled
in configuration, and the Tesseract binary is actually installed. Neither is
assumed, because the failure mode of assuming matters - a document that
silently produces no text looks identical to a document with no translations,
and reporting that as FAIL would blame the document for a missing dependency
(CLAUDE.md 16).

When OCR is unavailable the analyzer behaves exactly as it did before: the
document is reported with an OCR_REQUIRED issue and routed to human review.
"""

from __future__ import annotations

import shutil
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path
from typing import Protocol

from app.config import Settings, get_settings


class OcrUnavailableError(RuntimeError):
    """OCR was requested but cannot run.

    Messages are written for an operator and say what to install, because
    this is nearly always a deployment gap rather than a document problem.
    """


@dataclass(slots=True)
class OcrResult:
    """Text recovered from one rasterised page."""

    text: str
    page: int

    #: Mean per-word confidence, 0-1. Tesseract reports this per word; a low
    #: value means the recognition itself is doubtful, which is why an
    #: OCR-derived analysis is always routed to review rather than trusted.
    confidence: float = 0.0

    #: Non-fatal problems worth surfacing.
    notes: list[str] = field(default_factory=list)


class OcrEngine(Protocol):
    """What the PDF parser needs from an OCR implementation."""

    def is_available(self) -> bool: ...

    def unavailable_reason(self) -> str | None: ...

    def recognise_pdf(self, path: Path, pages: list[int]) -> list[OcrResult]: ...


class TesseractEngine:
    """Tesseract via pytesseract, rendering pages with pypdfium2.

    pypdfium2 rather than pdf2image: it ships as a self-contained wheel, so
    rasterising needs no Poppler install. That reduces the system dependency
    to the Tesseract binary alone, which is one thing for an administrator to
    provision instead of three.
    """

    def __init__(self, settings: Settings | None = None) -> None:
        self._settings = settings or get_settings()

    def is_available(self) -> bool:
        return self.unavailable_reason() is None

    def unavailable_reason(self) -> str | None:
        """Why OCR cannot run, or None when it can."""
        try:
            import pypdfium2  # noqa: F401
            import pytesseract  # noqa: F401
        except ImportError:
            return (
                'The OCR Python packages are not installed. Run '
                '"pip install -r requirements.txt" in the analyzer directory.'
            )

        if self._binary_path() is None:
            return (
                "The Tesseract OCR binary was not found. Install Tesseract and either put it "
                "on PATH or set ANALYZER_TESSERACT_PATH."
            )

        missing = self._missing_languages()

        if missing:
            return (
                f"Tesseract is installed but is missing language data for: {', '.join(missing)}. "
                "Install the corresponding traineddata files."
            )

        return None

    def recognise_pdf(self, path: Path, pages: list[int]) -> list[OcrResult]:
        """Rasterise and recognise the given 1-based page numbers.

        :raises OcrUnavailableError: if OCR is not usable
        """
        reason = self.unavailable_reason()

        if reason is not None:
            raise OcrUnavailableError(reason)

        import pypdfium2 as pdfium
        import pytesseract

        pytesseract.pytesseract.tesseract_cmd = self._binary_path()

        languages = "+".join(self._settings.ocr_languages)
        scale = self._settings.ocr_render_scale
        results: list[OcrResult] = []

        document = pdfium.PdfDocument(str(path))

        try:
            for page_number in pages[: self._settings.ocr_max_pages]:
                index = page_number - 1

                if index < 0 or index >= len(document):
                    continue

                page = document[index]

                try:
                    # scale is relative to 72 dpi; ~2.8 gives the 200 dpi
                    # Tesseract wants without the memory cost of 300.
                    bitmap = page.render(scale=scale)
                    image = bitmap.to_pil()
                except Exception as exc:  # noqa: BLE001 - pdfium raises broadly
                    results.append(
                        OcrResult(
                            text="",
                            page=page_number,
                            notes=[f"Page {page_number} could not be rendered for OCR: {exc}"],
                        )
                    )
                    continue

                try:
                    text = pytesseract.image_to_string(image, lang=languages)
                    confidence = self._mean_confidence(pytesseract, image, languages)
                except Exception as exc:  # noqa: BLE001 - pytesseract raises broadly
                    results.append(
                        OcrResult(
                            text="",
                            page=page_number,
                            notes=[f"Page {page_number} could not be recognised: {exc}"],
                        )
                    )
                    continue
                finally:
                    image.close()

                results.append(
                    OcrResult(text=text.strip(), page=page_number, confidence=confidence)
                )
        finally:
            document.close()

        return results

    # ------------------------------------------------------------------ #

    def _binary_path(self) -> str | None:
        configured = self._settings.tesseract_path

        if configured:
            return str(configured) if Path(configured).is_file() else None

        return shutil.which("tesseract")

    def _missing_languages(self) -> list[str]:
        try:
            import pytesseract

            pytesseract.pytesseract.tesseract_cmd = self._binary_path()
            installed = set(pytesseract.get_languages(config=""))
        except Exception:  # noqa: BLE001 - any failure here means "cannot tell"
            return []

        return [lang for lang in self._settings.ocr_languages if lang not in installed]

    @staticmethod
    def _mean_confidence(pytesseract_module, image, languages: str) -> float:
        """Mean per-word confidence, ignoring the -1 Tesseract uses for blanks."""
        try:
            data = pytesseract_module.image_to_data(
                image,
                lang=languages,
                output_type=pytesseract_module.Output.DICT,
            )
        except Exception:  # noqa: BLE001
            return 0.0

        scores = [int(value) for value in data.get("conf", []) if str(value).lstrip("-").isdigit()]
        scores = [score for score in scores if score >= 0]

        if not scores:
            return 0.0

        return round(sum(scores) / len(scores) / 100, 4)


class NullEngine:
    """Used when OCR is switched off. Always reports why."""

    def is_available(self) -> bool:
        return False

    def unavailable_reason(self) -> str | None:
        return "OCR is disabled in configuration."

    def recognise_pdf(self, path: Path, pages: list[int]) -> list[OcrResult]:
        raise OcrUnavailableError(self.unavailable_reason() or "OCR is disabled.")


@lru_cache
def get_ocr_engine() -> OcrEngine:
    settings = get_settings()

    return TesseractEngine(settings) if settings.ocr_enabled else NullEngine()
