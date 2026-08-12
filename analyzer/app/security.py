"""Request authentication.

A shared bearer token, compared in constant time. This service reads documents
off the Document Control share, so an unauthenticated caller on the network is
an information disclosure even though the service itself stores nothing.

When no key is configured the service accepts every request. That is the
development default; the startup banner warns about it, and a production
deployment is expected to set ANALYZER_API_KEY and bind to an interface the
Laravel host can reach and nothing else.
"""

from __future__ import annotations

import secrets

from fastapi import Depends, HTTPException, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from app.config import Settings, get_settings

_bearer_scheme = HTTPBearer(auto_error=False)


def require_api_key(
    credentials: HTTPAuthorizationCredentials | None = Depends(_bearer_scheme),
    settings: Settings = Depends(get_settings),
) -> None:
    expected = settings.api_key

    if not expected:
        return

    if credentials is None or credentials.scheme.lower() != "bearer":
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="A bearer token is required.",
            headers={"WWW-Authenticate": "Bearer"},
        )

    # compare_digest, not ==, so a wrong key cannot be recovered by timing how
    # long the rejection takes.
    if not secrets.compare_digest(credentials.credentials, expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid credentials.",
            headers={"WWW-Authenticate": "Bearer"},
        )
