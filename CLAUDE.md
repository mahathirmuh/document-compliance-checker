# CLAUDE.md

## Project Name
Document Trilingual Compliance Checker

## Objective
Build an internal web application to scan, list, upload, and analyze controlled documents such as SOP, Policy, Work Instruction, Guideline, Manual, Form, Record, and Report.

The application must check whether each document contains all three required languages:

1. English
2. Indonesian
3. Mandarin / Chinese

The system must support documents coming from multiple sources:

- Windows local folders
- Windows shared folders / UNC paths
- NAS folders mounted to the application server
- SharePoint / OneDrive via Microsoft Graph
- Manual upload from the web application

The application must not only check whether the three languages exist somewhere in the document. It should be designed so that later versions can check language coverage per section, missing translations, translation consistency, document template compliance, and other Document Control rules.

---

# 1. Recommended Technology Stack

## Backend
- Laravel 13
- PHP 8.4

## Database
- PostgreSQL

## Queue / Background Jobs
- Redis
- Laravel Queue

## Frontend
Preferred:
- Laravel Blade + Livewire

Alternative:
- Laravel + React

For the first version, prioritize simplicity and maintainability. Use Blade + Livewire unless there is a strong reason to use React.

## Document Analysis Service
Use a separate Python service for document parsing, language detection, OCR, semantic analysis, and AI-related processing.

Suggested:
- Python 3.12+
- FastAPI
- Uvicorn

## External Integration
- Microsoft Graph API
- Microsoft Entra ID
- SharePoint / OneDrive

---

# 2. High-Level Architecture

```text
Windows Local Folder -----------┐
Windows Shared Folder ----------|
NAS / Mounted Folder -----------|
SharePoint / OneDrive ----------|----> Laravel 13
Manual Upload ------------------|         |
                                         |
                                         +--> PostgreSQL
                                         |
                                         +--> Redis Queue
                                         |
                                         +--> Python Document Analyzer
                                                  |
                                                  +--> Text Extraction
                                                  +--> OCR
                                                  +--> Language Detection
                                                  +--> Coverage Analysis
                                                  +--> Semantic Validation
```

Laravel is the main application and orchestrator.

Python is responsible only for document analysis and should expose internal REST API endpoints that Laravel can call.

---

# 3. Main Business Flow

## 3.1 Folder Scan

1. Admin registers a document source.
2. Laravel scans the configured source.
3. Laravel finds supported document files.
4. File metadata is stored in PostgreSQL.
5. New or changed files are queued for analysis.
6. Python extracts document text.
7. Python detects English, Indonesian, and Mandarin.
8. Laravel stores the analysis results.
9. User sees the compliance status on the dashboard.

## 3.2 Manual Upload

1. User opens Upload Document.
2. User uploads a supported file.
3. Laravel validates the file.
4. Laravel stores the file temporarily or in configured application storage.
5. A document record is created.
6. Analysis job is dispatched.
7. Python analyzes the document.
8. Results are stored.
9. User can view the analysis result.

## 3.3 SharePoint Scan

1. Laravel authenticates to Microsoft Graph using application credentials.
2. Laravel accesses configured SharePoint / OneDrive locations.
3. Laravel retrieves folder and file metadata.
4. Laravel stores Graph item IDs and metadata.
5. New or changed files are downloaded temporarily for analysis.
6. Analysis is performed.
7. Temporary files may be deleted after processing.
8. SharePoint remains the source of truth.

---

# 4. Document Source Types

Create a generic source abstraction.

Supported source types:

```text
WINDOWS_LOCAL
WINDOWS_SHARE
NAS
SHAREPOINT
UPLOAD
```

Do not hard-code the application around SharePoint only.

Each source must be handled through a source adapter/service.

Suggested structure:

```text
app/
  Services/
    DocumentSources/
      Contracts/
        DocumentSourceInterface.php
      WindowsFolderSource.php
      SharePointSource.php
      UploadSource.php
```

Each source implementation should support operations such as:

```php
listFiles()
getMetadata()
openFile()
downloadTemporaryCopy()
exists()
```

---

# 5. Supported File Formats

Version 1 should support:

- DOCX
- PDF
- XLSX
- TXT

Optional later:
- PPTX
- DOC
- XLS
- Scanned PDF
- JPG
- JPEG
- PNG

The application must be designed so additional parsers can be added without changing core business logic.

---

# 6. Trilingual Detection Rules

