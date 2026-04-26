"""
FastAPI エントリーポイント
- マッチングエンジン・AI 推薦文生成の API を提供
- Laravel からの HTTP リクエストを受け付ける
"""
from fastapi import FastAPI

app = FastAPI(
    title="SES Matching Engine",
    description="マッチングスコア計算・AI 推薦文生成 API",
    version="1.0.0",
)


@app.get("/")
def root():
    return {"status": "ok"}
