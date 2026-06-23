from dataclass import dataclass
from typing import List, Optional

@dataclass
class SkillData:
    """スキル情報を保持する内部データ構造"""
    skill_id: int
    name: str

@dataclass
class EngineerData:
    """エンジニアの基本情報と保有スキル・工程経験を保持する内部データ構造"""
    engineer_id: int
    status: str
    desired_rate: Optional[int]
    work_style: str
    available_from: Optional[str]  # YYYY-MM-DD 形式
    skills: List[SkillData]
    processes: List[str]          # 工程名（要件定義、基本設計など）のリスト
    appeal_point: Optional[str] = None
    raw_skills: Optional[str] = None

@dataclass
class ProjectData:
    """案件の基本情報と必須・尚可スキル、工程経験を保持する内部データ構造"""
    project_id: int
    title: str
    status: str
    rate_max: Optional[int]
    work_style: str
    start_date: Optional[str]     # YYYY-MM-DD 形式
    created_at: str               # カスケードソート（登録日）で使用
    mandatory_skills: List[SkillData]
    preferred_skills: List[SkillData]
    processes: List[str]          # 案件が求める工程名のリスト
    commute_time_minutes: Optional[int] = None  # Google Maps APIから取得する通勤時間

@dataclass
class MatchResultInternal:
    """AI総合判定に渡す前、および判定後にアプリ層で計算・保持する内部マッチング結果構造"""
    project_id: int
    title: str
    match_score: int
    match_rank: str
    ai_score_reason: Optional[str] = None
    ai_comment: Optional[str] = None
    ai_missing: Optional[str] = None
    commute_time_minutes: Optional[int] = None