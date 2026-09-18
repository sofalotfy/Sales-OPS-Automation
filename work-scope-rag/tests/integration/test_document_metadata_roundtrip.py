"""End-to-end round-trip for the additive PATCH metadata endpoint.

Exercises metadata edits against real Postgres through the full app, proving
derived fields (chunks, status) are untouched while updated_at advances.
"""

import asyncio

import pytest
from sqlalchemy import select

from app.core.db import async_session_factory
from app.models.document import Document
from tests.testdata import SMOKE_MARKDOWN, multipart


@pytest.mark.asyncio
async def test_patch_round_trip_keeps_derived_fields(client, db_session):
    files = {
        "file": ("roundtrip.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"Roundtrip","source":"funnel"}',
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    document_id = resp.json()["document_id"]

    before = await _fetch_row(document_id)
    assert before.title == "Roundtrip"
    assert before.chunk_count > 0
    assert before.status == "ready"

    await asyncio.sleep(0.05)  # ensure updated_at can advance

    patch = await client.patch(
        f"/documents/{document_id}",
        json={"title": "Roundtrip v2", "source": None},
    )
    assert patch.status_code == 200, patch.text

    after = await _fetch_row(document_id)
    assert after.title == "Roundtrip v2"
    assert after.source is None
    assert after.chunk_count == before.chunk_count
    assert after.status == before.status == "ready"
    assert after.id == before.id
    assert after.updated_at > before.updated_at


async def _fetch_row(document_id):
    async with async_session_factory() as session:
        result = await session.execute(
            select(Document).where(Document.id == document_id)
        )
        return result.scalar_one()