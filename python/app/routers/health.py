from fastapi import APIRouter

from app.models.schemas import HealthResponse

router = APIRouter(tags=["health"])


@router.get(
    "/api/v1/health",
    response_model=HealthResponse,
    summary="ヘルスチェック（E3）",
)
def health_check() -> HealthResponse:
    return HealthResponse(status="ok")