Required languages:

```text
EN = English
ID = Indonesian
ZH = Mandarin / Chinese
```

## Version 1 Rule

A document passes if:

- English detected
- Indonesian detected
- Mandarin detected
- Minimum configurable text threshold is satisfied for each language

Do not mark a document PASS only because a single Chinese word or single Indonesian phrase exists.

Use configurable thresholds.

Example:

```text
Minimum meaningful text:
English    >= 100 characters
Indonesian >= 100 characters
Mandarin   >= 50 Chinese characters
```

These values must be configurable.

## Analysis Status

Use these statuses:

```text
PENDING
PROCESSING
PASS
PARTIAL
FAIL
REVIEW_REQUIRED
ERROR
```

Definitions:

### PASS
All required languages are detected and meet the configured minimum coverage requirements.

### PARTIAL
All languages exist, but one or more languages have insufficient coverage.

### FAIL
One or more required languages are not detected.

### REVIEW_REQUIRED
All languages are detected, but potential issues require human review.

### ERROR
The document could not be analyzed.

---

# 7. Future Analysis Rules

Design the application so future releases can support:

- Language detection per paragraph
- Language detection per table cell
- Language detection per section
- Missing translation detection
- Translation alignment
- Translation semantic similarity
- EN -> ID -> ZH language order
- Font color validation
- Header validation
- Footer validation
- Document code validation
- Revision number validation
- Template compliance
- Document type validation
- Section numbering validation
- Cover page validation
- Form-specific validation

Do not implement all advanced rules in version 1 unless required.

Architecture must make adding these rules easy.

---

# 8. PostgreSQL Database Design

Use UUID or BIGINT consistently. Prefer BIGINT for simple internal tables unless distributed IDs are required.

## 8.1 users

```text
id
name
email
password
role
status
last_login_at
created_at
updated_at
```

Suggested roles:

```text
SUPER_ADMIN
ADMIN
DOCUMENT_CONTROLLER
REVIEWER
VIEWER
```

---

## 8.2 document_sources

```text
id
name
type
path
configuration_json
enabled
scan_interval_minutes
last_scan_at
created_by
created_at
updated_at
```

Examples:

```text
SOP Shared Folder
WINDOWS_SHARE
\\fileserver\DocumentControl\SOP
```

```text
Corporate SharePoint
SHAREPOINT
/sites/DocumentControl/Documents
```

Sensitive credentials must never be stored as plain text in this table.

Use environment variables, encrypted secrets, managed identity, certificates, or secure secret storage.

---

## 8.3 documents

```text
id
document_source_id
source_type
source_item_id
drive_id
site_id
parent_path
file_path
file_name
original_file_name
extension
mime_type
file_size
document_code
document_title
document_type
current_revision
file_hash
source_etag
source_last_modified_at
analysis_status
is_active
created_at
updated_at
```

Important:

Do not store the whole SOP / Policy file as PostgreSQL BLOB unless there is a specific requirement.

Store metadata and reference paths instead.

---

## 8.4 document_versions

```text
id
document_id
revision
file_hash
source_etag
file_size
source_last_modified_at
detected_at
analyzed_at
created_at
updated_at
```

Do not overwrite historical analysis when the document changes.

---

## 8.5 document_analyses

```text
id
document_id
document_version_id
status
overall_score
analyzer_version
started_at
completed_at
error_message
raw_result_json
created_at
updated_at
```

Use JSONB for raw analysis output.

---

## 8.6 language_results

```text
id
document_analysis_id
language_code
detected
character_count
word_count
coverage_percent
confidence
created_at
updated_at
```

Example:

```text
EN | true | 4120 | 850 | 35.10 | 0.98
ID | true | 3980 | 790 | 33.80 | 0.97
ZH | true | 3650 | 0   | 31.10 | 0.96
```

Do not rely only on word count for Chinese.

---

## 8.7 document_issues

```text
id
document_analysis_id
page_number
section_name
issue_type
language_code
severity
description
metadata_json
created_at
updated_at
```

Suggested severities:

```text
INFO
WARNING
ERROR
CRITICAL
```

Suggested issue types:

```text
LANGUAGE_MISSING
LOW_LANGUAGE_COVERAGE
PARSER_ERROR
OCR_REQUIRED
TRANSLATION_MISMATCH
WRONG_LANGUAGE_ORDER
WRONG_FONT_COLOR
INVALID_TEMPLATE
```

---

