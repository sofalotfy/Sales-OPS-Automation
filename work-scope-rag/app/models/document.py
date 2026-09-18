from datetime import datetime, timezone

from sqlalchemy import DateTime, Integer, LargeBinary, String, Text
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.models.base import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class Document(Base):
    __tablename__ = "documents"

    id: Mapped[str] = mapped_column(
        UUID(as_uuid=False),
        primary_key=True,
        default=lambda: __import__("uuid").uuid4().hex,
    )
    title: Mapped[str] = mapped_column(String(512))
    source: Mapped[str | None] = mapped_column(String(255))
    file_type: Mapped[str] = mapped_column(String(32))
    content_hash: Mapped[str] = mapped_column(String(64), index=True)
    original_data: Mapped[bytes | None] = mapped_column(LargeBinary)
    original_filename: Mapped[str | None] = mapped_column(String(255))
    metadata_col: Mapped[dict] = mapped_column(
        "metadata", JSONB, default=dict
    )
    status: Mapped[str] = mapped_column(
        String(16), default="processing", index=True
    )
    embedding_model: Mapped[str | None] = mapped_column(String(255))
    char_count: Mapped[int] = mapped_column(Integer, default=0)
    chunk_count: Mapped[int] = mapped_column(Integer, default=0)
    error: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=utcnow, onupdate=utcnow
    )

    chunks: Mapped[list["Chunk"]] = relationship(
        back_populates="document",
        cascade="all, delete-orphan",
        passive_deletes=True,
        order_by="Chunk.chunk_index",
    )

    def to_summary(self) -> dict:
        return {
            "document_id": self.id,
            "title": self.title,
            "source": self.source,
            "file_type": self.file_type,
            "status": self.status,
            "chunk_count": self.chunk_count,
            "original_available": self.original_data is not None,
            "created_at": self.created_at.isoformat(),
            "updated_at": self.updated_at.isoformat(),
        }