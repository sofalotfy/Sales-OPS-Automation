from fastapi import FastAPI
from fastapi.responses import RedirectResponse

from app.api import auth, users
from app.migrations.bootstrap import lifespan

app = FastAPI(title="auth-service", lifespan=lifespan)

app.include_router(auth.router)
app.include_router(users.router)


@app.get("/", include_in_schema=False)
async def root():
    return RedirectResponse(url="/docs")


@app.get("/health")
def health():
    return {"status": "ok"}