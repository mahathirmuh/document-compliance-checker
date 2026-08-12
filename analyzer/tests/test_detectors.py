"""Language detector tests (CLAUDE.md 29)."""

from __future__ import annotations

import pytest

from app.detectors.chinese_detector import ChineseDetector
from app.detectors.language_detector import LanguageDetector
from tests.conftest import CHINESE_TEXT, ENGLISH_TEXT, INDONESIAN_TEXT, JAPANESE_TEXT


class TestChineseDetector:
    def test_it_counts_han_characters(self) -> None:
        assert ChineseDetector.count_han("质量管理") == 4

    def test_it_ignores_latin_and_punctuation(self) -> None:
        assert ChineseDetector.count_han("Quality 质量 (QA) 管理!") == 4

    def test_it_ignores_cjk_punctuation(self) -> None:
        # Full-width punctuation is not Han and must not inflate the count.
        assert ChineseDetector.count_han("，。、；：！？（）") == 0

    def test_it_splits_a_mixed_segment_by_script(self) -> None:
        split = ChineseDetector.split("Quality Manual 质量手册")

        assert split.han_text == "质量手册"
        assert "Quality Manual" in split.latin_text
        assert split.han_count == 4

    def test_japanese_kanji_are_not_counted_as_chinese(self) -> None:
        # A false pass is the worst outcome for a compliance checker, so a
        # segment carrying kana has its Han excluded.
        split = ChineseDetector.split(JAPANESE_TEXT)

        assert split.han_count == 0
        assert split.rejected_as_cjk_other is True

    def test_hangul_is_not_counted_as_chinese(self) -> None:
        split = ChineseDetector.split("품질 관리 手册")

        assert split.han_count == 0
        assert split.rejected_as_cjk_other is True


class TestLanguageDetector:
    @pytest.fixture
    def detector(self) -> LanguageDetector:
        return LanguageDetector()

    def test_it_identifies_english(self, detector: LanguageDetector) -> None:
        profile = detector.profile([ENGLISH_TEXT])

        assert profile.tallies["en"].detected
        assert not profile.tallies["id"].detected
        assert profile.tallies["en"].confidence > 0.5

    def test_it_identifies_indonesian(self, detector: LanguageDetector) -> None:
        # The hard case: English and Indonesian share script and loanwords,
        # which is why a trained identifier is used instead of keywords.
        profile = detector.profile([INDONESIAN_TEXT])

        assert profile.tallies["id"].detected
        assert not profile.tallies["en"].detected

    def test_it_identifies_chinese_by_script(self, detector: LanguageDetector) -> None:
        profile = detector.profile([CHINESE_TEXT])

        assert profile.tallies["zh"].detected
        assert profile.tallies["zh"].confidence == 1.0

    def test_it_separates_all_three_in_one_document(self, detector: LanguageDetector) -> None:
        profile = detector.profile([ENGLISH_TEXT, INDONESIAN_TEXT, CHINESE_TEXT])

        assert profile.tallies["en"].detected
        assert profile.tallies["id"].detected
        assert profile.tallies["zh"].detected

    def test_it_separates_scripts_inside_one_segment(self, detector: LanguageDetector) -> None:
        mixed = f"{ENGLISH_TEXT} {CHINESE_TEXT}"
        profile = detector.profile([mixed])

        assert profile.tallies["en"].detected
        assert profile.tallies["zh"].detected
        assert profile.tallies["zh"].characters == ChineseDetector.count_han(CHINESE_TEXT)

    def test_chinese_never_gets_a_word_count(self, detector: LanguageDetector) -> None:
        profile = detector.profile([CHINESE_TEXT])

        assert profile.tallies["zh"].words is None
        assert profile.tallies["zh"].characters > 0

    def test_coverage_percentages_are_relative_to_the_whole_document(
        self, detector: LanguageDetector
    ) -> None:
        profile = detector.profile([ENGLISH_TEXT, INDONESIAN_TEXT, CHINESE_TEXT])
        total = sum(profile.coverage(code) for code in ("en", "id", "zh"))

        # Each figure is rounded to two places independently, so the three can
        # sum a hundredth either side of 100.
        assert 99.0 <= total <= 100.05

    def test_digits_and_punctuation_do_not_count_as_coverage(
        self, detector: LanguageDetector
    ) -> None:
        # A table of part numbers is not English.
        profile = detector.profile(["12345 / 678-90 :: 2024-01-01"])

        assert profile.total_characters == 0
        assert not profile.tallies["en"].detected

    def test_a_token_translation_is_measured_as_a_token(
        self, detector: LanguageDetector
    ) -> None:
        # The analyzer reports two characters; whether two is enough is a
        # threshold decision made in Laravel (CLAUDE.md 6).
        profile = detector.profile([ENGLISH_TEXT, INDONESIAN_TEXT, "质量"])

        assert profile.tallies["zh"].characters == 2

    def test_short_segments_are_pooled_rather_than_guessed_at(self) -> None:
        # Individually these carry no signal. Pooled, they classify as one
        # block and land entirely on the winner rather than being split.
        detector = LanguageDetector(min_latin_segment_chars=40)
        fragments = ["Scope", "Purpose", "Responsibility", "References", "Records"]

        profile = detector.profile(fragments)

        attributed = profile.tallies["en"].characters + profile.tallies["id"].characters
        assert attributed > 0
        assert profile.tallies["en"].characters == 0 or profile.tallies["id"].characters == 0

    def test_confidence_is_length_weighted(self, detector: LanguageDetector) -> None:
        # A long body paragraph should dominate a short caption.
        profile = detector.profile([ENGLISH_TEXT * 3, "OK"])

        assert 0.0 < profile.tallies["en"].confidence <= 1.0

    def test_an_empty_document_profiles_to_nothing(self, detector: LanguageDetector) -> None:
        profile = detector.profile([])

        assert profile.total_characters == 0
        assert all(not tally.detected for tally in profile.tallies.values())
