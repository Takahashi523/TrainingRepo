"""
E1 マッチング計算・E3 ヘルスチェックエンドポイントのテスト。
DB 依存は dependency_overrides で差し替え、calculate_matching は mocker.patch でモック化する。
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
    NoActiveCandidateError,
)


# ---------------------------------------------------------------------------
# DB 依存を差し替え（実 DB に接続しない）
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
        total_hits=num_matches,
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
    def test_returns_200_with_matches(self, mocker):
        """正常系：マッチング結果を返すこと。"""
        mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(engineer_id=1, num_matches=2),
        )

        response = client.post(
            "/api/v1/matching/calculate",
            json={"engineer_id": 1},
        )

        assert response.status_code == 200
        body = response.json()
        assert body["engineer_id"] == 1
        assert body["total_hits"] == 2
        assert len(body["matches"]) == 2
        assert body["matches"][0]["match_score"] == 75
        assert body["matches"][0]["match_rank"] == "B"
        assert "generated_at" in body

    def test_generated_at_is_iso8601(self, mocker):
        """generated_at が ISO8601 文字列であること。"""
        mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(num_matches=1),
        )

        response = client.post(
            "/api/v1/matching/calculate",
            json={"engineer_id": 1},
        )

        body = response.json()
        datetime.fromisoformat(body["generated_at"])  # パース成功すれば ISO8601

    def test_returns_empty_matches_when_no_candidates(self, mocker):
        """候補ゼロの場合、matches が空配列で 200 を返すこと。"""
        mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(num_matches=0),
        )

        response = client.post(
            "/api/v1/matching/calculate",
            json={"engineer_id": 1},
        )

        assert response.status_code == 200
        assert response.json()["matches"] == []

    def test_passes_project_ids_to_run_matching(self, mocker):
        """project_ids が run_matching に渡されること。"""
        mock_run = mocker.patch(
            "app.routers.matching.run_matching",
            return_value=_make_output(num_matches=1),
        )

        client.post(
            "/api/v1/matching/calculate",
            json={"engineer_id": 1, "project_ids": [10, 20, 30]},
        )

        assert mock_run.call_args.args[2] == [10, 20, 30]

    def test_returns_404_for_unknown_engineer(self, mocker):
        """存在しない engineer_id の場合、404 と ENGINEER_NOT_FOUND を返すこと。"""
        mocker.patch(
            "app.routers.matching.run_matching",
            side_effect=EngineerNotFoundError(999),
        )

        response = client.post(
            "/api/v1/matching/calculate",
            json={"engineer_id": 999},
        )

        assert response.status_code == 404
        body = response.json()
        assert body["error_code"] == "ENGINEER_NOT_FOUND"
        assert "message" in body

    def test_returns_504_on_bedrock_timeout(self, mocker):
        """Bedrock タイムアウト（リトライ後も失敗）の場合、504 と UPSTREAM_TIMEOUT を返すこと。"""
        mocker.patch(
            "app.routers.matching.run_matching",
            side_effect=BedrockError("Bedrock タイムアウト"),
        )

        response = client.post(
            "/api/v1/matching/calculate",
            json={"engineer_id": 1},
        )

        assert response.status_code == 504
        body = response.json()
        assert body["error_code"] == "UPSTREAM_TIMEOUT"
        assert "message" in body

    def test_returns_422_for_no_active_project(self, mocker):
        """パイプライン除外後に候補ゼロの場合、422 と NO_ACTIVE_PROJECT を返すこと。"""
        mocker.patch(
            "app.routers.matching.run_matching",
            side_effect=NoActiveCandidateError(1),
        )

        response = client.post(
            "/api/v1/matching/calculate",
            json={"engineer_id": 1},
        )

        assert response.status_code == 422
        body = response.json()
        assert body["error_code"] == "NO_ACTIVE_PROJECT"
        assert "message" in body

    def test_returns_422_for_missing_engineer_id(self):
        """engineer_id が未指定の場合、Pydantic バリデーションエラー 422 を返すこと。"""
        response = client.post(
            "/api/v1/matching/calculate",
            json={},
        )

        assert response.status_code == 422
