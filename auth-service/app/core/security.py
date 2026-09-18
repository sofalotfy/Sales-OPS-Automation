import hashlib
import secrets

from argon2 import PasswordHasher
from argon2.exceptions import VerifyMismatchError

# OWASP Password Storage Cheat Sheet baseline for Argon2id: m=19 MiB, t=2, p=1 (RFC 9106).
_hasher = PasswordHasher(time_cost=2, memory_cost=19456, parallelism=1)


def hash_password(password: str) -> str:
    return _hasher.hash(password)


def verify_password(password: str, password_hash: str) -> bool:
    try:
        return _hasher.verify(password_hash, password)
    except VerifyMismatchError:
        return False


def generate_token() -> str:
    """Opaque high-entropy bearer token (32 random bytes, url-safe). Shown once."""

    return secrets.token_urlsafe(48)


def token_hash(raw_token: str) -> str:
    """SHA-256 of the raw token — the only form stored in the database.

    High-entropy tokens do not need a slow KDF; hashing defeats database-dump
    exposure without adding per-request latency.
    """

    return hashlib.sha256(raw_token.encode()).hexdigest()