import pytest
from sqlalchemy import func, select

from app.models import Chunk, Document
from tests.testdata import SMOKE_MARKDOWN, multipart


@pytest.mark.asyncio
async def test_ingestion_persists_ready_document_and_chunks(
    client, db_session
):
    files = {
        "file": ("roundtrip.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"Roundtrip","source":"integration"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    document_id = resp.json()["document_id"]

    doc = await db_session.get(Document, document_id)
    assert doc is not None
    assert doc.status == "ready"
    assert doc.chunk_count >= 1

    chunk_count = await db_session.scalar(
        select(func.count()).select_from(Chunk).where(
            Chunk.document_id == document_id
        )
    )
    assert chunk_count == doc.chunk_count
    assert chunk_count >= 1

    chunks = await db_session.scalars(
        select(Chunk)
        .where(Chunk.document_id == document_id)
        .order_by(Chunk.chunk_index)
    )
    for i, chunk in enumerate(chunks):
        assert chunk.chunk_index == i
        assert chunk.text
        assert chunk.embedding
        assert len(chunk.embedding) == 384