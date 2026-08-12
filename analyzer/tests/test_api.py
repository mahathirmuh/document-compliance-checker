"""API contract tests (CLAUDE.md 29).

These pin the wire format Laravel's AnalyzerClient parses. A change that
breaks one of these is a breaking API change and needs /api/v2.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.services.analysis_service import get_analysis_service


@pytest.fixture
def client(monkeypatch: pytest.MonkeyPatch) -> TestClient:
    monkeypatch.delenv("ANALYZER_API_KEY", raising=False)
    monkeypatch.delenv("ANALYZER_ALLOWED_ROOTS", raising=False)
    get_settings.cache_clear()
    get_analysis_service.cache_clear()

    with TestClient(app) as test_client:
        yield test_client

    get_analysis_service.cache_clear()


class TestHealth:
    def test_it_reports_ok_and_supported_formats(self, client: TestClient) -> None:
        response = client.get("/health")

        assert response.status_code == 200
        body = response.json()
        assert body["status"] == "ok"
        assert {"docx", "pdf", "xlsx", "txt"} <= set(body["supported_extensions"])

    def test_health_needs_no_token(self, client: TestClient) -> None:
        assert client.get("/health").status_code == 200


class TestAnalyzeContract:
    def test_a_trilingual_document_reports_all_three_languages(
        self, client: TestClient, trilingual_complete_docx: Path
    ) -> None:
        response = client.post(
            "/api/v1/analyze",
            json={"file_path": str(trilingual_complete_docx), "document_id": 123, "version_id": 456},
        )

        assert response.status_code == 200
        body = response.json()

        assert set(body["languages"]) == {"en", "id", "zh"}
        for code in ("en", "id", "zh"):
            assert body["languages"][code]["detected"] is True
            assert body["languages"][code]["character_count"] > 0

    def test_the_response_carries_the_fields_laravel_parses(
        self, client: TestClient, trilingual_complete_docx: Path
    ) -> None:
        body = client.post(
            "/api/v1/analyze", json={"file_path": str(trilingual_complete_docx)}
        ).json()

        # The CLAUDE.md 14 contract.
        assert {"status", "overall_score", "languages", "issues", "analyzer_version"} <= set(body)

        language = body["languages"]["en"]
        assert {"detected", "coverage", "confidence", "character_count", "word_count"} <= set(language)

    def test_correlation_ids_are_echoed_back(
        self, client: TestClient, trilingual_complete_docx: Path
    ) -> None:
        body = client.post(
            "/api/v1/analyze",
            json={"file_path": str(trilingual_complete_docx), "document_id": 7, "version_id": 9},
        ).json()

        assert body["document_id"] == 7
        assert body["version_id"] == 9

    def test_chinese_word_count_is_always_null(
        self, client: TestClient, trilingual_complete_docx: Path
    ) -> None:
        body = client.post(
            "/api/v1/analyze", json={"file_path": str(trilingual_complete_docx)}
        ).json()

        assert body["languages"]["zh"]["word_count"] is None
        assert body["languages"]["en"]["word_count"] > 0

    def test_it_analyses_each_supported_format(
        self,
        client: TestClient,
        trilingual_xlsx: Path,
        trilingual_txt: Path,
        bilingual_pdf: Path,
    ) -> None:
        for path in (trilingual_xlsx, trilingual_txt, bilingual_pdf):
            response = client.post("/api/v1/analyze", json={"file_path": str(path)})

            assert response.status_code == 200, path.name
            assert response.json()["total_characters"] > 0, path.name


class TestScannedDocuments:
    def test_a_scanned_pdf_raises_ocr_required_not_an_error(
        self, client: TestClient, scanned_document_pdf: Path
    ) -> None:
        # CLAUDE.md 16: never silently return FAIL for a scan.
        response = client.post("/api/v1/analyze", json={"file_path": str(scanned_document_pdf)})

        assert response.status_code == 200
        body = response.json()

        assert body["status"] == "REVIEW_REQUIRED"
        assert any(issue["type"] == "OCR_REQUIRED" for issue in body["issues"])

    def test_an_empty_document_also_asks_for_review(
        self, client: TestClient, empty_document_docx: Path
    ) -> None:
        body = client.post("/api/v1/analyze", json={"file_path": str(empty_document_docx)}).json()

        assert any(issue["type"] == "OCR_REQUIRED" for issue in body["issues"])


class TestErrorHandling:
    def test_a_missing_file_is_a_404(self, client: TestClient, tmp_path: Path) -> None:
        response = client.post(
            "/api/v1/analyze", json={"file_path": str(tmp_path / "nope.docx")}
        )

        assert response.status_code == 404

    def test_an_unsupported_format_is_a_415(self, client: TestClient, tmp_path: Path) -> None:
        path = tmp_path / "slides.pptx"
        path.write_bytes(b"anything")

        response = client.post("/api/v1/analyze", json={"file_path": str(path)})

        assert response.status_code == 415

    def test_a_corrupt_document_is_a_422(self, client: TestClient, tmp_path: Path) -> None:
        path = tmp_path / "broken.docx"
        path.write_bytes(b"PK\x03\x04 nonsense")

        response = client.post("/api/v1/analyze", json={"file_path": str(path)})

        assert response.status_code == 422

    def test_an_empty_file_is_rejected(self, client: TestClient, tmp_path: Path) -> None:
        path = tmp_path / "zero.txt"
        path.write_bytes(b"")

        assert client.post("/api/v1/analyze", json={"file_path": str(path)}).status_code == 422

    def test_unknown_body_fields_are_rejected(self, client: TestClient) -> None:
        response = client.post(
            "/api/v1/analyze", json={"file_path": "/tmp/x.docx", "surprise": True}
        )

        assert response.status_code == 422

    def test_error_messages_do_not_echo_the_path_back(
        self, client: TestClient, tmp_path: Path
    ) -> None:
        secret_path = tmp_path / "Confidential-Board-Minutes.docx"

        response = client.post("/api/v1/analyze", json={"file_path": str(secret_path)})

        assert "Confidential" not in response.text


class TestSecurity:
    def test_a_configured_key_is_required(
        self, monkeypatch: pytest.MonkeyPatch, trilingual_complete_docx: Path
    ) -> None:
        monkeypatch.setenv("ANALYZER_API_KEY", "s3cret-token")
        get_settings.cache_clear()
        get_analysis_service.cache_clear()

        with TestClient(app) as client:
            unauthenticated = client.post(
                "/api/v1/analyze", json={"file_path": str(trilingual_complete_docx)}
            )
            assert unauthenticated.status_code == 401

            wrong = client.post(
                "/api/v1/analyze",
                json={"file_path": str(trilingual_complete_docx)},
                headers={"Authorization": "Bearer wrong-token"},
            )
            assert wrong.status_code == 401

            correct = client.post(
                "/api/v1/analyze",
                json={"file_path": str(trilingual_complete_docx)},
                headers={"Authorization": "Bearer s3cret-token"},
            )
            assert correct.status_code == 200

        get_settings.cache_clear()
        get_analysis_service.cache_clear()

    def test_paths_outside_the_allowed_roots_are_refused(
        self,
        monkeypatch: pytest.MonkeyPatch,
        tmp_path: Path,
        trilingual_complete_docx: Path,
    ) -> None:
        # Without this the endpoint would read any file the service account
        # can see, for anyone who can reach the port.
        allowed = tmp_path / "allowed"
        allowed.mkdir()

        monkeypatch.setenv("ANALYZER_ALLOWED_ROOTS", str(allowed))
        monkeypatch.delenv("ANALYZER_API_KEY", raising=False)
        get_settings.cache_clear()
        get_analysis_service.cache_clear()

        with TestClient(app) as client:
            response = client.post(
                "/api/v1/analyze", json={"file_path": str(trilingual_complete_docx)}
            )

            assert response.status_code == 403

        get_settings.cache_clear()
        get_analysis_service.cache_clear()

    def test_traversal_out_of_an_allowed_root_is_refused(
        self, monkeypatch: pytest.MonkeyPatch, tmp_path: Path
    ) -> None:
        allowed = tmp_path / "allowed"
        allowed.mkdir()
        outside = tmp_path / "outside.txt"
        outside.write_text("secret", encoding="utf-8")

        monkeypatch.setenv("ANALYZER_ALLOWED_ROOTS", str(allowed))
        monkeypatch.delenv("ANALYZER_API_KEY", raising=False)
        get_settings.cache_clear()
        get_analysis_service.cache_clear()

        with TestClient(app) as client:
            traversal = str(allowed / ".." / "outside.txt")
            response = client.post("/api/v1/analyze", json={"file_path": traversal})

            assert response.status_code == 403

        get_settings.cache_clear()
        get_analysis_service.cache_clear()
