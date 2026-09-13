"""コネクションプール設定のリグレッションテスト。

アイドル接続が MySQL 側で切られた後の初回リクエストが 500 になる不具合
（本番 EC2 で発生を確認）の再発防止。実際の切断はテストでは再現できないため、
「対策の設定が入っていること」自体を固定する。
"""

from app.models.db import engine

# MySQL の wait_timeout の既定値（秒）。pool_recycle はこれより必ず短くする。
MYSQL_DEFAULT_WAIT_TIMEOUT = 28800


def test_pool_pre_ping_is_enabled():
    """貸し出し前の疎通確認が有効であること。

    無効だと、MySQL が wait_timeout で切った接続をそのまま使ってしまい
    "MySQL server has gone away (BrokenPipeError)" で 500 になる。
    """
    assert engine.pool._pre_ping is True


def test_pool_recycle_is_shorter_than_mysql_wait_timeout():
    """接続の再作成周期が MySQL の wait_timeout より短いこと。

    SQLAlchemy の既定は -1（再作成しない）。正の値であること、かつ
    MySQL 側が切断するより先にこちらから作り直す設定であることを確認する。
    """
    assert 0 < engine.pool._recycle < MYSQL_DEFAULT_WAIT_TIMEOUT
