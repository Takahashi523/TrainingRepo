from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from app.routers import matching, profile
from app.services.bedrock_service import BedrockError
from app.services.matching_service import EngineerNotFoundError, NoActiveCandidateError

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


@app.exception_handler(NoActiveCandidateError)
async def no_active_candidate_handler(request: Request, exc: NoActiveCandidateError):
    return JSONResponse(
        status_code=422,
        content={"error_code": "NO_ACTIVE_PROJECT", "message": str(exc)},
    )


@app.exception_handler(BedrockError)
async def bedrock_error_handler(request: Request, exc: BedrockError):
    # Bedrock タイムアウト（リトライ後も失敗）は 504 UPSTREAM_TIMEOUT（スコアリングロジック設計書 §4.2）
    return JSONResponse(
        status_code=504,
        content={"error_code": "UPSTREAM_TIMEOUT", "message": str(exc)},
    )
