"""Document parsers.

Each format gets one parser implementing DocumentParser. Adding a format -
PPTX, DOC, scanned images - means writing one class and registering it, with
no change to the detection or scoring code (CLAUDE.md 5).
"""

from app.parsers.base import DocumentParser, ExtractedDocument, SegmentKind, TextSegment
from app.parsers.registry import ParserRegistry, get_registry

__all__ = [
    "DocumentParser",
    "ExtractedDocument",
    "ParserRegistry",
    "SegmentKind",
    "TextSegment",
    "get_registry",
]
