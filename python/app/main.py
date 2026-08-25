import logging

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.routers import health, matching, profile
from app.services.bedrock_service import BedrockError
from app.services.matching_service import EngineerNotFoundError, NoActiveCandidateError

logger = logging.getLogger(__name__)

app = FastAPI(
    title="Nexus Matching Engine",
    description="マッチングスコア計算・AIプロフィール要約 API",
    version="1.0.0",
)

app.include_router(health.router)
app.include_router(matching.router)
app.include_router(profile.router)


# ---------------------------------------------------------------------------
# 例外ハンドラ群
#
# エラー応答は必ず {"error_code": ..., "message": ...} の **フラット形式** で返す
# （スコアリングロジック設計書 v0.7 §4.4「エラー応答の形式」）。呼び出し側の Laravel は
# HttpMatchingEngineClient::mapErrorResponse() で `$body['error_code']` を **トップレベル**
# で参照して 404/422 を分岐するため、`{"detail": {"error_code": ...}}` の入れ子で返すと
# 判定に失敗し、すべて「上流障害（engine_error）」に落ちてユーザーには
# 「マッチングエンジンとの通信に失敗しました」とだけ表示される。
#
# FastAPI の HTTPException(detail={...}) は既定ハンドラにより必ず detail 配下へ入れ子になる。
# そのためルーター側では例外を捕捉せず（try/except を置かず）、すべて本ファイルのハンドラに
# 到達させること。過去に routers/matching.py・routers/profile.py が HTTPException へ変換して
# おり、404/422 の分岐が本番で機能しない不整合が発生した（PR #59 で発覚・PR #25 で是正）。
#
# なお 500 の error_code は設計書 §4.2 の表に従い INTERNAL_ERROR が正。
# ルーター側にあった INTERNAL_SERVER_ERROR は設計書に存在しない値だった。
#
# ※ 500 応答の形を検証するテストでは TestClient(app, raise_server_exceptions=False) を使うこと。
#   既定（True）だと Starlette の ServerErrorMiddleware が応答返却後に例外を再送出するため、
#   レスポンスを受け取れず「ハンドラが効いていない」と誤読しやすい。
# ---------------------------------------------------------------------------


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    return JSONResponse(
        status_code=400,
        content={"error_code": "INVALID_PARAMETER", "message": str(exc)},
    )


@app.exception_handler(Exception)
async def internal_error_handler(request: Request, exc: Exception):
    logger.error("Unhandled exception", exc_info=exc)
    return JSONResponse(
        status_code=500,
        content={"error_code": "INTERNAL_ERROR", "message": "内部エラーが発生しました"},
    )


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
