"""
matching_service.py の DB 取得関数・マッチング計算フローのテスト。
外部 DB には接続せず、SQLAlchemy Session を MagicMock で代替する。
"""
from datetime import date, datetime
from unittest.mock import MagicMock

import pytest

from app.services.matching_service import (
    EngineerData,
    EngineerNotFoundError,
    EngineerSkill,
    MatchCandidate,
    NoActiveCandidateError,
    ProjectData,
    ProjectSkill,
    _cascade_sort,
    _proc_overlap_count,
    _rate_in_range,
    _work_style_match,
    calculate_matching,
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
        status="proposable",
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
            status=None,
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


# ---------------------------------------------------------------------------
# カスケードソートヘルパー
# ---------------------------------------------------------------------------

def _make_engineer(**overrides) -> EngineerData:
    defaults = dict(
        id=1,
        status="proposable",
        appeal_note="テスト用",
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
        skills=[],
    )
    defaults.update(overrides)
    return EngineerData(**defaults)


def _make_project(project_id: int = 10, **overrides) -> ProjectData:
    defaults = dict(
        id=project_id,
        description="テスト案件",
        negotiation_required=1,
        start_date=date(2026, 8, 1),
        rate_min=70,
        rate_max=90,
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
        skills=[],
    )
    defaults.update(overrides)
    return ProjectData(**defaults)


class TestCascadeSortHelpers:
    """_proc_overlap_count / _rate_in_range / _work_style_match のテスト。"""

    def test_proc_overlap_count_two_matches(self):
        # 全 proc を明示指定してデフォルト値の干渉を防ぐ
        engineer = _make_engineer(
            proc_requirements=1, proc_basic_design=0, proc_detail_design=0,
            proc_development=1, proc_testing=0, proc_maintenance=0,
        )
        project = _make_project(
            proc_requirements=1, proc_basic_design=0, proc_detail_design=0,
            proc_development=1, proc_testing=1, proc_maintenance=0,
        )
        assert _proc_overlap_count(engineer, project) == 2

    def test_proc_overlap_count_zero_no_common(self):
        engineer = _make_engineer(
            proc_requirements=0, proc_basic_design=0, proc_detail_design=0,
            proc_development=0, proc_testing=1, proc_maintenance=0,
        )
        project = _make_project(
            proc_requirements=1, proc_basic_design=0, proc_detail_design=0,
            proc_development=1, proc_testing=0, proc_maintenance=0,
        )
        assert _proc_overlap_count(engineer, project) == 0

    def test_rate_in_range_within_range(self):
        engineer = _make_engineer(desired_rate=80)
        project = _make_project(rate_min=70, rate_max=90)
        assert _rate_in_range(engineer, project) is True

    def test_rate_in_range_none_desired_rate(self):
        engineer = _make_engineer(desired_rate=None)
        project = _make_project(rate_min=70, rate_max=90)
        assert _rate_in_range(engineer, project) is False

    def test_rate_in_range_none_project_rate(self):
        engineer = _make_engineer(desired_rate=80)
        project = _make_project(rate_min=None, rate_max=None)
        assert _rate_in_range(engineer, project) is False

    def test_rate_in_range_above_max(self):
        engineer = _make_engineer(desired_rate=100)
        project = _make_project(rate_min=70, rate_max=90)
        assert _rate_in_range(engineer, project) is False

    def test_work_style_match_onsite(self):
        engineer = _make_engineer(work_style_onsite=1, work_style_hybrid=0, work_style_remote=0)
        project = _make_project(work_style="onsite")
        assert _work_style_match(engineer, project) is True

    def test_work_style_match_hybrid(self):
        engineer = _make_engineer(work_style_onsite=0, work_style_hybrid=1, work_style_remote=0)
        project = _make_project(work_style="hybrid")
        assert _work_style_match(engineer, project) is True

    def test_work_style_match_remote(self):
        engineer = _make_engineer(work_style_onsite=0, work_style_hybrid=0, work_style_remote=1)
        project = _make_project(work_style="remote")
        assert _work_style_match(engineer, project) is True

    def test_work_style_no_match(self):
        engineer = _make_engineer(work_style_onsite=0, work_style_hybrid=0, work_style_remote=1)
        project = _make_project(work_style="onsite")
        assert _work_style_match(engineer, project) is False


class TestCascadeSort:
    """_cascade_sort のテスト。"""

    def test_higher_proc_overlap_ranks_first(self):
        """工程経験重複数が多い案件が先頭に来ること。"""
        engineer = _make_engineer(proc_requirements=1, proc_development=1, proc_testing=1)
        p_low = _make_project(1, proc_requirements=1, proc_development=0, proc_testing=0)   # 重複1
        p_high = _make_project(2, proc_requirements=1, proc_development=1, proc_testing=1)  # 重複3
        result = _cascade_sort([p_low, p_high], engineer)
        assert result[0].id == 2

    def test_rate_in_range_preferred_when_proc_tied(self):
        """工程重複数が同じ場合、単価適合の案件が先頭に来ること。"""
        engineer = _make_engineer(desired_rate=80, proc_requirements=0, proc_development=0)
        p_out = _make_project(1, rate_min=50, rate_max=70)   # 単価範囲外
        p_in = _make_project(2, rate_min=70, rate_max=90)    # 単価範囲内
        result = _cascade_sort([p_out, p_in], engineer)
        assert result[0].id == 2

    def test_earlier_start_date_preferred_when_all_conditions_tied(self):
        """工程・単価・勤務形態が同じ場合、開始時期が早い案件が先頭に来ること。"""
        engineer = _make_engineer(
            desired_rate=80,
            work_style_hybrid=1,
            proc_requirements=0, proc_development=0,
        )
        p_later = _make_project(1, start_date=date(2026, 10, 1), work_style="hybrid",
                                rate_min=70, rate_max=90)
        p_earlier = _make_project(2, start_date=date(2026, 8, 1), work_style="hybrid",
                                  rate_min=70, rate_max=90)
        result = _cascade_sort([p_later, p_earlier], engineer)
        assert result[0].id == 2

    def test_returns_all_candidates_sorted(self):
        """ソート後に候補数が変わらないこと（絞込は呼び出し元が行う）。"""
        engineer = _make_engineer()
        projects = [_make_project(i) for i in range(1, 6)]
        result = _cascade_sort(projects, engineer)
        assert len(result) == 5


# ---------------------------------------------------------------------------
# calculate_matching
# ---------------------------------------------------------------------------

def _make_ai_result(score: int):
    """MatchingAIResult のテスト用ファクトリ。"""
    from app.services.bedrock_service import MatchingAIResult
    rank = "A" if score >= 80 else "B" if score >= 65 else "C" if score >= 50 else "D"
    return MatchingAIResult(
        match_score=score,
        match_rank=rank,
        ai_score_reason="テスト理由" * 10,
        ai_comment="テストコメント" * 8,
        ai_missing="テスト不足" * 3,
    )


class TestCalculateMatching:
    """calculate_matching のフロー統合テスト。"""

    def test_returns_top5_sorted_by_score(self, mocker):
        """6件候補 → スコア降順で上位5件のみ返すこと。"""
        engineer = _make_engineer()
        projects = [_make_project(i) for i in range(1, 7)]
        scores = [55, 70, 85, 40, 90, 75]

        mocker.patch("app.services.matching_service.fetch_engineer", return_value=engineer)
        mocker.patch("app.services.matching_service.fetch_active_projects", return_value=projects)
        mocker.patch("app.services.matching_service.fetch_registered_project_ids", return_value=set())
        mock_invoke = mocker.patch(
            "app.services.bedrock_service.invoke_matching",
            side_effect=[_make_ai_result(s) for s in scores],
        )

        result = calculate_matching(MagicMock(), engineer_id=1, project_ids=None)

        assert len(result.matches) == 5
        assert result.matches[0].match_score == 90   # 最高スコアが先頭
        assert result.matches[0].project_id == 5
        assert result.matches[4].match_score == 55   # 5位
        assert mock_invoke.call_count == 6            # 6件全てに AI 呼び出し

    def test_excludes_pipeline_registered_projects(self, mocker):
        """パイプライン登録済み案件は AI 呼び出し対象から除外されること。"""
        engineer = _make_engineer()
        projects = [_make_project(1), _make_project(2), _make_project(3)]

        mocker.patch("app.services.matching_service.fetch_engineer", return_value=engineer)
        mocker.patch("app.services.matching_service.fetch_active_projects", return_value=projects)
        mocker.patch("app.services.matching_service.fetch_registered_project_ids", return_value={1})
        mock_invoke = mocker.patch(
            "app.services.bedrock_service.invoke_matching",
            side_effect=[_make_ai_result(80), _make_ai_result(70)],
        )

        result = calculate_matching(MagicMock(), engineer_id=1, project_ids=None)

        assert mock_invoke.call_count == 2  # project_id=1 は除外されるため2件
        project_ids_scored = {call.args[1].id for call in mock_invoke.call_args_list}
        assert 1 not in project_ids_scored

    def test_cascade_sort_applied_when_over_30_candidates(self, mocker):
        """候補が31件以上の場合、30件に絞ってから AI 呼び出しされること。"""
        engineer = _make_engineer()
        projects = [_make_project(i) for i in range(1, 36)]  # 35件

        mocker.patch("app.services.matching_service.fetch_engineer", return_value=engineer)
        mocker.patch("app.services.matching_service.fetch_active_projects", return_value=projects)
        mocker.patch("app.services.matching_service.fetch_registered_project_ids", return_value=set())
        mock_invoke = mocker.patch(
            "app.services.bedrock_service.invoke_matching",
            side_effect=[_make_ai_result(50 + i) for i in range(30)],
        )

        result = calculate_matching(MagicMock(), engineer_id=1, project_ids=None)

        assert mock_invoke.call_count == 30  # 35件 → カスケードソートで30件に絞込

    def test_engineer_not_found_propagates(self, mocker):
        """fetch_engineer が EngineerNotFoundError を送出した場合、そのまま伝播すること。"""
        mocker.patch(
            "app.services.matching_service.fetch_engineer",
            side_effect=EngineerNotFoundError(999),
        )

        with pytest.raises(EngineerNotFoundError) as exc_info:
            calculate_matching(MagicMock(), engineer_id=999, project_ids=None)

        assert exc_info.value.engineer_id == 999

    def test_empty_candidates_raises_no_active_candidate_error(self, mocker):
        """全案件がパイプライン除外後に0件になった場合、NoActiveCandidateError を送出すること。"""
        engineer = _make_engineer()
        projects = [_make_project(1), _make_project(2)]

        mocker.patch("app.services.matching_service.fetch_engineer", return_value=engineer)
        mocker.patch("app.services.matching_service.fetch_active_projects", return_value=projects)
        mocker.patch("app.services.matching_service.fetch_registered_project_ids",
                     return_value={1, 2})
        mock_invoke = mocker.patch("app.services.bedrock_service.invoke_matching")

        with pytest.raises(NoActiveCandidateError) as exc_info:
            calculate_matching(MagicMock(), engineer_id=1, project_ids=None)

        assert exc_info.value.engineer_id == 1
        mock_invoke.assert_not_called()

    def test_non_proposable_engineer_continues_without_error(self, mocker):
        """status='not_proposable' のエンジニアでも例外を送出せずマッチングを実行すること。"""
        engineer = _make_engineer(status="not_proposable")
        # 候補が0件だと NoActiveCandidateError になるため、1件は候補として残す
        projects = [_make_project(1)]

        mocker.patch("app.services.matching_service.fetch_engineer", return_value=engineer)
        mocker.patch("app.services.matching_service.fetch_active_projects", return_value=projects)
        mocker.patch("app.services.matching_service.fetch_registered_project_ids", return_value=set())
        mocker.patch(
            "app.services.bedrock_service.invoke_matching",
            return_value=_make_ai_result(70),
        )

        result = calculate_matching(MagicMock(), engineer_id=1, project_ids=None)

        assert len(result.matches) == 1

    def test_fewer_than_5_candidates_returns_all(self, mocker):
        """候補が5件未満の場合、全件返すこと。"""
        engineer = _make_engineer()
        projects = [_make_project(i) for i in range(1, 4)]  # 3件

        mocker.patch("app.services.matching_service.fetch_engineer", return_value=engineer)
        mocker.patch("app.services.matching_service.fetch_active_projects", return_value=projects)
        mocker.patch("app.services.matching_service.fetch_registered_project_ids", return_value=set())
        mocker.patch(
            "app.services.bedrock_service.invoke_matching",
            side_effect=[_make_ai_result(s) for s in [80, 60, 70]],
        )

        result = calculate_matching(MagicMock(), engineer_id=1, project_ids=None)

        assert len(result.matches) == 3
        assert result.engineer_id == 1
