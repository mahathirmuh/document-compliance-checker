"""OCR integration (CLAUDE.md 16, 27 Phase 4).

The behaviour that matters most is what happens when OCR is *not* available,
because that is the default and because getting it wrong means reporting a
scanned document as a translation failure.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import app
from app.ocr.engine import NullEngine, OcrResult, OcrUnavailableError, TesseractEngine
from app.parsers.pdf_parser import PdfParser
from app.services.analysis_service import get_analysis_service
from tests.conftest import CHINESE_TEXT, ENGLISH_TEXT, INDONESIAN_TEXT


class FakeOcrEngine:
    """Stands in for Tesseract so the suite needs no binary installed."""

    def __init__(self, results: list[OcrResult] | None = None, reason: str | None = None) -> None:
        self._results = results or []
        self._reason = reason
        self.calls: list[list[int]] = []

    def is_available(self) -> bool:
        return self._reason is None

    def unavailable_reason(self) -> str | None:
        return self._reason

    def recognise_pdf(self, path: Path, pages: list[int]) -> list[OcrResult]:
        if self._reason is not None:
            raise OcrUnavailableError(self._reason)

        self.calls.append(pages)

        return self._results


class TestOcrDisabled:
    def test_a_scanned_pdf_still_reports_no_text_when_ocr_is_off(
        self, scanned_document_pdf: Path
    ) -> None:
        parser = PdfParser(ocr=NullEngine())

        result = parser.parse(scanned_document_pdf)

        assert result.text.strip() == ""
        assert any("scanned" in note for note in result.notes)

    def test_being_switched_off_is_not_reported_as_a_fault(
        self, scanned_document_pdf: Path
    ) -> None:
        # Adding "and OCR is disabled" to every scanned document would be
        # noise; the OCR_REQUIRED issue already says what to do.
        parser = PdfParser(ocr=NullEngine())

        result = parser.parse(scanned_document_pdf)

        assert not any("OCR could not run" in note for note in result.notes)

    def test_being_enabled_but_broken_is_reported(self, scanned_document_pdf: Path) -> None:
        # A deployment problem must be visible, unlike a deliberate choice.
        engine = FakeOcrEngine(reason="The Tesseract OCR binary was not found.")

        result = PdfParser(ocr=engine).parse(scanned_document_pdf)

        assert any("Tesseract OCR binary was not found" in note for note in result.notes)


class TestOcrRecovery:
    def test_it_recovers_text_from_a_page_with_no_text_layer(
        self, scanned_document_pdf: Path
    ) -> None:
        engine = FakeOcrEngine([
            OcrResult(text=f"{ENGLISH_TEXT}\n{INDONESIAN_TEXT}\n{CHINESE_TEXT}", page=1, confidence=0.92),
        ])

        result = PdfParser(ocr=engine).parse(scanned_document_pdf)

        assert ENGLISH_TEXT in result.text
        assert CHINESE_TEXT in result.text
        assert engine.calls == [[1]]

    def test_recovered_text_is_flagged_for_review(self, scanned_document_pdf: Path) -> None:
        # OCR output is advisory. It must never be mistaken for a clean
        # extraction (CLAUDE.md 33).
        engine = FakeOcrEngine([OcrResult(text=ENGLISH_TEXT, page=1, confidence=0.95)])

        result = PdfParser(ocr=engine).parse(scanned_document_pdf)

        assert any("recovered by OCR" in note for note in result.notes)

    def test_low_confidence_recognition_is_called_out(self, scanned_document_pdf: Path) -> None:
        engine = FakeOcrEngine([OcrResult(text=ENGLISH_TEXT, page=1, confidence=0.21)])

        result = PdfParser(ocr=engine).parse(scanned_document_pdf)

        assert any("confidence was low" in note for note in result.notes)

    def test_ocr_is_not_attempted_on_pages_that_already_have_text(
        self, bilingual_pdf: Path
    ) -> None:
        # Re-recognising a page with a perfectly good text layer would be
        # slower and less accurate than the text already extracted.
        engine = FakeOcrEngine([OcrResult(text="should not be used", page=1)])

        result = PdfParser(ocr=engine).parse(bilingual_pdf)

        assert engine.calls == []
        assert "should not be used" not in result.text

    def test_recovered_segments_carry_their_page_number(
        self, scanned_document_pdf: Path
    ) -> None:
        engine = FakeOcrEngine([OcrResult(text=ENGLISH_TEXT, page=1, confidence=0.9)])

        result = PdfParser(ocr=engine).parse(scanned_document_pdf)
        recovered = [segment for segment in result.segments if segment.text]

        assert recovered
        assert all(segment.page == 1 for segment in recovered)

    def test_a_page_that_fails_recognition_does_not_lose_the_others(
        self, scanned_document_pdf: Path
    ) -> None:
        engine = FakeOcrEngine([
            OcrResult(text="", page=1, notes=["Page 1 could not be rendered for OCR: boom"]),
        ])

        result = PdfParser(ocr=engine).parse(scanned_document_pdf)

        assert any("could not be rendered" in note for note in result.notes)


class TestTesseractEngineAvailability:
    def test_it_reports_a_missing_binary_rather_than_raising(
        self, monkeypatch: pytest.MonkeyPatch
    ) -> None:
        monkeypatch.setenv("ANALYZER_TESSERACT_PATH", "C:\\definitely\\not\\here\\tesseract.exe")
        get_settings.cache_clear()

        engine = TesseractEngine()

        assert engine.is_available() is False
        assert "Tesseract OCR binary was not found" in (engine.unavailable_reason() or "")

        get_settings.cache_clear()

    def test_recognising_without_a_binary_raises_a_clear_error(
        self, monkeypatch: pytest.MonkeyPatch, scanned_document_pdf: Path
    ) -> None:
        monkeypatch.setenv("ANALYZER_TESSERACT_PATH", "C:\\definitely\\not\\here\\tesseract.exe")
        get_settings.cache_clear()

        with pytest.raises(OcrUnavailableError, match="Tesseract"):
            TesseractEngine().recognise_pdf(scanned_document_pdf, [1])

        get_settings.cache_clear()


class TestOcrOverTheApi:
    @pytest.fixture
    def client(self, monkeypatch: pytest.MonkeyPatch) -> TestClient:
        monkeypatch.delenv("ANALYZER_API_KEY", raising=False)
        monkeypatch.delenv("ANALYZER_ALLOWED_ROOTS", raising=False)
        get_settings.cache_clear()
        get_analysis_service.cache_clear()

        with TestClient(app) as test_client:
            yield test_client

        get_analysis_service.cache_clear()

    def test_a_scanned_pdf_is_still_review_required_with_ocr_off(
        self, client: TestClient, scanned_document_pdf: Path
    ) -> None:
        # The Phase 1 guarantee must survive Phase 4: a scan is never a
        # translation failure (CLAUDE.md 16).
        body = client.post(
            "/api/v1/analyze", json={"file_path": str(scanned_document_pdf)}
        ).json()

        assert body["status"] == "REVIEW_REQUIRED"
        assert any(issue["type"] == "OCR_REQUIRED" for issue in body["issues"])
