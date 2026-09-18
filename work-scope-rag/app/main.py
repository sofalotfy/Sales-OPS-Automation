from fastapi import FastAPI
from fastapi.responses import RedirectResponse

from app.api import documents, queries
from app.migrations.bootstrap import lifespan

app = FastAPI(title="work-scope-rag", lifespan=lifespan)

app.include_router(documents.router)
app.include_router(queries.router)


@app.get("/", include_in_schema=False)
async def root():
    return RedirectResponse(url="/docs")


@app.get("/health")
def health():
    return {"status": "ok"}