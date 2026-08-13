"""Numbers in a document, read the way each language writes them.

An SOP about reagent dosing is mostly prose, and the prose is allowed to
differ between translations - that is what translation is. The numbers are
not. If the English says dose at 5 ppm and the Indonesian says 500 ppm, one
of them is wrong, and no amount of language detection will notice.

Two things make this harder than scanning for digits.

**The decimal separator is inverted.** English writes 6.5 and 1,000;
Indonesian writes 6,5 and 1.000. A naive comparison reports every correctly
translated Indonesian document as a mismatch - the worst possible outcome,
because a check that cries wolf trains a Document Controller to ignore it.
So a token is parsed into every value it could plausibly mean, and two
tokens agree when their candidate sets overlap.

**Units are translated but symbols are not.** "hours" becomes "jam" becomes
"小时", so comparing unit words across languages would produce noise.
Comparison therefore runs on values alone; the unit is captured only to
decide whether a number is a measurement worth checking, and to quote back
in the finding.

**Only numbers carrying a unit symbol are compared.** This started out more
permissive - any decimal, or any integer over 100 - and run against a real
53-page SOP it produced 43 findings, of which every single one was clause
numbering (8.1, 10.2, 4.2) or a year (2015, 2026). A check that wrong is
worse than no check: it buries the one finding that matters under forty that
do not, and teaches a Document Controller to close the page.

A unit is what separates "8.1" the clause from "8.1 mg" the dose. It costs
coverage - "store for 90 days" has a word unit that is translated, so it
cannot be compared - but what remains is exactly the set of figures that are
both language-independent and physically consequential: doses,
concentrations, temperatures, pressures, pH limits.
"""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass

#: Unit symbols that survive translation unchanged.
#:
#: English and Indonesian both write these as Latin symbols - "5 cm" reads
#: the same in either - so one list covers both.
_UNIT_SYMBOLS = (
    "°c", "°f", "℃", "℉",
    "%", "‰",
    "ppm", "ppb", "phr",
    "mg", "kg", "µg", "ug", "g", "t",
    "ml", "l", "m3", "m³",
    "mm", "cm", "km", "m",
    "kpa", "mpa", "bar", "psi", "atm",
    "kw", "kwh", "mw", "hp", "v", "kv", "a", "ma", "hz",
    "ntu", "ms/cm", "µs/cm", "us/cm", "mg/l", "mg/kg", "g/l", "meq/l",
    "rpm", "lpm", "gpm", "m/s",
)

#: How Chinese writes those same units.
#:
#: Needed for the vocabulary to be symmetric, and its absence caused a real
#: false positive: an SOP saying "at least 5 cm" in English and Indonesian
#: and "至少保留 5 厘米" in Chinese was reported as a mismatch, because the
#: Chinese 5 carried no unit this module recognised and so never counted as a
#: measurement at all.
#:
#: Time units are deliberately excluded on all sides. English and Indonesian
#: write "hours" and "jam" as words rather than symbols, so recognising 小时
#: would make Chinese the only language holding a measurement there and
#: reintroduce the same asymmetry in the opposite direction.
_CJK_UNITS = (
    "毫米", "厘米", "千米", "公里", "米",
    "毫克", "千克", "公斤", "克", "吨",
    "毫升", "升", "立方米",
    "摄氏度", "度",
    "千帕", "兆帕", "帕", "巴",
)

#: Ordered longest-first so "mg/l" is matched before "mg", "°c" before "c",
#: and 厘米 before 米.
_UNIT_PATTERN = "|".join(
    re.escape(unit)
    for unit in sorted(_UNIT_SYMBOLS + _CJK_UNITS, key=len, reverse=True)
)

#: The number body itself. Accepts both separator conventions; deciding what
#: they mean happens in _candidates.
_NUMBER_BODY = r"\d{1,3}(?:[.,\s]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?"

#: A number followed by a unit symbol.
_NUMBER_RE = re.compile(
    r"(?<![\w.,])"
    rf"(?P<number>{_NUMBER_BODY})"
    r"\s*"
    rf"(?P<unit>{_UNIT_PATTERN})"
    # Latin-only boundary rather than \w: Chinese runs words together, so
    # "5 厘米空间" must still match while "5 mgx" must not.
    r"(?![A-Za-z0-9])",
    re.IGNORECASE,
)

#: Units that are written *before* their value. pH is the one that matters
#: here - "pH 6.5" is a limit an SOP states constantly, and it would
#: otherwise look identical to a clause number.
_PREFIX_UNITS = ("ph",)

