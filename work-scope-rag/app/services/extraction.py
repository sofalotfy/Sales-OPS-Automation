from __future__ import annotations

import io

import fitz

from app.core.config import settings
from app.core.errors import bad_request, validation_error

# Accepted content types mapped to internal file_type values
CONTENT_TYPE_TO_FILETYPE = {
    "text/plain": "text",
    "text/markdown": "markdown",
    "application/pdf": "pdf",
}


class ExtractionService:
    """Validates and extracts text from uploaded document files."""

    def __init__(self, max_size_mb: int | None = None):
        self.max_size_mb = max_size_mb or settings.max_file_size_mb

    def extract(
        self, filename: str, content_type: str, data: bytes
    ) -> tuple[str, str]:
        if content_type not in CONTENT_TYPE_TO_FILETYPE:
            raise bad_request(
                f"Unsupported file type '{content_type}'. "
                "Supported types: text/plain, text/markdown, application/pdf."
            )

        if len(data) > self.max_size_mb * 1024 * 1024:
            raise validation_error(
                f"File exceeds the {self.max_size_mb} MB size limit."
            )

        file_type = CONTENT_TYPE_TO_FILETYPE[content_type]

        if file_type == "pdf":
            text = self._extract_pdf(data)
        else:
            text = self._decode_text(data)

        text = text.strip()

        if not text:
            detail = (
                "PDF contains no extractable text; upload a text-based PDF."
                if file_type == "pdf"
                else "Document contains no text content."
            )
            if file_type != "pdf":
                raise bad_request(detail)
            raise validation_error(detail)

        return text, file_type

    def _extract_pdf(self, data: bytes) -> str:
        try:
            doc = fitz.open(stream=data, filetype="pdf")
        except Exception as exc:  # corrupt/encrypted PDF
            raise validation_error(
                f"PDF could not be read: {exc}"
            ) from exc
        try:
            if doc.needs_pass:
                raise validation_error(
                    "PDF is password-protected; upload an open PDF."
                )
            texts = [page.get_text() for page in doc]
            return "\n".join(texts)
        finally:
            doc.close()

    def _decode_text(self, data: bytes) -> str:
        try:
            return data.decode("utf-8")
        except UnicodeDecodeError as exc:
            raise validation_error(
                "Document is not valid UTF-8 text."
            ) from exc