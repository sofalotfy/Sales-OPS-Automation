import asyncio

import pytest

from tests.testdata import SMOKE_MARKDOWN, multipart

CONCURRENT_INGESTS = 100
CONCURRENT_QUERIES = 500


@pytest.mark.asyncio
async def test_100_concurrent_ingest_requests(client):
    async def ingest(i: int) -> int:
        files = {
            "file": (f"load-{i}.md", SMOKE_MARKDOWN.encode(), "text/markdown"),
            "metadata": f'{{"title":"Load {i}","source":"load"}}'
        }
        resp = await client.post("/documents", **multipart(files))
        return resp.status_code

    results = await asyncio.gather(
        *[ingest(i) for i in range(CONCURRENT_INGESTS)]
    )
    assert all(code == 201 for code in results), f"failures: {results.count(403)} non-201"


@pytest.mark.asyncio
async def test_500_concurrent_query_requests(client):
    async def query(i: int) -> int:
        resp = await client.post(
            "/query",
            json={"query": f"inbound sales triage query number {i}", "top_k": 3},
        )
        return resp.status_code

    results = await asyncio.gather(
        *[query(i) for i in range(CONCURRENT_QUERIES)]
    )
    assert all(code == 200 for code in results), "some queries failed"