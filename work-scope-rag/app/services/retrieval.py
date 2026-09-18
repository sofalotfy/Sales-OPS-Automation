from __future__ import annotations

from sqlalchemy import select

from app.core.config import settings
from app.core.db import async_session_factory
from app.models import Chunk, Document
from app.services.embeddings import embeddings


class RetrievalService:
    """Semantic similarity search over stored chunks (pgvector)."""

    async def query(
        self,
        query_text: str,
        top_k: int = 5,
        filters: dict | None = None,
    ) -> list[dict]:
        top_k = min(max(int(top_k), 1), settings.max_top_k)

        query_vector = await embeddings.embed_query(query_text)
        distance = Chunk.embedding.cosine_distance(query_vector)
        score_expr = (1 - distance).label("score")

        stmt = (
            select(Chunk, Document, score_expr)
            .join(Document, Chunk.document_id == Document.id)
            .where(Document.status == "ready")
            .order_by(distance.asc())
            .limit(top_k)
        )
        if filters:
            stmt = stmt.where(Document.metadata_col.contains(filters))

        async with async_session_factory() as session:
            rows = (await session.execute(stmt)).all()

        results: list[dict] = []
        for chunk, doc, score in rows:
            score = float(score)
            if score < settings.relevance_threshold:
                continue
            results.append(
                {
                    "rank": len(results) + 1,
                    "text": chunk.text,
                    "similarity_score": round(score, 4),
                    "document_id": doc.id,
                    "source": doc.source,
                    "title": doc.title,
                    "chunk_index": chunk.chunk_index,
                }
            )
        return results


retrieval = RetrievalService()