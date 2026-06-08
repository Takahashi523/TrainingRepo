"""
bedrock_service.py のテスト。
boto3 の invoke_model は pytest-mock でモック化し、実際の Bedrock には接続しない。
"""
from __future__ import annotations

import json
from datetime import date
from unittest.mock import MagicMock

import pytest

import app.services.bedrock_service as svc
from app.services.bedrock_service import (
    BedrockError,
    MatchingAIResult,
    _build_matching_user_prompt,
    _build_summary_user_prompt,
    _determine_rank,
    _parse_matching_json,
    invoke_matching,
    invoke_profile_summary,
)
from app.services.matching_service import (
    EngineerData,
    EngineerSkill,
    ProjectData,
    ProjectSkill,
)


# ---------------------------------------------------------------------------
# フィクスチャ
# ---------------------------------------------------------------------------

@pytest.fixture(autouse=True)
def reset_bedrock_client():
    """テスト間でシングルトンをリセットする。"""
    svc._bedrock_client = None
    yield
    svc._bedrock_client = None


def _make_engineer(**overrides) -> EngineerData:
    defaults = dict(
        id=1,
        appeal_note="Java 10年・Spring Boot 経験豊富。顧客折衝経験あり。",
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
        skills=[
            EngineerSkill(label="Java", detail="Spring Boot 5年"),
            EngineerSkill(label="AWS", detail="EC2/S3 3年"),
        ],
    )
    defaults.update(overrides)
    return EngineerData(**defaults)


def _make_project(**overrides) -> ProjectData:
    defaults = dict(
        id=10,
        description="Java/Spring Boot を用いた金融システムの開発・保守。",
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
        created_at=None,
        skills=[
            ProjectSkill(skill_type="required", label="Java", detail="3年以上"),
            ProjectSkill(skill_type="preferred", label="AWS", detail=None),
        ],
    )
    defaults.update(overrides)
    return ProjectData(**defaults)


def _bedrock_response(payload: dict | str) -> dict:
    """boto3 invoke_model の戻り値形式を模倣する。"""
    text = json.dumps(payload, ensure_ascii=False) if isinstance(payload, dict) else payload
    body_mock = MagicMock()
    body_mock.read.return_value = json.dumps(
        {"content": [{"text": text}]}, ensure_ascii=False
    ).encode()
    return {"body": body_mock}


def _valid_ai_response(score: int = 78) -> dict:
    rank = "A" if score >= 80 else "B" if score >= 65 else "C" if score >= 50 else "D"
    return {
        "match_score": score,
        "match_rank": rank,
        "ai_score_reason": "必須スキルのJavaは5年以上の経験があり充足している。" * 5,
        "ai_comment": "Java/Spring Boot に長けたエンジニアで案件要件に合致している。" * 4,
        "ai_missing": "詳細設計経験がない点が懸念される。",
    }


# ---------------------------------------------------------------------------
# _determine_rank
# ---------------------------------------------------------------------------

class TestDetermineRank:
    @pytest.mark.parametrize("score,expected", [
        (100, "A"), (80, "A"), (79, "B"), (65, "B"),
        (64, "C"), (50, "C"), (49, "D"), (0, "D"),
    ])
    def test_rank_boundaries(self, score, expected):
        assert _determine_rank(score) == expected


# ---------------------------------------------------------------------------
# _parse_matching_json
# ---------------------------------------------------------------------------

class TestParseMatchingJson:
    def test_parses_plain_json(self):
        text = json.dumps({"match_score": 75, "match_rank": "B"})
        result = _parse_matching_json(text)
        assert result["match_score"] == 75

    def test_strips_code_fence(self):
        text = "```json\n{\"match_score\": 60}\n```"
        result = _parse_matching_json(text)
        assert result["match_score"] == 60

    def test_raises_on_invalid_json(self):
        with pytest.raises(json.JSONDecodeError):
            _parse_matching_json("not json at all")


# ---------------------------------------------------------------------------
# invoke_matching
# ---------------------------------------------------------------------------

