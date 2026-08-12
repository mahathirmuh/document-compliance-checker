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

    min_language_confidence: float = Field(
        default=0.80,
        description=(
            "Latin text whose top language confidence is below this is "
            "attributed to no language. Essential rather than defensive: "
            "lingua is restricted to two languages, so its confidences sum "
            "to 1.0 and the winner is always at least 0.5 - there is no "
            "value meaning 'I don't know'. Without a floor, every fragment "
            "of OCR noise is forced into English or Indonesian."
        ),
    )

    boilerplate_min_pages: int = Field(
        default=3,
        description=(
            "Text repeated on at least this many pages is treated as page "
            "furniture - a header, footer or watermark - and excluded from "
            "language measurement. A company name in a running header is not "
            "a translation, but repeated across 13 pages it can single "
            "handedly push a document over a coverage threshold."
        ),
    )

    boilerplate_max_chars: int = Field(
        default=120,
        description=(
            "Only lines this short can be furniture. Headers and footers are "
            "brief by nature; a full paragraph appearing on several pages is "
            "repeated content, and discarding it would delete real "
            "translations from the measurement."
        ),
    )

    # --- Section analysis ---------------------------------------------------
    min_section_chars: int = Field(
        default=120,
        description=(
            "Sections shorter than this are measured but never reported as "
            "missing a language. A heading or a two-line note cannot "
            "reasonably carry all three, and flagging them would bury the "
            "sections that genuinely need attention."
        ),
    )

    short_translation_ratio: float = Field(
        default=0.35,
        description=(
            "A language present in a section but shorter than this fraction "
            "of the section's longest language is reported as a suspiciously "
            "short translation. Compared on density-normalised lengths, not "
            "raw character counts."
        ),
    )

    # --- OCR ----------------------------------------------------------------
    ocr_enabled: bool = Field(
        default=False,
        description=(
            "Recognise text in scanned PDFs. Off by default: it needs the "
            "Tesseract binary installed, and it is far slower than text "
            "extraction. When off - or when Tesseract is missing - a scanned "
            "document is still reported as OCR_REQUIRED rather than as a "
            "translation failure."
        ),
    )

    tesseract_path: str | None = Field(
        default=None,
        description="Full path to the Tesseract binary. Falls back to PATH.",
    )

    tessdata_dir: str | None = Field(
        default=None,
        description=(
            "Directory holding the .traineddata language files. Needed when "
            "they do not live beside the binary - which is the normal case "
            "when Tesseract is installed machine-wide but the extra languages "
            "were added without administrator rights. Falls back to "
            "Tesseract's own lookup."
        ),
    )

    # NoDecode for the same reason as allowed_roots: without it the env value
    # would have to be a JSON array rather than "eng,ind,chi_sim".
    ocr_languages: Annotated[list[str], NoDecode] = Field(
        default_factory=lambda: ["eng", "ind", "chi_sim"],
        description=(
            "Tesseract language codes to recognise with, in one pass. All "
            "three are needed together: a trilingual page contains all of "
            "them, and recognising with only one would drop the others."
        ),
    )

    ocr_render_scale: float = Field(
        default=2.8,
        description=(
            "Page render scale relative to 72 dpi, so 2.8 is roughly 200 dpi "
            "- what Tesseract wants, without the memory cost of 300."
        ),
    )

    ocr_max_pages: int = Field(
        default=50,
        description=(
            "Stop after this many pages. OCR takes seconds per page, and a "
            "300-page scanned manual would hold a queue worker for an hour."
        ),
    )

    ocr_min_confidence: float = Field(
        default=0.60,
        description=(
            "Mean word confidence below which the recognition is reported as "
            "doubtful. OCR output is advisory regardless - a document read "
            "this way always goes to human review (CLAUDE.md 16, 33)."
        ),
    )

    chinese_density_factor: float = Field(
        default=3.0,
        description=(
            "How many Latin characters one Han character is worth when "
            "comparing translation lengths. Chinese renders the same content "
            "in roughly a third of the characters, so comparing raw counts "
            "would report every correctly translated Chinese section as "
            "suspiciously short. Only affects the short-translation "
            "comparison - never the coverage thresholds, which are applied "
            "in Laravel against real character counts."
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

    @field_validator("ocr_languages", mode="before")
    @classmethod
    def _split_languages(cls, value: object) -> object:
        """Accept "eng,ind,chi_sim" from the environment."""
        if isinstance(value, str):
            return [part.strip() for part in value.split(",") if part.strip()]
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
