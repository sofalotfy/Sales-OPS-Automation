import pytest

from tests.testdata import SMOKE_MARKDOWN, multipart


async def _seed(client, title, source, text):
    files = {
        "file": (f"{title}.md", text.encode(), "text/markdown"),
        "metadata": f'{{"title":"{title}","source":"{source}"}}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text
    return resp.json()["document_id"]


TOPIC_A = """Robusta offers RAG-based triage of inbound sales inquiries to
route leads to the correct account executive quickly."""

TOPIC_B = """The company provides automated brief generation that summarizes
meeting outcomes for the sales leadership team."""


@pytest.mark.asyncio
async def test_query_retrieves_relevant_corpus_content(client):
    await _seed(client, "topic-a", "sales", TOPIC_A)
    await _seed(client, "topic-b", "sales", TOPIC_B)

    resp = await client.post(
        "/query",
        json={"query": "triage of inbound leads to account executives", "top_k": 5},
    )
    assert resp.status_code == 200
    results = resp.json()["results"]
    assert len(results) >= 1
    assert "triage" in results[0]["text"].lower()


@pytest.mark.asyncio
async def test_query_metadata_filter_restricts_documents(client):
    await _seed(client, "fa", "finance", "Quarterly revenue reporting details.")
    await _seed(client, "sa", "sales", "Inbound sales lead triage details.")

    resp = await client.post(
        "/query",
        json={
            "query": "revenue reporting",
            "top_k": 5,
            "filters": {"source": "finance"},
        },
    )
    assert resp.status_code == 200
    results = resp.json()["results"]
    assert len(results) >= 1
    assert all(r["source"] == "finance" for r in results)