from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.models.db import get_db
from app.models.schemas import ProfileSummaryRequest, ProfileSummaryResponse, ErrorResponse
from app.services.matching_service import generate_profile_summary, EngineerNotFoundError

router = APIRouter()

@router.post(
    "/api/v1/ai/profile-summary", 
    response_model=ProfileSummaryResponse,
    status_code=status.HTTP_200_OK,
    summary="最新のAI活用方針に準拠したプロフィール要約文生成（E2）",
    description=(
        "画面から入力された『アピールポイント』と『フリーテキストスキル』を基に、\n"
        "AIが表記ゆれを吸収して肉付けしたプロフィール紹介文を生成し、DBのエンジニア情報を更新します。"
    ),
    responses={
        status.HTTP_404_NOT_FOUND: {"model": ErrorResponse, "description": "エンジニアが見つかりません。"}
    }
)
def profile_summary(request: ProfileSummaryRequest, db: Session = Depends(get_db)):
    """
    E2 人材プロフィール要約エンドポイント。
    """
    try:
        # 吉田さんが最新のAI方針（経歴書廃止版）で完璧に肉付けしたサービス層を呼び出し！
        ai_summary, generated_at = generate_profile_summary(
            db=db,
            engineer_id=request.engineer_id,
            appeal_point=request.appeal_point,  # 画面から受け取ったアピールポイントを注入
            raw_skills=request.raw_skills        # 画面から受け取ったフリースキルを注入
        )
        
        return ProfileSummaryResponse(
            engineer_id=request.engineer_id,
            ai_summary=ai_summary,
            ai_summary_generated_at=generated_at.isoformat(),  # ISO8601形式文字列に変換
        )

    except EngineerNotFoundError as e:
        # サービス層で発生したエンジニア不存例外を正確にキャッチし、404エラーで返却
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={"error_code": "ENGINEER_NOT_FOUND", "message": str(e)}
        )
        
    except Exception as e:
        # その他の予期せぬエラー（インフラ層やタイムアウトなど）に対する防衛策
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={"error_code": "INTERNAL_SERVER_ERROR", "message": f"予期せぬエラーが発生しました: {str(e)}"}
        )
