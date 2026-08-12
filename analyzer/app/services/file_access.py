"""Validating the path a caller asked us to read.

This endpoint takes a filesystem path from a network request. Without a check
here, anyone who can reach the service can read any file the analyzer process
can - so the path is resolved and then tested against a configured allow list
before anything is opened.

Resolution happens first, deliberately: comparing the string would let
`/allowed/../../etc/shadow` through, and following a symlink out of an allowed
root would too.
"""

from __future__ import annotations

from pathlib import Path

from app.config import Settings


class FileAccessError(RuntimeError):
    """The path may not be read.

    Messages are written to be safe to return over the API: they say what is
    wrong without confirming whether a given path exists, so the endpoint
    cannot be used to probe the filesystem.
    """


def resolve_document_path(raw_path: str, settings: Settings) -> Path:
    """Resolve and authorise a requested document path.

    :raises FileAccessError: if the path is malformed, outside every allowed
        root, missing, not a regular file, or larger than the configured cap.
    """
    if "\0" in raw_path:
        raise FileAccessError("The requested path is not valid.")

    try:
        path = Path(raw_path).resolve(strict=False)
    except (OSError, ValueError) as exc:
        raise FileAccessError("The requested path is not valid.") from exc

    _assert_within_allowed_roots(path, settings)

    if not path.exists():
        raise FileAccessError("The document could not be found at the supplied path.")

    if not path.is_file():
        raise FileAccessError("The supplied path is not a file.")

    try:
        size = path.stat().st_size
    except OSError as exc:
        raise FileAccessError("The document could not be read.") from exc

    if size == 0:
        raise FileAccessError("The document is empty.")

    if size > settings.max_file_bytes:
        limit_mb = settings.max_file_bytes // (1024 * 1024)
        raise FileAccessError(f"The document is larger than the {limit_mb} MB limit.")

    return path


def _assert_within_allowed_roots(path: Path, settings: Settings) -> None:
    """Confirm the resolved path sits under a configured root.

    An empty allow list permits everything. That is the development default
    and is documented as such; a production deployment is expected to set
    ANALYZER_ALLOWED_ROOTS.
    """
    if not settings.allowed_roots:
        return

    for root in settings.allowed_roots:
        try:
            path.relative_to(root)
        except ValueError:
            continue
        else:
            return

    raise FileAccessError("The requested path is outside the directories this service may read.")
