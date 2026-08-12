"""Orchestrates one analysis: read the file, measure the languages, report.

The one rule this service holds to is that it reports measurements and never
verdicts. It knows nothing about "how much Indonesian is enough" - that is a
Document Control policy living in the Laravel `settings` table, and keeping it
out of here is what lets a threshold change re-grade documents without a
redeploy (CLAUDE.md 6, 15.5).

The `status` field it returns is advisory, present only because the CLAUDE.md
14 contract specifies it. Laravel recomputes the real one.
"""

from __future__ import annotations

import time
from functools import lru_cache
from pathlib import Path

from app import __version__
from app.config import Settings, get_settings
from app.detectors.language_detector import LanguageDetector, TextProfile
from app.parsers.base import ExtractedDocument, ParserError
from app.parsers.registry import ParserRegistry, get_registry
from app.schemas.response import (
    AnalyzeResponse,
    Issue,
    IssueSeverity,
    IssueType,
    LanguageCode,
    LanguageReport,
    SectionReport,
)
from app.services.section_analyzer import SectionAnalyzer
from app.services.section_analyzer import SectionReport as SectionResult


class AnalysisService:
    def __init__(
        self,
        registry: ParserRegistry | None = None,
        detector: LanguageDetector | None = None,
        settings: Settings | None = None,
        sections: SectionAnalyzer | None = None,
    ) -> None:
        self._settings = settings or get_settings()
        self._registry = registry or get_registry()
        self._detector = detector or LanguageDetector()
        self._sections = sections or SectionAnalyzer(self._detector)

    def analyze(
        self,
        path: Path,
        document_id: int | None = None,
        version_id: int | None = None,
    ) -> AnalyzeResponse:
        started = time.perf_counter()

        parser = self._registry.for_path(path)
        extracted = parser.parse(path)

        profile = self._detector.profile([segment.text for segment in extracted.segments])
        sections = self._sections.analyze(extracted.segments)

        issues = self._collect_issues(extracted, profile)
        issues.extend(self._section_issues(sections))

        return AnalyzeResponse(
            status=self._advisory_status(profile, issues),
            overall_score=self._advisory_score(profile),
            languages=self._language_reports(profile),
            issues=issues,
            sections=[self._to_section_report(section) for section in sections],
            analyzer_version=__version__,
            document_id=document_id,
            version_id=version_id,
            total_characters=profile.total_characters,
            page_count=extracted.page_count,
            segment_count=len(extracted.segments),
            parser=parser.name,
            duration_ms=int((time.perf_counter() - started) * 1000),
        )

    # ------------------------------------------------------------------ #

    def _language_reports(self, profile: TextProfile) -> dict[LanguageCode, LanguageReport]:
        reports: dict[LanguageCode, LanguageReport] = {}

        for code in ("en", "id", "zh"):
            tally = profile.tallies[code]

            reports[LanguageCode(code)] = LanguageReport(
                detected=tally.detected,
                character_count=tally.characters,
                word_count=tally.words,
                coverage=profile.coverage(code),
                confidence=tally.confidence,
            )

        return reports

    def _collect_issues(self, extracted: ExtractedDocument, profile: TextProfile) -> list[Issue]:
        issues: list[Issue] = []

        # A document that yields almost no text is a scan, not a document with
        # no translations. Saying FAIL here would blame the document for a
        # parser limitation, so it is escalated to a human instead
        # (CLAUDE.md 16).
        if profile.total_characters < self._settings.min_extractable_chars:
            issues.append(
                Issue(
                    type=IssueType.OCR_REQUIRED,
                    severity=IssueSeverity.WARNING,
                    description=(
                        "Almost no readable text could be extracted. This is most likely a "
                        "scanned document and needs to be reviewed by hand, or re-processed "
                        "once OCR is enabled."
                    ),
                    metadata={
                        "characters_found": profile.total_characters,
                        "minimum_expected": self._settings.min_extractable_chars,
                        "page_count": extracted.page_count,
                    },
                )
            )

        for note in extracted.notes:
            issues.append(
                Issue(
                    type=IssueType.PARSER_ERROR,
                    severity=IssueSeverity.INFO,
                    description=note,
                )
            )

        if profile.cjk_other_segments:
            issues.append(
                Issue(
                    type=IssueType.PARSER_ERROR,
                    severity=IssueSeverity.INFO,
                    description=(
                        f"{profile.cjk_other_segments} segment(s) contained Japanese or Korean "
                        "script; their Han characters were not counted as Chinese."
                    ),
                    metadata={"segments": profile.cjk_other_segments},
                )
            )

        return issues

    def _to_section_report(self, section: SectionResult) -> SectionReport:
        return SectionReport(
            name=section.name,
            sequence=section.sequence,
            page=section.page,
            total_characters=section.total_characters,
            segment_count=section.segment_count,
            characters={
                LanguageCode(code): section.profile.tallies[code].characters
                for code in ("en", "id", "zh")
            },
            missing=[LanguageCode(code) for code in section.missing],
            short=[LanguageCode(code) for code in section.short],
            evaluated=section.total_characters >= self._settings.min_section_chars,
        )

    def _section_issues(self, sections: list[SectionResult]) -> list[Issue]:
        """Locate the gaps.

        This is what turns "this document is missing Mandarin" into "section
        4.2 is missing Mandarin", which is the difference between a finding a
        Document Controller can act on and one they have to go hunting for
        (CLAUDE.md 7, 21).

        A section that is missing *every* language is not reported: that means
        the section is numbers or codes, not that three translations are
        absent.
        """
        issues: list[Issue] = []

        for section in sections:
            if len(section.missing) == len(("en", "id", "zh")):
                continue

            for code in section.missing:
                issues.append(
                    Issue(
                        type=IssueType.MISSING_SECTION_TRANSLATION,
                        severity=IssueSeverity.WARNING,
                        description=(
                            f"Section '{section.name}' has no {self._language_name(code)} text, "
                            "although other languages are present in it."
                        ),
                        language=LanguageCode(code),
                        page=section.page,
                        section=section.name,
                        metadata={
                            "sequence": section.sequence,
                            "section_characters": section.total_characters,
                        },
                    )
                )

            for code in section.short:
                characters = section.profile.tallies[code].characters
                normalised = section.normalised_lengths

                longest_code = max(normalised, key=lambda key: normalised[key])
                longest_characters = section.profile.tallies[longest_code].characters

                # The comparison ran on density-normalised lengths, so the
                # message quotes those too. Reporting raw counts would make a
                # correct finding look arbitrary: "264 English against 311
                # Chinese" reads as balanced until you know the Chinese is
                # worth roughly three times its character count.
                detail = (
                    f"{self._language_name(code)} is comparable to "
                    f"{normalised.get(code, 0):.0f} characters of Latin text against "
                    f"{normalised.get(longest_code, 0):.0f} for "
                    f"{self._language_name(longest_code)}"
                )

                issues.append(
                    Issue(
                        type=IssueType.SHORT_TRANSLATION,
                        severity=IssueSeverity.INFO,
                        description=(
                            f"Section '{section.name}' has {characters} characters of "
                            f"{self._language_name(code)} against {longest_characters} of "
                            f"{self._language_name(longest_code)}. Allowing for script "
                            f"density, {detail}. The translation may be incomplete."
                        ),
                        language=LanguageCode(code),
                        page=section.page,
                        section=section.name,
                        metadata={
                            "sequence": section.sequence,
                            "characters": characters,
                            "longest_language": longest_code,
                            "longest_language_characters": longest_characters,
                            "normalised_lengths": {
                                key: round(value, 1) for key, value in normalised.items()
                            },
                        },
                    )
                )

        return issues

    @staticmethod
    def _language_name(code: str) -> str:
        return {"en": "English", "id": "Indonesian", "zh": "Chinese"}.get(code, code)

    def _advisory_status(self, profile: TextProfile, issues: list[Issue]) -> str:
        """A rough status for humans reading the raw response.

        Not authoritative and not used for anything: Laravel applies the
        configured thresholds and owns the verdict. This exists so a developer
        curling the endpoint gets an immediately readable answer.
        """
        if any(issue.type is IssueType.OCR_REQUIRED for issue in issues):
            return "REVIEW_REQUIRED"

        detected = [code for code in ("en", "id", "zh") if profile.tallies[code].detected]

        if len(detected) == 3:
            return "PASS"

        return "FAIL"

    def _advisory_score(self, profile: TextProfile) -> float | None:
        """Share of the three required languages that are present at all."""
        if profile.total_characters == 0:
            return None

        present = sum(1 for code in ("en", "id", "zh") if profile.tallies[code].detected)

        return round((present / 3) * 100, 2)


@lru_cache
def get_analysis_service() -> AnalysisService:
    return AnalysisService()


__all__ = ["AnalysisService", "ParserError", "get_analysis_service"]
