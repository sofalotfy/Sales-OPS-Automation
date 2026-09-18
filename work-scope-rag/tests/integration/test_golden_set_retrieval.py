import pytest

from tests.golden_set import GOLDEN_CORPUS, GOLDEN_QA, MIN_HIT_RATE
from tests.testdata import multipart


async def _seed(client, title, source, text):
    files = {
        "file": (f"{title}.md", text.encode(), "text/markdown"),
        "metadata": f'{{"title":"{title}","source":"{source}"}}'
    }
    resp = await client.post("/documents", **multipart(files))
    assert resp.status_code == 201, resp.text


@pytest.mark.asyncio
async def test_golden_set_retrieval_quality(client):
    for doc in GOLDEN_CORPUS:
        await _seed(client, doc["title"], doc["source"], doc["text"])

    hits = 0
    misses = []
    for qa in GOLDEN_QA:
        resp = await client.post(
            "/query",
            json={"query": qa["query"], "top_k": 5},
        )
        assert resp.status_code == 200, resp.text
        texts = [r["text"].lower() for r in resp.json()["results"]]
        if any(key in text for text in texts for key in qa["keys"]):
            hits += 1
        else:
            misses.append(qa["query"])

    hit_rate = hits / len(GOLDEN_QA)
    assert hit_rate >= MIN_HIT_RATE, (
        f"golden-set hit rate {hit_rate:.0%} below the {MIN_HIT_RATE:.0%} "
        f"target; misses: {misses}"
    )