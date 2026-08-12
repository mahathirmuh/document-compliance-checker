"""Chinese detection by script.

Chinese is identified from the Unicode block a character sits in, not by a
statistical model. Han script is unambiguous and needs no training data, so
this is both faster and more certain than asking a classifier (CLAUDE.md 15.2).

Two things this deliberately does *not* do:

- It does not treat one Han character as evidence of Mandarin coverage. It
  reports a count; whether that count is enough is a threshold decision made
  in Laravel (CLAUDE.md 6).

- It does not count kanji as Chinese. A segment containing kana is Japanese,
  and its Han characters are excluded - otherwise a Japanese appendix in an
  otherwise English document would be reported as Mandarin coverage, which is
  a false pass, the worst kind of error for a compliance checker. The same
  applies to Hangul.
"""

from __future__ import annotations

from dataclasses import dataclass

#: Han ranges. Extensions B and beyond are included for completeness; they are
#: rare in business documents but cost nothing to check.
_HAN_RANGES: tuple[tuple[int, int], ...] = (
    (0x3400, 0x4DBF),    # CJK Unified Ideographs Extension A
    (0x4E00, 0x9FFF),    # CJK Unified Ideographs
    (0xF900, 0xFAFF),    # CJK Compatibility Ideographs
    (0x20000, 0x2A6DF),  # Extension B
    (0x2A700, 0x2EBEF),  # Extensions C-F
    (0x2F800, 0x2FA1F),  # Compatibility Ideographs Supplement
)

#: Iteration marks and the ideographic zero, which behave as Han in running text.
_HAN_SINGLETONS = frozenset({0x3005, 0x3007})

_KANA_RANGES: tuple[tuple[int, int], ...] = (
    (0x3040, 0x309F),  # Hiragana
    (0x30A0, 0x30FF),  # Katakana
    (0x31F0, 0x31FF),  # Katakana phonetic extensions
)

_HANGUL_RANGES: tuple[tuple[int, int], ...] = (
    (0x1100, 0x11FF),  # Jamo
    (0x3130, 0x318F),  # Compatibility Jamo
    (0xAC00, 0xD7AF),  # Syllables
)


def _in_ranges(code_point: int, ranges: tuple[tuple[int, int], ...]) -> bool:
    return any(low <= code_point <= high for low, high in ranges)


@dataclass(slots=True)
class ScriptSplit:
    """One segment separated into its Chinese and non-Chinese parts."""

    han_text: str
    latin_text: str

    #: True when the segment carried kana or Hangul, so its Han characters
    #: were treated as Japanese or Korean rather than Chinese.
    rejected_as_cjk_other: bool = False

    @property
    def han_count(self) -> int:
        return len(self.han_text)


class ChineseDetector:
    @staticmethod
    def is_han(char: str) -> bool:
        code_point = ord(char)
        return code_point in _HAN_SINGLETONS or _in_ranges(code_point, _HAN_RANGES)

    @staticmethod
    def contains_kana_or_hangul(text: str) -> bool:
        for char in text:
            code_point = ord(char)
            if _in_ranges(code_point, _KANA_RANGES) or _in_ranges(code_point, _HANGUL_RANGES):
                return True
        return False

    @classmethod
    def count_han(cls, text: str) -> int:
        return sum(1 for char in text if cls.is_han(char))

    @classmethod
    def split(cls, text: str) -> ScriptSplit:
        """Separate a segment's Han characters from its Latin text.

        Splitting by script before classifying matters because these documents
        routinely mix scripts inside one paragraph or table cell. Handing the
        raw mixed string to a Latin language detector would make it reason
        about characters it cannot model.
        """
        if cls.contains_kana_or_hangul(text):
            # Japanese or Korean: strip the Han so it is not miscounted, and
            # keep the Latin, which may still be a genuine English caption.
            latin_only = "".join(char for char in text if not cls.is_han(char))
            return ScriptSplit(han_text="", latin_text=latin_only, rejected_as_cjk_other=True)

        han_chars: list[str] = []
        latin_chars: list[str] = []

        for char in text:
            if cls.is_han(char):
                han_chars.append(char)
            else:
                latin_chars.append(char)

        return ScriptSplit(han_text="".join(han_chars), latin_text="".join(latin_chars))
