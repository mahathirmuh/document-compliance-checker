"""Which text is the translation of which (CLAUDE.md 7, 21).

The coverage table answers "how much Indonesian is in this document". It
cannot answer the question a Document Controller actually asks next: *which*
Indonesian passage corresponds to *which* English one, and do they say the
same thing.

This module does the first half - the pairing. It does not judge meaning, and
deliberately so. Deciding whether 脱盐水 is a faithful translation of
"demineralized water" is a semantic question; deciding that both sit in
section 3.1 is a structural one, and only the structural half can be answered
without a model that might be wrong. Presenting the two side by side lets the
person who *can* judge meaning do it in seconds instead of opening the file
and scrolling.

Three properties this has to hold to be trustworthy:

**Nothing is hidden.** A segment the detector cannot place goes to
`unassigned` rather than being dropped. A reviewer scanning three columns
will assume they are seeing the whole section, and quietly discarding an
unclassifiable line is how a missing translation goes unnoticed.

**Text is never chopped.** A segment is shown whole, under whichever language
dominates it. Splitting "Demineralized water 脱盐水" into two columns would
read better and would be a lie about what the document contains - a reviewer
checking layout compliance needs to see that those two sit in one cell.

**The same sections as everywhere else.** Grouping is delegated to
SectionAnalyzer, so the compare view and the coverage table can never disagree
about where section 3.1 begins.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from app.detectors.language_detector import LanguageDetector
from app.parsers.base import TextSegment
from app.services.section_analyzer import SectionAnalyzer

_REQUIRED = ("en", "id", "zh")


@dataclass(slots=True)
class LanguageBlock:
    """Everything in one section written predominantly in one language."""

    language: str
    segments: list[str] = field(default_factory=list)

    @property
    def characters(self) -> int:
        """Meaningful characters, matching how coverage is counted.

        Alphabetic and Han only - the same basis as the coverage table, so a
        block that looks substantial here cannot correspond to a zero there.
        """
        return sum(
            1
            for text in self.segments
            for char in text
            if char.isalpha()
        )

    @property
    def is_empty(self) -> bool:
        return not self.segments


@dataclass(slots=True)
class AlignedSection:
    """One section's text, split by the language each piece is written in."""

    name: str
    sequence: int
    page: int | None
    blocks: dict[str, LanguageBlock]

    #: Segments the detector would not commit to a language: numeric tables,
    #: codes, OCR noise, or Latin text too ambiguous to call. Kept and shown
    #: rather than discarded - see the module docstring.
    unassigned: list[str] = field(default_factory=list)

    @property
    def present_languages(self) -> list[str]:
        return [code for code in _REQUIRED if not self.blocks[code].is_empty]

    @property
    def missing_languages(self) -> list[str]:
        return [code for code in _REQUIRED if self.blocks[code].is_empty]


@dataclass(slots=True)
class AlignmentResult:
    sections: list[AlignedSection] = field(default_factory=list)

    #: True when the character budget ran out and later sections were dropped.
    #: Surfaced so the caller can tell a reviewer they are not looking at the
    #: whole document, which silence would not.
    truncated: bool = False


class DocumentAligner:
    """Groups a document's segments into section x language blocks."""

    def __init__(
        self,
        detector: LanguageDetector | None = None,
        sections: SectionAnalyzer | None = None,
        chinese_density: float = 3.0,
    ) -> None:
        self._detector = detector or LanguageDetector()
        self._sections = sections or SectionAnalyzer(self._detector)
        self._chinese_density = chinese_density

    def align(
        self,
        segments: list[TextSegment],
        max_characters: int | None = None,
    ) -> AlignmentResult:
        """Pair up the text of each section by language.

        :param max_characters: stop once this much text has been collected.
            A 500-page manual would otherwise produce a response no browser
            should be asked to render.
        """
        result = AlignmentResult()
        budget = max_characters

        for sequence, (name, page, group) in enumerate(
            self._sections.group(segments), start=1
        ):
            section = AlignedSection(
                name=name,
                sequence=sequence,
                page=page,
                blocks={code: LanguageBlock(language=code) for code in _REQUIRED},
            )

            for segment in group:
                text = segment.text

                if not text:
                    continue

                if budget is not None:
                    if budget <= 0:
                        result.truncated = True
                        break

                    budget -= len(text)

                code = self._detector.dominant_language(text, self._chinese_density)

                if code in _REQUIRED:
                    section.blocks[code].segments.append(text)
                else:
                    section.unassigned.append(text)

            result.sections.append(section)

            if result.truncated:
                break

        return result
