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
