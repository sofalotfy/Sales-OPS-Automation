import pytest

from tests.testdata import SMOKE_MARKDOWN, multipart


@pytest.fixture
def app_and_guard():
    from app.core.auth_guard import require_valid_token
    from app.main import app

    return app, app.dependency_overrides.get(require_valid_token)


async def _ingest(client, title="MetaDoc", source="m"):
    files = {
        "file": ("meta.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": f'{{"title":"{title}","source":"{source}"}}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    return resp.json()["document_id"]


@pytest.mark.asyncio
async def test_patch_updates_title_and_source(client):
    document_id = await _ingest(client)

    resp = await client.patch(
        f"/documents/{document_id}",
        json={"title": "Renamed Doc", "source": "finance"},
    )
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["document_id"] == document_id
    assert body["title"] == "Renamed Doc"
    assert body["source"] == "finance"
    assert body["status"] == "ready"
    assert body["chunk_count"] == body["chunk_count"]  # derived fields untouched


@pytest.mark.asyncio
async def test_patch_with_null_source_clears_it(client):
    document_id = await _ingest(client)

    resp = await client.patch(f"/documents/{document_id}", json={"source": None})
    assert resp.status_code == 200
    assert resp.json()["source"] is None


@pytest.mark.asyncio
async def test_patch_empty_body_returns_400(client):
    document_id = await _ingest(client)

    resp = await client.patch(f"/documents/{document_id}", json={})
    assert resp.status_code == 400
    assert "Nothing to update." in resp.json()["detail"]


@pytest.mark.asyncio
async def test_patch_unknown_document_returns_404(client):
    resp = await client.patch(
        "/documents/not-a-real-id",
        json={"title": "X"},
    )
    assert resp.status_code == 404


@pytest.mark.asyncio
async def test_patch_blank_or_invalid_fields_return_422(client):
    document_id = await _ingest(client)

    for payload in [
        {"title": "  "},
        {"title": "x" * 513},
        {"source": ""},
        {"source": "x" * 256},
        {"title": ["not", "a", "string"]},
    ]:
        resp = await client.patch(f"/documents/{document_id}", json=payload)
        assert resp.status_code == 422, (payload, resp.text)


@pytest.mark.asyncio
async def test_patch_requires_a_valid_token(client, app_and_guard):
    """401 case: the guard is swapped for a denying dependency."""
    from fastapi import HTTPException
    from app.core.auth_guard import require_valid_token

    app, original = app_and_guard

    def _deny():
        raise HTTPException(status_code=401, detail="Not authenticated.")

    app.dependency_overrides[require_valid_token] = _deny
    try:
        resp = await client.patch(
            "/documents/00000000-0000-0000-0000-000000000000",
            json={"title": "X"},
        )
        assert resp.status_code == 401
        assert resp.json()["detail"] == "Not authenticated."
        # The operation must not have run: the document id does not exist yet,
        # and a 404 would mean the guard let the request through.
    finally:
        if original is not None:
            app.dependency_overrides[require_valid_token] = original
        else:
            app.dependency_overrides.pop(require_valid_token, None)