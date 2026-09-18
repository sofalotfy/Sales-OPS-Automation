from fastapi import APIRouter, Depends

from app.core.auth_guard import require_valid_token
from app.schemas.query import QueryRequest, QueryResponse, QueryResult
from app.services.retrieval import retrieval

router = APIRouter(tags=["query"])


@router.post("/query", response_model=QueryResponse)
async def query_documents(
    request: QueryRequest, _: dict = Depends(require_valid_token)
):
    results = await retrieval.query(
        query_text=request.query,
        top_k=request.top_k,
        filters=request.filters,
    )
    return QueryResponse(
        success=True,
        query=request.query,
        result_count=len(results),
        results=[QueryResult(**r) for r in results],
    )