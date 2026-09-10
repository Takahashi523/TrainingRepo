from fastapi import APIRouter, Depends, status
from sqlalchemy.orm import Session

from app.models.db import get_db
from app.models.schemas import ProfileSummaryRequest, ProfileSummaryResponse, ErrorResponse
from app.services.matching_service import generate_profile_summary

router = APIRouter()

@router.post(
    "/api/v1/ai/profile-summary", 
    response_model=ProfileSummaryResponse,
    status_code=status.HTTP_200_OK,
    summary="人材プロフィール要約文生成（E2）",
    description=(
        "engineers.appeal_note を入力として、AIが強み・特徴を要約したプロフィール紹介文を生成し、\n"
        "ai_summary / ai_summary_generated_at を返却します。\n"
        "DBへの保存は行いません（スコアリングロジック設計書 v0.7 §1.3：保存責務は Laravel 側）。"
    ),
    responses={
        status.HTTP_404_NOT_FOUND: {"model": ErrorResponse, "description": "エンジニアが見つかりません。"}
    }
)
def profile_summary(request: ProfileSummaryRequest, db: Session = Depends(get_db)):
    """
    E2 人材プロフィール要約エンドポイント。

    例外は捕捉せず、すべて main.py の app レベル例外ハンドラに委ねる（設計書 v0.7 §4.3 / §4.4）。
    EngineerNotFoundError → 404 / BedrockError → 504 / その他 → 500 INTERNAL_ERROR が、
    いずれもフラット形式で返る。ここで握って HTTPException に変換してはならない理由は
    main.py のハンドラ群を参照。
    """
    # サービス層を呼び出し。appeal_note は Python 側が DB から取得する（設計書 v0.7 §4.3 準拠）
    ai_summary, generated_at = generate_profile_summary(
        db=db,
        engineer_id=request.engineer_id,
    )

    return ProfileSummaryResponse(
        engineer_id=request.engineer_id,
        ai_summary=ai_summary,
        ai_summary_generated_at=generated_at.isoformat(),  # ISO8601形式文字列に変換
    )
