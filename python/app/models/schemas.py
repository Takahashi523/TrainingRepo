from pydantic import BaseModel, Field
from typing import Optional


# --- E3: ヘルスチェック ---

class HealthResponse(BaseModel):
    status: str


# --- E1: マッチング計算 ---
# スコアリングロジック設計書 v0.6 §4.2 準拠。
# limit / rank_filter / total_hits は v0.6 改訂履歴（B-05・B-06・B-07）にて
# 「フロント側で未使用、QA#33/50で5件固定済」のため削除された経緯があるため、持たない。

class MatchingRequest(BaseModel):
    engineer_id: int
    project_ids: Optional[list[int]] = None


class MatchResult(BaseModel):
    project_id: int
    # 0〜100（bedrock_service 側で min(100, max(0, raw_score)) にクランプ済み・設計書 v0.7 §3.3.1）。
    # スキーマ側でも範囲を宣言し、クランプが外れた場合にレスポンス生成時点で検出できるようにする。
    match_score: int = Field(ge=0, le=100)
    match_rank: str         # A / B / C / D
    ai_score_reason: str    # 200字以上300字以下
    ai_comment: str         # 150字以上250字以下
    ai_missing: str         # 50字以上150字以下


class MatchingResponse(BaseModel):
    engineer_id: int
    generated_at: str       # ISO8601
    matches: list[MatchResult]  # スコア降順、常に最大5件（QA#33・QA#50）


# --- E2: プロフィール要約 ---
# スコアリングロジック設計書 v0.6 §4.3 準拠。
# 入力は engineer_id のみ。engineers.appeal_note を Python 側が DB から取得して AI に渡す。

class ProfileSummaryRequest(BaseModel):
    engineer_id: int = Field(..., description="対象人材ID（appeal_note を取得して入力に使用）")


class ProfileSummaryResponse(BaseModel):
    engineer_id: int
    ai_summary: str = Field(..., description="AIが生成したプロフィール要約（人材詳細画面で表示）")
    ai_summary_generated_at: str    # ISO8601


# --- 共通エラーレスポンス ---

class ErrorResponse(BaseModel):
    error_code: str
    message: str
