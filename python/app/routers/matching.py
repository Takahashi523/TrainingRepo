from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.orm import Session

# 作成済みのスキーマ、サービス、例外クラスをインポート
from app.models.db import get_db
from app.models.schemas import MatchingRequest, MatchingResponse, ErrorResponse

# ★ calculate_matching を run_matching としてエイリアスインポートする（テストのモック用）
from app.services.matching_service import (
    calculate_matching as run_matching,
    EngineerNotFoundError,
    NoActiveCandidateError,
)
# BedrockError は main.py の @app.exception_handler(BedrockError) で 504 に変換するため、
# ここでは except Exception に握りつぶされないよう明示的に import して再送出する
from app.services.bedrock_service import BedrockError

router = APIRouter(
    prefix="/api/v1/matching",
    tags=["matching"],
)


@router.post(
    "/calculate",
    response_model=MatchingResponse,
    status_code=status.HTTP_200_OK,
    summary="推薦理由およびマッチング計算の実行",
    description=(
        "指定されたエンジニアIDを基に、オープン状態の案件とのマッチング計算を行います。\n"
        "スコア降順で上位最大5件の案件候補を、AIによる推薦理由・コメント・不足スキル情報付きで返却します。"
    ),
    responses={
        status.HTTP_404_NOT_FOUND: {
            "model": ErrorResponse,
            "description": "エンジニアが見つかりません。",
        },
        status.HTTP_422_UNPROCESSABLE_ENTITY: {
            "model": ErrorResponse,
            "description": "パイプライン除外後に有効な候補案件が0件です。",
        },
    },
)
# ★ 関数名を execute_matching から matching_calculate に変更（区別しやすくするため）
def matching_calculate(request: MatchingRequest, db: Session = Depends(get_db)):
    try:
        # 完璧に肉付けされたサービス層のメインフローを呼び出し（エイリアスした run_matching を使用）
        output = run_matching(
            db=db,
            engineer_id=request.engineer_id,
            project_ids=request.project_ids,
        )

        # サービス層の戻り値 (MatchingOutput) を Pydantic のレスポンス型に変換して返却
        return MatchingResponse(
            engineer_id=output.engineer_id,
            generated_at=output.generated_at.isoformat(),  # ISO8601形式の文字列に変換
            matches=[
                {
                    "project_id": m.project_id,
                    "match_score": m.match_score,
                    "match_rank": m.match_rank,
                    "ai_score_reason": m.ai_score_reason,
                    "ai_comment": m.ai_comment,
                    "ai_missing": m.ai_missing,
                }
                for m in output.matches
            ],
        )

    except EngineerNotFoundError as e:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail={
                "error_code": "ENGINEER_NOT_FOUND",
                "message": str(e),
            },
        )

    except NoActiveCandidateError as e:
        # スコアリングロジック設計書 §4.2 準拠の 422 エラーハンドリング
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "error_code": "NO_ACTIVE_PROJECT",
                "message": str(e),
            },
        )

    except BedrockError:
        # ここで HTTPException に変換せず、そのまま re-raise する。
        # main.py の @app.exception_handler(BedrockError) が捕捉し、
        # 504 UPSTREAM_TIMEOUT として応答してくれる（設計書 §4.2 準拠）。
        # 下の except Exception より前に置くことで、そちらに握りつぶされるのを防ぐ。
        raise

    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={
                "error_code": "INTERNAL_SERVER_ERROR",
                "message": "予期せぬエラーが発生しました。",
            },
        )
