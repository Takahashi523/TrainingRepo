from __future__ import annotations

from dataclasses import dataclass, field
from typing import Optional

from sqlalchemy import bindparam, text
from sqlalchemy.orm import Session


# ---------------------------------------------------------------------------
# カスタム例外
# ---------------------------------------------------------------------------

class EngineerNotFoundError(Exception):
    def __init__(self, engineer_id: int) -> None:
        self.engineer_id = engineer_id
        super().__init__(f"engineer_id={engineer_id} not found")


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


# ---------------------------------------------------------------------------
# 定数
# ---------------------------------------------------------------------------

# SELECT * を避け、AIマッチングに必要なカラムのみを明示的に指定する
_ENGINEER_COLUMNS = (
    "id, appeal_note, has_negotiation_exp, available_from,"
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
