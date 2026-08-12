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
