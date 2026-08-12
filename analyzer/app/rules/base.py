"""The contract every Document Control rule implements.

CLAUDE.md 7 asks that adding a rule be easy. Concretely that means: write one
class implementing DocumentRule, add it to the registry, and nothing in
extraction, detection, scoring or the API changes.

Three properties every rule must honour:

**A rule that cannot run says so.** `applicable()` separates "checked and
passed" from "could not check" - font colour cannot be read from a scanned
PDF, and reporting that as a pass would be a false clean bill of health on
exactly the documents least likely to be compliant.

**Rules never decide the document's status.** They emit findings. Whether a
finding makes a document non-compliant is a Document Control policy applied
in Laravel, the same split that keeps thresholds out of this service.

**Rules are configured by the caller.** Enablement and patterns arrive with
the request, because they are policy a Document Controller edits at runtime,
not deployment settings.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import TYPE_CHECKING

from app.parsers.base import ExtractedDocument

if TYPE_CHECKING:
    from app.detectors.language_detector import LanguageDetector
    from app.services.section_analyzer import SectionReport


@dataclass(slots=True)
class RuleFinding:
    """One thing a rule noticed."""

    #: An App\Enums\IssueType value. Laravel drops anything it does not know,
    #: so a new finding type has to be added on both sides deliberately.
    issue_type: str

    description: str
    severity: str = "WARNING"
    language: str | None = None
    page: int | None = None
    section: str | None = None
    metadata: dict[str, object] = field(default_factory=dict)


@dataclass(slots=True)
class RuleOutcome:
    """The result of running one rule over one document."""

    rule: str
    applicable: bool
    findings: list[RuleFinding] = field(default_factory=list)

    #: Why the rule could not run. Set only when applicable is False, and
    #: written for an operator: "font colour cannot be read from a PDF" tells
    #: them the check was skipped and why, which a silent pass would not.
    skipped_reason: str | None = None

    @property
    def passed(self) -> bool:
        """True only when the rule ran and found nothing.

        A rule that could not run is neither passed nor failed, and callers
        must consult `applicable` before reading this.
        """
        return self.applicable and not self.findings


@dataclass(slots=True)
class RuleContext:
    """Everything a rule is allowed to look at."""

    document: ExtractedDocument
    sections: list[SectionReport]
    detector: LanguageDetector

    #: This rule's slice of the request configuration.
    config: dict[str, object] = field(default_factory=dict)

    def option(self, key: str, default: object = None) -> object:
        return self.config.get(key, default)


class DocumentRule(ABC):
    """Base class for Document Control rules."""

    #: Stable identifier, used as the configuration key and reported back.
    name: str = "unnamed"

    #: Human-readable, for the settings screen.
    label: str = "Unnamed rule"

    @abstractmethod
    def evaluate(self, context: RuleContext) -> RuleOutcome:
        """Run the rule and report what it found."""

    def applicable_to(self, context: RuleContext) -> tuple[bool, str | None]:
        """Whether this rule can run against this document.

        Defaults to yes. Override where a rule depends on something a given
        format cannot provide.
        """
        return True, None

    def skipped(self, reason: str) -> RuleOutcome:
        return RuleOutcome(rule=self.name, applicable=False, skipped_reason=reason)

    def result(self, findings: list[RuleFinding]) -> RuleOutcome:
        return RuleOutcome(rule=self.name, applicable=True, findings=findings)
