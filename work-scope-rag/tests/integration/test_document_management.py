import pytest

from tests.testdata import SMOKE_MARKDOWN, multipart

OLD_TEXT = """Robusta sells hardware networking equipment for data centers."""

NEW_TEXT = """Robusta provides machine learning consulting for retail analytics."""


async def _doc_id(client, source, text, title):
    files = {
        "file": ("doc.md", text.encode(), "text/markdown"),
        "metadata": f'{{"title":"{title}","source":"{source}"}}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    return resp.json()["document_id"]


@pytest.mark.asyncio
async def test_atomic_replace_keeps_id_and_queries_new_content(client):
    document_id = await _doc_id(
        client, "atomic", OLD_TEXT, "Hardware"
    )

    new_files = {
        "file": ("doc.md", NEW_TEXT.encode(), "text/markdown"),
        "metadata": '{"title":"ML Consulting","source":"atomic"}'
    }
    put = await client.put(f"/documents/{document_id}", **multipart(new_files))
    assert put.status_code == 200, put.text
    assert put.json()["document_id"] == document_id

    resp = await client.post(
        "/query",
        json={"query": "machine learning retail consulting", "top_k": 5},
    )
    assert resp.status_code == 200
    texts = [r["text"] for r in resp.json()["results"]]
    assert any("machine learning" in t.lower() or "machine-learning" in t.lower() for t in texts)
    assert not any("hardware networking" in t.lower() for t in texts)


@pytest.mark.asyncio
async def test_delete_removes_document_from_search(client):
    document_id = await _doc_id(client, "delq", SMOKE_MARKDOWN, "Smoke")

    resp = await client.delete(f"/documents/{document_id}")
    assert resp.status_code == 200

    resp = await client.post(
        "/query", json={"query": "Robusta inbound sales triage", "top_k": 5}
    )
    assert resp.status_code == 200
    assert resp.json()["result_count"] == 0


@pytest.mark.asyncio
async def test_query_never_returns_non_ready_documents(client):
    from app.models import Document
    from tests.conftest import test_engine

    document_id = await _doc_id(client, "status", "Obsolete content never to surface.", "Old")
    async with test_engine.connect() as conn:
        await conn.execute(
            Document.__table__.update().where(
                Document.id == document_id
            ).values(status="failed")
        )
        await conn.commit()

    resp = await client.post(
        "/query", json={"query": "obsolete content", "top_k": 5}
    )
    assert resp.json()["result_count"] == 0


@pytest.mark.asyncio
async def test_failed_embedding_marks_document_failed(client, monkeypatch):
    import app.services.embeddings as embeddings_module

    async def boom(_chunks):
        raise RuntimeError("embedding service unavailable")

    monkeypatch.setattr(embeddings_module.embeddings, "embed_texts", boom)

    files = {
        "file": ("fail.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"Fail","source":"fail"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 500

    listing = await client.get("/documents", params={"status": "failed"})
    assert listing.status_code == 200
    items = listing.json()["items"]
    assert len(items) == 1
    assert items[0]["title"] == "Fail"
    assert items[0]["status"] == "failed"