"""Pairing text with the language it is written in (CLAUDE.md 7, 21).

The property that matters most here is not accuracy but completeness. A
reviewer reading three columns will assume they are looking at the whole
section, so anything the aligner silently drops is a translation gap it has
just hidden. Several of these tests exist only to prove nothing disappears.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.parsers.base import SegmentKind, TextSegment
from app.services.alignment import DocumentAligner
from app.services.analysis_service import get_analysis_service
from tests.conftest import CHINESE_TEXT, ENGLISH_TEXT, INDONESIAN_TEXT


def heading(text: str) -> TextSegment:
    return TextSegment(text=text, kind=SegmentKind.HEADING, section=text)


def paragraph(text: str, section: str | None = None) -> TextSegment:
    return TextSegment(text=text, kind=SegmentKind.PARAGRAPH, section=section)


@pytest.fixture
def aligner() -> DocumentAligner:
    return DocumentAligner()


class TestAlignment:
    def test_each_language_lands_in_its_own_block(self, aligner: DocumentAligner) -> None:
        result = aligner.align([
            paragraph(ENGLISH_TEXT),
            paragraph(INDONESIAN_TEXT),
            paragraph(CHINESE_TEXT),
        ])

        assert len(result.sections) == 1
        blocks = result.sections[0].blocks

        assert blocks["en"].segments == [ENGLISH_TEXT]
        assert blocks["id"].segments == [INDONESIAN_TEXT]
        assert blocks["zh"].segments == [CHINESE_TEXT]

    def test_it_splits_on_the_same_sections_the_coverage_table_uses(
        self, aligner: DocumentAligner
    ) -> None:
        # Two implementations of "where does a section start" would drift, and
        # the compare view would disagree with the coverage table about which
        # section a reviewer is looking at.
        result = aligner.align([
            heading("1. Scope"),
            paragraph(ENGLISH_TEXT, "1. Scope"),
            heading("2. Responsibility"),
            paragraph(INDONESIAN_TEXT, "2. Responsibility"),
        ])

        assert [section.name for section in result.sections] == [
            "1. Scope",
            "2. Responsibility",
        ]
        assert [section.sequence for section in result.sections] == [1, 2]

    def test_a_missing_translation_is_reported_per_section(
        self, aligner: DocumentAligner
    ) -> None:
        result = aligner.align([
            heading("1. Scope"),
            paragraph(ENGLISH_TEXT, "1. Scope"),
            paragraph(CHINESE_TEXT, "1. Scope"),
        ])

        section = result.sections[0]

        assert section.present_languages == ["en", "zh"]
        assert section.missing_languages == ["id"]

    def test_nothing_is_dropped_when_the_language_cannot_be_determined(
        self, aligner: DocumentAligner
    ) -> None:
        # The whole point. A line of equipment codes belongs to no language,
        # and discarding it is how a reviewer stops seeing part of a section.
        result = aligner.align([
            paragraph(ENGLISH_TEXT),
            paragraph("P-101 / P-102 / TK-204"),
        ])

        section = result.sections[0]
        everything = (
            section.blocks["en"].segments
            + section.blocks["id"].segments
            + section.blocks["zh"].segments
            + section.unassigned
        )

        assert "P-101 / P-102 / TK-204" in everything

    def test_every_segment_appears_exactly_once(self, aligner: DocumentAligner) -> None:
        segments = [
            paragraph(ENGLISH_TEXT),
            paragraph(INDONESIAN_TEXT),
            paragraph(CHINESE_TEXT),
            paragraph("12.5 ppm"),
            paragraph("Rev. 006"),
        ]

        result = aligner.align(segments)
        section = result.sections[0]

        collected = (
            section.blocks["en"].segments
            + section.blocks["id"].segments
            + section.blocks["zh"].segments
            + section.unassigned
        )

        assert sorted(collected) == sorted(segment.text for segment in segments)

    def test_a_mixed_segment_is_kept_whole(self, aligner: DocumentAligner) -> None:
        # Splitting "Demineralized water 脱盐水" across two columns would read
        # better and would misrepresent the document: a reviewer checking
        # layout needs to see that both sit in one cell.
        mixed = f"{ENGLISH_TEXT} {CHINESE_TEXT}"

        result = aligner.align([paragraph(mixed)])
        section = result.sections[0]

        found = [
            text
            for code in ("en", "id", "zh")
            for text in section.blocks[code].segments
        ] + section.unassigned

        assert found == [mixed]

    def test_characters_are_counted_the_way_coverage_counts_them(
        self, aligner: DocumentAligner
    ) -> None:
        # Alphabetic and Han only. A block that looks substantial here must
        # not correspond to a zero in the coverage table.
        result = aligner.align([paragraph("1234567890 --- ,,, 12.5")])
        section = result.sections[0]

        assert all(section.blocks[code].characters == 0 for code in ("en", "id", "zh"))

    def test_an_empty_document_produces_no_sections(self, aligner: DocumentAligner) -> None:
        assert aligner.align([]).sections == []

    def test_it_stops_and_says_so_when_the_budget_runs_out(
        self, aligner: DocumentAligner
    ) -> None:
        # Returning part of a document as though it were all of it would let a
        # reviewer sign off on a section they never saw.
        segments = [paragraph(ENGLISH_TEXT) for _ in range(50)]

        result = aligner.align(segments, max_characters=200)

        assert result.truncated is True

    def test_an_untruncated_document_says_so(self, aligner: DocumentAligner) -> None:
        result = aligner.align([paragraph(ENGLISH_TEXT)], max_characters=1_000_000)

        assert result.truncated is False


class TestExtractEndpoint:
    @pytest.fixture
    def client(self, monkeypatch: pytest.MonkeyPatch) -> TestClient:
        monkeypatch.delenv("ANALYZER_API_KEY", raising=False)
        monkeypatch.delenv("ANALYZER_ALLOWED_ROOTS", raising=False)
        get_settings.cache_clear()
        get_analysis_service.cache_clear()

        with TestClient(app) as test_client:
            yield test_client

        get_analysis_service.cache_clear()

    def test_it_returns_each_language_separately(
        self, client: TestClient, trilingual_complete_docx: Path
    ) -> None:
        response = client.post(
            "/api/v1/extract",
            json={"file_path": str(trilingual_complete_docx), "document_id": 12},
        )

        assert response.status_code == 200
        body = response.json()

        assert body["document_id"] == 12
        assert body["truncated"] is False
        assert body["parser"] == "docx"

        blocks = body["sections"][0]["blocks"]
        assert blocks["en"]["segments"] == [ENGLISH_TEXT]
        assert blocks["id"]["segments"] == [INDONESIAN_TEXT]
        assert blocks["zh"]["segments"] == [CHINESE_TEXT]

    def test_it_reports_which_language_a_section_is_missing(
        self, client: TestClient, tmp_path: Path
    ) -> None:
        from tests.conftest import build_docx

        path = build_docx(tmp_path / "two.docx", [ENGLISH_TEXT, CHINESE_TEXT])

        body = client.post("/api/v1/extract", json={"file_path": str(path)}).json()

        assert body["sections"][0]["missing"] == ["id"]

    def test_a_table_layout_is_paired_up_by_column(
        self, client: TestClient, trilingual_table_docx: Path
    ) -> None:
        # The layout these documents actually use - one language per column.
        body = client.post(
            "/api/v1/extract", json={"file_path": str(trilingual_table_docx)}
        ).json()

        blocks = body["sections"][0]["blocks"]

        for code, expected in (("en", ENGLISH_TEXT), ("id", INDONESIAN_TEXT), ("zh", CHINESE_TEXT)):
            assert expected in blocks[code]["segments"]

    def test_an_unreadable_file_type_is_refused(
        self, client: TestClient, tmp_path: Path
    ) -> None:
        path = tmp_path / "notes.rtf"
        path.write_text("hello", encoding="utf-8")

        response = client.post("/api/v1/extract", json={"file_path": str(path)})

        assert response.status_code == 415

    def test_a_missing_file_is_a_404(self, client: TestClient, tmp_path: Path) -> None:
        response = client.post(
            "/api/v1/extract", json={"file_path": str(tmp_path / "nope.docx")}
        )

        assert response.status_code == 404

    def test_a_path_outside_the_allowed_roots_is_refused(
        self, monkeypatch: pytest.MonkeyPatch, tmp_path: Path, trilingual_complete_docx: Path
    ) -> None:
        # The same policy the analyze endpoint enforces. Extraction returns
        # document *text*, so a gap here would be worse than one there.
        monkeypatch.setenv("ANALYZER_ALLOWED_ROOTS", str(tmp_path / "elsewhere"))
        get_settings.cache_clear()
        get_analysis_service.cache_clear()

        with TestClient(app) as client:
            response = client.post(
                "/api/v1/extract", json={"file_path": str(trilingual_complete_docx)}
            )

        get_analysis_service.cache_clear()

        assert response.status_code == 403

    def test_it_requires_the_api_key_when_one_is_configured(
        self, monkeypatch: pytest.MonkeyPatch, trilingual_complete_docx: Path
    ) -> None:
        monkeypatch.setenv("ANALYZER_API_KEY", "s3cret")
        monkeypatch.delenv("ANALYZER_ALLOWED_ROOTS", raising=False)
        get_settings.cache_clear()
        get_analysis_service.cache_clear()

        with TestClient(app) as client:
            unauthenticated = client.post(
                "/api/v1/extract", json={"file_path": str(trilingual_complete_docx)}
            )
            authenticated = client.post(
                "/api/v1/extract",
                json={"file_path": str(trilingual_complete_docx)},
                headers={"Authorization": "Bearer s3cret"},
            )

        get_analysis_service.cache_clear()

        assert unauthenticated.status_code == 401
        assert authenticated.status_code == 200