## 8.8 scan_logs

```text
id
document_source_id
started_at
completed_at
status
total_found
new_files
modified_files
unchanged_files
deleted_files
error_count
message
created_at
updated_at
```

---

## 8.9 audit_logs

```text
id
user_id
action
entity_type
entity_id
old_values_json
new_values_json
ip_address
user_agent
created_at
```

All important administrative actions must be auditable.

---

# 9. Detecting File Changes

Do not re-analyze every file on every scan.

Use one or more of:

- File hash
- File size
- Last modified timestamp
- SharePoint eTag
- SharePoint item ID
- Source modification timestamp

Recommended logic:

```text
New file
    -> create document
    -> create version
    -> analyze

Existing file + unchanged metadata/hash
    -> skip

Existing file + changed eTag/hash
    -> create new version
    -> analyze new version
```

---

# 10. Windows Folder Requirements

The application may run on either Windows or Linux.

## If Laravel Server Runs on Windows

Examples:

```text
D:\DocumentControl\SOP
```

or:

```text
\\fileserver\DocumentControl\SOP
```

The Windows account running:

- IIS
- Apache
- PHP
- Laravel queue worker
- Laravel scheduler

must have at least READ access to the shared folder.

Do not assume the interactive user account permission applies to the web service account.

## If Laravel Runs on Linux

Mount the Windows share first.

Example logical location:

```text
/mnt/document-control
```

Laravel should treat the mounted folder as a normal local folder.

Credentials for network shares must not be hard-coded into source code.

---

# 11. SharePoint / Microsoft Graph Requirements

Use application authentication.

Preferred authentication:

- Tenant ID
- Client ID
- Certificate

Client Secret may be supported for development but certificate-based authentication is preferred for production.

Environment example:

```env
MS_GRAPH_TENANT_ID=
MS_GRAPH_CLIENT_ID=
MS_GRAPH_CLIENT_SECRET=
MS_GRAPH_CERTIFICATE_PATH=
MS_GRAPH_CERTIFICATE_PASSWORD=
```

Never commit secrets into Git.

Use `.env.example` without real values.

SharePoint source configuration should store only non-sensitive identifiers such as:

```text
site_id
drive_id
folder_item_id
folder_path
```

---

# 12. Security Requirements

Security is mandatory.

Implement:

- Authentication
- Role-based authorization
- CSRF protection
- Server-side validation
- Upload file validation
- File extension validation
- MIME type validation
- Maximum file size
- Rate limiting where necessary
- Audit logging
- Safe temporary file handling
- No arbitrary filesystem path access
- Prevent directory traversal
- Do not expose UNC paths to unauthorized users
- Do not expose SharePoint access tokens
- Do not expose Microsoft Graph credentials
- Do not store passwords in plain text
- Encrypt sensitive configuration values
- Sanitize filenames
- Reject executable file uploads
- Log analysis errors without leaking secrets

---

# 13. Upload Security

Allowed upload extensions for V1:

```text
docx
pdf
xlsx
txt
```

Disallow:

```text
exe
bat
cmd
ps1
msi
dll
js
vbs
com
scr
```

Do not trust extension only.

Validate MIME type and file structure where possible.

Generate a safe internal filename instead of using the uploaded filename directly.

---

# 14. Python Document Analyzer

Create a separate service.

Suggested structure:

```text
analyzer/
  app/
    main.py
    parsers/
      docx_parser.py
      pdf_parser.py
      xlsx_parser.py
      txt_parser.py
    detectors/
      language_detector.py
      chinese_detector.py
    services/
      analysis_service.py
    schemas/
      request.py
      response.py
```

Suggested endpoint:

```http
POST /api/v1/analyze
```

Request:

```json
{
  "file_path": "/temporary/path/document.docx",
  "document_id": 123,
  "version_id": 456
}
```

Response:

```json
{
  "status": "PASS",
  "overall_score": 96.4,
  "languages": {
    "en": {
      "detected": true,
      "coverage": 34.2,
      "confidence": 0.98
    },
    "id": {
      "detected": true,
      "coverage": 33.1,
      "confidence": 0.97
    },
    "zh": {
      "detected": true,
      "coverage": 32.7,
      "confidence": 0.99
    }
  },
  "issues": []
}
```

The API contract must be versioned.

Do not tightly couple Laravel to one Python library.

---

# 15. Language Detection Strategy

Use a multi-step strategy.

## Step 1: Extract text

