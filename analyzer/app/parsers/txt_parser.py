"""Plain text extraction.

The only real problem here is encoding. A trilingual .txt from a Windows
Document Control share is as likely to be UTF-16 or GBK as UTF-8, and reading
Chinese with the wrong codec produces mojibake that the detector will happily
classify as something - which is worse than failing, because it is a confident
wrong answer.

So: honour a BOM when present, then try codecs in order, and refuse the file
if none of them decodes it cleanly.
"""

from __future__ import annotations

import codecs
from pathlib import Path

from app.parsers.base import (
    DocumentParser,
    ExtractedDocument,
    ParserError,
    SegmentKind,
    TextSegment,
)

#: Tried in order. UTF-8 first because it is correct far more often than not;
#: GB18030 before Big5 because Simplified is the expected script here; cp1252
#: last as a permissive fallback that decodes nearly anything.
_FALLBACK_CODECS = ("utf-8", "utf-16", "gb18030", "big5", "cp1252")

_BOMS: tuple[tuple[bytes, str], ...] = (
    (codecs.BOM_UTF8, "utf-8-sig"),
    (codecs.BOM_UTF32_LE, "utf-32"),
    (codecs.BOM_UTF32_BE, "utf-32"),
    (codecs.BOM_UTF16_LE, "utf-16"),
    (codecs.BOM_UTF16_BE, "utf-16"),
)


class TxtParser(DocumentParser):
    extensions = ("txt", "text", "md")
    name = "txt"

    def parse(self, path: Path) -> ExtractedDocument:
        try:
            raw = path.read_bytes()
        except OSError as exc:
            raise ParserError("The text file could not be read.") from exc

        text, encoding = self._decode(raw)

        result = ExtractedDocument(parser=self.name)

        if encoding not in {"utf-8", "utf-8-sig"}:
            result.notes.append(f"Decoded as {encoding} rather than UTF-8.")

        for line in text.splitlines():
            stripped = line.strip()
            if stripped:
                result.segments.append(TextSegment(text=stripped, kind=SegmentKind.LINE))

        return result

    # ------------------------------------------------------------------ #

    def _decode(self, raw: bytes) -> tuple[str, str]:
        """Decode bytes, preferring an explicit BOM over guessing."""
        if not raw:
            return "", "utf-8"

        # UTF-32 BOMs start with the UTF-16 LE BOM, so longer markers first.
        for bom, encoding in sorted(_BOMS, key=lambda pair: -len(pair[0])):
            if raw.startswith(bom):
                try:
                    return raw.decode(encoding), encoding
                except UnicodeDecodeError:
                    break

        for encoding in _FALLBACK_CODECS:
            try:
                return raw.decode(encoding), encoding
            except (UnicodeDecodeError, LookupError):
                continue

        raise ParserError(
            "The text file's character encoding could not be determined. "
            "Re-save it as UTF-8 and try again."
        )
