from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.models.db import get_db
from app.models.schemas import HealthResponse, MatchingRequest, MatchingResponse, MatchResult
from app.services.matching_service import calculate_matching as run_matching

router = APIRouter()


@router.get("/api/v1/health", response_model=HealthResponse)
def health_check():
    return HealthResponse(status="ok")


@router.post("/api/v1/matching/calculate", response_model=MatchingResponse)
def matching_calculate(request: MatchingRequest, db: Session = Depends(get_db)):
    """E1 マッチングスコア計算エンドポイント。
    エラーは main.py の exception_handler で ENGINEER_NOT_FOUND / EXTERNAL_API_ERROR に変換される。
    """
    output = run_matching(db, request.engineer_id, request.project_ids)
    return MatchingResponse(
        engineer_id=output.engineer_id,
        generated_at=output.generated_at.isoformat(),
        matches=[
            MatchResult(
                project_id=m.project_id,
                match_score=m.match_score,
                match_rank=m.match_rank,
                ai_score_reason=m.ai_score_reason,
                ai_comment=m.ai_comment,
                ai_missing=m.ai_missing,
            )
            for m in output.matches
        ],
    )