Preserve where possible:

- Paragraph boundaries
- Page references
- Table cells
- Headings

## Step 2: Detect Chinese characters

Use Unicode Han character detection as an initial signal.

Do not assume every Han character equals sufficient Mandarin coverage.

## Step 3: Detect English vs Indonesian

English and Indonesian both use Latin characters.

Use a real language identification library or model.

Do not rely only on keyword matching.

## Step 4: Aggregate results

Produce:

- Character count
- Meaningful text count
- Coverage percentage
- Confidence
- Document-level result

## Step 5: Apply thresholds

Thresholds must be configurable from application settings.

---

# 16. OCR Strategy

Some PDF files may be scanned images.

V1 behavior:

```text
Text extractable
    -> normal analysis

Very little/no text
    -> mark OCR_REQUIRED
```

Future:

Integrate OCR to process scanned documents.

Do not silently return FAIL for a scanned PDF that contains no extractable text.

Return:

```text
REVIEW_REQUIRED
Issue: OCR_REQUIRED
```

---

# 17. Background Processing

Never perform heavy document analysis directly inside the HTTP request lifecycle.

Use Laravel Queue.

Suggested jobs:

```text
ScanDocumentSourceJob
IndexDocumentJob
AnalyzeDocumentJob
SyncSharePointFolderJob
CalculateFileHashJob
CleanupTemporaryFileJob
```

Suggested flow:

```text
Scheduler
   |
ScanDocumentSourceJob
   |
Detect new/modified files
   |
AnalyzeDocumentJob
   |
Python Analyzer
   |
Store result
```

---

# 18. Laravel Scheduler

Use Laravel Scheduler for recurring scans.

Example behavior:

```text
Every 15 minutes
    scan active document sources

Daily
    clean old temporary files

Daily
    check failed analysis jobs

Weekly
    generate compliance summary
```

Actual scan interval should be configurable per source.

---

# 19. Dashboard Requirements

Main dashboard should display:

```text
Total Documents
Pass
Partial
Fail
Review Required
Pending
Error
Overall Compliance %
```

Also show:

```text
Documents by Type
Documents by Source
Documents by Department
Language Compliance
Recent Analysis
Recent Failed Scans
```

---

# 20. Document List

Columns:

```text
Document Code
Document Title
Document Type
Source
File Name
Revision
English
Indonesian
Mandarin
Compliance Score
Status
Last Modified
Last Analyzed
Actions
```

Filters:

```text
Source
Document Type
Status
Language Missing
Department
Revision
Date
```

Search:

```text
Document Code
Document Title
File Name
```

---

# 21. Document Detail Page

Display:

```text
Document Code
Title
Type
Revision
Source
Source Path
File Name
File Size
Last Modified
Last Analyzed
Current Status
Overall Score
```

Language section:

```text
English
Detected
Coverage
Confidence

Indonesian
Detected
Coverage
Confidence

Mandarin
Detected
Coverage
Confidence
```

Issues:

```text
Page
Section
Severity
Issue
Language
Description
```

Version history:

```text
Revision
Detected Date
Analyzed Date
Score
Status
```

---

# 22. Source Management UI

Admin must be able to:

- Add source
- Edit source
- Enable/disable source
- Run scan now
- View scan history
- Test connection
- Delete source safely

Fields depend on source type.

Example Windows source:

```text
Name
Type
Folder Path
Scan Interval
Enabled
```

Example SharePoint source:

```text
Name
Type
Site ID
Drive ID
Folder ID / Path
Scan Interval
Enabled
```

Do not expose secrets on UI after they are saved.

---

# 23. Settings

Create configurable application settings.

Examples:

```text
Minimum English characters
Minimum Indonesian characters
Minimum Chinese characters
Minimum compliance score
Allowed file extensions
Maximum upload size
Temporary file retention
Default scan interval
OCR enabled
AI semantic check enabled
```

Avoid hard-coding business thresholds.

---

# 24. API Design

Use internal service classes even if no public API is initially required.

Potential REST endpoints:

```text
GET    /api/documents
GET    /api/documents/{id}
POST   /api/documents/upload
POST   /api/documents/{id}/reanalyze

GET    /api/sources
POST   /api/sources
PUT    /api/sources/{id}
POST   /api/sources/{id}/scan

GET    /api/analyses/{id}
GET    /api/dashboard/summary
```

Use Laravel API Resources for structured responses.

---

