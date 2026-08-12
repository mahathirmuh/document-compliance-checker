"""Page furniture must not count as translated content.

Written after a real scanned SOP containing no Indonesian prose whatsoever
was reported as PASS with 937 characters of "Indonesian". Every one of those
characters came from the running header - "MERDEKA TSINGSHAN INDONESIA" - and
from OCR fragments of it. The words are genuinely Indonesian, so no
confidence threshold could ever reject them; the company name simply is not a
translation.
"""

from __future__ import annotations

import pytest

from app.config import get_settings
from app.detectors.language_detector import LanguageDetector
from app.parsers.base import SegmentKind, TextSegment
from app.services.boilerplate import (
    content_segments,
    furniture_vocabulary,
    identify_boilerplate,
    strip_furniture_words,
)
from tests.conftest import CHINESE_TEXT, ENGLISH_TEXT, INDONESIAN_TEXT

HEADER = "MERDEKA TSINGSHAN INDONESIA"


def paged(text: str, page: int) -> TextSegment:
    return TextSegment(text=text, kind=SegmentKind.LINE, page=page)


def document_with_running_header(pages: int = 6) -> list[TextSegment]:
    segments: list[TextSegment] = []

    for page in range(1, pages + 1):
        segments.append(paged(HEADER, page))
        segments.append(paged(ENGLISH_TEXT, page))
        segments.append(paged(f"Page {page} of {pages}", page))

    return segments


class TestIdentifyingFurniture:
    def test_a_running_header_is_identified(self) -> None:
        found = identify_boilerplate(document_with_running_header())

        assert "merdeka tsingshan indonesia" in found

    def test_page_numbers_collapse_together(self) -> None:
        # "Page 3 of 13" and "Page 7 of 13" are the same footer.
        found = identify_boilerplate(document_with_running_header())

        assert "page of" in found

    def test_body_text_is_not_furniture(self) -> None:
        found = identify_boilerplate(document_with_running_header())

        assert not any("standard operating procedure" in line for line in found)

    def test_a_repeated_paragraph_is_content_not_furniture(self) -> None:
        # Length is what separates the two. Discarding a paragraph that
        # happens to appear on several pages would delete real translations
        # from the measurement.
        found = identify_boilerplate(document_with_running_header())

        assert not any(len(line) > 120 for line in found)

    def test_a_short_document_is_left_alone(self) -> None:
        # With two pages, "repeated" is not a meaningful statement and a
        # genuine two-page document would lose real content.
        segments = document_with_running_header(pages=2)

        assert identify_boilerplate(segments) == set()


class TestFurnitureVocabulary:
    def test_it_collects_the_words_of_the_header(self) -> None:
        vocabulary = furniture_vocabulary(document_with_running_header())

        assert {"merdeka", "tsingshan", "indonesia"} <= vocabulary

    def test_short_words_are_not_collected(self) -> None:
        # A three-letter word in the furniture must not suppress real prose.
        segments = document_with_running_header()
        segments.append(paged("PT dan CV", 1))

        assert "dan" not in furniture_vocabulary(segments)

    def test_a_docx_header_contributes_its_words(self) -> None:
        segments = [
            TextSegment(text=HEADER, kind=SegmentKind.HEADER),
            TextSegment(text=ENGLISH_TEXT),
        ]

        assert "tsingshan" in furniture_vocabulary(segments)


class TestStrippingFurnitureWords:
    @pytest.fixture
    def vocabulary(self) -> set[str]:
        return {"merdeka", "tsingshan", "indonesia"}

    def test_it_removes_exact_matches(self, vocabulary: set[str]) -> None:
        assert strip_furniture_words("PT MERDEKA TSINGSHAN INDONESIA", vocabulary).strip() == "PT"

    def test_it_removes_ocr_run_ons(self, vocabulary: set[str]) -> None:
        # OCR merges words differently on every page, so the mangled forms
        # never match a whole line - only the word level catches them.
        assert strip_furniture_words("MERDEKATSINGSHAN", vocabulary).strip() == ""

    def test_it_removes_ocr_truncations(self, vocabulary: set[str]) -> None:
        assert strip_furniture_words("INDONES", vocabulary).strip() == ""

    def test_it_keeps_real_prose(self, vocabulary: set[str]) -> None:
        kept = strip_furniture_words(INDONESIAN_TEXT, vocabulary)

        assert "prosedur" in kept.lower()
        assert len(kept) > len(INDONESIAN_TEXT) * 0.9

    def test_a_short_word_is_not_matched_by_containment(self, vocabulary: set[str]) -> None:
        # "ind" is inside "indonesia" but far too short for that to mean
        # anything.
        assert strip_furniture_words("ind", vocabulary).strip() == "ind"


