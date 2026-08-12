"""English / Indonesian / Chinese attribution.

Chinese comes from script. English and Indonesian are the hard part: both are
Latin script, they share a lot of technical vocabulary, and in a controlled
document they often appear as short parallel fragments in adjacent table
cells. Keyword matching cannot separate them reliably, so a trained
identifier does the work (CLAUDE.md 15.3).

Two policies worth understanding before changing anything here:

**Short segments are pooled, not guessed at.** A three-word table heading
carries almost no signal. Classifying each one individually would produce
confident nonsense, so anything below the configured length goes into a pool
that is classified once, as a block, with far more context.

**The pool is winner-take-all.** Splitting its characters proportionally to
the detector's confidence would read better on mixed forms, but it would also
hand a few hundred Indonesian characters to a document that contains no
Indonesian at all - and for a compliance checker, a false PASS is much worse
than a false FAIL. So the pool goes entirely to whichever language wins it.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from functools import lru_cache

from lingua import Language, LanguageDetectorBuilder
from lingua import LanguageDetector as LinguaDetector

from app.config import get_settings
from app.detectors.chinese_detector import ChineseDetector

#: Restricted to the two languages that are actually in scope. Giving lingua
#: the full 75-language set would let it answer "Malay" for Indonesian text,
#: which is correct in the abstract and useless here.
_LATIN_LANGUAGES = (Language.ENGLISH, Language.INDONESIAN)

_LINGUA_TO_CODE = {
    Language.ENGLISH: "en",
    Language.INDONESIAN: "id",
}


@lru_cache
def _build_detector() -> LinguaDetector:
    """Build the lingua detector once.

    Model loading is expensive - hundreds of milliseconds - so it happens once
    per process, and the FastAPI lifespan warms it at startup so the first
    real request does not pay for it.
    """
    return (
        LanguageDetectorBuilder.from_languages(*_LATIN_LANGUAGES)
        .with_preloaded_language_models()
        .build()
    )


def warm_up() -> None:
    """Load the language models. Called from application startup."""
    _build_detector().detect_language_of("warm up the models")


@dataclass(slots=True)
class LanguageTally:
    """Running totals for one language."""

    characters: int = 0
    words: int | None = 0

    #: Sum of (confidence x characters), so the mean below is length-weighted:
    #: a 2,000-character body paragraph should count for more than a caption.
    _confidence_weight: float = 0.0

    def add(self, characters: int, words: int | None, confidence: float) -> None:
        self.characters += characters

        if words is not None and self.words is not None:
            self.words += words

        self._confidence_weight += confidence * characters

    @property
    def detected(self) -> bool:
        return self.characters > 0

    @property
    def confidence(self) -> float:
        if self.characters == 0:
            return 0.0

        return round(min(self._confidence_weight / self.characters, 1.0), 4)


@dataclass(slots=True)
class TextProfile:
    """The measured language make-up of one document."""

    tallies: dict[str, LanguageTally] = field(
        default_factory=lambda: {
            "en": LanguageTally(),
            "id": LanguageTally(),
            # Chinese never carries a word count: Han script has no
            # whitespace word boundaries (CLAUDE.md 8.6).
            "zh": LanguageTally(words=None),
        }
    )

    #: Meaningful characters that could not be attributed to any language -
    #: typically numbers-and-punctuation segments. Counted in the total so
    #: coverage percentages stay honest.
    unattributed_characters: int = 0

    #: Segments whose Han was excluded because they were Japanese or Korean.
    cjk_other_segments: int = 0

    @property
    def total_characters(self) -> int:
        attributed = sum(tally.characters for tally in self.tallies.values())

        return attributed + self.unattributed_characters

    def coverage(self, code: str) -> float:
        total = self.total_characters
        if total == 0:
            return 0.0

        return round((self.tallies[code].characters / total) * 100, 2)


class LanguageDetector:
    """Attributes each extracted segment to English, Indonesian or Chinese."""

    def __init__(
        self,
        min_latin_segment_chars: int | None = None,
        min_confidence: float | None = None,
    ) -> None:
        settings = get_settings()
        self._min_latin_chars = (
            min_latin_segment_chars
            if min_latin_segment_chars is not None
            else settings.min_latin_segment_chars
        )
        self._min_confidence = (
            min_confidence
            if min_confidence is not None
            else settings.min_language_confidence
        )

    def profile(self, segments: list[str]) -> TextProfile:
        """Measure the language make-up of the text it is given.

        Page furniture is expected to have been removed already, by
        app.services.boilerplate. This class deliberately knows nothing about
        headers or repetition - it measures whatever text it receives.
        """
        profile = TextProfile()
        short_latin: list[str] = []

        for segment in segments:
            split = ChineseDetector.split(segment)

            if split.rejected_as_cjk_other:
                profile.cjk_other_segments += 1

            if split.han_count:
                # Script detection is deterministic, so confidence is 1.0 -
                # there is no model here that could be uncertain.
                profile.tallies["zh"].add(
                    characters=split.han_count,
                    words=None,
                    confidence=1.0,
                )

            latin = split.latin_text
            meaningful = self._meaningful_latin(latin)

            if not meaningful:
                continue

            if len(meaningful) >= self._min_latin_chars:
                self._attribute(profile, latin, meaningful)
            else:
                short_latin.append(latin)

        self._attribute_pool(profile, short_latin)

        return profile

    def dominant_language(self, text: str, chinese_density: float = 3.0) -> str | None:
        """The single language a segment is mostly written in.

        Used by rules that care about *order* rather than volume - which
        language a paragraph belongs to, not how much of each the document
        contains overall.

        Han is weighted before comparing, for the same reason the
        short-translation check normalises: a bilingual caption of 20 Chinese
        characters and 40 English ones is predominantly Chinese, even though
        the raw counts say otherwise.

        Returns None when the segment carries no meaningful text, or when the
        Latin classifier cannot commit - the caller must treat that as
        "unknown" rather than assuming a language.
        """
        split = ChineseDetector.split(text)
        latin = self._meaningful_latin(split.latin_text)

        han_weight = split.han_count * chinese_density

        if not latin:
            return "zh" if split.han_count else None

        if han_weight > len(latin):
            return "zh"

        code, _ = self._classify(latin)

        return code

    # ------------------------------------------------------------------ #

    def _attribute(self, profile: TextProfile, raw: str, meaningful: str) -> None:
        code, confidence = self._classify(meaningful)

        if code is None:
            profile.unattributed_characters += len(meaningful)
            return

        profile.tallies[code].add(
            characters=len(meaningful),
            words=len(raw.split()),
            confidence=confidence,
        )

    def _attribute_pool(self, profile: TextProfile, short_latin: list[str]) -> None:
        """Classify all the short fragments together, as one block."""
        if not short_latin:
            return

        joined = " ".join(short_latin)
        meaningful = self._meaningful_latin(joined)

        if not meaningful:
            return

        code, confidence = self._classify(meaningful)

        if code is None:
            profile.unattributed_characters += len(meaningful)
            return

        profile.tallies[code].add(
            characters=len(meaningful),
            words=len(joined.split()),
            confidence=confidence,
        )

    def _classify(self, text: str) -> tuple[str | None, float]:
        """Return the winning language code and its confidence.

        Returns (None, 0.0) when the answer is not trustworthy, in which case
        the characters count toward the document total but are attributed to
        nobody - diluting every coverage figure equally instead of inflating
        one.

        The floor is essential, not defensive. lingua is restricted to two
        languages here, so its confidences sum to 1.0 and the winner is always
        at least 0.5: there is no value that means "I don't know". Without an
        explicit floor every scrap of Latin text - "seg", "Informa", a stray
        OCR fragment - is forced into English or Indonesian, and a document
        with no Indonesian at all accumulates hundreds of Indonesian
        characters out of noise.
        """
        values = _build_detector().compute_language_confidence_values(text)

        if not values:
            return None, 0.0

        best = values[0]

        if best.value < self._min_confidence:
            return None, 0.0

        return _LINGUA_TO_CODE.get(best.language), float(best.value)

    @staticmethod
    def _meaningful_latin(text: str) -> str:
        """Keep only alphabetic characters.

        Digits, punctuation and whitespace carry no language, and counting
        them would let a table of part numbers look like coverage.
        """
        return "".join(char for char in text if char.isalpha())
