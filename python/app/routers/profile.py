from fastapi import APIRouter
from app.models.schemas import ProfileSummaryRequest, ProfileSummaryResponse

router = APIRouter()


@router.post("/api/v1/ai/profile-summary", response_model=ProfileSummaryResponse)
def generate_profile_summary(request: ProfileSummaryRequest):
    # Step 8 で実装予定
    raise NotImplementedError