class TestContentSegments:
    def test_furniture_lines_are_dropped(self) -> None:
        content = content_segments(document_with_running_header())
        texts = [segment.text for segment in content]

        assert HEADER not in texts
        assert any(ENGLISH_TEXT in text for text in texts)

    def test_declared_headers_and_footers_are_dropped(self) -> None:
        segments = [
            TextSegment(text=HEADER, kind=SegmentKind.HEADER),
            TextSegment(text="Page 1 of 4", kind=SegmentKind.FOOTER),
            TextSegment(text=ENGLISH_TEXT),
        ]

        assert [s.text for s in content_segments(segments)] == [ENGLISH_TEXT]

    def test_the_originals_are_not_modified(self) -> None:
        # Rules inspect the furniture, so their view must stay intact.
        segments = document_with_running_header()

        content_segments(segments)

        assert any(segment.text == HEADER for segment in segments)


class TestTheRegressionItself:
    """The exact failure that prompted all of this."""

    def test_a_company_name_in_a_header_does_not_create_a_language(self) -> None:
        # An English-only document whose header carries an Indonesian company
        # name must not be reported as containing Indonesian.
        segments = document_with_running_header(pages=13)

        detector = LanguageDetector()
        before = detector.profile([s.text for s in segments])
        after = detector.profile([s.text for s in content_segments(segments)])

        assert before.tallies["id"].characters > 100, "the bug should reproduce without filtering"
        assert after.tallies["id"].characters == 0
        assert after.tallies["en"].detected

    def test_ocr_variants_of_the_header_are_also_removed(self) -> None:
        segments = document_with_running_header(pages=6)
        segments += [
            paged("MERDEKATSINGSHA", 7),
            paged("XG INDONESIA", 8),
            paged("INDONES", 9),
            paged(ENGLISH_TEXT, 9),
        ]

        content = content_segments(segments)
        after = LanguageDetector().profile([s.text for s in content])

        assert after.tallies["id"].characters == 0

    def test_a_genuinely_trilingual_document_is_unaffected(self) -> None:
        # The fix must not cost real coverage.
        segments: list[TextSegment] = []

        for page in range(1, 7):
            segments.append(paged(HEADER, page))
            segments.append(paged(ENGLISH_TEXT, page))
            segments.append(paged(INDONESIAN_TEXT, page))
            segments.append(paged(CHINESE_TEXT, page))

        after = LanguageDetector().profile([s.text for s in content_segments(segments)])

        assert after.tallies["en"].detected
        assert after.tallies["id"].characters > 1000
        assert after.tallies["zh"].detected


class TestConfidenceFloor:
    def test_unclassifiable_fragments_are_attributed_to_nobody(self) -> None:
        # lingua is restricted to two languages, so its winner is always at
        # least 0.5 - there is no value meaning "I don't know". Without the
        # floor, every scrap of OCR noise becomes English or Indonesian.
        get_settings.cache_clear()
        detector = LanguageDetector(min_confidence=0.99)

        profile = detector.profile(["qwrtp zxcvb nmlkj hgfds"])

        assert profile.tallies["en"].characters == 0
        assert profile.tallies["id"].characters == 0
        assert profile.unattributed_characters > 0

    def test_a_permissive_floor_still_classifies_real_prose(self) -> None:
        detector = LanguageDetector(min_confidence=0.80)

        profile = detector.profile([INDONESIAN_TEXT])

        assert profile.tallies["id"].detected
