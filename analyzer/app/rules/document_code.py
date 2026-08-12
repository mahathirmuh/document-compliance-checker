"""Document code and revision (CLAUDE.md 7, 27 Phase 5).

This rule does more than check: it *extracts*. A document code and revision
sitting in a header are exactly the metadata the Laravel document list wants,
and until now those fields were only ever populated by hand on manual upload.
Reading them here fills them in for every scanned document too.
"""

from __future__ import annotations

import re

from app.parsers.base import SegmentKind
from app.rules.base import DocumentRule, RuleContext, RuleFinding, RuleOutcome

#: Matches the house style seen on real SOPs - MTI-ENV-EVM-SOP-002 - and the
#: general shape of two-to-five uppercase blocks joined by hyphens, ending in
#: a number. Overridable per deployment, because every organisation numbers
#: its documents differently.
#:
#: The boundaries are explicit character-class lookarounds rather than \b.
#: A real header reads "MTI-ENV-EVM-SOP-002_Rev. 006", and because "_" is a
#: word character, a trailing \b does not match after "002" - so \b would
#: silently fail on exactly the documents this is written for.
DEFAULT_CODE_PATTERN = (
    r"(?<![A-Za-z0-9-])[A-Z]{2,6}(?:-[A-Z0-9]{2,8}){1,4}-?\d{1,4}(?![A-Za-z0-9-])"
)

#: "Rev. 006", "Rev 6", "Revision 03", "Rev.000".
#:
#: Same explicit lookbehind as the code pattern, and for the same reason: a
#: real header reads "MTI-ENV-EVM-SOP-002_Rev. 006", and \b does not match
#: between "_" and "R" because both are word characters.
DEFAULT_REVISION_PATTERN = r"(?<![A-Za-z0-9])Rev(?:ision)?\.?\s*([0-9]{1,4})\b"


class DocumentCodePlacementRule(DocumentRule):
    """Finds and validates the document code and revision number."""

    name = "document_code"
    label = "Document code and revision"

    def __init__(self) -> None:
        #: Filled by evaluate() and read by the analysis service. An instance
        #: attribute, not a class one - a shared mutable default would leak
        #: one document's code into the next request.
        self.extracted: dict[str, str | None] = {"document_code": None, "revision": None}

    def evaluate(self, context: RuleContext) -> RuleOutcome:
        code_pattern = str(context.option("code_pattern") or DEFAULT_CODE_PATTERN)
        revision_pattern = str(context.option("revision_pattern") or DEFAULT_REVISION_PATTERN)
        require_code = bool(context.option("require_code", True))
        require_revision = bool(context.option("require_revision", True))

        haystack = self._search_text(context)

        # Case-sensitive for the code: a document code is uppercase by
        # convention, and matching case-insensitively turns the pattern into
        # something that happily matches ordinary hyphenated prose.
        code = self._first_match(code_pattern, haystack, flags=re.MULTILINE)

        # Case-insensitive for the revision, because "Rev.", "rev" and
        # "Revision" all appear in practice.
        revision = self._first_match(
            revision_pattern, haystack, group=1, flags=re.IGNORECASE | re.MULTILINE
        )

        findings: list[RuleFinding] = []

        if require_code and code is None:
            findings.append(
                RuleFinding(
                    issue_type="MISSING_DOCUMENT_CODE",
                    severity="WARNING",
                    description=(
                        "No document code was found in the header, footer or first page. "
                        "A controlled document should carry its code where it can be read "
                        "without opening the body."
                    ),
                    metadata={"pattern": code_pattern},
                )
            )

        if require_revision and revision is None:
            findings.append(
                RuleFinding(
                    issue_type="MISSING_REVISION",
                    severity="WARNING",
                    description=(
                        "No revision number was found in the header, footer or first page."
                    ),
                    metadata={"pattern": revision_pattern},
                )
            )

        # Recorded even when nothing was required, because the value is
        # useful to the caller regardless of whether it was mandatory.
        self.extracted = {"document_code": code, "revision": revision}

        return self.result(findings)

    # ------------------------------------------------------------------ #

    @staticmethod
    def _search_text(context: RuleContext) -> str:
        """Where a document code is legitimately allowed to live.

        Headers, footers and the first page - not the whole body. Scanning
        everything would happily match a code quoted in a cross-reference on
        page 40 and report the document as correctly labelled when its header
        is blank.
        """
        parts: list[str] = []

        for segment in context.document.segments:
            in_furniture = segment.kind in (SegmentKind.HEADER, SegmentKind.FOOTER)
            on_first_page = segment.page == 1

            # A DOCX has no pages, so its opening segments stand in for the
            # cover.
            no_pages = segment.page is None

            if in_furniture or on_first_page or no_pages:
                parts.append(segment.text)

        if not parts:
            parts = [segment.text for segment in context.document.segments[:40]]

        return "\n".join(parts)

    @staticmethod
    def _first_match(pattern: str, haystack: str, group: int = 0, flags: int = 0) -> str | None:
        try:
            match = re.search(pattern, haystack, flags)
        except re.error:
            # A bad pattern is a configuration mistake, not a document
            # problem. Treated as "cannot tell" so it never fails a document.
            return None

        if match is None:
            return None

        try:
            return (match.group(group) or "").strip() or None
        except IndexError:
            return None
