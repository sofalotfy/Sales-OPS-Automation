import json
import uuid

from fastapi import APIRouter, Depends, File, Form, Query, Response, UploadFile

from app.core.auth_guard import require_valid_token
from app.core.errors import bad_request, not_found, validation_error
from app.schemas.document import (
    DeleteResponse,
    DocumentContentResponse,
    DocumentListResponse,
    IngestResponse,
    ReplaceResponse,
    UpdateMetadataRequest,
)
from app.services.ingestion import IngestionService

router = APIRouter(tags=["documents"])
ingestion = IngestionService()

FILETYPE_TO_MEDIA = {
    "text": "text/plain",
    "markdown": "text/markdown",
    "pdf": "application/pdf",
}
FILETYPE_TO_EXT = {"text": "txt", "markdown": "md", "pdf": "pdf"}


def _require_valid_id(document_id: str) -> str:
    try:
        uuid.UUID(document_id)
    except ValueError:
        raise not_found("Document not found.")
    return document_id


def _safe_filename(filename: str | None, document_id: str, file_type: str) -> str:
    """Fall back to document_id when the original name is not stored."""
    if filename:
        name = filename.replace('"', "").replace("\r", " ").replace("\n", " ")
        return name.strip() or f"document-{document_id}.{FILETYPE_TO_EXT.get(file_type, 'txt')}"
    return f"document-{document_id}.{FILETYPE_TO_EXT.get(file_type, 'txt')}"


def _parse_metadata(raw: str) -> dict:
    if not raw:
        return {}
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise validation_error(
            f"metadata must be a JSON object string: {exc}"
        ) from exc
    if not isinstance(parsed, dict):
        raise validation_error("metadata must be a JSON object.")
    if not parsed:
        raise validation_error("metadata must not be an empty object.")
    if any(isinstance(value, str) and not value.strip() for value in parsed.values()):
        raise validation_error("metadata values must not be blank.")
    return parsed


@router.post(
    "/documents",
    response_model=IngestResponse,
    status_code=201,
)
async def ingest_document(
    file: UploadFile | None = File(default=None),
    metadata: str = Form("{}"),
    _: dict = Depends(require_valid_token),
):
    if file is None:
        raise bad_request("A file is required.")
    meta = _parse_metadata(metadata)
    content_type = file.content_type or "application/octet-stream"
    data = await file.read()
    result = await ingestion.ingest(
        filename=file.filename or "document",
        content_type=content_type,
        data=data,
        metadata=meta,
    )
    return IngestResponse(**result)


@router.put(
    "/documents/{document_id}",
    response_model=ReplaceResponse,
)
async def replace_document(
    document_id: str,
    file: UploadFile | None = File(default=None),
    metadata: str = Form("{}"),
    _: dict = Depends(require_valid_token),
):
    if file is None:
        raise bad_request("A file is required.")
    _require_valid_id(document_id)
    meta = _parse_metadata(metadata)
    content_type = file.content_type or "application/octet-stream"
    data = await file.read()
    result = await ingestion.replace(
        document_id=document_id,
        filename=file.filename or "document",
        content_type=content_type,
        data=data,
        metadata=meta,
    )
    return ReplaceResponse(**result)


@router.get("/documents", response_model=DocumentListResponse)
async def list_documents(
    source: str | None = Query(default=None),
    status: str | None = Query(default=None),
    limit: int = Query(default=50, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    _: dict = Depends(require_valid_token),
):
    result = await ingestion.list_documents(
        source=source, status=status, limit=limit, offset=offset
    )
    return DocumentListResponse(**result)


@router.get(
    "/documents/{document_id}/content",
    response_model=DocumentContentResponse,
)
async def get_document_content(
    document_id: str,
    _: dict = Depends(require_valid_token),
):
    """Preview endpoint: reconstructed extracted text (additive, read-only)."""
    _require_valid_id(document_id)
    result = await ingestion.get_content(document_id)
    return DocumentContentResponse(**result)


@router.get("/documents/{document_id}/download")
async def download_document(
    document_id: str,
    _: dict = Depends(require_valid_token),
):
    """Stream the stored original upload back to the caller."""
    _require_valid_id(document_id)
    original, filename, file_type = await ingestion.get_original(document_id)
    media_type = FILETYPE_TO_MEDIA.get(file_type, "application/octet-stream")
    safe_name = _safe_filename(filename, document_id, file_type)
    return Response(
        content=original,
        media_type=media_type,
        headers={
            "Content-Disposition": f'attachment; filename="{safe_name}"',
        },
    )


@router.patch(
    "/documents/{document_id}",
    response_model=ReplaceResponse,
)
async def update_document_metadata(
    document_id: str,
    payload: UpdateMetadataRequest,
    _: dict = Depends(require_valid_token),
):
    """Metadata-only edit (FR-006). At least one of title/source required."""
    _require_valid_id(document_id)

    fields = payload.model_fields_set
    if not (fields & {"title", "source"}):
        raise bad_request("Nothing to update.")

    result = await ingestion.update_metadata(
        document_id=document_id,
        title=payload.title,
        update_source="source" in fields,
        source=payload.source,
    )
    return ReplaceResponse(**result)


@router.delete("/documents/{document_id}", response_model=DeleteResponse)
async def delete_document(document_id: str, _: dict = Depends(require_valid_token)):
    _require_valid_id(document_id)
    return await ingestion.delete(document_id)