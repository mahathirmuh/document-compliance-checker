# Document Trilingual Analyzer

FastAPI service that extracts text from controlled documents and measures how
much English, Indonesian and Chinese each one contains. Called by the Laravel
application over HTTP; it holds no state and no database.

## What it does and does not do

It **measures**. It reports, per language: characters, words (Latin scripts
only), coverage percentage and detector confidence.

It **does not decide compliance**. The PASS / PARTIAL / FAIL verdict is applied
by Laravel against thresholds a Document Controller can change at runtime. That
split is deliberate — retuning "how much Indonesian is enough" then never
requires redeploying this service, and every historical result keeps the
threshold it was actually judged against.

The `status` field in the response is advisory only, present because the
CLAUDE.md §14 contract specifies it. Laravel recomputes the real one.

## Setup

```bash
python -m venv .venv
.venv\Scripts\activate            # Windows
# source .venv/bin/activate       # Linux

pip install -r requirements.txt
cp .env.example .env              # then set ANALYZER_API_KEY and ANALYZER_ALLOWED_ROOTS
```

## Running

```bash
uvicorn app.main:app --host 127.0.0.1 --port 8001
```

Interactive docs at `http://127.0.0.1:8001/docs`.

Then in the Laravel `.env`:

```env
ANALYZER_ENABLED=true
ANALYZER_BASE_URL=http://127.0.0.1:8001
ANALYZER_API_KEY=<same value as the analyzer's>
```

## API

### `GET /health`

Unauthenticated, so a load balancer can use it.

```json
{ "status": "ok", "version": "1.0.0", "supported_extensions": ["docx", "md", "pdf", "text", "txt", "xlsx"] }
```

### `POST /api/v1/analyze`

Bearer token required when `ANALYZER_API_KEY` is set.

```json
{ "file_path": "D:\\DocumentControl\\SOP\\SOP-QA-001.docx", "document_id": 123, "version_id": 456 }
```

```json
{
  "status": "PASS",
  "overall_score": 100.0,
  "languages": {
    "en": { "detected": true, "character_count": 1180, "word_count": 196, "coverage": 42.1, "confidence": 0.99 },
    "id": { "detected": true, "character_count": 1204, "word_count": 181, "coverage": 43.0, "confidence": 0.98 },
    "zh": { "detected": true, "character_count": 417,  "word_count": null, "coverage": 14.9, "confidence": 1.0 }
  },
  "issues": [],
  "analyzer_version": "1.0.0",
  "total_characters": 2801,
  "page_count": null,
  "segment_count": 12,
  "parser": "docx",
  "duration_ms": 37
}
```

`word_count` is always `null` for `zh`: Han script has no whitespace word
boundaries, so a number there would be meaningless.

| Status | Meaning |
| --- | --- |
| 200 | Analysed |
| 401 | Missing or wrong bearer token |
| 403 | Path outside `ANALYZER_ALLOWED_ROOTS` |
| 404 | No file at that path |
| 415 | No parser for that extension |
| 422 | File unreadable, empty, or corrupt |

## How detection works

**Chinese — by script, not by model.** Han characters are identified from their
Unicode block, which is deterministic and needs no training data. Segments
containing kana or Hangul have their Han *excluded*: those are Japanese kanji or
Korean hanja, and counting them as Mandarin would be a false pass — the worst
error a compliance checker can make.

**English vs Indonesian — by trained identifier.** This is the genuinely hard
pair: same script, shared technical vocabulary, often short parallel fragments
in adjacent table cells. `lingua` is restricted to exactly these two languages,
which keeps it from answering "Malay" for Indonesian text.

**Mixed segments are split by script first**, so a cell reading
`Quality Manual 质量手册` contributes to both ZH and EN rather than confusing a
Latin-only detector.

**Short segments are pooled.** A three-word heading carries too little signal to
classify. Anything below `ANALYZER_MIN_LATIN_SEGMENT_CHARS` goes into one pool
that is classified as a block. The pool is winner-take-all rather than split
proportionally — a proportional split reads better on mixed forms, but it would
also hand a few hundred Indonesian characters to a document containing no
Indonesian at all.

**Only alphabetic characters count.** Digits and punctuation carry no language,
so a table of part numbers cannot look like coverage.

**Confidence is length-weighted**, so a 2,000-character body paragraph counts
for more than a caption.

## Scanned documents

A document yielding almost no text is a scan, not a document with no
translations. The service raises an `OCR_REQUIRED` issue and lets Laravel route
it to `REVIEW_REQUIRED`. It never reports it as a translation failure
(CLAUDE.md §16).

## Security

- **Bearer token** on `/api/v1/analyze`, compared with `secrets.compare_digest`
  so a wrong key cannot be recovered by timing.
- **Path allow list.** The endpoint takes a filesystem path from the network.
  Paths are resolved *before* being checked, so `..` and symlinks cannot escape
  a configured root. An empty allow list permits everything and is a
  development-only default — the service logs a warning at startup.
- **Error messages never echo the path back**, so the endpoint cannot be used
  to probe the filesystem. Unexpected failures are logged in full and reported
  generically.

## Testing

```bash
pip install -r requirements-dev.txt
pytest
ruff check .
```

57 tests. Fixtures are generated at test time rather than committed as
binaries, so what each one contains is readable in `tests/conftest.py`.

## Adding a format

Write a `DocumentParser` subclass, then add it to `_DEFAULT_PARSERS` in
`app/parsers/registry.py`. Nothing in the detection or scoring path changes.
Segments carry `kind`, `page` and `section` already, so the Phase 4 per-section
rules have somewhere to attach without a schema change.
