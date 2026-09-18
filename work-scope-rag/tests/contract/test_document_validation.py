import pytest

from tests.testdata import SMOKE_MARKDOWN, make_empty_pdf, multipart

BIG_BODY = b"a" * (11 * 1024 * 1024)


@pytest.mark.asyncio
async def test_reject_unsupported_content_type(client):
    files = {
        "file": ("scope.exe", SMOKE_MARKDOWN.encode(), "application/octet-stream"),
        "metadata": '{"title":"x","source":"test"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 400


@pytest.mark.asyncio
async def test_reject_empty_document(client):
    files = {
        "file": ("empty.md", b"", "text/markdown"),
        "metadata": '{"title":"empty","source":"test"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 400


@pytest.mark.asyncio
async def test_reject_oversized_document(client):
    files = {
        "file": ("big.md", BIG_BODY, "text/markdown"),
        "metadata": '{"title":"big","source":"test"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 422


@pytest.mark.asyncio
async def test_reject_pdf_without_extractable_text(client):
    files = {
        "file": ("blank.pdf", make_empty_pdf(), "application/pdf"),
        "metadata": '{"title":"blank","source":"test"}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 422


@pytest.mark.asyncio
async def test_duplicate_document_rejected_with_409(client):
    files = lambda: {
        "file": ("dup.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"Dup","source":"dup"}'
    }
    first = await client.post("/documents", **multipart(files()))
    assert first.status_code == 201
    second = await client.post("/documents", **multipart(files()))
    assert second.status_code == 409


@pytest.mark.asyncio
async def test_reject_missing_file_with_400(client):
    resp = await client.post(
        "/documents", data={"metadata": '{"title":"x","source":"test"}'}
    )
    assert resp.status_code == 400

    resp = await client.put(
        "/documents/00000000-0000-0000-0000-000000000000",
        data={"metadata": '{"title":"x","source":"test"}'},
    )
    assert resp.status_code == 400


@pytest.mark.asyncio
async def test_reject_empty_metadata_object(client):
    files = {
        "file": ("blank.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": "{}"
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 422


@pytest.mark.asyncio
async def test_reject_blank_metadata_values(client):
    files = {
        "file": ("blank.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"source":"  "}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 422