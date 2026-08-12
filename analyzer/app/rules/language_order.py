"""Translations must appear in the configured order (CLAUDE.md 7, 27 Phase 5)."""

from __future__ import annotations

from app.parsers.base import SegmentKind
from app.rules.base import DocumentRule, RuleContext, RuleFinding, RuleOutcome

_LANGUAGE_NAMES = {"en": "English", "id": "Indonesian", "zh": "Chinese"}

#: Segments shorter than this contribute nothing to the order.
#:
#: A fragment of a few characters cannot be classified with any confidence,
#: and letting one open the sequence would decide the whole finding on noise.
_MIN_ORDERING_CHARS = 25


class LanguageOrderRule(DocumentRule):
    """Checks that each section presents its languages in the expected order.

    Checked per section, and only for sections that actually contain more
    than one language. Across a whole document the "order" is meaningless -
    languages alternate section by section - and in a section with a single
    language there is no order to get wrong.

    Order is taken from first appearance, not from volume. A section that
    opens with a one-line Chinese title before its English body has still put
    Chinese first, and that is exactly what this rule is asked to catch.
    """

    name = "language_order"
    label = "Language order"

    def applicable_to(self, context: RuleContext) -> tuple[bool, str | None]:
        """Only meaningful where sections are real, authored units.

        Page-derived sections are not. A page break falls wherever the text
        ran out, routinely mid-way through a trilingual block, so the second
        page legitimately opens with the continuation of the previous
        language. Checking order against those produced a finding on 54 of 54
        pages of a real SOP - which is not a compliance report, it is noise
        that teaches people to ignore the rule.
        """
        origins = {section.origin for section in context.sections}

        if origins and origins <= {"page", "document"}:
            return False, (
                "Language order can only be checked where a document declares its own "
                "sections. This file has none, so its sections were derived from page "
                "breaks - which fall wherever the text ran out and say nothing about the "
                "order the author intended."
            )

        return True, None

    def evaluate(self, context: RuleContext) -> RuleOutcome:
        applicable, reason = self.applicable_to(context)

        if not applicable:
            return self.skipped(reason or "Not applicable.")

        expected = [
            str(code).lower()
            for code in (context.option("order") or ["en", "id", "zh"])
        ]

        findings: list[RuleFinding] = []

        for section in context.sections:
            if section.origin not in {"heading", "worksheet"}:
                continue

            observed = self._first_appearance_order(context, section)

            if len(observed) < 2:
                continue

            # Compare only the languages this section actually contains: a
            # section legitimately covering just English and Chinese must not
            # be failed for "missing" Indonesian from the sequence. That is
            # the missing-translation rule's job, not this one's.
            reference = [code for code in expected if code in observed]

            if observed == reference:
                continue

            findings.append(
                RuleFinding(
                    issue_type="WRONG_LANGUAGE_ORDER",
                    severity="WARNING",
                    description=(
                        f"Section '{section.name}' presents its languages as "
                        f"{self._describe(observed)}, but the expected order is "
                        f"{self._describe(reference)}."
                    ),
                    page=section.page,
                    section=section.name,
                    metadata={
                        "observed": observed,
                        "expected": reference,
                        "sequence": section.sequence,
                    },
                )
            )

        return self.result(findings)

    # ------------------------------------------------------------------ #

    def _first_appearance_order(self, context: RuleContext, section) -> list[str]:
        """The languages of a section's content, in the order they appear.

        Headings are excluded. A heading is the section's label, not part of
        its translated content, and it is very often written in one language
        only - so counting it would put that language first in every section
        and make the rule incapable of finding the thing it exists to find.
        """
        order: list[str] = []

        for segment in self._segments_for(context, section):
            if segment.kind is SegmentKind.HEADING:
                continue

            if len(segment.text) < _MIN_ORDERING_CHARS:
                continue

            code = context.detector.dominant_language(segment.text)

            if code is not None and code not in order:
                order.append(code)

        return order

    @staticmethod
    def _segments_for(context: RuleContext, section):
        """Re-derive this section's segments from the document.

        SectionReport carries aggregate counts rather than the segments
        themselves, so they are recovered by matching the same key the
        section analyzer grouped on.
        """
        if section.page is not None:
            candidates = [s for s in context.document.segments if s.page == section.page]

            if candidates:
                return candidates

        return [s for s in context.document.segments if (s.section or "") == section.name]

    @staticmethod
    def _describe(codes: list[str]) -> str:
        return " → ".join(_LANGUAGE_NAMES.get(code, code) for code in codes)
