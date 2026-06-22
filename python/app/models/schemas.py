from pydantic import BaseModel, Field
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


# --- E2: プロフィール要約（最新のAI活用方針に準拠） ---

class ProfileSummaryRequest(BaseModel):
    engineer_id: int = Field(..., description="エンジニアID")
    appeal_point: str = Field(
        ..., 
        description="文字数制限なしのアピールポイント（職務経歴書廃止に伴う代替テキスト入力）",
        example="インフラエンジニアとしてVMwareやWindows Serverの設計構築を5年経験。最近はPythonでのAPI開発を学習中です。"
    )
    raw_skills: str = Field(
        ..., 
        description="フリーテキスト入力されたスキル（AI側で表記ゆれを吸収する前提）",
        example="Java, AWS, くらうど, どっかー, FastAPI"
    )


class ProfileSummaryResponse(BaseModel):
    engineer_id: int
    ai_summary: str = Field(..., description="アピールポイントとスキルからAIが肉付け生成したプロフィール紹介文")
    ai_summary_generated_at: str    # ISO8601


# --- 共通エラーレスポンス ---

class ErrorResponse(BaseModel):
    error_code: str
    message: str
