import hashlib

from fastapi import HTTPException


def sha256_hex(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def validation_error(detail: str) -> HTTPException:
    return HTTPException(status_code=422, detail=detail)


def bad_request(detail: str) -> HTTPException:
    return HTTPException(status_code=400, detail=detail)


def not_found(detail: str) -> HTTPException:
    return HTTPException(status_code=404, detail=detail)


def conflict(detail: str) -> HTTPException:
    return HTTPException(status_code=409, detail=detail)


def internal_error(detail: str) -> HTTPException:
    return HTTPException(status_code=500, detail=detail)