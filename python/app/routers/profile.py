from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.models.db import get_db
from app.models.schemas import ProfileSummaryRequest, ProfileSummaryResponse
from app.services.matching_service import generate_profile_summary

router = APIRouter()


@router.post("/api/v1/ai/profile-summary", response_model=ProfileSummaryResponse)
def profile_summary(request: ProfileSummaryRequest, db: Session = Depends(get_db)):
    """E2 人材プロフィール要約エンドポイント。
    エラーは main.py の exception_handler で各エラーコードに変換される。
    """
    ai_summary, generated_at = generate_profile_summary(db, request.engineer_id)
    return ProfileSummaryResponse(
        engineer_id=request.engineer_id,
        ai_summary=ai_summary,
        ai_summary_generated_at=generated_at.isoformat(),
    )