class TestInvokeMatching:
    def test_returns_matching_ai_result_on_success(self, mocker):
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mock_client.invoke_model.return_value = _bedrock_response(_valid_ai_response(78))

        engineer = _make_engineer()
        project = _make_project()
        result = invoke_matching(engineer, project, commute_time_minutes=30)

        assert isinstance(result, MatchingAIResult)
        assert result.match_score == 78
        assert result.match_rank == "B"  # アプリ層で検算
        assert len(result.ai_score_reason) > 0

    def test_clamps_negative_score_to_zero(self, mocker):
        """必須スキル全不足ペナルティで負値になった場合でも 0 にクランプされること。"""
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        ai_resp = _valid_ai_response(0)
        ai_resp["match_score"] = -10  # AI が負値を返した場合
        mock_client.invoke_model.return_value = _bedrock_response(ai_resp)

        result = invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=45)

        assert result.match_score == 0
        assert result.match_rank == "D"

    def test_app_layer_corrects_wrong_rank_from_ai(self, mocker):
        """AI が match_rank を間違えた場合、アプリ層の検算値で上書きされること。"""
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        ai_resp = _valid_ai_response(85)
        ai_resp["match_rank"] = "C"  # AI が誤ったランクを返した場合
        mock_client.invoke_model.return_value = _bedrock_response(ai_resp)

        result = invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=20)

        assert result.match_score == 85
        assert result.match_rank == "A"  # アプリ層で正しく検算

    def test_retries_with_simplified_prompt_on_json_parse_failure(self, mocker):
        """JSON パース失敗時にリトライ専用プロンプトで再呼び出しされること。"""
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mock_client.invoke_model.side_effect = [
            _bedrock_response("invalid json output from AI"),   # 1回目: パース失敗
            _bedrock_response(_valid_ai_response(60)),          # 2回目: 成功
        ]

        result = invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=50)

        assert result.match_score == 60
        assert mock_client.invoke_model.call_count == 2

    def test_raises_bedrock_error_when_both_attempts_fail(self, mocker):
        """通常呼び出し・リトライ両方でパース失敗した場合に BedrockError を送出すること。"""
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mock_client.invoke_model.return_value = _bedrock_response("not json")

        with pytest.raises(BedrockError):
            invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=None)

    def test_retries_on_bedrock_client_error(self, mocker):
        """ClientError 発生時に指数バックオフでリトライされること。"""
        from botocore.exceptions import ClientError

        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mocker.patch("app.services.bedrock_service.time.sleep")  # スリープをスキップ

        error = ClientError({"Error": {"Code": "ThrottlingException", "Message": "rate exceeded"}}, "InvokeModel")
        mock_client.invoke_model.side_effect = [
            error,
            error,
            _bedrock_response(_valid_ai_response(70)),  # 3回目で成功
        ]

        result = invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=60)

        assert result.match_score == 70
        assert mock_client.invoke_model.call_count == 3

    def test_raises_bedrock_error_after_max_retries(self, mocker):
        """リトライ上限到達後に BedrockError を送出すること。"""
        from botocore.exceptions import ClientError

        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mocker.patch("app.services.bedrock_service.time.sleep")

        error = ClientError({"Error": {"Code": "ServiceUnavailableException", "Message": "unavailable"}}, "InvokeModel")
        mock_client.invoke_model.side_effect = error

        with pytest.raises(BedrockError):
            invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=None)

    def test_null_commute_time_does_not_raise(self, mocker):
        """commute_time_minutes が None でもエラーにならないこと。"""
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mock_client.invoke_model.return_value = _bedrock_response(_valid_ai_response(50))

        result = invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=None)

        assert result.match_score == 50


# ---------------------------------------------------------------------------
# invoke_profile_summary
# ---------------------------------------------------------------------------

