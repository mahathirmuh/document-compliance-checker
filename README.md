# Document Trilingual Compliance Checker

Internal web application that discovers controlled documents (SOP, Policy, Work
Instruction, Guideline, Manual, Form, Record, Report) across multiple sources and
checks that each one contains all three required languages:

| Code | Language           |
| ---- | ------------------ |
| EN   | English            |
| ID   | Indonesian         |
| ZH   | Mandarin / Chinese |

The full specification lives in [CLAUDE.md](CLAUDE.md). This README covers how to
run the thing.

---

## Status: Phases 1–4 complete

| Capability | State |
| --- | --- |
| Authentication, roles, audit log | ✅ Done |
| Document sources (local / UNC / NAS) | ✅ Done |
| Folder scanning + change detection | ✅ Done |
| Manual upload with content validation | ✅ Done |
| Document list, filters, detail page | ✅ Done |
| Dashboard | ✅ Done |
| Configurable thresholds | ✅ Done |
| Queue + scheduler | ✅ Done |
| Trilingual grading rules | ✅ Done |
| Text extraction: DOCX, PDF, XLSX, TXT | ✅ Done |
| EN / ID / ZH detection | ✅ Done |
| SharePoint / OneDrive via Microsoft Graph | ✅ Done |
| Per-section coverage + missing-translation locations | ✅ Done |
| Short-translation detection | ✅ Done |
| OCR for scanned PDFs | ✅ Done (needs Tesseract installed) |
| Document Control rules: language order, fonts, template | ⏳ Phase 5 |
| AI semantic translation similarity | ⏳ Phase 5 |

The Python analyzer in [analyzer/](analyzer/) does the extraction and
measurement; Laravel applies the thresholds and owns the verdict.

---

## Requirements

- PHP 8.4 with `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `zip`, `fileinfo`,
  `openssl`, `curl`, `gd`
- Composer 2
- PostgreSQL 14 or newer
- Node.js 20+ (for the frontend build)
- Redis — **optional**; Phase 1 runs the queue on the `database` driver

## Setup

```bash
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
# edit .env: DB_* and APP_URL

php artisan migrate
php artisan db:seed          # creates the first SUPER_ADMIN
```

The seeder prints a generated password once. Set `SEED_ADMIN_EMAIL`,
`SEED_ADMIN_NAME` and `SEED_ADMIN_PASSWORD` first if you would rather choose
them.

### The Python analyzer

Text extraction and language detection live in a separate FastAPI service. See
[analyzer/README.md](analyzer/README.md); in short:

```bash
cd analyzer
python -m venv .venv && .venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --port 8001
```

Then point Laravel at it:

```env
ANALYZER_ENABLED=true
ANALYZER_BASE_URL=http://127.0.0.1:8001
ANALYZER_API_KEY=<same value on both sides>
```

With `ANALYZER_ENABLED=false`, documents are still discovered, versioned and
queued — they simply stay at **Pending**. Nothing else changes, so the analyzer
can be taken down for maintenance without breaking scanning.

### SharePoint / OneDrive

Needed only if you register a SharePoint source. Register an application in
Entra ID, grant it the **application** permission `Sites.Read.All` (or
`Sites.Selected`, scoped to just the site) with admin consent, then:

```env
MS_GRAPH_TENANT_ID=...
MS_GRAPH_CLIENT_ID=...
MS_GRAPH_CERTIFICATE_PATH=D:\certs\docchecker.pfx     # production
MS_GRAPH_CERTIFICATE_PASSWORD=...
# MS_GRAPH_CLIENT_SECRET=...                          # development only
```

Certificate authentication is preferred: it proves possession of a key that
never leaves the server, where a secret is a bearer string anyone who can read
a config file can replay. The certificate must be readable by **both** the web
server and the queue worker account.

Then find the identifiers a source needs:

```bash
php artisan graph:discover contoso.sharepoint.com /sites/DocumentControl
```

That prints the `site_id` and the `drive_id` of each document library; enter
them on the source form, optionally narrowing to a folder such as `General/SOP`.

Only those non-sensitive identifiers are stored against the source. Credentials
live in the environment, are never rendered in the UI, and are redacted before
anything reaches the audit table.

### Running

```bash
php artisan serve            # web
php artisan queue:work       # REQUIRED - scans and analyses run here
php artisan schedule:work    # recurring scans (use cron / Task Scheduler in production)
```

Without a queue worker nothing is ever scanned or analysed. `php artisan
documents:report-stalled` reports work that has been sitting too long, and runs
daily from the scheduler.

### Useful commands

```bash
php artisan documents:scan-due              # queue every source that is due
php artisan documents:scan-due --source=3   # force one source, ignoring its interval
php artisan documents:report-stalled        # find stuck analyses
php artisan graph:discover <host> <site>    # find SharePoint site and drive IDs
```

---

## Testing

```bash
php artisan test            # 117 tests
vendor/bin/pint --test      # PSR-12 style check

