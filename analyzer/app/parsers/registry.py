"""Parser lookup.

The single place that maps an extension to a parser. Adding PPTX or scanned
image support means writing the parser and adding one line to _DEFAULT_PARSERS
- nothing in the analysis pipeline changes (CLAUDE.md 5).
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path

from app.parsers.base import DocumentParser, UnsupportedFormatError
from app.parsers.docx_parser import DocxParser
from app.parsers.pdf_parser import PdfParser
from app.parsers.txt_parser import TxtParser
from app.parsers.xlsx_parser import XlsxParser

_DEFAULT_PARSERS: tuple[type[DocumentParser], ...] = (
    DocxParser,
    PdfParser,
    XlsxParser,
    TxtParser,
)


class ParserRegistry:
    def __init__(self, parsers: list[DocumentParser] | None = None) -> None:
        self._parsers: list[DocumentParser] = parsers or [
            parser_class() for parser_class in _DEFAULT_PARSERS
        ]

    def register(self, parser: DocumentParser) -> None:
        self._parsers.append(parser)

    def for_extension(self, extension: str) -> DocumentParser:
        normalised = extension.lower().lstrip(".")

        for parser in self._parsers:
            if parser.supports(normalised):
                return parser

        raise UnsupportedFormatError(
            f"No parser is available for .{normalised} files. "
            f"Supported formats: {', '.join(self.supported_extensions())}."
        )

    def for_path(self, path: Path) -> DocumentParser:
        return self.for_extension(path.suffix)

    def supported_extensions(self) -> list[str]:
        extensions: list[str] = []

        for parser in self._parsers:
            extensions.extend(parser.extensions)

        return sorted(set(extensions))


@lru_cache
def get_registry() -> ParserRegistry:
    return ParserRegistry()
