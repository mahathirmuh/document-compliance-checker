"""FastAPI application.

The API is versioned under /api/v1 (CLAUDE.md 14). The response shape is a
contract with the Laravel client - changing a field means adding /api/v2, not
editing this one.
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException, status

from app import __version__
from app.config import Settings, get_settings
from app.detectors import language_detector
from app.parsers.base import ParserError, UnsupportedFormatError
from app.parsers.registry import get_registry
from app.schemas.request import AnalyzeRequest, ExtractRequest
from app.schemas.response import AnalyzeResponse, ExtractResponse, HealthResponse
from app.security import require_api_key
from app.services.analysis_service import AnalysisService, get_analysis_service
from app.services.file_access import FileAccessError, resolve_document_path

logger = logging.getLogger("analyzer")


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()

    # Loading the language models takes a few hundred milliseconds. Doing it
    # here means the first real analysis does not pay for it, and a broken
    # install fails at startup rather than on a queue worker at 2am.
    logger.info("Loading language models...")
    language_detector.warm_up()

    if not settings.api_key:
        logger.warning(
            "ANALYZER_API_KEY is not set - the service will accept unauthenticated requests."
        )

    if not settings.allowed_roots:
        logger.warning(
            "ANALYZER_ALLOWED_ROOTS is not set - the service may read any path it can access."
        )

    logger.info("Analyzer %s ready.", __version__)

    yield


app = FastAPI(
    title="Document Trilingual Analyzer",
    version=__version__,
    summary="Extracts text from controlled documents and measures EN / ID / ZH content.",
    lifespan=lifespan,
)


@app.get("/health", response_model=HealthResponse, tags=["service"])
def health() -> HealthResponse:
    """Liveness check. Deliberately unauthenticated so a load balancer can use it."""
    return HealthResponse(
        status="ok",
        version=__version__,
        supported_extensions=get_registry().supported_extensions(),
    )


@app.post(
    "/api/v1/analyze",
    response_model=AnalyzeResponse,
    tags=["analysis"],
    dependencies=[Depends(require_api_key)],
    responses={
        401: {"description": "Missing or invalid bearer token."},
        403: {"description": "The path is outside the directories this service may read."},
        404: {"description": "No document at the supplied path."},
        415: {"description": "No parser is available for this file type."},
        422: {"description": "The document could not be parsed."},
    },
)
def analyze(
    request: AnalyzeRequest,
    settings: Settings = Depends(get_settings),
    service: AnalysisService = Depends(get_analysis_service),
) -> AnalyzeResponse:
    """Measure the language content of one document."""
    path = _resolve(request.file_path, settings)

    try:
        return service.analyze(
            path,
            request.document_id,
            request.version_id,
            rules=request.rules,
        )
    except UnsupportedFormatError as exc:
        raise HTTPException(
            status_code=status.HTTP_415_UNSUPPORTED_MEDIA_TYPE, detail=str(exc)
        ) from exc
    except ParserError as exc:
        # A parse failure is a fact about the document, so the message - which
        # is written for an operator and contains no path - is returned.
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT, detail=str(exc)
        ) from exc
    except Exception as exc:
        # Anything unforeseen is logged in full and reported generically, so a
        # stack trace containing a UNC path never reaches the client.
        logger.exception(
            "Unhandled analysis failure",
            extra={"document_id": request.document_id, "version_id": request.version_id},
        )
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="The document could not be analysed.",
        ) from exc


@app.post(
    "/api/v1/extract",
    response_model=ExtractResponse,
    tags=["analysis"],
    dependencies=[Depends(require_api_key)],
    responses={
        401: {"description": "Missing or invalid bearer token."},
        403: {"description": "The path is outside the directories this service may read."},
        404: {"description": "No document at the supplied path."},
        415: {"description": "No parser is available for this file type."},
        422: {"description": "The document could not be parsed."},
    },
)
def extract(
    request: ExtractRequest,
    settings: Settings = Depends(get_settings),
    service: AnalysisService = Depends(get_analysis_service),
) -> ExtractResponse:
    """Return one document's text, paired up by section and language.

    Deliberately stores nothing. The caller reads this to render a
    side-by-side comparison and then discards it, so the application never
    becomes a second copy of a controlled document (CLAUDE.md 12).
    """
    path = _resolve(request.file_path, settings)

    try:
        return service.extract(
            path,
            request.document_id,
            request.version_id,
            max_characters=request.max_characters,
        )
    except UnsupportedFormatError as exc:
        raise HTTPException(
            status_code=status.HTTP_415_UNSUPPORTED_MEDIA_TYPE, detail=str(exc)
        ) from exc
    except ParserError as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT, detail=str(exc)
        ) from exc
    except Exception as exc:
        logger.exception(
            "Unhandled extraction failure",
            extra={"document_id": request.document_id, "version_id": request.version_id},
        )
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="The document could not be read.",
        ) from exc


def _resolve(file_path: str, settings: Settings):
    """Resolve a requested path, or refuse it with the right status.

    "outside" is a policy refusal (403); everything else the caller can
    reasonably fix is a 404 or 422. The messages never confirm whether some
    other path exists, so this cannot be used to probe the filesystem.
    """
    try:
        return resolve_document_path(file_path, settings)
    except FileAccessError as exc:
        message = str(exc)

        if "outside" in message:
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail=message) from exc
        if "could not be found" in message:
            raise HTTPException(status_code=status.HTTP_404_NOT_FOUND, detail=message) from exc

        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT, detail=message
        ) from exc
