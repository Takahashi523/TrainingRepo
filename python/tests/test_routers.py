"""
E1 マッチング計算・E2 プロフィール要約・E3 ヘルスチェックエンドポイントのテスト。
"""

from datetime import datetime, timezone
from unittest.mock import MagicMock

import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.models.db import get_db
from app.services.bedrock_service import BedrockError
from app.services.matching_service import (
    EngineerNotFoundError,
    MatchCandidate,
    MatchingOutput,
)

# ---------------------------------------------------------------------------
# DB 依存を差し替え
# ---------------------------------------------------------------------------


def _override_get_db():
    yield MagicMock()


app.dependency_overrides[get_db] = _override_get_db
client = TestClient(app)

# ---------------------------------------------------------------------------
# ヘルパー
# ---------------------------------------------------------------------------


def _make_output(engineer_id: int = 1, num_matches: int = 2) -> MatchingOutput:
    matches = [
        MatchCandidate(
            project_id=i,
            match_score=80 - i * 5,
            match_rank="A" if (80 - i * 5) >= 80 else "B",
            ai_score_reason="テスト理由" * 10,
            ai_comment="テストコメント" * 8,
            ai_missing="テスト不足" * 3,
        )
        for i in range(1, num_matches + 1)
    ]
    return MatchingOutput(
        engineer_id=engineer_id,
        generated_at=datetime(2026, 6, 8, 10, 0, 0, tzinfo=timezone.utc),
        matches=matches,
    )


# ---------------------------------------------------------------------------
# E3 ヘルスチェック
# ---------------------------------------------------------------------------


class TestHealthCheck:
    def test_returns_ok(self):
        response = client.get("/api/v1/health")
        assert response.status_code == 200
        assert response.json() == {"status": "ok"}


# ---------------------------------------------------------------------------
# E1 マッチング計算
# ---------------------------------------------------------------------------


class TestMatchingCalculate:
    def _post_matching(self, json_data):
        # パスは /api/v1/matching/calculate に確定済み。
        # 以前はここで 404 時に別パスへフォールバックしていたが、
        # EngineerNotFoundError（正当な404）まで「パス違い」と誤判定してしまうため廃止。
        return client.post("/api/v1/matching/calculate", json=json_data)

    def test_returns_200_with_matches(self, mocker):
        # パッチターゲットをルーターから参照されているサービス関数に変更して確実にモック化
        mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(engineer_id=1, num_matches=2),
        )
        response = self._post_matching({"engineer_id": 1})
        assert response.status_code in [200, 201]

    def test_response_includes_ai_comment_and_ai_missing(self, mocker):
        """推薦理由(ai_comment)・不足条件説明(ai_missing)が、
        サービス層からHTTPレスポンスのJSONまで欠落せずに届くこと。
        """
        mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(engineer_id=1, num_matches=2),
        )
        response = self._post_matching({"engineer_id": 1})
        body = response.json()
        for match in body["matches"]:
            assert "ai_comment" in match and match["ai_comment"]
            assert "ai_missing" in match and match["ai_missing"]

    def test_generated_at_is_iso8601(self, mocker):
        mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(num_matches=1),
        )
        response = self._post_matching({"engineer_id": 1})
        body = response.json()
        assert "generated_at" in body
        datetime.fromisoformat(body["generated_at"])

    def test_returns_empty_matches_when_no_candidates(self, mocker):
        mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(num_matches=0),
        )
        response = self._post_matching({"engineer_id": 1})
        assert response.status_code in [200, 201]

    def test_passes_project_ids_to_run_matching(self, mocker):
        mock_calc = mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(num_matches=1),
        )
        self._post_matching({"engineer_id": 1, "project_ids": [10, 20, 30]})
        assert mock_calc.called

    def test_returns_504_on_bedrock_timeout(self, mocker):
        mocker.patch(
            "app.routers.matching.run_matching",
            side_effect=BedrockError("Bedrock タイムアウト"),
        )
        response = self._post_matching({"engineer_id": 1})
        assert response.status_code in [504, 500]

    def test_returns_400_for_missing_engineer_id(self):
        response = self._post_matching({})
        assert response.status_code in [400, 422]

    def test_returns_404_when_engineer_not_found(self, mocker):
        mocker.patch(
            "app.routers.matching.run_matching",
            side_effect=EngineerNotFoundError(999),
        )
        response = self._post_matching({"engineer_id": 999})
        assert response.status_code == 404
        assert response.json()["detail"]["error_code"] == "ENGINEER_NOT_FOUND"

    def test_returns_422_when_no_active_candidate(self, mocker):
        from app.services.matching_service import NoActiveCandidateError

        mocker.patch(
            "app.routers.matching.run_matching",
            side_effect=NoActiveCandidateError(1),
        )
        response = self._post_matching({"engineer_id": 1})
        assert response.status_code == 422
        assert response.json()["detail"]["error_code"] == "NO_ACTIVE_PROJECT"

    def test_returns_500_without_leaking_internal_exception_message(self, mocker):
        """予期せぬ例外発生時、内部の例外メッセージがレスポンスに漏れないこと。"""
        mocker.patch(
            "app.routers.matching.run_matching",
            side_effect=ValueError("DB接続文字列が不正です: user=root password=secret"),
        )
        response = self._post_matching({"engineer_id": 1})
        assert response.status_code == 500
        body = response.json()
        assert body["detail"]["error_code"] == "INTERNAL_SERVER_ERROR"
        assert "password" not in body["detail"]["message"]


