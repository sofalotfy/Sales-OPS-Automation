import fitz


def multipart(files: dict) -> dict:
    """Split a {file, metadata} dict into httpx data/files parts.

    httpx sends every value in ``files=`` as an uploaded file, so the JSON
    metadata string must ride in ``data=`` to be bound as a Form field.
    """
    return {
        "data": {"metadata": files["metadata"]},
        "files": {"file": files["file"]},
    }


def make_pdf(text: str = "Robusta offers inbound sales triage services.") -> bytes:
    doc = fitz.open()
    page = doc.new_page()
    page.insert_text((72, 72), text)
    return doc.tobytes()


def make_empty_pdf() -> bytes:
    doc = fitz.open()
    doc.new_page()
    return doc.tobytes()


SMOKE_MARKDOWN = """# Robusta Company Scope

Robusta Technologies provides inbound sales intelligence services.

## Services

- Triage of inbound sales inquiries using retrieval-augmented generation.
- Enrichment of leads with industry and company data.
- Automated client brief generation for sales meetings.

## Coverage

Our scope covers enterprise software companies in Europe and North America.
"""

PDF_TEXT = "Robusta Technologies specializes in RAG for inbound sales teams."