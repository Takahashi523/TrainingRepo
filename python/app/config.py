from dotenv import load_dotenv
import os

load_dotenv()


# --- クラスベースの設定（テストコードやPydanticが期待する形式） ---
class Settings:
    APP_ENV: str = os.getenv("APP_ENV", "local")

    # DB 設定
    DB_HOST: str = os.getenv("DB_HOST", "mysql")
    DB_PORT: int = int(os.getenv("DB_PORT", "3306"))
    DB_NAME: str = os.getenv("DB_NAME", "ses_matching")
    DB_USER: str = os.getenv("DB_USER", "root")
    DB_PASSWORD: str = os.getenv("DB_PASSWORD", "secret")

    # AWS 設定
    AWS_REGION: str = os.getenv("AWS_REGION", "ap-northeast-1")

    # SSM パラメータ名
    GOOGLE_MAPS_API_KEY_SSM_NAME: str = os.getenv(
        "GOOGLE_MAPS_API_KEY_SSM_NAME", "Nexus-google-maps-key"
    )

    # AWSアカウント未接続時の手動確認用スタブモード。
    # .env に MOCK_MODE=true を設定した場合のみ有効化される（未設定時は False）。
    # pytest 実行時は invoke_matching/invoke_profile_summary をモックする前提のため、
    # 常に実装コードパスを通す False をデフォルトとする。
    MOCK_MODE: bool = os.getenv("MOCK_MODE", "false").lower() == "true"


# テストコードや他のモジュールがインポートできるように実体（インスタンス）化
settings = Settings()


# --- 互換性維持のためのグローバル変数（既存のコードが直接参照している場合用） ---
APP_ENV = settings.APP_ENV
DB_HOST = settings.DB_HOST
DB_PORT = settings.DB_PORT
DB_NAME = settings.DB_NAME
DB_USER = settings.DB_USER
DB_PASSWORD = settings.DB_PASSWORD
AWS_REGION = settings.AWS_REGION
GOOGLE_MAPS_API_KEY_SSM_NAME = settings.GOOGLE_MAPS_API_KEY_SSM_NAME
MOCK_MODE = settings.MOCK_MODE
