"""
matching_service.py の DB 取得関数テスト。
外部 DB には接続せず、SQLAlchemy Session を MagicMock で代替する。
"""
from datetime import date, datetime
from unittest.mock import MagicMock

import pytest

from app.services.matching_service import (
    EngineerNotFoundError,
    EngineerSkill,
    ProjectSkill,
    fetch_active_projects,
    fetch_engineer,
    fetch_registered_project_ids,
)


# ---------------------------------------------------------------------------
# ヘルパー
# ---------------------------------------------------------------------------

def _row(**kwargs):
    """SQLAlchemy Row の代替 MagicMock を生成する。"""
    row = MagicMock()
    for k, v in kwargs.items():
        setattr(row, k, v)
    return row


def _exec_result(*rows, fetchone=False):
    """db.execute() の戻り値を模倣する MagicMock を生成する。"""
    result = MagicMock()
    if fetchone:
        result.fetchone.return_value = rows[0] if rows else None
    else:
        result.fetchall.return_value = list(rows)
    return result


# ---------------------------------------------------------------------------
# fetch_engineer
# ---------------------------------------------------------------------------

class TestFetchEngineer:
    """fetch_engineer のテスト。"""

    _ENGINEER_DEFAULTS = dict(
        id=1,
        appeal_note="Java 10年・Spring Boot 経験豊富",
        has_negotiation_exp=1,
        available_from=date(2026, 7, 1),
        desired_rate=80,
        nearest_station="渋谷",
        proc_requirements=1,
        proc_basic_design=1,
        proc_detail_design=0,
        proc_development=1,
        proc_testing=1,
        proc_maintenance=0,
        work_style_onsite=1,
        work_style_hybrid=1,
        work_style_remote=0,
    )

    def _make_db(self, engineer_row, skill_rows):
        db = MagicMock()
        db.execute.side_effect = [
            _exec_result(engineer_row, fetchone=True),
            _exec_result(*skill_rows),
        ]
        return db

    def test_returns_engineer_data_with_skills(self):
        engineer_row = _row(**self._ENGINEER_DEFAULTS)
        skill_rows = [
            _row(label="Java", detail="Spring Boot 5年"),
            _row(label="Python", detail=None),
        ]
        db = self._make_db(engineer_row, skill_rows)

        result = fetch_engineer(db, engineer_id=1)

        assert result.id == 1
        assert result.appeal_note == "Java 10年・Spring Boot 経験豊富"
        assert result.has_negotiation_exp == 1
        assert result.available_from == date(2026, 7, 1)
        assert result.desired_rate == 80
        assert result.nearest_station == "渋谷"
        assert result.proc_requirements == 1
        assert result.proc_basic_design == 1
        assert result.proc_detail_design == 0
        assert result.work_style_onsite == 1
        assert result.work_style_remote == 0
        assert len(result.skills) == 2
        assert result.skills[0] == EngineerSkill(label="Java", detail="Spring Boot 5年")
        assert result.skills[1] == EngineerSkill(label="Python", detail=None)

    def test_returns_engineer_data_with_no_skills(self):
        engineer_row = _row(**self._ENGINEER_DEFAULTS)
        db = self._make_db(engineer_row, skill_rows=[])

        result = fetch_engineer(db, engineer_id=1)

        assert result.id == 1
        assert result.skills == []

    def test_raises_not_found_when_engineer_missing(self):
        db = MagicMock()
        db.execute.return_value = _exec_result(fetchone=True)  # fetchone returns None

        with pytest.raises(EngineerNotFoundError) as exc_info:
            fetch_engineer(db, engineer_id=999)

        assert exc_info.value.engineer_id == 999

    def test_all_nullable_fields_can_be_none(self):
        """NULL が多いエンジニアでも EngineerData を正常に構築できること。"""
        engineer_row = _row(
            id=2,
            appeal_note=None,
            has_negotiation_exp=None,
            available_from=None,
            desired_rate=None,
            nearest_station=None,
            proc_requirements=None,
            proc_basic_design=None,
            proc_detail_design=None,
            proc_development=None,
            proc_testing=None,
            proc_maintenance=None,
            work_style_onsite=None,
            work_style_hybrid=None,
            work_style_remote=None,
        )
        db = self._make_db(engineer_row, skill_rows=[])

        result = fetch_engineer(db, engineer_id=2)

        assert result.id == 2
        assert result.appeal_note is None
        assert result.nearest_station is None
        assert result.skills == []


# ---------------------------------------------------------------------------
# fetch_active_projects
# ---------------------------------------------------------------------------

