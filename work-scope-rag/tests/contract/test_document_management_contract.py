import pytest

from tests.testdata import SMOKE_MARKDOWN, multipart


async def _ingest(client, filename="m.md", text=SMOKE_MARKDOWN, title="Mgmt", source="m", file_type="text/markdown"):
    files = {
        "file": (filename, text.encode(), file_type),
        "metadata": f'{{"title":"{title}","source":"{source}"}}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    return resp.json()


@pytest.mark.asyncio
async def test_list_documents_with_filters_and_pagination(client):
    await _ingest(client, source="sales", title="Doc1")
    await _ingest(client, source="finance", title="Doc2")

    resp = await client.get("/documents", params={"source": "sales"})
    assert resp.status_code == 200
    body = resp.json()
    assert all(item["source"] == "sales" for item in body["items"])
    assert body["total"] == 1
    assert body["limit"] == 50
    assert body["offset"] == 0

    resp = await client.get("/documents", params={"limit": 1, "offset": 0})
    assert resp.status_code == 200
    body = resp.json()
    assert len(body["items"]) == 1
    assert body["total"] == 2

    resp = await client.get("/documents", params={"status": "ready"})
    assert resp.status_code == 200
    body = resp.json()
    assert all(item["status"] == "ready" for item in body["items"])
    assert body["total"] == 2


@pytest.mark.asyncio
async def test_replace_preserves_document_id(client):
    created = await _ingest(client, filename="replace.md", title="Before", source="r")
    document_id = created["document_id"]

    new_text = "# Replaced\\n\\nCompletely new company scope content for replacement tests."
    files = {
        "file": ("replace.md", new_text.encode(), "text/markdown"),
        "metadata": '{"title":"After","source":"r"}'
    }
    resp = await client.put(f"/documents/{document_id}", **multipart(files))
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["document_id"] == document_id
    assert body["title"] == "After"


@pytest.mark.asyncio
async def test_replace_unknown_document_returns_404(client):
    files = {
        "file": ("x.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"X","source":"x"}'
    }
    resp = await client.put("/documents/does-not-exist", **multipart(files))
    assert resp.status_code == 404


@pytest.mark.asyncio
async def test_delete_document_and_missing_returns_404(client):
    created = await _ingest(client, filename="del.md", title="DelDoc", source="d")
    document_id = created["document_id"]

    resp = await client.delete(f"/documents/{document_id}")
    assert resp.status_code == 200
    assert resp.json()["deleted"] is True

    resp = await client.delete(f"/documents/{document_id}")
    assert resp.status_code == 404