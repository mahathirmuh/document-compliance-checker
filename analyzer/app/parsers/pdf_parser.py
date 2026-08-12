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

        if page_limit > 0 and pages_with_text == 0:
            result.notes.append(
                "No extractable text was found on any page; this is most likely a scanned document."
            )

        return result
