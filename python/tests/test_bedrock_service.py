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
        status="proposable",
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
        ai_response = _valid_ai_response(78)
        mock_client.invoke_model.return_value = _bedrock_response(ai_response)

        engineer = _make_engineer()
        project = _make_project()
        result = invoke_matching(engineer, project, commute_time_minutes=30)

        assert isinstance(result, MatchingAIResult)
        assert result.match_score == 78
        assert result.match_rank == "B"  # アプリ層で検算
        assert len(result.ai_score_reason) > 0
        # 推薦理由自動生成機能（ai_comment）・不足条件説明機能（ai_missing）が
        # Bedrock のレスポンスから正しく抽出され、途中で欠落・混同していないことを検証する
        assert result.ai_comment == ai_response["ai_comment"]
        assert result.ai_missing == ai_response["ai_missing"]
        # ai_score_reason・ai_comment・ai_missing が互いに取り違えられていないことも確認
        assert result.ai_comment != result.ai_missing
        assert result.ai_score_reason != result.ai_comment

    def test_uses_bedrock_parameters_per_prompt_design_doc(self, mocker):
        """Bedrock呼び出しパラメータ（max_tokens=800 / temperature=0.3 / top_p=0.9）が
        AIプロンプト設計書 v0.3 §3.1 の仕様通りに送信されること。
        """
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mock_client.invoke_model.return_value = _bedrock_response(_valid_ai_response(78))

        invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=30)

        sent_body = json.loads(mock_client.invoke_model.call_args.kwargs["body"])
        assert sent_body["max_tokens"] == 800
        assert sent_body["temperature"] == 0.3
        assert sent_body["top_p"] == 0.9

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

    def test_clamps_score_above_100(self, mocker):
        """観点別配点の合計が 100 を超えた場合でも 100 にクランプされること（設計書 v0.7 §3.3.1）。

        スコアは各観点の点数の合計を AI に出力させる方式のため、上振れは仕様上ありうる失敗モード。
        100 超がそのまま Laravel 側へ渡ると、マッチング結果画面には表示されるのに
        パイプライン追加で between:0,100 に弾かれ、ユーザーに回避手段のない行き止まりになる
        （match_score はスナップショット項目のため画面上で修正できない）。
        """
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        ai_resp = _valid_ai_response(0)
        ai_resp["match_score"] = 105  # AI が上限超えを返した場合
        mock_client.invoke_model.return_value = _bedrock_response(ai_resp)

        result = invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=20)

        assert result.match_score == 100
        assert result.match_rank == "A"

    def test_non_numeric_score_raises_bedrock_error(self, mocker):
        """match_score が非数値の場合、BedrockError（504）として扱われること。

        int() は従来 try の外にあり、素の ValueError が 500 として表に出ていた。
        AI のフォーマット崩れは上流障害（504 UPSTREAM_TIMEOUT）に寄せる。
        """
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        ai_resp = _valid_ai_response(0)
        ai_resp["match_score"] = "高い"  # AI が数値以外を返した場合
        mock_client.invoke_model.return_value = _bedrock_response(ai_resp)

        with pytest.raises(BedrockError):
            invoke_matching(_make_engineer(), _make_project(), commute_time_minutes=20)

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
        """E2 はスコアリングロジック設計書 v0.6 §4.3 準拠で、
        engineers.appeal_note（H1）のみを単一の入力とする。
        """
        appeal_note = "テスト用アピールポイント"
        prompt = _build_summary_user_prompt(appeal_note)
        assert appeal_note in prompt

    def test_matching_prompt_contains_scoring_guide(self):
        """【判定観点と配点目安】が8観点すべてプロンプトに埋め込まれていること
        （AIプロンプト設計書 v0.3 §3.3 準拠。以前は欠落していた重要項目）。
        """
        prompt = _build_matching_user_prompt(_make_engineer(), _make_project(), commute_time_minutes=30)

        assert "【判定観点と配点目安】" in prompt
        assert "必須スキル充足度(最大30点)" in prompt
        assert "工程経験適合度(最大20点)" in prompt
        assert "尚可スキル適合度(最大10点)" in prompt
        assert "勤務形態適合度(最大10点)" in prompt
        assert "勤務地/通勤適合度(最大10点)" in prompt
        assert "単価適合度(最大10点)" in prompt
        assert "稼働開始時期適合度(最大5点)" in prompt
        assert "顧客折衝/人物要件適合度(最大5点)" in prompt
        # -30点ペナルティと合計値一致の厳守事項も含まれること
        assert "-30点" in prompt
        assert "match_score は上記「合計" in prompt

    def test_matching_prompt_contains_correct_char_count_ranges(self):
        """出力フォーマットの文字数指定が設計書通り（ai_comment 150-250字・ai_missing 50-150字）であること。"""
        prompt = _build_matching_user_prompt(_make_engineer(), _make_project(), commute_time_minutes=30)

        assert "200字以上300字以下" in prompt   # ai_score_reason
        assert "150字以上250字以下" in prompt   # ai_comment
        assert "50字以上150字以下" in prompt    # ai_missing


class TestInvokeProfileSummaryParameters:
    def test_uses_max_tokens_600_per_design_doc(self, mocker):
        """E2のmax_tokensがAIプロンプト設計書 v0.3 §4.2の仕様通り600であること。"""
        mock_client = MagicMock()
        mocker.patch.object(svc, "_get_client", return_value=mock_client)
        mock_client.invoke_model.return_value = _bedrock_response("要約テキスト")

        invoke_profile_summary("アピールポイントのテキスト")

        sent_body = json.loads(mock_client.invoke_model.call_args.kwargs["body"])
        assert sent_body["max_tokens"] == 600
        assert sent_body["temperature"] == 0.3
        assert sent_body["top_p"] == 0.9

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