cd analyzer
pytest                      # 85 tests
ruff check .
```

The SharePoint tests run against a faked Graph, so the suite needs no tenant,
no credentials and no network.

> **The suite runs on PostgreSQL and uses `RefreshDatabase`, which drops every
> table.** `phpunit.xml` points it at `document_compliance_test`, and
> `tests/TestCase.php` refuses to start against any database not on its allow
> list. Create the test database once:
>
> ```sql
> CREATE DATABASE document_compliance_test;
> ```

SQLite is deliberately not used: the schema relies on `jsonb` and the queries on
`ILIKE`, so SQLite would accept the migrations and then fail on the queries.

---

## Architecture

```text
Windows local / UNC / NAS ──┐
SharePoint / OneDrive ──────┼──► Laravel 13 ──► PostgreSQL
Manual upload ──────────────┘         │
                                      ├──► Queue (database, or Redis)
                                      │
                                      └──► Python analyzer  (FastAPI, :8001)
                                                │
                                                ├── DOCX / PDF / XLSX / TXT parsers
                                                ├── Han script detection  → ZH
                                                └── lingua                → EN / ID
```

A few decisions worth knowing before changing anything:

**Source adapters.** Every source implements `DocumentSourceInterface`.
`DocumentSourceFactory` is the only place in the application allowed to switch on
`DocumentSourceType`; nothing in the scanning or indexing path knows where a file
came from. Adding a source type means writing one adapter and adding one arm to
that factory.

**Change detection.** Cheapest evidence first: the source's own change token if
it issues one, otherwise size plus modification time, and only then a content
hash. A repeat scan of an untouched folder reads no file contents at all, and a
repeat scan of a SharePoint library downloads nothing.

For SharePoint the token is Graph's **cTag**, not its eTag. Both move when a
file is edited, but eTag *also* moves on metadata-only changes — renaming a
file, editing a library column, changing a content type. Keying on eTag would
re-download and re-analyse an unchanged document every time a Document
Controller tidied a column.

**Versions are append-only.** A changed file creates a new `document_version`;
the previous one keeps `is_current = false` and keeps its analyses. Nothing
overwrites a historical result.

**Grading is Laravel's job, not the analyzer's.** The Python service measures —
characters, coverage, confidence. The PASS / PARTIAL / FAIL / REVIEW_REQUIRED
verdict is applied in `DocumentAnalysisService` against thresholds from the
`settings` table. Retuning a threshold therefore does not require touching the
analyzer, and each `language_results` row stores the threshold it was judged
against so old results keep reading correctly.

**Scanned PDFs are never FAIL.** A document with no extractable text goes to
`REVIEW_REQUIRED` with an `OCR_REQUIRED` issue. Reporting it as a translation
failure would blame the document for a parser limitation. OCR can recover the
text when Tesseract is installed, but its output stays advisory.

**Findings are located at the section, not the paragraph.** A trilingual SOP
writes each language as its own paragraph or column, so a per-paragraph check
would report every English paragraph as missing two languages. The section is
the smallest unit expected to hold all three, and therefore the smallest unit
where "a translation is missing" is a real finding rather than an artefact of
layout.

**Paths.** `PathGuard` is the only code allowed to turn operator input into a
filesystem path. Containment is checked against *resolved* paths, so symlinks and
NTFS junctions cannot escape a source root, and system folders cannot be
registered at all.

**Uploads.** Checked against a deny list, an allow list, the sniffed MIME type,
magic bytes, and — for `.docx` / `.xlsx` — the presence of the right OOXML member.
The name written to disk is always generated; the user's filename is metadata only.

## Roles

| Role | Can |
| --- | --- |
| `VIEWER` | Read the dashboard, document list and detail pages |
| `REVIEWER` | As above |
| `DOCUMENT_CONTROLLER` | Also upload documents, re-queue analyses, download files |
| `ADMIN` | Also manage sources, run scans, change settings, read the audit log |
| `SUPER_ADMIN` | Also delete sources and manage user accounts |

## Configuration

Business values live in the `settings` table and are edited from **Settings** in
the UI; `config/documents.php` holds the fallbacks. Nothing else in the
application may read a threshold directly.

Secrets — database, Microsoft Graph — come from the environment only. They are
never stored in `document_sources`, never rendered in the UI, and are redacted
before anything reaches the audit table.
