from fastapi import FastAPI
from app.routers import matching, profile

app = FastAPI(
    title="Nexus Matching Engine",
    description="マッチングスコア計算・AIプロフィール要約 API",
    version="1.0.0",
)

app.include_router(matching.router)
app.include_router(profile.router)
