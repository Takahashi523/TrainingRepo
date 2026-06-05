from fastapi import APIRouter
from app.models.schemas import HealthResponse, MatchingRequest, MatchingResponse

router = APIRouter()


@router.get("/api/v1/health", response_model=HealthResponse)
def health_check():
    return HealthResponse(status="ok")


@router.post("/api/v1/matching/calculate", response_model=MatchingResponse)
def calculate_matching(request: MatchingRequest):
    # Step 3〜6 で実装予定
    raise NotImplementedError
