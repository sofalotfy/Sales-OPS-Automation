import pytest

from tests.testdata import SMOKE_MARKDOWN, multipart


@pytest.fixture
def app_and_guard():
    from app.core.auth_guard import require_valid_token
    from app.main import app

    return app, app.dependency_overrides.get(require_valid_token)


async def _ingest(client, filename="orig.md", title="ContentDoc", source="c"):
    files = {
        "file": (filename, SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": f'{{"title":"{title}","source":"{source}"}}',
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    return resp.json()["document_id"]


@pytest.mark.asyncio
async def test_content_returns_reconstructed_text(client):
    document_id = await _ingest(client)

    resp = await client.get(f"/documents/{document_id}/content")
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["document_id"] == document_id
    assert body["title"] == "ContentDoc"
    assert body["source"] == "c"
    assert body["status"] == "ready"
    assert body["chunk_count"] > 0
    assert body["char_count"] > 0
    assert body["original_available"] is True
    assert "Robusta" in body["content"]


@pytest.mark.asyncio
async def test_content_unknown_document_returns_404(client):
    unknown_uuid = "00000000-0000-0000-0000-000000000000"

    resp = await client.get(f"/documents/{unknown_uuid}/content")
    assert resp.status_code == 404

    resp = await client.get("/documents/not-a-real-id/content")
    assert resp.status_code == 404


@pytest.mark.asyncio
async def test_download_returns_exact_original_bytes(client):
    document_id = await _ingest(client, filename="manual.md")

    resp = await client.get(f"/documents/{document_id}/download")
    assert resp.status_code == 200
    assert resp.content == SMOKE_MARKDOWN.encode()
    assert resp.headers["content-type"].startswith("text/markdown")
    disposition = resp.headers["content-disposition"]
    assert disposition.startswith("attachment")
    assert "manual.md" in disposition


@pytest.mark.asyncio
async def test_download_unknown_document_returns_404(client):
    resp = await client.get("/documents/not-a-real-id/download")
    assert resp.status_code == 404


@pytest.mark.asyncio
async def test_content_and_download_require_valid_token(client, app_and_guard):
    from fastapi import HTTPException
    from app.core.auth_guard import require_valid_token

    app, original = app_and_guard

    def _deny():
        raise HTTPException(status_code=401, detail="Not authenticated.")

    app.dependency_overrides[require_valid_token] = _deny
    try:
        doc_url = "/documents/00000000-0000-0000-0000-000000000000"
        for path in (f"{doc_url}/content", f"{doc_url}/download"):
            resp = await client.get(path)
            assert resp.status_code == 401, path
            assert resp.json()["detail"] == "Not authenticated."
            # The id does not exist: a 404 would mean the guard let it through.
    finally:
        if original is not None:
            app.dependency_overrides[require_valid_token] = original
        else:
            app.dependency_overrides.pop(require_valid_token, None)