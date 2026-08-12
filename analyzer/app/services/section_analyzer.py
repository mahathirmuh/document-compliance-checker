"""Per-section language coverage (CLAUDE.md 7, 27 Phase 4).

Why the section is the unit
---------------------------

The obvious next step after "this document is missing Mandarin" is "*where*
is it missing". The tempting answer is per paragraph - and it is wrong.

A trilingual SOP usually writes each language as its own consecutive
paragraph, or as its own column in a table. Checked per paragraph, every
English paragraph is "missing Indonesian and Chinese", and a perfectly
compliant document produces hundreds of findings that a Document Controller
has to dismiss one by one. The check would be technically correct and
practically useless.

A section is the smallest unit that is *expected* to contain all three
languages, so it is the smallest unit where "a language is missing" is a real
finding. Paragraph positions are still carried on each segment, so a future
rule that genuinely needs them - translation alignment, EN->ID->ZH ordering -
has them available.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from app.config import get_settings
from app.detectors.language_detector import LanguageDetector, TextProfile
from app.parsers.base import SegmentKind, TextSegment

#: Sections without a heading of their own - a preamble, a plain text file, a
#: PDF page with no detected structure - are gathered under this.
UNTITLED_SECTION = "(document body)"

_REQUIRED = ("en", "id", "zh")


@dataclass(slots=True)
class SectionReport:
    """What one section of a document contains."""

    name: str
    sequence: int
    page: int | None
    profile: TextProfile
    segment_count: int

    #: Languages with no text at all in this section.
    missing: list[str] = field(default_factory=list)

    #: Languages present but far shorter than the longest one here - the
    #: "suspiciously short translation" case from CLAUDE.md 33.
    short: list[str] = field(default_factory=list)

    #: Density-normalised lengths, which is what the short check actually
    #: compared. Kept so the reported message can quote the same numbers the
    #: decision used - quoting raw counts instead makes a correct finding look
    #: arbitrary, because 264 English against 311 Chinese reads as balanced
    #: until you know the Chinese is worth roughly three times its length.
    normalised_lengths: dict[str, float] = field(default_factory=dict)

    @property
    def total_characters(self) -> int:
        return self.profile.total_characters


class SectionAnalyzer:
    """Groups extracted segments into sections and profiles each one."""

    def __init__(
        self,
        detector: LanguageDetector | None = None,
        min_section_chars: int | None = None,
        short_translation_ratio: float | None = None,
        chinese_density_factor: float | None = None,
    ) -> None:
        settings = get_settings()

        self._detector = detector or LanguageDetector()

        self._min_section_chars = (
            min_section_chars
            if min_section_chars is not None
            else settings.min_section_chars
        )
        self._short_ratio = (
            short_translation_ratio
            if short_translation_ratio is not None
            else settings.short_translation_ratio
        )
        self._chinese_density = (
            chinese_density_factor
            if chinese_density_factor is not None
            else settings.chinese_density_factor
        )

    def analyze(self, segments: list[TextSegment]) -> list[SectionReport]:
        reports: list[SectionReport] = []

        for sequence, (name, page, group) in enumerate(self._group(segments), start=1):
            profile = self._detector.profile([segment.text for segment in group])

            report = SectionReport(
                name=name,
                sequence=sequence,
                page=page,
                profile=profile,
                segment_count=len(group),
            )

            # A heading on its own, or a two-line note, cannot reasonably be
            # expected to carry all three languages. Reporting those would
            # bury the sections that genuinely need attention.
            if profile.total_characters >= self._min_section_chars:
                report.missing = self._missing_languages(profile)
                report.normalised_lengths = {
                    code: self._normalised_length(profile, code) for code in _REQUIRED
                }
                report.short = self._short_languages(profile, report.missing)

            reports.append(report)

        return reports

    # ------------------------------------------------------------------ #

    def _group(
        self, segments: list[TextSegment]
    ) -> list[tuple[str, int | None, list[TextSegment]]]:
        """Split the segment stream into sections, preserving document order.

        A section starts at a heading and runs until the next one. Formats
        without headings fall back to whatever the parser recorded as the
        section - the worksheet name for XLSX - and then to the page, and
        only then to a single body section.

        The page fallback matters more than it looks. PDF is the dominant
        format in a real controlled-document library, and a PDF has no
        heading styles to read: without it every PDF collapses into one
        "(document body)" section and the whole per-section feature reports
        nothing useful for exactly the documents that need it most. A page is
        a coarser unit than a heading, but "page 7 has no Indonesian" is still
        a location a Document Controller can act on.
        """
        if self._should_group_by_page(segments):
            return self._group_by_page(segments)

        groups: list[tuple[str, int | None, list[TextSegment]]] = []
        current_name: str | None = None
        current_page: int | None = None
        current: list[TextSegment] = []

        def flush() -> None:
            if current:
                groups.append((current_name or UNTITLED_SECTION, current_page, list(current)))

        for segment in segments:
            starts_section = (
                segment.kind is SegmentKind.HEADING
                or (segment.section is not None and segment.section != current_name)
            )

            if starts_section and current:
                flush()
                current.clear()

            if starts_section:
                current_name = segment.section or segment.text
                current_page = segment.page

            if current_name is None:
                current_name = UNTITLED_SECTION
                current_page = segment.page

            current.append(segment)

        flush()

        return groups

    @staticmethod
    def _should_group_by_page(segments: list[TextSegment]) -> bool:
        """True for a paginated document that carries no structure of its own."""
        if not segments:
            return False

        has_structure = any(
            segment.kind is SegmentKind.HEADING or segment.section for segment in segments
        )

        if has_structure:
            return False

        return any(segment.page is not None for segment in segments)

    @staticmethod
    def _group_by_page(
        segments: list[TextSegment],
    ) -> list[tuple[str, int | None, list[TextSegment]]]:
        """One section per page, in page order."""
        pages: dict[int, list[TextSegment]] = {}

        for segment in segments:
            pages.setdefault(segment.page or 0, []).append(segment)

        return [
            (f"Page {page}", page, group)
            for page, group in sorted(pages.items())
        ]

    def _missing_languages(self, profile: TextProfile) -> list[str]:
        return [code for code in _REQUIRED if not profile.tallies[code].detected]

    def _short_languages(self, profile: TextProfile, missing: list[str]) -> list[str]:
        """Languages present but disproportionately short.

        Measured against the longest language in the *same* section rather
        than a fixed character count, because sections vary enormously in
        length: 200 characters is a full translation of a short clause and a
        one-line summary of a long one. Only a within-section comparison can
        tell those apart.

        Lengths are density-normalised before comparing. Chinese renders the
        same content in roughly a third of the characters of English or
        Indonesian, so a raw comparison reports every correctly translated
        Chinese section as suspiciously short - which is worse than not
        checking at all, because it trains a Document Controller to ignore
        the finding.
        """
        present = [code for code in _REQUIRED if code not in missing]

        if len(present) < 2:
            return []

        normalised = {code: self._normalised_length(profile, code) for code in present}
        longest = max(normalised.values())

        if longest <= 0:
            return []

        return [code for code in present if normalised[code] < longest * self._short_ratio]

    def _normalised_length(self, profile: TextProfile, code: str) -> float:
        """Character count scaled to a common information density."""
        characters = profile.tallies[code].characters

        return characters * self._chinese_density if code == "zh" else float(characters)
