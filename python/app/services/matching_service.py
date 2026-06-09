from __future__ import annotations

import logging
from dataclasses import dataclass, field
from datetime import date, datetime, timezone
from typing import Optional

from sqlalchemy import bindparam, text
from sqlalchemy.orm import Session

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# カスタム例外
# ---------------------------------------------------------------------------

class EngineerNotFoundError(Exception):
    def __init__(self, engineer_id: int) -> None:
        self.engineer_id = engineer_id
        super().__init__(f"engineer_id={engineer_id} not found")


class NoActiveCandidateError(Exception):
    """パイプライン除外後に候補が0件になった場合に送出する（スコアリングロジック設計書 §4.2 422 NO_ACTIVE_PROJECT）。"""
    def __init__(self, engineer_id: int) -> None:
        self.engineer_id = engineer_id
        super().__init__(f"No active candidates for engineer_id={engineer_id}")


# ---------------------------------------------------------------------------
# 内部データクラス
# Pydantic は API の入出力型定義に使用する。DB→サービス層の内部構造は
# 軽量な dataclass で表現し、Pydantic のバリデーションオーバーヘッドを避ける。
# ---------------------------------------------------------------------------

@dataclass
class EngineerSkill:
    label: Optional[str]
    detail: Optional[str]


@dataclass
class EngineerData:
    id: int
    status: Optional[str]            # proposable / interviewing / not_proposable
    appeal_note: Optional[str]
    has_negotiation_exp: Optional[int]
    available_from: Optional[object]   # datetime.date or None
    desired_rate: Optional[int]
    nearest_station: Optional[str]
    proc_requirements: Optional[int]
    proc_basic_design: Optional[int]
    proc_detail_design: Optional[int]
    proc_development: Optional[int]
    proc_testing: Optional[int]
    proc_maintenance: Optional[int]
    work_style_onsite: Optional[int]
    work_style_hybrid: Optional[int]
    work_style_remote: Optional[int]
    skills: list[EngineerSkill] = field(default_factory=list)


@dataclass
class ProjectSkill:
    skill_type: str   # 'required' or 'preferred'
    label: Optional[str]
    detail: Optional[str]


@dataclass
class ProjectData:
    id: int
    description: Optional[str]
    negotiation_required: Optional[int]
    start_date: Optional[object]       # datetime.date or None
    rate_min: Optional[int]
    rate_max: Optional[int]
    rate_note: Optional[str]
    work_style: Optional[str]
    work_location_station: Optional[str]
    proc_requirements: Optional[int]
    proc_basic_design: Optional[int]
    proc_detail_design: Optional[int]
    proc_development: Optional[int]
    proc_testing: Optional[int]
    proc_maintenance: Optional[int]
    created_at: Optional[object]       # datetime.datetime or None
    skills: list[ProjectSkill] = field(default_factory=list)


@dataclass
class MatchCandidate:
    """AI 総合判定結果（1案件分）。"""
    project_id: int
    match_score: int    # 0〜100（クランプ済み）
    match_rank: str     # A / B / C / D（アプリ層で検算済み）
    ai_score_reason: str
    ai_comment: str
    ai_missing: str


@dataclass
class MatchingOutput:
    """calculate_matching の戻り値。"""
    engineer_id: int
    generated_at: datetime
    matches: list[MatchCandidate]


# ---------------------------------------------------------------------------
# 定数
# ---------------------------------------------------------------------------

# SELECT * を避け、AIマッチングに必要なカラムのみを明示的に指定する
_ENGINEER_COLUMNS = (
    "id, status, appeal_note, has_negotiation_exp, available_from,"
    " desired_rate, nearest_station,"
    " proc_requirements, proc_basic_design, proc_detail_design,"
    " proc_development, proc_testing, proc_maintenance,"
    " work_style_onsite, work_style_hybrid, work_style_remote"
)

_PROJECT_COLUMNS = (
    "id, description, negotiation_required, start_date,"
    " rate_min, rate_max, rate_note, work_style, work_location_station,"
    " proc_requirements, proc_basic_design, proc_detail_design,"
    " proc_development, proc_testing, proc_maintenance,"
    " created_at"
)

_PROC_FIELDS = [
    "proc_requirements", "proc_basic_design", "proc_detail_design",
    "proc_development", "proc_testing", "proc_maintenance",
]

_DATE_MAX = date.max
_DATETIME_MAX = datetime.max


# ---------------------------------------------------------------------------
# DB 取得関数
# ---------------------------------------------------------------------------