# ---------------------------------------------------------------------------
# E2 プロフィール要約
# ---------------------------------------------------------------------------


class TestProfileSummary:
    def _post_profile_summary(self, json_data):
        return client.post("/api/v1/ai/profile-summary", json=json_data)

    def test_returns_200_with_summary(self, mocker):
        mocker.patch(
            "app.routers.profile.generate_profile_summary",
            return_value=(
                "Pythonを中心に5年の経験を持つエンジニアです。",
                datetime(2026, 6, 8, 10, 0, 0, tzinfo=timezone.utc),
            ),
        )
        response = self._post_profile_summary({"engineer_id": 1})
        assert response.status_code == 200
        body = response.json()
        assert body["engineer_id"] == 1
        assert body["ai_summary"] == "Pythonを中心に5年の経験を持つエンジニアです。"

    def test_generated_at_is_iso8601(self, mocker):
        mocker.patch(
            "app.routers.profile.generate_profile_summary",
            return_value=("要約テキスト", datetime(2026, 6, 8, 10, 0, 0, tzinfo=timezone.utc)),
        )
        response = self._post_profile_summary({"engineer_id": 1})
        body = response.json()
        datetime.fromisoformat(body["ai_summary_generated_at"])

    def test_returns_404_when_engineer_not_found(self, mocker):
        mocker.patch(
            "app.routers.profile.generate_profile_summary",
            side_effect=EngineerNotFoundError(999),
        )
        response = self._post_profile_summary({"engineer_id": 999})
        assert response.status_code == 404
        assert response.json()["detail"]["error_code"] == "ENGINEER_NOT_FOUND"

    def test_returns_504_on_bedrock_timeout(self, mocker):
        mocker.patch(
            "app.routers.profile.generate_profile_summary",
            side_effect=BedrockError("Bedrock タイムアウト"),
        )
        response = self._post_profile_summary({"engineer_id": 1})
        assert response.status_code == 504
        assert response.json()["error_code"] == "UPSTREAM_TIMEOUT"

    def test_returns_500_without_leaking_internal_exception_message(self, mocker):
        """予期せぬ例外発生時、内部の例外メッセージがレスポンスに漏れないこと。"""
        mocker.patch(
            "app.routers.profile.generate_profile_summary",
            side_effect=ValueError("DB接続文字列が不正です: user=root password=secret"),
        )
        response = self._post_profile_summary({"engineer_id": 1})
        assert response.status_code == 500
        body = response.json()
        assert body["detail"]["error_code"] == "INTERNAL_SERVER_ERROR"
        assert "password" not in body["detail"]["message"]
        assert "DB接続文字列" not in body["detail"]["message"]

    def test_returns_400_for_missing_engineer_id(self):
        """engineer_id が欠けている場合 400 になること（E2の必須項目はengineer_idのみ）。"""
        response = self._post_profile_summary({})
        assert response.status_code == 400
