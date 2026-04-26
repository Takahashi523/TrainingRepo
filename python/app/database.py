"""
DB 接続設定
- SQLAlchemy を使用（Python の ORM デファクトスタンダード）
- テーブル定義・マイグレーションは Laravel 側で管理
- Python 側は読み取りメインの使用
"""
from sqlalchemy import create_engine
from sqlalchemy.orm import sessionmaker, DeclarativeBase
from dotenv import load_dotenv
import os

load_dotenv()

# DB 接続 URL の組み立て
DATABASE_URL = (
    f"mysql+pymysql://{os.getenv('DB_USER')}:{os.getenv('DB_PASSWORD')}"
    f"@{os.getenv('DB_HOST')}:{os.getenv('DB_PORT')}/{os.getenv('DB_NAME')}"
)

# エンジン作成
engine = create_engine(DATABASE_URL)

# セッションファクトリ
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)


class Base(DeclarativeBase):
    """ORM モデルの基底クラス"""
    pass


def get_db():
    """FastAPI の Depends で使用する DB セッション取得関数"""
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
