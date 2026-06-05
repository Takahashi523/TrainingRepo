from dotenv import load_dotenv
import os

load_dotenv()

# アプリ設定
APP_ENV: str = os.getenv("APP_ENV", "local")

# DB 設定
DB_HOST: str = os.getenv("DB_HOST", "mysql")
DB_PORT: int = int(os.getenv("DB_PORT", "3306"))
DB_NAME: str = os.getenv("DB_NAME", "ses_matching")
DB_USER: str = os.getenv("DB_USER", "root")
DB_PASSWORD: str = os.getenv("DB_PASSWORD", "secret")

# AWS 設定
AWS_REGION: str = os.getenv("AWS_REGION", "ap-northeast-1")

# SSM パラメータ名（Google Maps API キーの格納先）
GOOGLE_MAPS_API_KEY_SSM_NAME: str = os.getenv(
    "GOOGLE_MAPS_API_KEY_SSM_NAME", "Nexus-google-maps-key"
)
