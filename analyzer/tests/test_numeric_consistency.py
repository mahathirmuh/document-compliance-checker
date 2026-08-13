"""Measurements must agree across translations (CLAUDE.md 7, 27 Phase 5).

The defect this rule exists for is a dose or a limit that differs between
languages - the one document error that every other check in this service
passes cleanly, and the one most likely to hurt somebody.

Half of these tests are about *not* firing. A finding this alarming has to be
right, or it teaches people to close the page.
"""

from __future__ import annotations

from app.detectors.language_detector import LanguageDetector
from app.parsers.base import ExtractedDocument, SegmentKind, TextSegment
from app.rules.base import RuleContext
from app.rules.numeric_consistency import NumericConsistencyRule
from app.services.section_analyzer import SectionAnalyzer
from tests.conftest import CHINESE_TEXT, ENGLISH_TEXT, INDONESIAN_TEXT


def context_for(
    segments: list[TextSegment],
    config: dict | None = None,
    ocr_pages: int = 0,
) -> RuleContext:
    document = ExtractedDocument(
        segments=segments,
        parser="docx",
        ocr_recovered_pages=ocr_pages,
    )
    detector = LanguageDetector()

    return RuleContext(
        document=document,
        sections=SectionAnalyzer(detector).analyze(segments),
        detector=detector,
        config=config or {},
    )


def paragraph(text: str, section: str | None = None) -> TextSegment:
    return TextSegment(text=text, kind=SegmentKind.PARAGRAPH, section=section)


def heading(text: str) -> TextSegment:
    return TextSegment(text=text, kind=SegmentKind.HEADING, section=text)


class TestMismatchDetection:
    def test_a_wrong_dose_is_reported(self) -> None:
        # 5 ppm against 500 ppm. Every other check passes this document.
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                paragraph(f"{ENGLISH_TEXT} The reagent is dosed at 5 ppm."),
                paragraph(f"{INDONESIAN_TEXT} Reagen diinjeksikan pada 500 ppm."),
            ])
        )

        assert outcome.applicable is True
        assert len(outcome.findings) == 1

        finding = outcome.findings[0]
        assert finding.issue_type == "NUMERIC_MISMATCH"
        assert "5 ppm" in finding.description

    def test_a_dropped_figure_is_reported(self) -> None:
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                paragraph(f"{ENGLISH_TEXT} Hold at 50°C and 250 kPa."),
                paragraph(f"{INDONESIAN_TEXT} Tahan pada 50°C."),
            ])
        )

        assert len(outcome.findings) == 1
        assert "250" in outcome.findings[0].metadata["missing"][0]

    def test_the_finding_names_the_language_that_is_short(self) -> None:
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm with 12.5 mg/l residual."),
                paragraph(f"{INDONESIAN_TEXT} Dosis 5 ppm."),
            ])
        )

        assert outcome.findings[0].language == "id"
        assert outcome.findings[0].metadata["reference_language"] == "en"


class TestItDoesNotCryWolf:
    def test_matching_figures_across_separator_conventions_pass(self) -> None:
        # The correctness case that decides whether this rule is usable at
        # all: Indonesian writes 6,5 for the same number English writes 6.5.
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                paragraph(f"{ENGLISH_TEXT} Maintain pH between 6.5 and 8.5."),
                paragraph(f"{INDONESIAN_TEXT} Pertahankan pH antara 6,5 dan 8,5."),
            ])
        )

        assert outcome.passed is True

    def test_a_chinese_translation_with_the_same_figures_passes(self) -> None:
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm, do not exceed 50°C."),
                paragraph(f"{CHINESE_TEXT} 投加量 5 ppm，不得超过 50℃。"),
            ])
        )

        assert outcome.passed is True

    def test_clause_numbering_does_not_trigger_a_finding(self) -> None:
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                paragraph(f"1. {ENGLISH_TEXT}"),
                paragraph(f"1. {INDONESIAN_TEXT}"),
            ])
        )

        assert outcome.passed is True

    def test_a_section_missing_a_language_entirely_is_left_alone(self) -> None:
        # That is a missing translation, a different finding with a different
        # fix. Reporting both would double the noise for one defect.
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm."),
            ])
        )

        assert outcome.passed is True

    def test_a_document_with_no_figures_passes(self) -> None:
        outcome = NumericConsistencyRule().evaluate(
            context_for([paragraph(ENGLISH_TEXT), paragraph(INDONESIAN_TEXT)])
        )

        assert outcome.passed is True

    def test_an_empty_document_passes(self) -> None:
        assert NumericConsistencyRule().evaluate(context_for([])).passed is True