class TestFetchActiveProjects:
    """fetch_active_projects のテスト。"""

    def _make_project_row(self, project_id: int, **overrides):
        defaults = dict(
            id=project_id,
            description=f"案件{project_id}の詳細説明",
            negotiation_required=0,
            start_date=date(2026, 8, 1),
            rate_min=60,
            rate_max=80,
            rate_note=None,
            work_style="hybrid",
            work_location_station="新宿",
            proc_requirements=1,
            proc_basic_design=1,
            proc_detail_design=0,
            proc_development=1,
            proc_testing=0,
            proc_maintenance=0,
            created_at=datetime(2026, 5, 1),
        )
        defaults.update(overrides)
        return _row(**defaults)

    def _make_skill_row(self, project_id, skill_type, label, detail=None):
        return _row(project_id=project_id, skill_type=skill_type, label=label, detail=detail)

    def _make_db(self, project_rows, skill_rows):
        db = MagicMock()
        db.execute.side_effect = [
            _exec_result(*project_rows),
            _exec_result(*skill_rows),
        ]
        return db

    def test_returns_all_open_projects_when_no_filter(self):
        project_rows = [self._make_project_row(1), self._make_project_row(2)]
        skill_rows = [
            self._make_skill_row(1, "required", "Java", "Spring Boot"),
            self._make_skill_row(1, "preferred", "AWS", None),
            self._make_skill_row(2, "required", "Python"),
        ]
        db = self._make_db(project_rows, skill_rows)

        results = fetch_active_projects(db, project_ids=None)

        assert len(results) == 2
        assert results[0].id == 1
        assert results[0].description == "案件1の詳細説明"
        assert len(results[0].skills) == 2
        assert results[0].skills[0] == ProjectSkill(skill_type="required", label="Java", detail="Spring Boot")
        assert results[1].id == 2
        assert len(results[1].skills) == 1

    def test_returns_empty_list_when_no_open_projects(self):
        db = MagicMock()
        db.execute.return_value = _exec_result()  # empty fetchall

        results = fetch_active_projects(db, project_ids=None)

        assert results == []

    def test_returns_empty_list_immediately_when_project_ids_is_empty(self):
        db = MagicMock()

        results = fetch_active_projects(db, project_ids=[])

        assert results == []
        db.execute.assert_not_called()  # DB に一切アクセスしないこと

    def test_filters_by_project_ids(self):
        project_rows = [self._make_project_row(3)]
        skill_rows = []
        db = self._make_db(project_rows, skill_rows)

        results = fetch_active_projects(db, project_ids=[3, 99])

        assert len(results) == 1
        assert results[0].id == 3

    def test_project_with_no_skills_has_empty_skills_list(self):
        project_rows = [self._make_project_row(5)]
        skill_rows = []
        db = self._make_db(project_rows, skill_rows)

        results = fetch_active_projects(db, project_ids=None)

        assert len(results) == 1
        assert results[0].skills == []

    def test_all_nullable_fields_can_be_none(self):
        """NULL が多い案件でも ProjectData を正常に構築できること。"""
        project_row = self._make_project_row(
            10,
            description=None,
            rate_min=None,
            rate_max=None,
            rate_note=None,
            work_style=None,
            work_location_station=None,
        )
        db = self._make_db([project_row], [])

        results = fetch_active_projects(db, project_ids=None)

        assert results[0].description is None
        assert results[0].rate_max is None
        assert results[0].work_location_station is None


# ---------------------------------------------------------------------------
# fetch_registered_project_ids
# ---------------------------------------------------------------------------

class TestFetchRegisteredProjectIds:
    """fetch_registered_project_ids のテスト。"""

    def test_returns_set_of_registered_project_ids(self):
        db = MagicMock()
        db.execute.return_value = _exec_result(
            _row(project_id=10), _row(project_id=20), _row(project_id=30)
        )

        result = fetch_registered_project_ids(db, engineer_id=1)

        assert result == {10, 20, 30}

    def test_returns_empty_set_when_no_pipelines(self):
        db = MagicMock()
        db.execute.return_value = _exec_result()

        result = fetch_registered_project_ids(db, engineer_id=1)

        assert result == set()

    def test_returns_set_not_list(self):
        """戻り値が set であること（重複除外・高速 in チェックのため）。"""
        db = MagicMock()
        db.execute.return_value = _exec_result(_row(project_id=5))

        result = fetch_registered_project_ids(db, engineer_id=2)

        assert isinstance(result, set)
