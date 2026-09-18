"""End-to-end round-trip for original-file retention plus the additive
content preview and download endpoints.
"""

import uuid

import pytest

from app.models.document import Document
from tests.testdata import SMOKE_MARKDOWN, multipart


@pytest.mark.asyncio
async def test_original_stored_and_roundtrips(client, db_session):
    files = {
        "file": ("roundtrip-orig.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"OrigRoundtrip","source":"funnel"}',
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    document_id = resp.json()["document_id"]
    assert resp.json()["original_available"] is True

    row = await _fetch_row(document_id)
    assert row.original_data == SMOKE_MARKDOWN.encode()
    assert row.original_filename == "roundtrip-orig.md"

    content = await client.get(f"/documents/{document_id}/content")
    assert content.status_code == 200, content.text
    body = content.json()
    assert body["status"] == "ready"
    assert body["char_count"] == len(SMOKE_MARKDOWN.strip())
    assert body["chunk_count"] > 0
    assert body["original_available"] is True
    assert "Robusta" in body["content"]

    download = await client.get(f"/documents/{document_id}/download")
    assert download.status_code == 200
    assert download.content == SMOKE_MARKDOWN.encode()
    assert "roundtrip-orig.md" in download.headers["content-disposition"]


@pytest.mark.asyncio
async def test_download_404_when_original_not_stored(client, db_session):
    old = Document(
        id=uuid.uuid4().hex,
        title="Pre-retention doc",
        source=None,
        file_type="markdown",
        content_hash="h" * 64,
        original_data=None,
        original_filename=None,
        metadata_col={},
        status="ready",
        char_count=0,
        chunk_count=0,
    )
    db_session.add(old)
    await db_session.commit()

    resp = await client.get(f"/documents/{old.id}/download")
    assert resp.status_code == 404
    assert "stored" in resp.json()["detail"]

    content = await client.get(f"/documents/{old.id}/content")
    assert content.status_code == 200
    assert content.json()["original_available"] is False


async def _fetch_row(document_id):
    from sqlalchemy import select

    from app.core.db import async_session_factory

    async with async_session_factory() as session:
        result = await session.execute(
            select(Document).where(Document.id == document_id)
        )
        return result.scalar_one()