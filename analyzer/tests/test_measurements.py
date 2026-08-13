"""Reading numbers the way each language writes them.

The test that matters most is the one proving 6.5 and 6,5 are the same
number. Indonesian inverts the decimal separator, so a check that misses this
reports a mismatch on every correctly translated document - and a check that
cries wolf is worse than no check, because people stop reading its findings.
"""

from __future__ import annotations

import pytest

from app.services.measurements import find_measurements, unmatched


def values(text: str) -> list[frozenset[float]]:
    return [measurement.values for measurement in find_measurements(text)]


class TestSeparatorConventions:
    def test_english_and_indonesian_decimals_are_the_same_number(self) -> None:
        english = find_measurements("maintain pH between 6.5 and 8.5")
        indonesian = find_measurements("pertahankan pH antara 6,5 dan 8,5")

        assert unmatched(english, indonesian) == []
        assert unmatched(indonesian, english) == []

    def test_grouped_thousands_agree_across_conventions(self) -> None:
        english = find_measurements("capacity is 1,250.5 m3")
        indonesian = find_measurements("kapasitas 1.250,5 m3")

        assert unmatched(english, indonesian) == []

    def test_repeated_separators_are_grouping_not_decimals(self) -> None:
        # 1.000.000 is never one point something.
        assert values("total 1.000.000 mg") == [frozenset({1_000_000.0})]

    def test_three_trailing_digits_are_kept_ambiguous(self) -> None:
        # 6.500 is six and a half to an Indonesian reader and six thousand
        # five hundred to an English one. Guessing would mean guessing the
        # author's locale from a document written in three.
        assert values("dose 6.500 mg") == [frozenset({6500.0, 6.5})]

    def test_an_ambiguous_token_matches_either_reading(self) -> None:
        ambiguous = find_measurements("6.500 mg")

        assert unmatched(ambiguous, find_measurements("6500 mg")) == []
        assert unmatched(ambiguous, find_measurements("6,5 mg")) == []

    def test_two_trailing_digits_are_a_decimal(self) -> None:
        assert values("12.75 kg") == [frozenset({12.75})]


class TestWhatCountsAsAMeasurement:
    """Only numbers carrying a unit are compared.

    An earlier, more permissive filter - any decimal, or any integer over 100
    - was run against a real 53-page SOP and produced 43 findings, every one
    of them a clause number or a year. These tests pin the cases that caused
    that.
    """

    def test_a_number_with_a_unit_is_a_measurement(self) -> None:
        assert len(find_measurements("dose at 5 ppm")) == 1

    def test_a_bare_decimal_is_not_a_measurement(self) -> None:
        # "8.1" is a clause, not a quantity. This is the single change that
        # took a real document from 43 findings to a usable number.
        assert find_measurements("see 8.1 for details") == []

    def test_section_numbering_is_ignored(self) -> None:
        for clause in ("8.1", "8.2", "4.2", "10.4"):
            assert find_measurements(f"Refer to clause {clause} of this procedure") == []

    def test_a_year_is_ignored(self) -> None:
        assert find_measurements("issued 25-APR-2024, revised 2026") == []

    def test_clause_numbering_is_ignored(self) -> None:
        assert find_measurements("1. Scope") == []
        assert find_measurements("3. Responsibility") == []

    def test_a_small_bare_integer_is_ignored(self) -> None:
        # "twice daily" is "2 kali sehari": a digit in one language and a word
        # in another, with no defect involved.
        assert find_measurements("apply 2 times per shift") == []

    def test_a_bare_large_integer_is_ignored(self) -> None:
        assert find_measurements("volume 1500") == []

    def test_a_ph_limit_is_recognised(self) -> None:
        # Written before its number, and constant in a water-treatment SOP.
        # Without this it would be indistinguishable from a clause number.
        found = find_measurements("maintain pH 6.5 at all times")

        assert len(found) == 1
        assert found[0].unit == "ph"

    def test_a_ph_limit_matches_its_indonesian_form(self) -> None:
        assert unmatched(
            find_measurements("maintain pH 6.5"),
            find_measurements("pertahankan pH 6,5"),
        ) == []

    def test_a_word_unit_does_not_make_a_measurement(self) -> None:
        # "hours" becomes "jam" becomes "小时". A word unit cannot be compared
        # across languages, so it does not qualify a number for comparison.
        assert find_measurements("wait 250 hours") == []

    def test_matching_runs_on_values_not_on_units(self) -> None:
        # Two symbol units, same value. The unit is recorded for the message
        # and never used to decide agreement.
        assert unmatched(find_measurements("250 mg"), find_measurements("250 kg")) == []


class TestChineseForms:
    def test_full_width_digits_are_read(self) -> None:
        # Chinese text routinely carries these. Left alone, every number in
        # the Chinese column would report as missing.
        assert values("投加量 ５００ ppm") == [frozenset({500.0})]

    def test_the_single_character_degree_symbol_is_read(self) -> None:
        assert len(find_measurements("不得超过 50℃")) == 1

    def test_a_chinese_dose_matches_its_english_source(self) -> None:
        english = find_measurements("dose at 5 ppm, do not exceed 50°C")
        chinese = find_measurements("投加量 5 ppm，不得超过 50℃")

        assert unmatched(english, chinese) == []

    def test_a_chinese_unit_word_counts_as_a_unit(self) -> None:
        # A real false positive from a 53-page SOP: English and Indonesian
        # said "5 cm", Chinese said "5 厘米", and the Chinese 5 carried no
        # unit this module recognised - so it was reported as missing.
        found = find_measurements("容器不得过满，必须至少保留 5 厘米空间")

        assert len(found) == 1
        assert found[0].unit == "厘米"

    def test_a_chinese_unit_matches_its_latin_equivalent(self) -> None:
        assert unmatched(
            find_measurements("at least 5 cm should be left"),
            find_measurements("必须至少保留 5 厘米空间"),
        ) == []

    def test_a_unit_may_be_followed_immediately_by_more_chinese(self) -> None:
        # Chinese runs words together, so a trailing word-boundary assertion
        # would reject every unit that is not at the end of a sentence.
        assert len(find_measurements("温度保持 50 摄氏度以下运行")) == 1

    def test_a_latin_unit_still_needs_its_boundary(self) -> None:
        assert find_measurements("part number 5 mgx900") == []


class TestUnmatched:
    def test_it_reports_a_value_present_in_one_language_only(self) -> None:
        english = find_measurements("dose at 5 ppm and 12.5 mg")
        indonesian = find_measurements("dosis 5 ppm")

        missing = unmatched(english, indonesian)

        assert [measurement.raw for measurement in missing] == ["12.5"]

    def test_a_wrong_figure_is_reported(self) -> None:
        # The defect this whole rule exists for.
        english = find_measurements("dose at 5 ppm")
        indonesian = find_measurements("dosis 500 ppm")

        assert [m.raw for m in unmatched(english, indonesian)] == ["5"]

    def test_each_counterpart_is_consumed_once(self) -> None:
        # A limit stated as both an upper and a lower bound is exactly the
        # kind of thing a translation drops one half of.
        english = find_measurements("between 6.5 mg and 6.5 mg")
        indonesian = find_measurements("sekitar 6,5 mg")

        assert len(unmatched(english, indonesian)) == 1

    def test_identical_text_reports_nothing(self) -> None:
        assert unmatched(find_measurements("5 ppm"), find_measurements("5 ppm")) == []

    @pytest.mark.parametrize("text", ["", "no numbers here", "---"])
    def test_text_without_numbers_yields_nothing(self, text: str) -> None:
        assert find_measurements(text) == []