class TestOcr:
    def test_it_declines_to_run_on_recognised_text(self) -> None:
        # OCR confuses 5 with S and 0 with O as a matter of course. Comparing
        # numbers there would raise the most alarming finding the system has,
        # on the documents least able to justify it.
        outcome = NumericConsistencyRule().evaluate(
            context_for(
                [
                    paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm."),
                    paragraph(f"{INDONESIAN_TEXT} Dosis 500 ppm."),
                ],
                ocr_pages=4,
            )
        )

        assert outcome.applicable is False
        assert outcome.findings == []
        assert "OCR" in (outcome.skipped_reason or "")

    def test_a_skip_is_not_a_pass(self) -> None:
        outcome = NumericConsistencyRule().evaluate(
            context_for([paragraph(ENGLISH_TEXT)], ocr_pages=2)
        )

        assert outcome.passed is False


class TestSectionScope:
    def test_figures_are_compared_within_a_section_not_across_the_document(self) -> None:
        # A number in section 1 has no business appearing in section 2.
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                heading("1. Dosing"),
                paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm.", "1. Dosing"),
                paragraph(f"{INDONESIAN_TEXT} Dosis 5 ppm.", "1. Dosing"),
                heading("2. Temperature"),
                paragraph(f"{ENGLISH_TEXT} Hold at 250°C.", "2. Temperature"),
                paragraph(f"{INDONESIAN_TEXT} Tahan pada 250°C.", "2. Temperature"),
            ])
        )

        assert outcome.passed is True

    def test_the_finding_names_the_section(self) -> None:
        outcome = NumericConsistencyRule().evaluate(
            context_for([
                heading("3. Dosing"),
                paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm.", "3. Dosing"),
                paragraph(f"{INDONESIAN_TEXT} Dosis 500 ppm.", "3. Dosing"),
            ])
        )

        assert outcome.findings[0].section == "3. Dosing"


class TestRegistration:
    def test_the_rule_is_off_unless_enabled(self) -> None:
        from app.rules.registry import RuleRegistry

        detector = LanguageDetector()
        segments = [
            paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm."),
            paragraph(f"{INDONESIAN_TEXT} Dosis 500 ppm."),
        ]
        document = ExtractedDocument(segments=segments, parser="docx")
        sections = SectionAnalyzer(detector).analyze(segments)

        outcomes, _ = RuleRegistry().run(None, document, sections, detector)

        assert outcomes == []

    def test_it_runs_when_enabled(self) -> None:
        from app.rules.registry import RuleRegistry

        detector = LanguageDetector()
        segments = [
            paragraph(f"{ENGLISH_TEXT} Dose at 5 ppm."),
            paragraph(f"{INDONESIAN_TEXT} Dosis 500 ppm."),
        ]
        document = ExtractedDocument(segments=segments, parser="docx")
        sections = SectionAnalyzer(detector).analyze(segments)

        outcomes, _ = RuleRegistry().run(
            {"numeric_consistency": {"enabled": True}}, document, sections, detector
        )

        assert [outcome.rule for outcome in outcomes] == ["numeric_consistency"]
        assert outcomes[0].findings

    def test_it_is_listed_as_available(self) -> None:
        from app.rules.registry import RuleRegistry

        assert "numeric_consistency" in RuleRegistry().available()
