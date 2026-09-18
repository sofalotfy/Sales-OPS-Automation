from typing import Any

from pydantic import BaseModel, Field, field_validator


class QueryRequest(BaseModel):
    query: str = Field(min_length=1)
    top_k: int = Field(default=5, ge=1, le=20)
    filters: dict[str, Any] | None = None

    @field_validator("query")
    @classmethod
    def strip_nonempty(cls, value: str) -> str:
        value = value.strip()
        if not value:
            raise ValueError("query must not be empty.")
        return value


class QueryResult(BaseModel):
    rank: int
    text: str
    similarity_score: float
    document_id: str
    source: str | None = None
    title: str
    chunk_index: int


class QueryResponse(BaseModel):
    success: bool = True
    query: str
    result_count: int
    results: list[QueryResult]