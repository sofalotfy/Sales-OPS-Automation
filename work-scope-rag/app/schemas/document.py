from pydantic import BaseModel, Field, field_validator


class DocumentSummary(BaseModel):
    document_id: str
    title: str
    source: str | None = None
    file_type: str
    status: str
    chunk_count: int
    original_available: bool
    created_at: str
    updated_at: str


class IngestResponse(DocumentSummary):
    pass


class DocumentListResponse(BaseModel):
    items: list[DocumentSummary]
    total: int
    limit: int
    offset: int


class ReplaceResponse(DocumentSummary):
    pass


class DeleteResponse(BaseModel):
    deleted: bool = Field(default=True)
    document_id: str


class DocumentContentResponse(BaseModel):
    """Reconstructed extracted text plus the read-only fields (preview view)."""

    document_id: str
    title: str
    source: str | None = None
    file_type: str
    status: str
    char_count: int
    chunk_count: int
    original_available: bool
    content: str


class UpdateMetadataRequest(BaseModel):
    """Metadata-only change for PATCH /documents/{document_id}.

    `title` present must be a non-blank 1-512 char string. `source` may be a
    non-blank 1-255 char string, or explicit `null` to clear it.
    """

    title: str | None = Field(default=None, max_length=512)
    source: str | None = Field(default=None, max_length=255)

    @field_validator("title", "source")
    @classmethod
    def strip_and_reject_blank(cls, value: str | None) -> str | None:
        if value is None:
            return None
        stripped = value.strip()
        if not stripped:
            raise ValueError("must not be blank")
        return stripped