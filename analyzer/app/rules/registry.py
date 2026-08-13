"""Rule lookup and execution.

The one place that knows which rules exist. Adding a Document Control rule
means writing the class and adding it here - extraction, detection, scoring
and the API are all untouched (CLAUDE.md 7).

Rules are **off unless the caller enables them**. A Document Controller turns
a rule on in Laravel and it arrives with the request; the analyzer never
decides on its own that a document should be judged against a template.
"""

from __future__ import annotations

from functools import lru_cache

from app.rules.base import DocumentRule, RuleContext, RuleOutcome
from app.rules.document_code import DocumentCodePlacementRule
from app.rules.formatting import FontColorRule
from app.rules.language_order import LanguageOrderRule
from app.rules.numeric_consistency import NumericConsistencyRule
from app.rules.structure import CoverPageRule, HeaderFooterRule

_RULE_CLASSES: tuple[type[DocumentRule], ...] = (
    LanguageOrderRule,
    DocumentCodePlacementRule,
    HeaderFooterRule,
    CoverPageRule,
    FontColorRule,
    NumericConsistencyRule,
)


class RuleRegistry:
    def __init__(self, rules: list[DocumentRule] | None = None) -> None:
        self._rule_classes = list(_RULE_CLASSES) if rules is None else None
        self._instances = rules

    def available(self) -> list[str]:
        return [rule_class.name for rule_class in _RULE_CLASSES]

    def run(
        self,
        config: dict[str, dict[str, object]] | None,
        document,
        sections,
        detector,
    ) -> tuple[list[RuleOutcome], dict[str, str | None]]:
        """Run every enabled rule.

        :return: the outcomes, and any metadata the rules extracted along the
            way - currently the document code and revision, which are worth
            having whether or not the rule that found them was checking.
        """
        outcomes: list[RuleOutcome] = []
        extracted: dict[str, str | None] = {}

        if not config:
            return outcomes, extracted

        for rule in self._rules():
            rule_config = config.get(rule.name)

            if not isinstance(rule_config, dict) or not rule_config.get("enabled"):
                continue

            context = RuleContext(
                document=document,
                sections=sections,
                detector=detector,
                config=rule_config,
            )

            applicable, reason = rule.applicable_to(context)

            outcomes.append(
                rule.evaluate(context)
                if applicable
                else rule.skipped(reason or "Not applicable.")
            )

            # Rules may surface metadata as a side effect of checking. Only
            # non-empty values are kept so a later rule cannot blank an
            # earlier one's find.
            for key, value in getattr(rule, "extracted", {}).items():
                if value:
                    extracted[key] = value

        return outcomes, extracted

    def _rules(self) -> list[DocumentRule]:
        # Fresh instances per run: DocumentCodePlacementRule accumulates its
        # extraction on the instance, and reusing one across requests would
        # leak one document's code into the next.
        return self._instances if self._instances is not None else [
            rule_class() for rule_class in self._rule_classes or []
        ]


@lru_cache
def get_rule_registry() -> RuleRegistry:
    return RuleRegistry()
