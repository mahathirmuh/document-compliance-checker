"""Response bodies for the v1 API.

The shape is fixed by CLAUDE.md 14 and is consumed by
App\\Services\\Analyzer\\DTO\\AnalysisResult on the Laravel side. Changing a
field name here is a breaking API change and needs a new version prefix, not
an edit.
"""

from __future__ import annotations

from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field


class LanguageCode(StrEnum):
    EN = "en"
    ID = "id"
    ZH = "zh"


class IssueType(StrEnum):
    """Issue types the analyzer is allowed to raise.

    Deliberately short. These are findings extraction can make on its own:
    the file was unreadable, there was no text layer, or a *section* is
    internally inconsistent. Document-level verdicts - which language is
    missing, whether coverage is sufficient - are all derived by Laravel from
    the measurements below, because they depend on thresholds this service
    does not know.
    """

    PARSER_ERROR = "PARSER_ERROR"
    OCR_REQUIRED = "OCR_REQUIRED"

    #: A section contains some languages but not all three.
    MISSING_SECTION_TRANSLATION = "MISSING_SECTION_TRANSLATION"

    #: A section's translation is present but far shorter than its siblings.
    SHORT_TRANSLATION = "SHORT_TRANSLATION"

    #: A measurement appears in one language of a section but not in its
    #: translations - a dose, a temperature, a pH limit that does not agree.
    NUMERIC_MISMATCH = "NUMERIC_MISMATCH"

    # --- Raised by Document Control rules, only when enabled. ---

    WRONG_LANGUAGE_ORDER = "WRONG_LANGUAGE_ORDER"
    MISSING_DOCUMENT_CODE = "MISSING_DOCUMENT_CODE"
    MISSING_REVISION = "MISSING_REVISION"
    MISSING_HEADER = "MISSING_HEADER"
    MISSING_FOOTER = "MISSING_FOOTER"
    INVALID_COVER_PAGE = "INVALID_COVER_PAGE"
    WRONG_FONT_COLOR = "WRONG_FONT_COLOR"


class IssueSeverity(StrEnum):
    INFO = "INFO"
    WARNING = "WARNING"
    ERROR = "ERROR"
    CRITICAL = "CRITICAL"


class LanguageReport(BaseModel):
    """What was measured for one language."""

    model_config = ConfigDict(extra="forbid")

    detected: bool = Field(description="Whether any text of this language was found at all.")
    character_count: int = Field(
        ge=0,
        description="Meaningful characters attributed to this language.",
    )
    word_count: int | None = Field(
        default=None,
        ge=0,
        description=(
            "Whitespace-delimited tokens. Always null for Chinese, which has "
            "no whitespace word boundaries (CLAUDE.md 8.6)."
        ),
    )
    coverage: float = Field(
        ge=0,
        le=100,
        description="Share of the document's meaningful text, 0-100.",
    )
    confidence: float = Field(ge=0, le=1, description="Length-weighted detector confidence, 0-1.")


class Issue(BaseModel):
    model_config = ConfigDict(extra="forbid")

    type: IssueType
    severity: IssueSeverity
    description: str
    language: LanguageCode | None = None
    page: int | None = None
    section: str | None = None
    metadata: dict[str, object] | None = None


class SectionReport(BaseModel):
    """Language make-up of one section of the document.

    The section is the smallest unit expected to hold all three languages, and
    therefore the smallest unit where "a translation is missing" is a real
    finding rather than an artefact of how the document is laid out.
    """

    model_config = ConfigDict(extra="forbid")

    name: str
    sequence: int = Field(ge=1, description="Position in the document, 1-based.")
    page: int | None = None
    total_characters: int = Field(ge=0)
    segment_count: int = Field(ge=0)

    #: Meaningful characters per language within this section.
    characters: dict[LanguageCode, int]

    #: Languages entirely absent from this section.
    missing: list[LanguageCode] = Field(default_factory=list)

    #: Languages present but disproportionately short against the longest
    #: language in the same section.
    short: list[LanguageCode] = Field(default_factory=list)

    #: False for sections too small to reasonably carry all three languages,
    #: which are measured but never reported against.
    evaluated: bool = True