class TestInvokeProfileSummary:
    def test_returns_summary_text_on_success(self, mocker):
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        summary = "JavaとSpring Bootに豊富な経験を持ち、顧客折衝も対応可能なエンジニアです。"
        mock_client.invoke_model.return_value = _bedrock_response(summary)

        result = invoke_profile_summary("Java 10年・Spring Boot 経験豊富。")

        assert result == summary

    def test_returns_empty_string_when_appeal_note_is_empty(self, mocker):
        """appeal_note が空の場合は Bedrock を呼ばず空文字を返すこと。"""
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)

        result = invoke_profile_summary("")

        assert result == ""
        mock_client.invoke_model.assert_not_called()

    def test_returns_empty_string_when_appeal_note_is_whitespace(self, mocker):
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)

        result = invoke_profile_summary("   ")

        assert result == ""
        mock_client.invoke_model.assert_not_called()

    def test_raises_bedrock_error_on_api_failure(self, mocker):
        from botocore.exceptions import ClientError

        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mocker.patch("app.services.bedrock_service.time.sleep")

        error = ClientError({"Error": {"Code": "ServiceUnavailableException", "Message": "err"}}, "InvokeModel")
        mock_client.invoke_model.side_effect = error

        with pytest.raises(BedrockError):
            invoke_profile_summary("アピールポイントのテキスト")


# ---------------------------------------------------------------------------
# プロンプト構築（内容の確認）
# ---------------------------------------------------------------------------

class TestBuildPrompts:
    def test_matching_prompt_contains_engineer_info(self):
        engineer = _make_engineer()
        project = _make_project()
        prompt = _build_matching_user_prompt(engineer, project, commute_time_minutes=35)

        assert "Java 10年" in prompt
        assert "渋谷" in prompt
        assert "要件定義" in prompt      # proc_requirements=1
        assert "常駐可" in prompt        # work_style_onsite=1

    def test_matching_prompt_contains_project_info(self):
        engineer = _make_engineer()
        project = _make_project()
        prompt = _build_matching_user_prompt(engineer, project, commute_time_minutes=35)

        assert "金融システム" in prompt
        assert "新宿" in prompt
        assert "一部リモート可" in prompt  # work_style=hybrid

    def test_matching_prompt_shows_null_commute_time(self):
        prompt = _build_matching_user_prompt(_make_engineer(), _make_project(), commute_time_minutes=None)
        assert "NULL（算出失敗）" in prompt

    def test_matching_prompt_shows_no_desired_rate(self):
        engineer = _make_engineer(desired_rate=None)
        prompt = _build_matching_user_prompt(engineer, _make_project(), commute_time_minutes=30)
        assert "希望なし" in prompt

    def test_summary_prompt_contains_appeal_note(self):
        appeal_note = "テスト用アピールポイント"
        prompt = _build_summary_user_prompt(appeal_note)
        assert appeal_note in prompt

    def test_matching_prompt_shows_false_negotiation(self):
        """negotiation_required=0 のとき '不問' が出力されること（_tinyint_label value=0 ブランチ）。"""
        project = _make_project(negotiation_required=0)
        prompt = _build_matching_user_prompt(_make_engineer(), project, commute_time_minutes=30)
        assert "不問" in prompt

    def test_matching_prompt_shows_unknown_when_none(self):
        """negotiation_required=None のとき '未入力' が出力されること（_tinyint_label value=None ブランチ）。"""
        project = _make_project(negotiation_required=None)
        prompt = _build_matching_user_prompt(_make_engineer(), project, commute_time_minutes=30)
        assert "未入力" in prompt

    def test_matching_prompt_shows_remote_work_style(self):
        """work_style_remote=1 のとき 'フルリモート希望' が出力されること。"""
        engineer = _make_engineer(work_style_onsite=0, work_style_hybrid=0, work_style_remote=1)
        prompt = _build_matching_user_prompt(engineer, _make_project(), commute_time_minutes=0)
        assert "フルリモート希望" in prompt

    def test_matching_prompt_shows_no_skills_when_empty(self):
        """スキルが空のとき '（なし）' が出力されること（_skills_text 空リストブランチ）。"""
        project = _make_project(skills=[])
        prompt = _build_matching_user_prompt(_make_engineer(), project, commute_time_minutes=30)
        assert "（なし）" in prompt
