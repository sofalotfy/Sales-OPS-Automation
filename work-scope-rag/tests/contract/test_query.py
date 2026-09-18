import pytest

from tests.testdata import SMOKE_MARKDOWN, multipart

QUERY_BODY = {
    "query": "What does Robusta offer for inbound sales?",
    "top_k": 5,
    "filters": {"source": "test"},
}


@pytest.mark.asyncio
async def test_query_returns_ranked_results_with_scores(client):
    seed = {
        "file": ("seed.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
        "metadata": '{"title":"Seed","source":"test"}'
    }
    seeded = await client.post("/documents", **multipart(seed))
    assert seeded.status_code == 201, seeded.text

    resp = await client.post("/query", json=QUERY_BODY)
    assert resp.status_code == 200, resp.text
    body = resp.json()
    assert body["success"] is True
    assert body["result_count"] >= 1
    assert len(body["results"]) == body["result_count"]
    scores = [r["similarity_score"] for r in body["results"]]
    assert scores == sorted(scores, reverse=True)
    for r in body["results"]:
        assert 0 <= r["similarity_score"] < 1
        assert r["text"]
        assert r["document_id"]
        assert "similarity_score" in r
        assert "rank" in r


@pytest.mark.asyncio
async def test_query_unknown_term_returns_empty_set(client):
    resp = await client.post(
        "/query",
        json={"query": "zzz completely unrelated qqq", "top_k": 5},
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["success"] is True
    assert body["result_count"] == 0
    assert body["results"] == []


@pytest.mark.asyncio
async def test_query_rejects_empty_query(client):
    resp = await client.post("/query", json={"query": "", "top_k": 5})
    assert resp.status_code == 422


@pytest.mark.asyncio
async def test_query_rejects_bad_top_k(client):
    resp = await client.post(
        "/query", json={"query": "sales", "top_k": 0}
    )
    assert resp.status_code == 422
    resp = await client.post(
        "/query", json={"query": "sales", "top_k": 21}
    )
    assert resp.status_code == 422


@pytest.mark.asyncio
async def test_query_rejects_non_object_filters(client):
    resp = await client.post(
        "/query", json={"query": "sales", "top_k": 5, "filters": ["x"]}
    )
    assert resp.status_code == 422