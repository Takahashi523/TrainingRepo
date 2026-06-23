from dataclasses import dataclass  # モジュール名を正しく修正
from typing import List, Optional

@dataclass
class EngineerSkill:
    """エンジニアのスキルを保持するクラス"""
    label: Optional[str]
    detail: Optional[str]

@dataclass
class ProjectSkill:
    """案件のスキルを保持するクラス"""
    skill_type: str  # mandatory / preferred
    label: Optional[str]
    detail: Optional[str]

@dataclass
class EngineerData:
    """エンジニアの基本情報と保有スキル・工程経験を保持する内部データ構造"""
    id: int
    status: Optional[str]            # proposable / interviewing / not_proposable
    appeal_note: Optional[str]
    has_negotiation_exp: Optional[int]
    available_from: Optional[str]     # YYYY-MM-DD 形式
    desired_rate: Optional[int]
    nearest_station: Optional[str]
    proc_requirements: int
    proc_basic_design: int
    proc_detail_design: int
    proc_development: int
    proc_testing: int
    proc_maintenance: int
    work_style_onsite: int
    work_style_hybrid: int
    work_style_remote: int
    skills: List[EngineerSkill]
    appeal_point: Optional[str] = None  # E2新方針用
    raw_skills: Optional[str] = None    # E2新方針用

@dataclass
class ProjectData:
    """案件の基本情報と必須・尚可スキル、工程経験を保持する内部データ構造"""
    id: int
    description: Optional[str]
    negotiation_required: Optional[int]
    start_date: Optional[str]        # YYYY-MM-DD 形式
    rate_min: Optional[int]
    rate_max: Optional[int]
    rate_note: Optional[str]
    work_style: Optional[str]        # onsite / hybrid / remote
    work_location_station: Optional[str]
    proc_requirements: int
    proc_basic_design: int
    proc_detail_design: int
    proc_development: int
    proc_testing: int
    proc_maintenance: int
    created_at: Optional[str]        # カスケードソートで使用
    skills: List[ProjectSkill]

@dataclass
class MatchCandidate:
    """APIレスポンスおよびAI総合判定を保持する内部マッチング結果構造"""
    project_id: int
    match_score: int
    match_rank: str
    ai_score_reason: Optional[str] = None
    ai_comment: Optional[str] = None
    ai_missing: Optional[str] = None

@dataclass
class MatchingOutput:
    """E1全体の出力を保持する構造"""
    engineer_id: int
    generated_at: str  # ISO8601形式文字列、または datetime オブジェクト
    matches: List[MatchCandidate]