def fetch_engineer(db: Session, engineer_id: int) -> EngineerData:
    """engineers + engineer_skills を取得して EngineerData を返す。
    存在しない場合は EngineerNotFoundError を送出する。
    """
    row = db.execute(
        text(f"SELECT {_ENGINEER_COLUMNS} FROM engineers WHERE id = :engineer_id"),
        {"engineer_id": engineer_id},
    ).fetchone()

    if row is None:
        raise EngineerNotFoundError(engineer_id)

    skills_rows = db.execute(
        text("SELECT label, detail FROM engineer_skills WHERE engineer_id = :engineer_id"),
        {"engineer_id": engineer_id},
    ).fetchall()

    return EngineerData(
        id=row.id,
        status=row.status,
        appeal_note=row.appeal_note,
        has_negotiation_exp=row.has_negotiation_exp,
        available_from=row.available_from,
        desired_rate=row.desired_rate,
        nearest_station=row.nearest_station,
        proc_requirements=row.proc_requirements,
        proc_basic_design=row.proc_basic_design,
        proc_detail_design=row.proc_detail_design,
        proc_development=row.proc_development,
        proc_testing=row.proc_testing,
        proc_maintenance=row.proc_maintenance,
        work_style_onsite=row.work_style_onsite,
        work_style_hybrid=row.work_style_hybrid,
        work_style_remote=row.work_style_remote,
        skills=[EngineerSkill(label=s.label, detail=s.detail) for s in skills_rows],
    )


def fetch_active_projects(
    db: Session,
    project_ids: Optional[list[int]] = None,
) -> list[ProjectData]:
    """status='open' の案件 + project_skills を一括取得する。
    project_ids 指定時はその ID に限定し、未指定時は全 open 案件を対象とする。
    N+1 を避けるため project_skills は IN 句で一括取得する。
    """
    if project_ids is not None and not project_ids:
        return []

    if project_ids is not None:
        stmt = text(
            f"SELECT {_PROJECT_COLUMNS} FROM projects"
            " WHERE status = 'open' AND id IN :ids"
        ).bindparams(bindparam("ids", expanding=True))
        rows = db.execute(stmt, {"ids": project_ids}).fetchall()
    else:
        rows = db.execute(
            text(f"SELECT {_PROJECT_COLUMNS} FROM projects WHERE status = 'open'")
        ).fetchall()

    if not rows:
        return []

    pid_list = [r.id for r in rows]

    skills_rows = db.execute(
        text(
            "SELECT project_id, skill_type, label, detail"
            " FROM project_skills WHERE project_id IN :ids"
        ).bindparams(bindparam("ids", expanding=True)),
        {"ids": pid_list},
    ).fetchall()

    # project_id ごとにスキルをグループ化
    skills_map: dict[int, list[ProjectSkill]] = {pid: [] for pid in pid_list}
    for s in skills_rows:
        skills_map[s.project_id].append(
            ProjectSkill(skill_type=s.skill_type, label=s.label, detail=s.detail)
        )

    return [
        ProjectData(
            id=r.id,
            description=r.description,
            negotiation_required=r.negotiation_required,
            start_date=r.start_date,
            rate_min=r.rate_min,
            rate_max=r.rate_max,
            rate_note=r.rate_note,
            work_style=r.work_style,
            work_location_station=r.work_location_station,
            proc_requirements=r.proc_requirements,
            proc_basic_design=r.proc_basic_design,
            proc_detail_design=r.proc_detail_design,
            proc_development=r.proc_development,
            proc_testing=r.proc_testing,
            proc_maintenance=r.proc_maintenance,
            created_at=r.created_at,
            skills=skills_map[r.id],
        )
        for r in rows
    ]


def fetch_registered_project_ids(db: Session, engineer_id: int) -> set[int]:
    """pipelines テーブルから既登録の project_id セットを返す。
    Step 3.5 のパイプライン除外用。AI 呼び出し前に除外することでコストを削減する。
    """
    rows = db.execute(
        text("SELECT project_id FROM pipelines WHERE engineer_id = :engineer_id"),
        {"engineer_id": engineer_id},
    ).fetchall()
    return {r.project_id for r in rows}


# ---------------------------------------------------------------------------
# カスケードソートヘルパー（Step 3.6）
# ---------------------------------------------------------------------------

def _proc_overlap_count(engineer: EngineerData, project: ProjectData) -> int:
    """エンジニアと案件の工程経験重複数を返す。"""
    return sum(
        1 for f in _PROC_FIELDS
        if getattr(engineer, f) == 1 and getattr(project, f) == 1
    )