_PREFIXED_RE = re.compile(
    rf"\b(?P<unit>{'|'.join(_PREFIX_UNITS)})\s*"
    rf"(?P<number>{_NUMBER_BODY})"
    # Latin-only boundary rather than \w: Chinese runs words together, so
    # "5 厘米空间" must still match while "5 mgx" must not.
    r"(?![A-Za-z0-9])",
    re.IGNORECASE,
)


@dataclass(frozen=True, slots=True)
class Measurement:
    """One number found in text, with every value it could denote."""

    raw: str
    values: frozenset[float]
    unit: str | None = None

    def agrees_with(self, other: Measurement) -> bool:
        """True when the two tokens could denote the same quantity.

        Overlap rather than equality, because "6.500" is genuinely ambiguous:
        6,500 to an English reader, 6.5 to an Indonesian one. Demanding a
        single interpretation would mean guessing the author's locale from a
        document that contains three.
        """
        return bool(self.values & other.values)

    @property
    def display(self) -> str:
        return f"{self.raw} {self.unit}".strip() if self.unit else self.raw


def find_measurements(text: str) -> list[Measurement]:
    """Every number in `text` that carries a unit.

    A number without one is structure - a clause, a year, a step count - and
    is left alone. See the module docstring for why that filter is this
    strict.
    """
    normalised = _normalise(text)
    found: list[Measurement] = []

    # Prefixed units first, so the span of "pH 6.5" is claimed before the
    # suffix pass can look at the 6.5 on its own.
    consumed: list[tuple[int, int]] = []

    for match in _PREFIXED_RE.finditer(normalised):
        measurement = _build(match.group("number"), match.group("unit"))

        if measurement is not None:
            found.append(measurement)
            consumed.append(match.span())

    for match in _NUMBER_RE.finditer(normalised):
        if any(start <= match.start() < end for start, end in consumed):
            continue

        measurement = _build(match.group("number"), match.group("unit"))

        if measurement is not None:
            found.append(measurement)

    return found


def unmatched(source: list[Measurement], against: list[Measurement]) -> list[Measurement]:
    """Measurements in `source` with no counterpart in `against`.

    Each counterpart is consumed once, so a value written twice in one
    language and once in another is reported - a limit stated for both an
    upper and a lower bound is exactly the kind of thing a translation drops.
    """
    remaining = list(against)
    missing: list[Measurement] = []

    for measurement in source:
        for index, candidate in enumerate(remaining):
            if measurement.agrees_with(candidate):
                remaining.pop(index)
                break
        else:
            missing.append(measurement)

    return missing


# --------------------------------------------------------------------------- #


def _build(raw: str, unit: str) -> Measurement | None:
    values = _candidates(raw.strip())

    if not values:
        return None

    return Measurement(raw=raw.strip(), values=frozenset(values), unit=unit.lower())


def _normalise(text: str) -> str:
    """Fold full-width forms and CJK punctuation into their ASCII equivalents.

    Chinese text routinely carries ０-９, ．and ，. Left alone they simply do
    not match, so every number in the Chinese column would report as missing.
    """
    folded = unicodedata.normalize("NFKC", text)

    return folded.translate(str.maketrans({"，": ",", "．": ".", "、": ","}))


def _candidates(raw: str) -> set[float]:
    """Every value `raw` could denote under either separator convention."""
    body = raw.replace(" ", "")

    if not any(char.isdigit() for char in body):
        return set()

    has_dot = "." in body
    has_comma = "," in body

    # Both present: the last one is the decimal point and the other groups.
    # True in both conventions, so there is nothing to guess.
    if has_dot and has_comma:
        decimal = "." if body.rfind(".") > body.rfind(",") else ","
        grouping = "," if decimal == "." else "."

        return _parse(body.replace(grouping, "").replace(decimal, "."))

    if not has_dot and not has_comma:
        return _parse(body)

    separator = "." if has_dot else ","

    # Repeated: grouping in both conventions - 1.000.000 is never a decimal.
    if body.count(separator) > 1:
        return _parse(body.replace(separator, ""))

    tail = body.split(separator)[1]

    # Exactly three trailing digits is the ambiguous case, and the only one:
    # 6.500 is six thousand five hundred to an English reader and six and a
    # half to an Indonesian one. Both are kept.
    if len(tail) == 3:
        return _parse(body.replace(separator, "")) | _parse(body.replace(separator, "."))

    return _parse(body.replace(separator, "."))


def _parse(text: str) -> set[float]:
    try:
        return {float(text)}
    except ValueError:
        return set()
