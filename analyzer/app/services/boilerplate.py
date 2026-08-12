"""Separating page furniture from document content.

A running header is not a translation. "MERDEKA TSINGSHAN INDONESIA" is
genuinely composed of Indonesian words, so no confidence threshold will ever
reject it - lingua rates it 1.00, correctly. But repeated across thirteen
pages it contributed most of the "Indonesian" in a document that contains no
Indonesian prose at all, and that was enough to carry it over the coverage
threshold and report PASS.

So repetition, not confidence, is what identifies it. Text appearing in the
same shape on most pages is furniture: a header, a footer, a document-number
block, a watermark. It is excluded from language measurement.

Rules still see the full segment list - the header/footer rule exists
precisely to check this furniture. Only the language counts are filtered.
"""

from __future__ import annotations

from collections import defaultdict

from app.config import get_settings
from app.detectors.chinese_detector import ChineseDetector
from app.parsers.base import SegmentKind, TextSegment


def identify_boilerplate(
    segments: list[TextSegment],
    min_pages: int | None = None,
) -> set[str]:
    """Text that repeats across pages, normalised for comparison.

    Returns the normalised forms, which callers match with `normalise()`.
    """
    settings = get_settings()
    threshold = min_pages if min_pages is not None else settings.boilerplate_min_pages

    pages_by_text: dict[str, set[int]] = defaultdict(set)
    page_numbers: set[int] = set()

    max_chars = settings.boilerplate_max_chars

    for segment in segments:
        if segment.page is None:
            continue

        page_numbers.add(segment.page)

        # Length is what separates furniture from repeated content. A header
        # is brief by nature; a full paragraph appearing on several pages is
        # content, and discarding it would delete real translations from the
        # measurement.
        if effective_length(segment.text) > max_chars:
            continue

        key = normalise(segment.text)

        if key:
            pages_by_text[key].add(segment.page)

    # With only a couple of pages, "repeated" is not a meaningful statement
    # and a genuine two-page document would lose real content.
    if len(page_numbers) < threshold:
        return set()

    return {
        text
        for text, pages in pages_by_text.items()
        if len(pages) >= threshold
    }


def furniture_vocabulary(
    segments: list[TextSegment],
    min_pages: int | None = None,
) -> set[str]:
    """The words that make up a document's page furniture.

    Exact-line matching is not enough on a scanned document. OCR mangles the
    same header differently on every page - "MERDEKA TSINGSHAN INDONESIA"
    comes back as "MERDEKATSINGSHA", "XG INDONESIA", "MERDEKA T!", "INDONES" -
    so the lines never normalise to the same string and each survives as
    apparently-Indonesian text.

    Working at the word level catches those. Every one of those fragments is
    built from words that appear in the running header, and a company name is
    not a translation no matter how many times it is misread.

    Only words of four characters or more are collected, so common short
    words in the furniture cannot suppress real prose elsewhere.
    """
    vocabulary: set[str] = set()

    for line in identify_boilerplate(segments, min_pages):
        vocabulary.update(word for word in line.split() if len(word) >= 4)

    # DOCX states its furniture outright rather than repeating it per page.
    for segment in segments:
        if segment.kind in (SegmentKind.HEADER, SegmentKind.FOOTER):
            vocabulary.update(
                word for word in normalise(segment.text).split() if len(word) >= 4
            )

    return vocabulary


def strip_furniture_words(text: str, vocabulary: set[str]) -> str:
    """Remove furniture words from a segment, leaving whatever else is there.

    A word matches when it equals a furniture word, or when one contains the
    other and both are long enough for that to be meaningful - which is what
    catches OCR run-ons like "MERDEKATSINGSHAN" and truncations like
    "INDONES".
    """
    if not vocabulary:
        return text

    kept: list[str] = []

    for word in text.split():
        letters = "".join(char for char in word if char.isalpha()).lower()

        if not letters:
            kept.append(word)
            continue

        if letters in vocabulary:
            continue

        if len(letters) >= 6 and any(
            (letters in known or known in letters)
            for known in vocabulary
            if len(known) >= 6
        ):
            continue

        kept.append(word)

    return " ".join(kept)


def effective_length(text: str) -> float:
    """Length of a line, scaled so scripts compare fairly.

    Han characters count for more, because Chinese conveys roughly three
    times as much per character. Measured raw, a full Chinese paragraph looks
    as short as a header and would be discarded as furniture - deleting the
    document's Mandarin coverage entirely.
    """
    density = get_settings().chinese_density_factor
    han = ChineseDetector.count_han(text)

    return (len(text) - han) + (han * density)


def normalise(text: str) -> str:
    """The comparable form of a line.

    Digits are dropped so "Page 3 of 13" and "Page 7 of 13" collapse together;
    case and spacing are flattened so OCR jitter between pages does not
    prevent a match.
    """
    kept = [char.lower() for char in text if char.isalpha() or char.isspace()]

    return " ".join("".join(kept).split())


def content_segments(
    segments: list[TextSegment],
    min_pages: int | None = None,
) -> list[TextSegment]:
    """The segments that carry actual document content.

    Two passes, because one is not enough on a scan:

      1. Whole lines that are furniture are dropped. Explicit DOCX headers and
         footers go outright - the format states what they are - and for
         paginated formats, lines repeated across pages go too.

      2. Furniture *words* are stripped from whatever remains, which catches
         the OCR variants that never matched a whole line.

    A segment left with nothing meaningful is dropped entirely.

    The returned segments are copies: the originals stay intact for the
    Document Control rules, whose whole job is to inspect that furniture.
    """
    boilerplate = identify_boilerplate(segments, min_pages)
    vocabulary = furniture_vocabulary(segments, min_pages)

    kept: list[TextSegment] = []

    for segment in segments:
        if segment.kind in (SegmentKind.HEADER, SegmentKind.FOOTER):
            continue

        if normalise(segment.text) in boilerplate:
            continue

        text = strip_furniture_words(segment.text, vocabulary)

        if not any(char.isalnum() for char in text):
            continue

        kept.append(
            TextSegment(
                text=text,
                kind=segment.kind,
                page=segment.page,
                section=segment.section,
                index=segment.index,
                font_colors=segment.font_colors,
            )
        )

    return kept