def _rate_in_range(engineer: EngineerData, project: ProjectData) -> bool:
    """エンジニアの希望単価が案件の単価レンジ内かを返す。"""
    if engineer.desired_rate is None or project.rate_min is None or project.rate_max is None:
        return False
    return project.rate_min <= engineer.desired_rate <= project.rate_max


def _work_style_match(engineer: EngineerData, project: ProjectData) -> bool:
    """エンジニアの勤務形態希望と案件の稼働形態が適合するかを返す。"""
    return (
        (project.work_style == "onsite" and engineer.work_style_onsite == 1)
        or (project.work_style == "hybrid" and engineer.work_style_hybrid == 1)
        or (project.work_style == "remote" and engineer.work_style_remote == 1)
    )


def _cascade_sort(
    candidates: list[ProjectData],
    engineer: EngineerData,
) -> list[ProjectData]:
    """候補 >30 件時の絞込ソート（スコアリングロジック設計書 v0.6 §3.6 準拠）。
    工程経験重複数(降順) → 単価適合(降順) → 勤務形態適合(降順) → 開始時期(昇順) → 登録日(昇順)
    """
    def sort_key(p: ProjectData) -> tuple:
        return (
            -_proc_overlap_count(engineer, p),
            0 if _rate_in_range(engineer, p) else 1,
            0 if _work_style_match(engineer, p) else 1,
            p.start_date or _DATE_MAX,
            p.created_at or _DATETIME_MAX,
        )
    return sorted(candidates, key=sort_key)


# ---------------------------------------------------------------------------
# 通勤時間スタブ（Step 6 で gmaps_service に差し替え）
# ---------------------------------------------------------------------------

def _get_commute_time_minutes(
    engineer: EngineerData,
    project: ProjectData,
) -> Optional[int]:
    return None


# ---------------------------------------------------------------------------
# E1 マッチング計算フロー（Step 3.0〜3.12）
# ---------------------------------------------------------------------------

_MAX_MATCHES = 5  # QA#33・QA#50 確定値


def calculate_matching(
    db: Session,
    engineer_id: int,
    project_ids: Optional[list[int]],
) -> MatchingOutput:
    """E1 マッチング計算のメインフロー（AIプロンプト設計書 v0.3 / スコアリングロジック設計書 v0.6 準拠）。
    bedrock_service は循環インポート回避のためローカルインポートする。
    """
    from app.services.bedrock_service import invoke_matching

    # Step 3.1: エンジニア情報取得（存在しない場合は EngineerNotFoundError を送出）
    engineer = fetch_engineer(db, engineer_id)

    # Step 3.4: proposable 以外でもマッチング実行可能だが警告を記録
    if engineer.status != "proposable":
        logger.warning(
            "engineer_id=%d status=%s で calculate_matching を実行",
            engineer_id,
            engineer.status,
        )

    # Step 3.2 + 3.3: 全 open 案件取得（project_ids 指定時はフィルタ）
    projects = fetch_active_projects(db, project_ids=project_ids)

    # Step 3.5: パイプライン登録済み案件を除外（AI 呼び出し前に除外しコストを削減）
    registered_ids = fetch_registered_project_ids(db, engineer_id)
    candidates = [p for p in projects if p.id not in registered_ids]

    # 候補0件は 422 NO_ACTIVE_PROJECT（スコアリングロジック設計書 §4.2）
    if not candidates:
        raise NoActiveCandidateError(engineer_id)

    # Step 3.6: 候補 >30 件ならカスケードソートで上位 30 件に絞込
    if len(candidates) > 30:
        candidates = _cascade_sort(candidates, engineer)[:30]

    # Step 3.7〜3.10: 案件ごとに通勤時間取得 → Bedrock AI 総合判定 → クランプ・ランク検算
    results: list[MatchCandidate] = []
    for project in candidates:
        commute_time = _get_commute_time_minutes(engineer, project)   # Step 3.7（スタブ）
        ai_result = invoke_matching(engineer, project, commute_time)  # Step 3.8〜3.10
        results.append(MatchCandidate(
            project_id=project.id,
            match_score=ai_result.match_score,
            match_rank=ai_result.match_rank,
            ai_score_reason=ai_result.ai_score_reason,
            ai_comment=ai_result.ai_comment,
            ai_missing=ai_result.ai_missing,
        ))

    # Step 3.11: スコア降順ソート → 上位5件に絞込（QA#33・QA#50 確定）
    results.sort(key=lambda r: r.match_score, reverse=True)

    return MatchingOutput(
        engineer_id=engineer_id,
        generated_at=datetime.now(timezone.utc),
        matches=results[:_MAX_MATCHES],
    )
