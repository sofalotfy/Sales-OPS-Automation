import pytest

from tests.testdata import PDF_TEXT, SMOKE_MARKDOWN, make_pdf, multipart


@pytest.mark.asyncio
async def test_ingest_markdown_returns_ready_document(client):
    files = {
        "file": ("scope.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"Scope","source":"test"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    body = resp.json()
    assert body["status"] == "ready"
    assert body["chunk_count"] >= 1
    assert body["title"] == "Scope"
    assert body["source"] == "test"
    assert body["document_id"]


@pytest.mark.asyncio
async def test_ingest_pdf_returns_ready_document(client):
    files = {
        "file": ("scope.pdf", make_pdf(PDF_TEXT), "application/pdf"),
        "metadata": '{"title":"Pdf Scope","source":"test"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    body = resp.json()
    assert body["status"] == "ready"
    assert body["chunk_count"] >= 1