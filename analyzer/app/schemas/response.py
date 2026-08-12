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

    A deliberately short list: these are the things extraction can discover.
    Everything else - missing languages, low coverage - is derived by Laravel
    from the measurements below, because those depend on thresholds this
    service does not know.
    """

    PARSER_ERROR = "PARSER_ERROR"
    OCR_REQUIRED = "OCR_REQUIRED"


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

    analyzer_version: str
    document_id: int | None = None
    version_id: int | None = None

    # --- Extraction telemetry ----------------------------------------------
    total_characters: int = Field(ge=0, description="Total meaningful characters found.")
    page_count: int | None = None
    segment_count: int = Field(ge=0, description="Paragraphs, headings and table cells extracted.")
    parser: str = Field(description="Which parser handled the file.")
    duration_ms: int = Field(ge=0)


class HealthResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: str
    version: str
    supported_extensions: list[str]