# 25. Coding Standards

Follow:

- PSR-12
- Laravel conventions
- Thin controllers
- Business logic in services
- Validation in Form Requests
- Authorization in Policies
- Database access through Eloquent where appropriate
- Use transactions for multi-table writes
- Use enums for statuses
- Use DTOs for complex service inputs
- Use events only where they add value

Avoid:

- Huge controllers
- Business logic inside Blade
- Raw SQL unless justified
- Hard-coded filesystem paths
- Hard-coded permissions
- Hard-coded language thresholds
- Hard-coded Microsoft credentials

---

# 26. Suggested Laravel Structure

```text
app/
  Enums/
    AnalysisStatus.php
    DocumentSourceType.php
    DocumentType.php
    IssueSeverity.php

  Http/
    Controllers/
    Requests/
    Resources/

  Jobs/
    AnalyzeDocumentJob.php
    ScanDocumentSourceJob.php
    SyncSharePointFolderJob.php

  Models/
    Document.php
    DocumentVersion.php
    DocumentAnalysis.php
    LanguageResult.php
    DocumentIssue.php
    DocumentSource.php
    ScanLog.php
    AuditLog.php

  Policies/

  Services/
    Documents/
      DocumentService.php
      DocumentAnalysisService.php
      DocumentVersionService.php

    DocumentSources/
      Contracts/
        DocumentSourceInterface.php
      WindowsFolderSource.php
      SharePointSource.php
      UploadSource.php

    MicrosoftGraph/
      GraphAuthService.php
      SharePointService.php

    Analyzer/
      AnalyzerClient.php

    Files/
      FileHashService.php
      TemporaryFileService.php
```

---

# 27. Development Phases

## Phase 1 — Foundation

Implement:

- Laravel project
- PostgreSQL
- Authentication
- User roles
- Document sources
- Windows folder scan
- Manual upload
- Document metadata
- Queue
- Basic dashboard

## Phase 2 — Trilingual Analyzer

Implement:

- Python FastAPI service
- DOCX parser
- PDF parser
- XLSX parser
- TXT parser
- EN detection
- ID detection
- ZH detection
- PASS / PARTIAL / FAIL
- Analysis detail page

## Phase 3 — SharePoint

Implement:

- Microsoft Graph authentication
- SharePoint folder source
- File list sync
- eTag-based change detection
- Temporary download
- Analysis queue

## Phase 4 — Advanced Compliance

Implement:

- Paragraph-level analysis
- Section-level analysis
- Missing translation locations
- Coverage per section
- OCR
- Translation similarity

## Phase 5 — Document Control Rules

Implement configurable checks:

- EN -> ID -> ZH order
- Font color
- Header
- Footer
- Cover
- Revision
- Document code
- Template
- Section numbering

---

# 28. Version 1 Acceptance Criteria

Version 1 is considered usable when:

1. Admin can register a Windows folder source.
2. Admin can manually scan that folder.
3. Application lists supported documents.
4. User can upload a document manually.
5. New and modified files can be detected.
6. Documents can be queued for analysis.
7. Analyzer can extract text from DOCX, PDF, XLSX, and TXT.
8. Analyzer detects English, Indonesian, and Mandarin.
9. Application shows PASS, PARTIAL, FAIL, REVIEW_REQUIRED, or ERROR.
10. User can see language coverage and confidence.
11. Results are stored historically by document version.
12. Failed analysis does not crash the application.
13. Application has audit logs for administrative actions.
14. Application never exposes source credentials or access tokens.

---

# 29. Testing Requirements

Laravel:

- Unit tests
- Feature tests
- Queue job tests
- Authorization tests
- Source adapter tests

Python:

- Parser tests
- Language detector tests
- API contract tests

Create sample fixtures:

```text
english_only.docx
indonesian_only.docx
mandarin_only.docx
trilingual_complete.docx
trilingual_partial.docx
scanned_document.pdf
empty_document.docx
large_document.docx
```

Important test cases:

```text
EN only -> FAIL
ID only -> FAIL
ZH only -> FAIL
EN + ID -> FAIL
EN + ZH -> FAIL
ID + ZH -> FAIL
EN + ID + ZH sufficient coverage -> PASS
All languages but one under threshold -> PARTIAL
Scanned PDF -> REVIEW_REQUIRED / OCR_REQUIRED
Parser exception -> ERROR
```

---

# 30. Logging

Log:

