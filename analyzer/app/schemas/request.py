"""Request bodies for the v1 API."""

from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field


class AnalyzeRequest(BaseModel):
    """One document to analyse.

    Matches the contract in CLAUDE.md 14. `document_id` and `version_id` are
    carried through untouched so a caller can correlate an async log line back
    to its own records; the analyzer never looks them up.
    """

    model_config = ConfigDict(extra="forbid")

    file_path: str = Field(
        ...,
        min_length=1,
        description="Absolute path to a file readable by the analyzer process.",
    )
    document_id: int | None = Field(default=None, ge=1)
    version_id: int | None = Field(default=None, ge=1)

    rules: dict[str, dict[str, object]] | None = Field(
        default=None,
        description=(
            "Document Control rules to apply, keyed by rule name, each with "
            "at least {\"enabled\": true}. Sent by the caller rather than "
            "configured here because which rules apply - and the document "
            "code pattern, the permitted colours, the expected language "
            "order - are Document Control policy that an administrator edits "
            "at runtime, not deployment settings. Omitted or empty means no "
            "rules run, which is the Phase 1-4 behaviour unchanged."
        ),
        examples=[
            {
                "language_order": {"enabled": True, "order": ["en", "id", "zh"]},
                "document_code": {"enabled": True, "require_revision": True},
                "header_footer": {"enabled": True},
                "font_color": {"enabled": True, "allowed": ["000000", "1F497D"]},
            }
        ],
    )


class ExtractRequest(BaseModel):
    """One document to read back for side-by-side review.

    Separate from AnalyzeRequest because it is a different operation with a
    different cost and a different audience: analysis is a queue job that
    produces a verdict, this is a human opening a page and wanting to see the
    three languages next to each other.
    """

    model_config = ConfigDict(extra="forbid")

    file_path: str = Field(
        ...,
        min_length=1,
        description="Absolute path to a file readable by the analyzer process.",
    )
    document_id: int | None = Field(default=None, ge=1)
    version_id: int | None = Field(default=None, ge=1)

    max_characters: int | None = Field(
        default=400_000,
        ge=1_000,
        description=(
            "Stop collecting once this much text has been gathered. A "
            "500-page manual would otherwise return a response no browser "
            "should be asked to render. The response says when it was cut "
            "short rather than silently returning part of the document."
        ),
    )
