from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

from app.models.db import get_db
from app.models.schemas import ProfileSummaryRequest, ProfileSummaryResponse, ErrorResponse
from app.services.matching_service import generate_profile_summary, EngineerNotFoundError
# BedrockError は main.py の @app.exception_handler(BedrockError) で 504 に変換するため、
# ここでは except Exception に握りつぶされないよう明示的に import して再送出する
from app.services.bedrock_service import BedrockError

router = APIRouter()

@router.post(
    "/api/v1/ai/profile-summary", 
    response_model=ProfileSummaryResponse,
    status_code=status.HTTP_200_OK,
    summary="人材プロフィール要約文生成（E2）",
    description=(
        "engineers.appeal_note を入力として、AIが強み・特徴を要約したプロフィール紹介文を生成し、\n"
        "ai_summary / ai_summary_generated_at を返却します。\n"
        "DBへの保存は行いません（スコアリングロジック設計書 v0.6 §1.3：保存責務は Laravel 側）。"
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
        # サービス層を呼び出し。appeal_note は Python 側が DB から取得する（スコアリングロジック設計書 v0.6 §4.3 準拠）
        ai_summary, generated_at = generate_profile_summary(
            db=db,
            engineer_id=request.engineer_id,
        )
        
        return ProfileSummaryResponse(
            engineer_id=request.engineer_id,
            ai_summary=ai_summary,
            ai_summary_generated_at=generated_at.isoformat(),  # ISO8601形式文字列に変換
        )

    except EngineerNotFoundError:
        # ここで HTTPException に変換せず、そのまま re-raise する（BedrockError と同じ方式）。
        # main.py の @app.exception_handler(EngineerNotFoundError) が捕捉し、
        # 404 ENGINEER_NOT_FOUND をトップレベル形式 {"error_code": ..., "message": ...} で返す。
        # HTTPException に変換すると {"detail": {"error_code": ...}} と入れ子になり、
        # OpenAPI の responses={404: {"model": ErrorResponse}} 宣言（フラット型）と食い違うため
        # 揃えておく（matching.py と同様）。
        # 下の except Exception より前に置くことで、そちらに握りつぶされるのを防ぐ。
        raise

    except BedrockError:
        # ここで HTTPException に変換せず、そのまま re-raise する。
        # main.py の @app.exception_handler(BedrockError) が捕捉し、
        # 504 UPSTREAM_TIMEOUT として応答してくれる（設計書 §4.2 準拠）。
        # 下の except Exception より前に置くことで、そちらに握りつぶされるのを防ぐ。
        raise

    except Exception as e:
        # その他の予期せぬエラー（インフラ層やタイムアウトなど）に対する防衛策
        # 内部の例外メッセージ（DBカラム名やSQL断片等）を外部に漏らさないよう、
        # ログにのみ詳細を残し、レスポンスは汎用メッセージに統一する（matching.py と同様）。
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={"error_code": "INTERNAL_SERVER_ERROR", "message": "予期せぬエラーが発生しました。"}
        )
