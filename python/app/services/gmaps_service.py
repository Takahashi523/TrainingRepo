from __future__ import annotations

import logging
from typing import Optional

import boto3
import httpx
from botocore.config import Config

from app.config import AWS_REGION, GOOGLE_MAPS_API_KEY_SSM_NAME

logger = logging.getLogger(__name__)

_GMAPS_ENDPOINT = "https://maps.googleapis.com/maps/api/distancematrix/json"

# SSM 呼び出しのタイムアウト。既定値（connect/read とも60秒・リトライあり）のままだと、
# SSM が不通のときに E1 のフロー全体が数分単位でブロックされ得るため明示的に絞る。
# Distance Matrix 側の httpx タイムアウト（5秒）と釣り合う値とし、最悪でも
# (2+3)×2回 = 約10秒で打ち切って None（通勤時間算出失敗）に倒す。
# APIキーは初回取得後にキャッシュされるため、このコストを払うのは起動直後か失敗継続時のみ。
_SSM_CLIENT_CONFIG = Config(
    connect_timeout=2,
    read_timeout=3,
    retries={"max_attempts": 2},
)

# IAM ロールベースで boto3 が自動的に認証情報を取得するため APIキーの直書きは行わない
_api_key: Optional[str] = None


def _get_api_key() -> str:
    """SSM Parameter Store から Google Maps API キーを取得する（遅延シングルトン）。"""
    global _api_key
    if _api_key is None:
        ssm = boto3.client("ssm", region_name=AWS_REGION, config=_SSM_CLIENT_CONFIG)
        response = ssm.get_parameter(Name=GOOGLE_MAPS_API_KEY_SSM_NAME, WithDecryption=True)
        _api_key = response["Parameter"]["Value"]
    return _api_key


def get_commute_time_minutes(origin: str, destination: str) -> Optional[int]:
    """Google Maps Distance Matrix API で通勤時間（分）を返す。

    origin・destination のどちらかが空、または API 呼び出し失敗時は None を返す。
    None を受け取ったマッチングフローは処理を継続し、AI プロンプト内で
    "NULL（算出失敗）" として扱われ通勤適合度は 0 点になる。
    """
    if not origin or not destination:
        return None

    try:
        api_key = _get_api_key()
        response = httpx.get(
            _GMAPS_ENDPOINT,
            params={
                "origins": origin,
                "destinations": destination,
                "mode": "transit",
                "language": "ja",
                "key": api_key,
            },
            timeout=5.0,
        )
        response.raise_for_status()
        data = response.json()

        element = data["rows"][0]["elements"][0]
        if element["status"] != "OK":
            logger.warning(
                "Google Maps API element status=%s origin=%s dest=%s",
                element["status"],
                origin,
                destination,
            )
            return None

        duration_seconds: int = element["duration"]["value"]
        return duration_seconds // 60

    except Exception as exc:
        logger.warning(
            "Google Maps API 呼び出し失敗 origin=%s dest=%s error=%s",
            origin,
            destination,
            exc,
        )
        return None
