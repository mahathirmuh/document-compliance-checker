"""Header, footer and cover page (CLAUDE.md 7, 27 Phase 5)."""

from __future__ import annotations

from collections import Counter

from app.parsers.base import ExtractedDocument, SegmentKind
from app.rules.base import DocumentRule, RuleContext, RuleFinding, RuleOutcome


class HeaderFooterRule(DocumentRule):
    """Checks that a document carries a header and a footer.

    DOCX states these explicitly, so the check is exact. PDF has no such
    concept - a header is simply text that appears in the same place on most
    pages - so it is inferred from repetition. That inference is only run on
    documents with enough pages to make repetition meaningful; below that
    threshold the rule reports that it could not tell rather than guessing.
    """

    name = "header_footer"
    label = "Header and footer"

    #: Below this, "appears on most pages" is not a meaningful statement.
    MIN_PAGES_FOR_INFERENCE = 3

    #: A line has to appear on at least this share of pages to be furniture
    #: rather than body text that happens to repeat.
    REPETITION_THRESHOLD = 0.6

    def applicable_to(self, context: RuleContext) -> tuple[bool, str | None]:
        document = context.document

        if self._has_explicit_furniture(document):
            return True, None

        if document.page_count is None:
            return False, (
                "This format records no header or footer, and has no pages to infer them from."
            )

        if document.page_count < self.MIN_PAGES_FOR_INFERENCE:
            return False, (
                f"The document has only {document.page_count} page(s); a header cannot be "
                "distinguished from body text without more pages to compare."
            )

        return True, None

    def evaluate(self, context: RuleContext) -> RuleOutcome:
        applicable, reason = self.applicable_to(context)

        if not applicable:
            return self.skipped(reason or "Not applicable.")

        document = context.document
        require_header = bool(context.option("require_header", True))
        require_footer = bool(context.option("require_footer", True))

        if self._has_explicit_furniture(document):
            return self._evaluate_declared(document, require_header, require_footer)

        return self._evaluate_inferred(document, require_header, require_footer)

    # ------------------------------------------------------------------ #

    def _evaluate_declared(
        self, document: ExtractedDocument, require_header: bool, require_footer: bool
    ) -> RuleOutcome:
        """DOCX states its header and footer, so absence is a real finding."""
        has_header = any(s.kind is SegmentKind.HEADER for s in document.segments)
        has_footer = any(s.kind is SegmentKind.FOOTER for s in document.segments)

        findings: list[RuleFinding] = []

        if require_header and not has_header:
            findings.append(
                RuleFinding(
                    issue_type="MISSING_HEADER",
                    severity="WARNING",
                    description="The document defines no header.",
                    metadata={"method": "declared"},
                )
            )

        if require_footer and not has_footer:
            findings.append(
                RuleFinding(
                    issue_type="MISSING_FOOTER",
                    severity="WARNING",
                    description="The document defines no footer.",
                    metadata={"method": "declared"},
                )
            )

        return self.result(findings)

    def _evaluate_inferred(
        self, document: ExtractedDocument, require_header: bool, require_footer: bool
    ) -> RuleOutcome:
        """PDF has no header concept, so this can confirm presence but not absence.

        Repetition across pages is good evidence that furniture *exists*. Its
        absence proves nothing, because PDF text comes out in content-stream
        order rather than visual order - a real footer routinely extracts
        somewhere in the middle of the page. Reporting MISSING_FOOTER on that
        basis produced a false finding on every real SOP tested.

        So: found means passed, not found means not checked.
        """
        found_header = bool(self._repeated_lines(document, top=True))
        found_footer = bool(self._repeated_lines(document, top=False))

        wanted = [
            ("header", require_header, found_header),
            ("footer", require_footer, found_footer),
        ]
        undetermined = [name for name, required, found in wanted if required and not found]

        if undetermined:
            return self.skipped(
                f"No repeating {' or '.join(undetermined)} could be identified in this "
                f"{document.parser.upper()}. Text is extracted in content order rather than "
                "page order, so this does not mean one is absent - it means it could not be "
                "confirmed from the text alone."
            )

        return self.result([])

    # ------------------------------------------------------------------ #

    @staticmethod
    def _has_explicit_furniture(document: ExtractedDocument) -> bool:
        """Whether the format declares headers and footers at all.

        True for DOCX even when both are empty - the absence is then a real
        finding rather than a limitation of the parser.
        """
        return document.parser == "docx"

    def _repeated_lines(self, document: ExtractedDocument, top: bool) -> list[str]:
        """Lines appearing in the same position on most pages."""
        by_page: dict[int, list[str]] = {}

        for segment in document.segments:
            if segment.page is not None and segment.text:
                by_page.setdefault(segment.page, []).append(segment.text)

        if len(by_page) < self.MIN_PAGES_FOR_INFERENCE:
            return []

        counter: Counter[str] = Counter()

        for lines in by_page.values():
            if not lines:
                continue

            # Only the outermost couple of lines are candidates; scanning the
            # whole page would pick up any repeated body phrase.
            candidates = lines[:2] if top else lines[-2:]

            for line in candidates:
                # Page numbers differ per page, so the raw line rarely
                # repeats; the stable part is what surrounds them.
                normalised = "".join(ch for ch in line if not ch.isdigit()).strip()

                if len(normalised) >= 8:
                    counter[normalised] += 1

        minimum = max(2, int(len(by_page) * self.REPETITION_THRESHOLD))

        return [line for line, count in counter.items() if count >= minimum]


class CoverPageRule(DocumentRule):
    """Checks the document opens with something that looks like a cover.

    A cover carries the document's identity - its code and its title - before
    the body starts. The check is deliberately shallow: it looks for a code
    and a plausible title near the beginning, not for a specific layout,
    because layout varies far more between templates than identity does.
    """

    name = "cover_page"
    label = "Cover page"

    def evaluate(self, context: RuleContext) -> RuleOutcome:
        opening = self._opening_segments(context)

        if not opening:
            return self.skipped("The document has no readable opening text.")

        text = "\n".join(segment.text for segment in opening)

        require_code = bool(context.option("require_code", True))
        min_title_length = int(context.option("min_title_length", 10) or 10)

        findings: list[RuleFinding] = []

        from app.rules.document_code import DEFAULT_CODE_PATTERN, DocumentCodePlacementRule

        pattern = str(context.option("code_pattern") or DEFAULT_CODE_PATTERN)
        code = DocumentCodePlacementRule._first_match(pattern, text)

        has_title = any(len(segment.text) >= min_title_length for segment in opening)

        if require_code and code is None:
            findings.append(
                RuleFinding(
                    issue_type="INVALID_COVER_PAGE",
                    severity="INFO",
                    description=(
                        "The opening of the document does not carry a document code. "
                        "A cover page should identify the document before its content begins."
                    ),
                    page=1,
                    metadata={"checked_segments": len(opening)},
                )
            )

        if not has_title:
            findings.append(
                RuleFinding(
                    issue_type="INVALID_COVER_PAGE",
                    severity="INFO",
                    description="The opening of the document does not carry a readable title.",
                    page=1,
                    metadata={"min_title_length": min_title_length},
                )
            )

        return self.result(findings)

    @staticmethod
    def _opening_segments(context: RuleContext) -> list:
        """The first page, or the first handful of segments for DOCX."""
        first_page = [s for s in context.document.segments if s.page == 1]

        if first_page:
            return first_page

        return [s for s in context.document.segments if s.page is None][:15]
