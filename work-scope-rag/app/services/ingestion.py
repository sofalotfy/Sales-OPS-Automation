from __future__ import annotations

import uuid

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.db import async_session_factory
from app.core.errors import (
    conflict,
    internal_error,
    not_found,
)
from app.models import Chunk, Document
from app.services.chunking import SemanticChunker
from app.services.embeddings import embeddings
from app.services.extraction import ExtractionService


class IngestionService:
    """Synchronous ingestion pipeline: extract -> chunk -> embed -> store."""

    def __init__(self):
        self.extraction = ExtractionService()
        self.chunker = SemanticChunker()

    async def ingest(
        self,
        filename: str,
        content_type: str,
        data: bytes,
        metadata: dict,
    ) -> dict:
        text, file_type = self.extraction.extract(
            filename, content_type, data
        )
        content_hash = self._content_hash(text)

        title = (metadata.get("title") or _stem(filename)).strip()
        source = metadata.get("source")

        async with async_session_factory() as session:
            await self._reject_duplicate(session, content_hash, source, title)

            document = Document(
                id=uuid.uuid4().hex,
                title=title,
                source=source,
                file_type=file_type,
                content_hash=content_hash,
                original_data=data,
                original_filename=filename,
                metadata_col=metadata,
                status="processing",
                char_count=len(text),
                chunk_count=0,
            )
            session.add(document)
            await session.commit()
            document_id = document.id

        try:
            chunks = await self.chunker.chunk_text(text)
            vectors = await embeddings.embed_texts(chunks)
        except Exception as exc:
            await self._mark_failed(document_id, exc)
            raise internal_error(f"Ingestion failed: {exc}") from exc

        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            document.embedding_model = embeddings.model_name()

            for index, (chunk_text, vector) in enumerate(
                zip(chunks, vectors)
            ):
                session.add(
                    Chunk(
                        id=uuid.uuid4().hex,
                        document_id=document_id,
                        chunk_index=index,
                        text=chunk_text,
                        embedding=vector,
                        token_count=len(chunk_text.split()),
                    )
                )

            document.status = "ready"
            document.chunk_count = len(chunks)
            await session.commit()
            await session.refresh(document)
            return document.to_summary()

    async def replace(
        self, document_id: str, filename: str, content_type: str, data: bytes, metadata: dict
    ) -> dict:
        text, file_type = self.extraction.extract(filename, content_type, data)
        content_hash = self._content_hash(text)
        title = (metadata.get("title") or _stem(filename)).strip()
        source = metadata.get("source")

        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            if document is None:
                raise not_found("Document not found.")
            await self._reject_duplicate(
                session, content_hash, source, title, exclude_id=document_id
            )

        try:
            chunks = await self.chunker.chunk_text(text)
            vectors = await embeddings.embed_texts(chunks)
        except Exception as exc:
            await self._mark_failed(document_id, exc)
            raise internal_error(f"Replacement failed: {exc}") from exc

        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            if document is None:
                raise not_found("Document not found.")

            model_name = embeddings.model_name()

            await session.execute(
                Chunk.__table__.delete().where(
                    Chunk.document_id == document_id
                )
            )
            for index, (chunk_text, vector) in enumerate(
                zip(chunks, vectors)
            ):
                session.add(
                    Chunk(
                        id=uuid.uuid4().hex,
                        document_id=document_id,
                        chunk_index=index,
                        text=chunk_text,
                        embedding=vector,
                        token_count=len(chunk_text.split()),
                    )
                )

            document.title = title
            document.source = source
            document.file_type = file_type
            document.content_hash = content_hash
            document.original_data = data
            document.original_filename = filename
            document.metadata_col = metadata
            document.embedding_model = model_name
            document.char_count = len(text)
            document.chunk_count = len(chunks)
            document.status = "ready"
            document.error = None
            await session.commit()
            await session.refresh(document)
            return document.to_summary()

    async def delete(self, document_id: str) -> dict:
        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            if document is None:
                raise not_found("Document not found.")
            await session.delete(document)
            await session.commit()
            return {"deleted": True, "document_id": document_id}

    async def update_metadata(
        self,
        document_id: str,
        *,
        title: str | None = None,
        update_source: bool = False,
        source: str | None = None,
    ) -> dict:
        """Metadata-only update (FR-006 / document-metadata-api.md).

        Never touches chunks, embeddings, status, or derived fields. `title`
        updates only when non-None; `source` is updated only when
        `update_source` is True (source=null then clears it). The JSONB
        metadata column is kept in sync with the indexed columns.
        """
        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            if document is None:
                raise not_found("Document not found.")

            metadata = dict(document.metadata_col or {})
            if title is not None:
                document.title = title
                metadata["title"] = title
            if update_source:
                document.source = source
                if source is None:
                    metadata.pop("source", None)
                else:
                    metadata["source"] = source

            document.metadata_col = metadata
            await session.commit()
            await session.refresh(document)
            return document.to_summary()

    async def list_documents(
        self, source: str | None, status: str | None, limit: int, offset: int
    ) -> dict:
        async with async_session_factory() as session:
            conditions = []
            if source:
                conditions.append(Document.source == source)
            if status:
                conditions.append(Document.status == status)

            count_stmt = select(func.count()).select_from(Document)
            stmt = select(Document).order_by(Document.created_at.desc())
            if conditions:
                count_stmt = count_stmt.where(*conditions)
                stmt = stmt.where(*conditions)
            stmt = stmt.limit(limit).offset(offset)

            total = await session.scalar(count_stmt)
            documents = (await session.scalars(stmt)).all()
            return {
                "items": [d.to_summary() for d in documents],
                "total": total,
                "limit": limit,
                "offset": offset,
            }

    async def get_content(self, document_id: str) -> dict:
        """Reconstruct the extracted text from stored chunks (read-only view).

        Chunks are kept in reading order (chunk_index) and joined back into a
        continuous document for preview; the authoritative original file is
        served separately via ``get_original``.
        """
        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            if document is None:
                raise not_found("Document not found.")

            stmt = (
                select(Chunk.text)
                .where(Chunk.document_id == document_id)
                .order_by(Chunk.chunk_index)
            )
            texts = (await session.scalars(stmt)).all()

        return {
            "document_id": document.id,
            "title": document.title,
            "source": document.source,
            "file_type": document.file_type,
            "status": document.status,
            "char_count": document.char_count,
            "chunk_count": document.chunk_count,
            "original_available": document.original_data is not None,
            "content": "\n\n".join(texts),
        }

    async def get_original(self, document_id: str) -> tuple[bytes, str | None, str]:
        """Return the stored original upload bytes for download.

        Documents ingested before original-file retention was introduced have
        no stored bytes and are reported as 404 (unavailable).
        """
        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            if document is None:
                raise not_found("Document not found.")
            if document.original_data is None:
                raise not_found("Original file not stored for this document.")
            return document.original_data, document.original_filename, document.file_type

    @staticmethod
    async def _mark_failed(document_id: str, exc: Exception) -> None:
        async with async_session_factory() as session:
            document = await session.get(Document, document_id)
            if document is None:
                return
            document.status = "failed"
            document.error = str(exc)[-500:]
            try:
                await session.commit()
            except Exception:
                await session.rollback()

    @staticmethod
    def _content_hash(text: str) -> str:
        import hashlib

        return hashlib.sha256(text.encode("utf-8")).hexdigest()

    @staticmethod
    async def _reject_duplicate(
        session: AsyncSession,
        content_hash: str,
        source: str | None,
        title: str,
        exclude_id: str | None = None,
    ) -> None:
        stmt = select(Document).where(
            Document.content_hash == content_hash,
            Document.source == source,
            Document.title == title,
            Document.status == "ready",
        )
        if exclude_id is not None:
            stmt = stmt.where(Document.id != exclude_id)
        match = (await session.scalars(stmt)).first()
        if match is not None:
            raise conflict(
                f"A document with the same content already exists "
                f"(document_id={match.id})."
            )


def _stem(filename: str) -> str:
    name = filename.rsplit(".", 1)[0] if "." in filename else filename
    return name or "untitled"