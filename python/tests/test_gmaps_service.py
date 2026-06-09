"""
Google Maps Distance Matrix API クライアントのテスト。
SSM・httpx はすべて pytest-mock でモック化し、実際の外部通信は行わない。
"""
import pytest
from unittest.mock import MagicMock

import app.services.gmaps_service as gmaps_module
from app.services.gmaps_service import get_commute_time_minutes


# ---------------------------------------------------------------------------
# フィクスチャ：各テスト前に _api_key キャッシュをリセット
# ---------------------------------------------------------------------------

@pytest.fixture(autouse=True)
def reset_api_key_cache():
    """遅延シングルトンのキャッシュをテストごとにリセットする。"""
    gmaps_module._api_key = None
    yield
    gmaps_module._api_key = None


# ---------------------------------------------------------------------------
# SSM からの API キー取得
# ---------------------------------------------------------------------------

def _mock_ssm(mocker, key_value: str = "test-api-key"):
    """SSM get_parameter をモックして api_key を返す。"""
    mock_ssm_client = MagicMock()
    mock_ssm_client.get_parameter.return_value = {
        "Parameter": {"Value": key_value}
    }
    mocker.patch("boto3.client", return_value=mock_ssm_client)
    return mock_ssm_client


def _ok_response(duration_seconds: int) -> MagicMock:
    """Distance Matrix API の正常レスポンスを返す MagicMock を生成する。"""
    mock_resp = MagicMock()
    mock_resp.json.return_value = {
        "rows": [{"elements": [{"status": "OK", "duration": {"value": duration_seconds}}]}]
    }
    mock_resp.raise_for_status.return_value = None
    return mock_resp


# ---------------------------------------------------------------------------
# 正常系
# ---------------------------------------------------------------------------

class TestGetCommuteTimeMinutes:
    def test_returns_minutes_from_seconds(self, mocker):
        """API が 2700 秒を返した場合、45 分を返すこと。"""
        _mock_ssm(mocker)
        mocker.patch("httpx.get", return_value=_ok_response(2700))

        result = get_commute_time_minutes("渋谷駅", "新宿駅")

        assert result == 45

    def test_truncates_remainder_seconds(self, mocker):
        """端数秒は切り捨てること（1850秒 → 30分）。"""
        _mock_ssm(mocker)
        mocker.patch("httpx.get", return_value=_ok_response(1850))

        result = get_commute_time_minutes("渋谷駅", "新宿駅")

        assert result == 30

    def test_passes_correct_params_to_httpx(self, mocker):
        """origin・destination・mode=transit が httpx.get に渡されること。"""
        _mock_ssm(mocker)
        mock_get = mocker.patch("httpx.get", return_value=_ok_response(600))

        get_commute_time_minutes("渋谷駅", "新宿駅")

        call_kwargs = mock_get.call_args.kwargs
        assert call_kwargs["params"]["origins"] == "渋谷駅"
        assert call_kwargs["params"]["destinations"] == "新宿駅"
        assert call_kwargs["params"]["mode"] == "transit"

    def test_api_key_cached_after_first_call(self, mocker):
        """2回目以降は SSM を呼ばずキャッシュを使うこと。"""
        mock_ssm = _mock_ssm(mocker)
        mocker.patch("httpx.get", return_value=_ok_response(600))

        get_commute_time_minutes("渋谷駅", "新宿駅")
        get_commute_time_minutes("渋谷駅", "池袋駅")

        assert mock_ssm.get_parameter.call_count == 1


# ---------------------------------------------------------------------------
# None を返すケース
# ---------------------------------------------------------------------------

class TestGetCommuteTimeMinutesReturnsNone:
    def test_returns_none_when_origin_is_empty(self, mocker):
        """origin が空文字の場合は None を返すこと（API を呼ばない）。"""
        mock_get = mocker.patch("httpx.get")

        result = get_commute_time_minutes("", "新宿駅")

        assert result is None
        mock_get.assert_not_called()

    def test_returns_none_when_destination_is_empty(self, mocker):
        """destination が空文字の場合は None を返すこと（API を呼ばない）。"""
        mock_get = mocker.patch("httpx.get")

        result = get_commute_time_minutes("渋谷駅", "")

        assert result is None
        mock_get.assert_not_called()

    def test_returns_none_when_element_status_is_not_ok(self, mocker):
        """element status が ZERO_RESULTS の場合は None を返すこと。"""
        _mock_ssm(mocker)
        mock_resp = MagicMock()
        mock_resp.json.return_value = {
            "rows": [{"elements": [{"status": "ZERO_RESULTS"}]}]
        }
        mock_resp.raise_for_status.return_value = None
        mocker.patch("httpx.get", return_value=mock_resp)

        result = get_commute_time_minutes("渋谷駅", "存在しない駅")

        assert result is None

    def test_returns_none_on_http_error(self, mocker):
        """HTTP エラー（4xx/5xx）が発生した場合は None を返すこと。"""
        _mock_ssm(mocker)
        mocker.patch("httpx.get", side_effect=Exception("HTTP 500"))

        result = get_commute_time_minutes("渋谷駅", "新宿駅")

        assert result is None

    def test_returns_none_on_timeout(self, mocker):
        """タイムアウト発生時は None を返すこと。"""
        _mock_ssm(mocker)
        mocker.patch("httpx.get", side_effect=TimeoutError("timeout"))

        result = get_commute_time_minutes("渋谷駅", "新宿駅")

        assert result is None

    def test_returns_none_when_ssm_fails(self, mocker):
        """SSM から API キー取得失敗時は None を返すこと。"""
        mock_ssm_client = MagicMock()
        mock_ssm_client.get_parameter.side_effect = Exception("SSM error")
        mocker.patch("boto3.client", return_value=mock_ssm_client)

        result = get_commute_time_minutes("渋谷駅", "新宿駅")

        assert result is None
