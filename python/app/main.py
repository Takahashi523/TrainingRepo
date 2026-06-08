from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from app.routers import matching, profile
from app.services.bedrock_service import BedrockError
from app.services.matching_service import EngineerNotFoundError

app = FastAPI(
    title="Nexus Matching Engine",
    description="マッチングスコア計算・AIプロフィール要約 API",
    version="1.0.0",
)

app.include_router(matching.router)
app.include_router(profile.router)


@app.exception_handler(EngineerNotFoundError)
async def engineer_not_found_handler(request: Request, exc: EngineerNotFoundError):
    return JSONResponse(
        status_code=404,
        content={"error_code": "ENGINEER_NOT_FOUND", "message": str(exc)},
    )


@app.exception_handler(BedrockError)
async def bedrock_error_handler(request: Request, exc: BedrockError):
    return JSONResponse(
        status_code=502,
        content={"error_code": "EXTERNAL_API_ERROR", "message": str(exc)},
    )
