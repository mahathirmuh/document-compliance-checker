"""PDF extraction.

Text is kept per page so an issue can later be pinned to "page 7" rather than
to the document as a whole (CLAUDE.md 15.1, 21).

A PDF that yields almost nothing is the interesting case: that is a scan, not
an empty document. This parser reports what it found and lets the analysis
service raise OCR_REQUIRED - it never guesses at compliance (CLAUDE.md 16).
"""

from __future__ import annotations

from pathlib import Path

from pypdf import PdfReader
from pypdf.errors import PdfReadError

from app.config import get_settings
from app.ocr.engine import OcrEngine, OcrUnavailableError, get_ocr_engine
from app.parsers.base import (
    DocumentParser,
    ExtractedDocument,
    ParserError,
    SegmentKind,
    TextSegment,
)


class PdfParser(DocumentParser):
    extensions = ("pdf",)
    name = "pdf"

    def __init__(self, ocr: OcrEngine | None = None) -> None:
        self._ocr = ocr

    @property
    def ocr(self) -> OcrEngine:
        # Resolved lazily so configuration changes between requests are picked
        # up, and so constructing the parser never touches the OCR stack.
        return self._ocr if self._ocr is not None else get_ocr_engine()

    def parse(self, path: Path) -> ExtractedDocument:
        settings = get_settings()

        try:
            reader = PdfReader(str(path))
        except PdfReadError as exc:
            raise ParserError("The PDF could not be opened. It may be corrupt.") from exc
        except Exception as exc:
            raise ParserError("The PDF could not be opened.") from exc

        if reader.is_encrypted:
            # An empty-password decrypt covers the common "protected but not
            # really" case; anything else needs a human with the password.
            try:
                if reader.decrypt("") == 0:
                    raise ParserError("The PDF is password protected and cannot be analysed.")
            except ParserError:
                raise
            except Exception as exc:
                raise ParserError("The PDF is password protected and cannot be analysed.") from exc

        result = ExtractedDocument(parser=self.name)

        try:
            total_pages = len(reader.pages)
        except Exception as exc:
            raise ParserError("The PDF page index could not be read.") from exc

        result.page_count = total_pages
        page_limit = min(total_pages, settings.max_pdf_pages)

        if total_pages > page_limit:
            result.truncated = True
            result.notes.append(
                f"Only the first {page_limit} of {total_pages} pages were analysed."
            )

        pages_with_text = 0

        for index in range(page_limit):
            try:
                raw = reader.pages[index].extract_text() or ""
            except Exception:  # noqa: BLE001
                # One malformed page must not lose the other 200. The gap is
                # recorded so a reviewer can see the extraction was partial.
                result.notes.append(f"Page {index + 1} could not be read.")
                continue

            lines = [line.strip() for line in raw.splitlines()]
            page_had_text = False

            for line in lines:
                if line:
                    page_had_text = True
                    result.segments.append(
                        TextSegment(text=line, kind=SegmentKind.LINE, page=index + 1)
                    )

            if page_had_text:
                pages_with_text += 1

        pages_without_text = [
            page for page in range(1, page_limit + 1)
            if page not in {segment.page for segment in result.segments}
        ]

        if pages_without_text:
            self._recover_with_ocr(path, result, pages_without_text)

        if page_limit > 0 and pages_with_text == 0 and not result.segments:
            result.notes.append(
                "No extractable text was found on any page; this is most likely a scanned document."
            )

        return result

    # ------------------------------------------------------------------ #

    def _recover_with_ocr(
        self,
        path: Path,
        result: ExtractedDocument,
        pages: list[int],
    ) -> None:
        """Try to recover text from pages that have no text layer.

        Deliberately silent when OCR is unavailable: the caller already
        reports OCR_REQUIRED, and adding "and OCR is switched off" to every
        scanned document would be noise. The reason is recorded once, as a
        note, so an operator investigating knows which lever to pull.
        """
        engine = self.ocr

        if not engine.is_available():
            reason = engine.unavailable_reason()

            if reason and "disabled" not in reason:
                # Enabled but broken is worth saying out loud - that is a
                # deployment problem, not a deliberate choice.
                result.notes.append(f"OCR could not run: {reason}")

            return

        settings = get_settings()

        try:
            recognised = engine.recognise_pdf(path, pages)
        except OcrUnavailableError as exc:
            result.notes.append(f"OCR could not run: {exc}")

            return

        recovered_pages = 0
        low_confidence = 0

        for page_result in recognised:
            result.notes.extend(page_result.notes)

            lines = [line.strip() for line in page_result.text.splitlines() if line.strip()]

            if not lines:
                continue

            recovered_pages += 1

            if page_result.confidence < settings.ocr_min_confidence:
                low_confidence += 1

            for line in lines:
                result.segments.append(
                    TextSegment(text=line, kind=SegmentKind.LINE, page=page_result.page)
                )

        if recovered_pages:
            # Recorded so the analysis is never mistaken for a clean text
            # extraction. OCR output is advisory and the document should still
            # reach a human (CLAUDE.md 16, 33).
            result.ocr_recovered_pages = recovered_pages
            result.notes.append(
                f"Text on {recovered_pages} page(s) was recovered by OCR and should be reviewed."
            )

        if low_confidence:
            result.notes.append(
                f"OCR confidence was low on {low_confidence} page(s); "
                "the recognised text may be wrong."
            )

        # Segments must stay in reading order for section grouping to work.
        result.segments.sort(key=lambda segment: (segment.page or 0))