class RuleOutcomeReport(BaseModel):
    """Whether one Document Control rule ran, and what it concluded."""

    model_config = ConfigDict(extra="forbid")

    rule: str

    #: False when the rule could not run against this format. Distinct from
    #: passing: font colour cannot be read from a scanned PDF, and calling
    #: that a pass would be a clean bill of health on exactly the documents
    #: least likely to deserve one.
    applicable: bool

    passed: bool
    finding_count: int = Field(ge=0)

    #: Set only when applicable is False, and written for an operator.
    skipped_reason: str | None = None


class DocumentMetadata(BaseModel):
    """Identity read out of the document itself.

    Extracted as a side effect of the document code rule. Worth returning
    whether or not that rule was enforcing anything: until now these fields
    were only ever filled in by hand on manual upload.
    """

    model_config = ConfigDict(extra="forbid")

    document_code: str | None = None
    revision: str | None = None


class AnalyzeResponse(BaseModel):
    """The analyzer's report on one document."""

    model_config = ConfigDict(extra="forbid")

    status: str = Field(
        description=(
            "Advisory only. The analyzer reports what it measured; Laravel "
            "applies the configurable thresholds and owns the real verdict. "
            "Present because the CLAUDE.md 14 contract specifies it."
        ),
    )
    overall_score: float | None = Field(default=None, ge=0, le=100)
    languages: dict[LanguageCode, LanguageReport]
    issues: list[Issue] = Field(default_factory=list)

    #: Per-section breakdown. Added in analyzer 1.1; an additive field, so
    #: /api/v1 remains the same contract for a client that ignores it.
    sections: list[SectionReport] = Field(default_factory=list)

    #: Document Control rule outcomes. Added in analyzer 1.2, also additive.
    #: Empty when the request enabled no rules.
    rules: list[RuleOutcomeReport] = Field(default_factory=list)

    #: Identity read out of the document. Added in analyzer 1.2.
    metadata: DocumentMetadata = Field(default_factory=DocumentMetadata)

    analyzer_version: str
    document_id: int | None = None
    version_id: int | None = None

    # --- Extraction telemetry ----------------------------------------------
    total_characters: int = Field(ge=0, description="Total meaningful characters found.")
    page_count: int | None = None
    segment_count: int = Field(ge=0, description="Paragraphs, headings and table cells extracted.")
    parser: str = Field(description="Which parser handled the file.")
    duration_ms: int = Field(ge=0)


class LanguageBlockReport(BaseModel):
    """One section's text in one language."""

    model_config = ConfigDict(extra="forbid")

    characters: int = Field(ge=0, description="Meaningful characters, counted as coverage is.")
    segments: list[str] = Field(
        default_factory=list,
        description="Paragraphs and cells in document order, each kept whole.",
    )


class AlignedSectionReport(BaseModel):
    """One section, split by the language each piece of it is written in."""

    model_config = ConfigDict(extra="forbid")

    name: str
    sequence: int = Field(ge=1)
    page: int | None = None

    blocks: dict[LanguageCode, LanguageBlockReport]

    #: Text the detector would not attribute to any language - numeric tables,
    #: equipment codes, OCR noise. Returned rather than dropped: a reviewer
    #: reading three columns assumes they are seeing the whole section.
    unassigned: list[str] = Field(default_factory=list)

    missing: list[LanguageCode] = Field(default_factory=list)


class ExtractResponse(BaseModel):
    """A document's text, paired up by section and language.

    Carries no verdict and no measurement. This endpoint exists so a person
    can read the three languages next to each other and judge the translation
    themselves - the semantic check the service deliberately does not make
    (CLAUDE.md 33).
    """

    model_config = ConfigDict(extra="forbid")

    sections: list[AlignedSectionReport] = Field(default_factory=list)

    #: True when the character budget ran out. Says so rather than returning
    #: part of a document as though it were all of it.
    truncated: bool = False

    analyzer_version: str
    document_id: int | None = None
    version_id: int | None = None

    page_count: int | None = None
    parser: str
    duration_ms: int = Field(ge=0)


class HealthResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: str
    version: str
    supported_extensions: list[str]
