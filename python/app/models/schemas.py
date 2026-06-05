from pydantic import BaseModel
from typing import Optional


# --- E3: ヘルスチェック ---

class HealthResponse(BaseModel):
    status: str


# --- E1: マッチング計算 ---

class MatchingRequest(BaseModel):
    engineer_id: int
    project_ids: Optional[list[int]] = None


class MatchResult(BaseModel):
    project_id: int
    match_score: int        # 0〜100（クランプ済み）
    match_rank: str         # A / B / C / D
    ai_score_reason: str    # 200字以上300字以下
    ai_comment: str         # 150字以上250字以下
    ai_missing: str         # 50字以上150字以下


class MatchingResponse(BaseModel):
    engineer_id: int
    generated_at: str       # ISO8601
    matches: list[MatchResult]


# --- E2: プロフィール要約 ---

class ProfileSummaryRequest(BaseModel):
    engineer_id: int


class ProfileSummaryResponse(BaseModel):
    engineer_id: int
    ai_summary: str
    ai_summary_generated_at: str    # ISO8601


# --- 共通エラーレスポンス ---

class ErrorResponse(BaseModel):
    error_code: str
    message: str
