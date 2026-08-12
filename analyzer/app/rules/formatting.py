"""Font colour (CLAUDE.md 7, 27 Phase 5).

The single most important thing this rule does is refuse to answer when it
cannot see. Font colour is readable from DOCX and from nothing else: pypdf
exposes no usable colour information, and a scanned page has no text objects
at all. Reporting those as compliant would hand a clean bill of health to
exactly the documents least likely to deserve one, so they are reported as
not checked instead.
"""

from __future__ import annotations

from app.rules.base import DocumentRule, RuleContext, RuleFinding, RuleOutcome


class FontColorRule(DocumentRule):
    """Checks body text uses only the colours a template permits."""

    name = "font_color"
    label = "Font colour"

    def applicable_to(self, context: RuleContext) -> tuple[bool, str | None]:
        if not context.document.reports_font_colors:
            return False, (
                f"Font colour cannot be read from a {context.document.parser.upper()} file. "
                "This check only applies to Word documents."
            )

        has_any = any(segment.font_colors for segment in context.document.segments)

        if not has_any:
            return False, (
                "No explicit font colours are set in this document - its text inherits "
                "colour from the template or theme, which cannot be resolved here."
            )

        return True, None

    def evaluate(self, context: RuleContext) -> RuleOutcome:
        applicable, reason = self.applicable_to(context)

        if not applicable:
            return self.skipped(reason or "Not applicable.")

        allowed = {
            str(color).upper().lstrip("#")
            for color in (context.option("allowed") or ["000000"])
        }

        offenders: dict[str, list[str]] = {}

        for segment in context.document.segments:
            for color in segment.font_colors:
                if color not in allowed:
                    # One example per colour is enough to find it in the
                    # document; listing every occurrence would produce
                    # hundreds of identical findings.
                    offenders.setdefault(color, []).append(segment.text[:60])

        findings = [
            RuleFinding(
                issue_type="WRONG_FONT_COLOR",
                severity="INFO",
                description=(
                    f"Text uses colour #{color}, which is not in the permitted set "
                    f"({', '.join('#' + value for value in sorted(allowed))}). "
                    f"First seen in: \"{examples[0]}\""
                ),
                metadata={
                    "color": color,
                    "allowed": sorted(allowed),
                    "occurrences": len(examples),
                },
            )
            for color, examples in sorted(offenders.items())
        ]

        return self.result(findings)
