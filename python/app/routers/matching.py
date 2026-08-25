from fastapi import APIRouter, Depends, status
from sqlalchemy.orm import Session

# 作成済みのスキーマ、サービス、例外クラスをインポート
from app.models.db import get_db
from app.models.schemas import MatchingRequest, MatchingResponse, ErrorResponse

# ★ calculate_matching を run_matching としてエイリアスインポートする（テストのモック用）
from app.services.matching_service import calculate_matching as run_matching

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
    # 例外は捕捉せず、すべて main.py の app レベル例外ハンドラに委ねる（設計書 §4.2 / §4.4）。
    # EngineerNotFoundError → 404 / NoActiveCandidateError → 422 / BedrockError → 504 /
    # その他 → 500 INTERNAL_ERROR が、いずれもフラット形式で返る。
    # ここで握って HTTPException に変換してはならない理由は main.py のハンドラ群を参照。
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
