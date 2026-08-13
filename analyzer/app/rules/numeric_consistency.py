"""Measurements must agree across translations (CLAUDE.md 7, 27 Phase 5).

Prose is allowed to differ between translations. Numbers are not. In an SOP
for reagent dosing, "5 ppm" rendered as "500 ppm" in one language is the most
dangerous defect the document can carry, and every other check in this
service would pass it: all three languages are present, balanced, in the
right order, and the section is fully translated.

This is also the one semantic check that can be made without a model. Whether
脱盐水 faithfully translates "demineralized water" needs judgement; whether
both sentences contain 6.5 does not.
"""

from __future__ import annotations

from typing import TYPE_CHECKING

from app.rules.base import DocumentRule, RuleContext, RuleFinding, RuleOutcome

if TYPE_CHECKING:
    from app.services.alignment import AlignedSection
    from app.services.measurements import Measurement

# The service imports below are deferred into the methods that use them.
# app/services/__init__ pulls in AnalysisService, which imports the rule
# registry, which imports this module - so importing a service at module level
# here closes a cycle and fails at collection time.

_LANGUAGE_NAMES = {"en": "English", "id": "Indonesian", "zh": "Chinese"}

#: How many mismatched values a single finding will quote before summarising.
_MAX_QUOTED = 6


class NumericConsistencyRule(DocumentRule):
    """Checks that each section's numbers appear in all of its languages.

    Compared per section, against whichever language in that section carries
    the most measurements. A document-wide comparison would be meaningless -
    a number in section 2 has no business appearing in section 9 - and
    picking a fixed reference language would report every section that
    happens to state a figure only in its Chinese table.

    Only sections that already contain the other language are checked. A
    section with no Indonesian at all is a missing translation, which is a
    different finding with a different fix, and reporting both would double
    the noise for one defect.
    """

    name = "numeric_consistency"
    label = "Numeric consistency"

    def applicable_to(self, context: RuleContext) -> tuple[bool, str | None]:
        """Declines on recognised text.

        OCR misreads digits as a matter of course - 5 for S, 0 for O, 1 for l
        - so on a scanned document this rule would report mismatches that
        exist only in the recognition. Reporting those would be worse than
        not checking: it produces the most alarming finding the system can
        raise, on the documents least able to justify it.
        """
        if context.document.used_ocr:
            return False, (
                "Numbers cannot be compared in text recovered by OCR. Recognition "
                "routinely confuses digits with letters, so any mismatch found here "
                "would say more about the scan than about the translation."
            )

        return True, None

    def evaluate(self, context: RuleContext) -> RuleOutcome:
        applicable, reason = self.applicable_to(context)

        if not applicable:
            return self.skipped(reason or "Not applicable.")

        findings: list[RuleFinding] = []

        for section in self._aligned(context):
            findings.extend(self._check_section(section))

        return self.result(findings)

    # ------------------------------------------------------------------ #

    @staticmethod
    def _aligned(context: RuleContext) -> list[AlignedSection]:
        """Pair the document's text up by section and language.

        Runs on furniture-stripped segments, the same basis as the coverage
        figures. Leaving the furniture in would compare the page numbers in a
        running footer, which differ by design.
        """
        from app.services.alignment import DocumentAligner
        from app.services.boilerplate import content_segments

        aligner = DocumentAligner(context.detector)

        return aligner.align(content_segments(context.document.segments)).sections

    def _check_section(self, section: AlignedSection) -> list[RuleFinding]:
        from app.services.measurements import find_measurements, unmatched

        measurements = {
            code: find_measurements(" ".join(section.blocks[code].segments))
            for code in section.present_languages
        }

        # Nothing to compare against with fewer than two languages carrying
        # text, and nothing to compare with if no language states a figure.
        if len(measurements) < 2 or not any(measurements.values()):
            return []

        reference_code = max(measurements, key=lambda code: len(measurements[code]))
        reference = measurements[reference_code]

        if not reference:
            return []

        findings: list[RuleFinding] = []

        for code, found in measurements.items():
            if code == reference_code:
                continue

            missing = unmatched(reference, found)

            if not missing:
                continue

            findings.append(self._finding(section, reference_code, code, missing))

        return findings

    @staticmethod
    def _finding(
        section: AlignedSection,
        reference_code: str,
        code: str,
        missing: list[Measurement],
    ) -> RuleFinding:
        quoted = ", ".join(measurement.display for measurement in missing[:_MAX_QUOTED])
        remainder = len(missing) - _MAX_QUOTED

        if remainder > 0:
            quoted = f"{quoted} and {remainder} more"

        return RuleFinding(
            issue_type="NUMERIC_MISMATCH",
            severity="WARNING",
            description=(
                f"Section '{section.name}': {quoted} "
                f"{'appear' if len(missing) > 1 else 'appears'} in the "
                f"{_LANGUAGE_NAMES.get(reference_code, reference_code)} text but not in the "
                f"{_LANGUAGE_NAMES.get(code, code)}. A figure that differs between "
                "translations is a document defect, not a wording choice."
            ),
            language=code,
            page=section.page,
            section=section.name,
            metadata={
                "sequence": section.sequence,
                "reference_language": reference_code,
                "missing": [measurement.display for measurement in missing],
            },
        )
