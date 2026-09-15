from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, DeclarativeBase
from app.config import DB_USER, DB_PASSWORD, DB_HOST, DB_PORT, DB_NAME

DATABASE_URL = (
    f"mysql+pymysql://{DB_USER}:{DB_PASSWORD}"
    f"@{DB_HOST}:{DB_PORT}/{DB_NAME}"
)

# ---------------------------------------------------------------------------
# コネクションプールの設定
#
# pool_pre_ping / pool_recycle を指定しないと、しばらくアクセスが無かった後の
# 最初のリクエストが必ず 1 回 500 になる。
#
# MySQL は wait_timeout（既定 28800 秒 = 8 時間）を超えてアイドルした接続を
# サーバ側から切断するが、SQLAlchemy のプールはそれを知らないまま接続を保持し続ける。
# 次のリクエストでその死んだ接続が貸し出され、書き込み時点で初めて気づく：
#
#   pymysql.err.OperationalError:
#     (2006, "MySQL server has gone away (BrokenPipeError(32, 'Broken pipe'))")
#
# fetch_engineer() は EngineerNotFoundError しか送出しないため、この例外は
# そのまま main.py の internal_error_handler に落ちて 500 INTERNAL_ERROR になる。
# Laravel 側からは上流障害（engine_error）として扱われ、画面には
# 「マッチングエンジンとの通信に失敗しました」とだけ表示される。
# 本番の EC2 で実際に発生していることをログで確認済み
# （POST /api/v1/matching/calculate → 500。再実行すると正常に応答する）。
#
# 単体テストでは DB をモックしており、接続がアイドルで切られる状況自体が
# 再現しないため、この不具合はテストでは検出できない。結合テストを実機で
# 実施して初めて顕在化した。
#
# pool_pre_ping:
#   貸し出し前に軽量な疎通確認（SELECT 1 相当）を行い、失敗した接続は破棄して
#   張り直す。1 リクエストあたりの追加コストはローカル接続ではごく小さい。
#
# pool_recycle:
#   指定秒数を超えて生存している接続を、使う前に強制的に作り直す。
#   MySQL の wait_timeout より必ず短い値にすること。3600 秒は既定の
#   wait_timeout（28800 秒）に対して十分な余裕がある。
#   pre_ping との二重化であり、どちらか一方でも動作するが、
#   ping が通ってしまう半死状態の接続を掴まないための保険として併用する。
# ---------------------------------------------------------------------------
engine = create_engine(
    DATABASE_URL,
    pool_pre_ping=True,
    pool_recycle=3600,
)

SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)


class Base(DeclarativeBase):
    pass


def get_db():
    """FastAPI の Depends で使用する DB セッション取得関数"""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
