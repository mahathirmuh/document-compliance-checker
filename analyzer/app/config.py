"""Runtime configuration.

Everything here is infrastructure: where the service may read from, how big a
file it will open, whether a key is required. None of it is a compliance
threshold - those live in the Laravel `settings` table, because they are a
Document Control decision rather than a deployment one.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Annotated

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, NoDecode, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="ANALYZER_",
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # --- Service -----------------------------------------------------------
    host: str = "127.0.0.1"
    port: int = 8001
    debug: bool = False

    api_key: str | None = Field(
        default=None,
        description=(
            "Shared secret the caller must present as a bearer token. When "
            "unset the service accepts unauthenticated requests, which is "
            "only acceptable on a loopback bind."
        ),
    )

    # --- Filesystem access -------------------------------------------------
    # NoDecode stops pydantic-settings JSON-parsing the env value before the
    # validator below sees it. Without it, ANALYZER_ALLOWED_ROOTS would have
    # to be a JSON array, and a plain Windows path like D:\DocumentControl -
    # the obvious thing to write - would fail at startup.
    allowed_roots: Annotated[list[Path], NoDecode] = Field(
        default_factory=list,
        description=(
            "Directories the analyzer may read documents from, separated by "
            "';' or ','. A request for a path outside every root is refused. "
            "Leaving this empty allows any readable path, which is only "
            "sensible in development - in production it is the difference "
            "between 'analyze this SOP' and 'read me any file the service "
            "account can see'."
        ),
    )

    max_file_bytes: int = Field(
        default=512 * 1024 * 1024,
        description="Refuse files larger than this rather than exhausting memory.",
    )

    # --- Extraction limits -------------------------------------------------
    max_pdf_pages: int = Field(
        default=2000,
        description="Stop extracting after this many PDF pages.",
    )

    max_xlsx_cells: int = Field(
        default=500_000,
        description="Stop extracting after this many spreadsheet cells.",
    )

    min_extractable_chars: int = Field(
        default=20,
        description=(
            "Below this much meaningful text the document is treated as "
            "un-extractable and reported as OCR_REQUIRED rather than as a "
            "translation failure (CLAUDE.md 16)."
        ),
    )

    # --- Language identification -------------------------------------------
    min_latin_segment_chars: int = Field(
        default=12,
        description=(
            "Latin segments shorter than this are pooled and classified "
            "together instead of individually. A four-word table heading "
            "carries too little signal to identify on its own, and guessing "
            "on it would pollute the confidence figures."
        ),
    )

    @field_validator("allowed_roots", mode="before")
    @classmethod
    def _split_roots(cls, value: object) -> object:
        """Accept a path-separator-delimited string from the environment."""
        if isinstance(value, str):
            separator = ";" if ";" in value else ","
            return [part.strip() for part in value.split(separator) if part.strip()]
        return value

    @field_validator("allowed_roots", mode="after")
    @classmethod
    def _resolve_roots(cls, value: list[Path]) -> list[Path]:
        """Resolve once at startup so every later check is a cheap comparison."""
        resolved: list[Path] = []
        for root in value:
            try:
                resolved.append(root.resolve(strict=False))
            except OSError:  # pragma: no cover - unreachable network path
                continue
        return resolved


@lru_cache
def get_settings() -> Settings:
    """Cached settings instance.

    Cached so the roots are resolved once. Tests clear the cache when they
    need to vary configuration.
    """
    return Settings()
