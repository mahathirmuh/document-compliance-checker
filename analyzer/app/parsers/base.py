"""The contract every parser implements."""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from enum import StrEnum
from pathlib import Path


class SegmentKind(StrEnum):
    """What part of the document a piece of text came from.

    Recorded now, used later: Phase 4 rules need to check coverage per section
    and per table cell, and that is only possible if extraction preserves the
    distinction rather than flattening everything into one string
    (CLAUDE.md 7, 15.1).
    """

    PARAGRAPH = "paragraph"
    HEADING = "heading"
    TABLE_CELL = "table_cell"
    HEADER = "header"
    FOOTER = "footer"
    SHEET_CELL = "sheet_cell"
    LINE = "line"


@dataclass(slots=True)
class TextSegment:
    """One addressable piece of text."""

    text: str
    kind: SegmentKind = SegmentKind.PARAGRAPH

    #: 1-based page, where the format has pages. None for DOCX and XLSX.
    page: int | None = None

    #: Nearest preceding heading, or the worksheet name for XLSX.
    section: str | None = None

    def __post_init__(self) -> None:
        self.text = self.text.strip()


@dataclass(slots=True)
class ExtractedDocument:
    """Everything a parser managed to read out of one file."""

    segments: list[TextSegment] = field(default_factory=list)
    page_count: int | None = None
    parser: str = "unknown"

    #: Non-fatal problems - a truncated read, an unreadable sheet. Surfaced to
    #: the caller as INFO issues rather than being swallowed.
    notes: list[str] = field(default_factory=list)

    #: True when extraction hit a configured limit and stopped early.
    truncated: bool = False

    @property
    def text(self) -> str:
        return "\n".join(segment.text for segment in self.segments if segment.text)


class ParserError(RuntimeError):
    """Extraction failed.

    Messages are written to be safe to show an operator: they say what went
    wrong with the document without echoing its path back.
    """


class UnsupportedFormatError(ParserError):
    """No parser is registered for this extension."""


class DocumentParser(ABC):
    """Base class for format parsers."""

    #: Lower-case extensions, without the dot.
    extensions: tuple[str, ...] = ()

    #: Short name recorded on the response so a bad extraction is traceable.
    name: str = "base"

    @abstractmethod
    def parse(self, path: Path) -> ExtractedDocument:
        """Read `path` and return its text, split into segments.

        Must raise ParserError on failure rather than returning an empty
        document: "this file is broken" and "this file is blank" are
        different findings and must not collapse into one.
        """

    def supports(self, extension: str) -> bool:
        return extension.lower().lstrip(".") in self.extensions
