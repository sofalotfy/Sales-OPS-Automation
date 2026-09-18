from __future__ import annotations

from app.services.embeddings import embeddings


def _dot(a: list[float], b: list[float]) -> float:
    return sum(x * y for x, y in zip(a, b))


class SemanticChunker:
    """Splits document text into semantic chunks using embedding similarity.

    Ported and adapted from the prototype chunker (rag/chunker.py) with the
    same single embedding model used for retrieval (research.md decision 2/4).
    Vectors from embeddings.embed_texts are L2-normalized, so pairwise dot
    products equal cosine similarity without an extra sklearn dependency.
    """

    def __init__(
        self,
        similarity_threshold: float = 0.55,
        max_sentences: int = 8,
    ):
        self.similarity_threshold = similarity_threshold
        self.max_sentences = max_sentences

    async def chunk_text(self, text: str) -> list[str]:
        sentences = self._split_sentences(text)
        if not sentences:
            return []

        if len(sentences) <= self.max_sentences:
            return [" ".join(sentences)]

        vectors = await embeddings.embed_texts(sentences)
        chunks: list[str] = []
        current: list[str] = [sentences[0]]

        for i in range(1, len(sentences)):
            similarity = _dot(vectors[i - 1], vectors[i])
            should_split = (
                similarity < self.similarity_threshold
                or len(current) >= self.max_sentences
            )
            if should_split:
                chunks.append(" ".join(current))
                current = [sentences[i]]
            else:
                current.append(sentences[i])

        if current:
            chunks.append(" ".join(current))

        return chunks

    def _split_sentences(self, text: str) -> list[str]:
        sentences: list[str] = []
        for paragraph in text.split("\n\n"):
            paragraph = paragraph.strip()
            if not paragraph:
                continue
            current = ""
            for character in paragraph:
                current += character
                if character in ".!?":
                    sentence = current.strip()
                    if sentence:
                        sentences.append(sentence)
                    current = ""
            if current.strip():
                sentences.append(current.strip())
        return sentences