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

## Status: Phase 1 (foundation)

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
| Trilingual **grading** rules | ✅ Done and tested |
| Trilingual **text extraction** (Python analyzer) | ⏳ Phase 2 |
| SharePoint / Microsoft Graph | ⏳ Phase 3 |
| Per-section analysis, OCR, AI similarity | ⏳ Phase 4–5 |

Documents discovered today are queued and stay at **Pending** until the Phase 2
analyzer service is enabled. Everything around that gap is real: the job, its
retries, and the whole grading pipeline are covered by tests that feed the
service the measurements a real analyzer would produce.

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
```

---

## Testing

```bash
php artisan test
vendor/bin/pint --test      # PSR-12 style check
```

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
SharePoint (Phase 3) ───────┼──► Laravel 13 ──► PostgreSQL
Manual upload ──────────────┘         │
                                      ├──► Queue (database, or Redis)
                                      └──► Python analyzer (Phase 2, HTTP)
```

A few decisions worth knowing before changing anything:

**Source adapters.** Every source implements `DocumentSourceInterface`.
`DocumentSourceFactory` is the only place in the application allowed to switch on
`DocumentSourceType`; nothing in the scanning or indexing path knows where a file
came from. Adding a source type means writing one adapter and adding one arm to
that factory.

**Change detection.** A scan tries the cheap fingerprint first — eTag if the
source provides one, otherwise size plus modification time — and only computes a
content hash when that moves. A repeat scan of an untouched folder reads no file
contents at all.

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
failure would blame the document for a parser limitation.

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
