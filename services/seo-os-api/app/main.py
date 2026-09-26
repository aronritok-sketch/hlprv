"""HelloProVision SEO OS – API.

Csak a WordPress bővítmény hívja (aláírt kérésekkel), a böngésző közvetlenül nem.
"""

from fastapi import FastAPI, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from .jobs.registry import load_handlers
from .routers import core, dashboard, intelligence, projects, research


def create_app() -> FastAPI:
    app = FastAPI(title="HelloProVision SEO OS", version="0.1.0", docs_url=None, redoc_url=None)

    @app.exception_handler(HTTPException)
    async def http_error(request: Request, exc: HTTPException):
        detail = exc.detail
        if isinstance(detail, dict):
            return JSONResponse(detail, status_code=exc.status_code)
        return JSONResponse({"message": str(detail)}, status_code=exc.status_code)

    @app.exception_handler(RequestValidationError)
    async def validation_error(request: Request, exc: RequestValidationError):
        problems = []
        for err in exc.errors():
            where = ".".join(str(p) for p in err.get("loc", []) if p != "body")
            msg = str(err.get("msg", "")).removeprefix("Value error, ")
            problems.append(f"{where}: {msg}" if where else msg)
        return JSONResponse({"message": "Hibás adatok.", "problems": problems}, status_code=422)

    app.include_router(core.router)
    app.include_router(dashboard.router)
    app.include_router(projects.router)
    app.include_router(research.router)
    app.include_router(intelligence.router)
    load_handlers()
    return app


app = create_app()
