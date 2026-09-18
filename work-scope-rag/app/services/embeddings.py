import asyncio
from functools import lru_cache

from sentence_transformers import SentenceTransformer

from app.core.config import settings

_EMBEDDING_DIMENSION = 384


@lru_cache(maxsize=1)
def _get_model() -> SentenceTransformer:
    return SentenceTransformer(settings.embedding_model, device="cpu")


class EmbeddingsService:
    """Thin wrapper isolating the embedding model so it can be swapped later."""

    def dimension(self) -> int:
        return _EMBEDDING_DIMENSION

    def model_name(self) -> str:
        return settings.embedding_model

    def _embed_texts(self, texts: list[str]) -> list[list[float]]:
        if not texts:
            return []
        model = _get_model()
        vectors = model.encode(texts, normalize_embeddings=True)
        return vectors.tolist()

    async def embed_texts(self, texts: list[str]) -> list[list[float]]:
        return await asyncio.to_thread(self._embed_texts, texts)

    async def embed_query(self, text: str) -> list[float]:
        vectors = await self.embed_texts([text])
        return vectors[0]


embeddings = EmbeddingsService()