- Source scan started
- Source scan completed
- Source scan failed
- File discovered
- New file
- Modified file
- File skipped
- Analysis queued
- Analysis started
- Analysis completed
- Analysis failed
- SharePoint token error
- Graph API error
- Permission denied
- Parser error

Never log:

- Passwords
- Client secrets
- Access tokens
- Refresh tokens
- Full sensitive document contents

---

# 31. Production Deployment

Recommended services:

```text
Web Server
Laravel 13

Database
PostgreSQL

Queue
Redis

Worker
Laravel Queue Worker

Scheduler
Laravel Scheduler

Analyzer
Python FastAPI

Reverse Proxy
Nginx or IIS depending on environment
```

All services should run on-premise if required by company policy.

---

# 32. Environment Variables

Example:

```env
APP_NAME="Document Compliance Checker"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=document_checker
DB_USERNAME=
DB_PASSWORD=

QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1

ANALYZER_BASE_URL=http://127.0.0.1:8001
ANALYZER_API_KEY=

MS_GRAPH_TENANT_ID=
MS_GRAPH_CLIENT_ID=
MS_GRAPH_CLIENT_SECRET=
```

Never place production credentials in this document.

---

# 33. AI / Semantic Analysis

AI semantic validation is NOT required for initial MVP.

When added later, use it for:

- Translation similarity
- Missing semantic content
- Inconsistent translation
- Suspiciously short translation
- Section matching

The AI result must be advisory.

Do not automatically overwrite document content.

Any low-confidence AI result should be marked:

```text
REVIEW_REQUIRED
```

---

# 34. UI Principle

Prioritize:

- Simple
- Fast
- Internal-enterprise style
- Easy for Document Controller
- Clear PASS / FAIL indication
- Easy filtering
- Easy traceability

Avoid overly decorative UI.

Status indicators must also have text labels, not color only.

Example:

```text
PASS
PARTIAL
FAIL
REVIEW REQUIRED
ERROR
```

---

# 35. Important Implementation Rules for Claude Code

When implementing this project:

1. Do not implement everything in one large step.
2. Work phase by phase.
3. Before changing architecture, inspect existing code.
4. Preserve migrations and historical data.
5. Do not silently change database schema.
6. Create migrations for every schema change.
7. Never commit secrets.
8. Keep Laravel and Python analyzer loosely coupled.
9. Do not make Laravel dependent on Windows-only APIs.
10. Support paths through source adapters.
11. Use queue jobs for heavy work.
12. Avoid reading huge files entirely into memory when streaming is possible.
13. Validate all user-provided paths and filenames.
14. Never execute uploaded documents.
15. Do not trust source file extensions.
16. Avoid duplicated analysis for unchanged versions.
17. Keep analysis history.
18. Fail safely and record meaningful error messages.
19. Add tests for every major feature.
20. Ask before making destructive migration or data deletion changes.

---

# 36. First Implementation Target

Start with this exact scope:

```text
Laravel 13
PostgreSQL
Redis
Blade + Livewire

Features:
- Authentication
- Roles
- Document Sources
- Windows Folder Source
- Manual Upload
- Scan Folder
- List Documents
- File Metadata
- File Hash
- Detect New / Modified Files
- Queue Analysis Placeholder
- Dashboard Placeholder
```

Do NOT start SharePoint integration or AI validation before this foundation works.

After the Laravel foundation is stable, implement the Python analyzer.

---

# 37. Definition of Done for First Sprint

First sprint is complete when:

- Application starts successfully.
- PostgreSQL connection works.
- User can log in.
- Admin can add a Windows source.
- Admin can provide a folder path.
- Application can scan supported files from that path.
- File records appear in PostgreSQL.
- Duplicate scans do not duplicate unchanged files.
- Modified files create a new document version.
- User can manually upload a DOCX/PDF/XLSX/TXT file.
- Uploaded file appears in the document list.
- Analysis status defaults to PENDING.
- Queue infrastructure is ready.
- Basic dashboard shows counts.

---

# 38. Final Product Vision

The final application should become a centralized Document Control Compliance Checker capable of:

```text
Discovering documents
        +
Tracking versions
        +
Reading multiple sources
        +
Detecting EN / ID / ZH
        +
Finding missing translation
        +
Checking translation consistency
        +
Checking template compliance
        +
Providing audit trail
        +
Providing management dashboard
```

The initial version must focus on reliable document discovery and trilingual language checking first.
