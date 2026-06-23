from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass
from typing import Optional

import boto3
from botocore.exceptions import BotoCoreError, ClientError

from app.config import AWS_REGION
# 【循環インポート解消】matching_service からではなく、新設した internal_types からデータクラスを読み込む
from app.models.internal_types import EngineerData, ProjectData

logger = logging.getLogger(__name__)

MODEL_ID = "anthropic.claude-3-5-sonnet-20240620-v1:0"
PROMPT_VERSION = "v0.3"

# IAM ロールベースで boto3 が自動的に認証情報を取得するため APIキーの直書きは行わない
_bedrock_client = None


def _get_client():
    global _bedrock_client
    if _bedrock_client is None:
        _bedrock_client = boto3.client("bedrock-runtime", region_name=AWS_REGION)
    return _bedrock_client


# ---------------------------------------------------------------------------
# カスタム例外
# ---------------------------------------------------------------------------

class BedrockError(Exception):
    """Bedrock 呼び出し失敗・リトライ枯渇時の例外。"""
    pass


# ---------------------------------------------------------------------------
# 内部データクラス
# ---------------------------------------------------------------------------

@dataclass
class MatchingAIResult:
    """マッチング判定の AI 出力（クランプ・ランク検算済み）。"""
    match_score: int    # 0〜100（クランプ済み）
    match_rank: str     # A / B / C / D（アプリ層で検算）
    ai_score_reason: str
    ai_comment: str
    ai_missing: str


# ---------------------------------------------------------------------------
# ランク判定ヘルパー（設計書通りの閾値で検算）
# ---------------------------------------------------------------------------

def _determine_rank(score: int) -> str:
    if score >= 80:
        return "A"
    elif score >= 65:
        return "B"
    elif score >= 50:
        return "C"
    else:
        return "D"


# ---------------------------------------------------------------------------
# 共通 Bedrock 呼び出しラッパー
# ---------------------------------------------------------------------------

def _invoke_model(system_prompt: str, user_prompt: str, max_tokens: int) -> str:
    """共通のリトライ付き Bedrock 呼び出し処理。
    タイムアウトや各種エラー時はリトライを行い、枯渇した場合は BedrockError を送出する。
    """
    client = _get_client()
    
    body = json.dumps({
        "anthropic_version": "bedrock-2023-05-31",
        "max_tokens": max_tokens,
        "system": system_prompt,
        "messages": [
            {"role": "user", "content": user_prompt}
        ],
        "temperature": 0.0,  # 決定論的な出力を得るため 0.0 に固定
    })

    # 最大3回試行（初回 + リトライ2回）
    for attempt in range(3):
        try:
            response = client.invoke_model(
                modelId=MODEL_ID,
                contentType="application/json",
                accept="application/json",
                body=body,
            )
            response_body = json.loads(response.get("body").read())
            return str(response_body["content"][0]["text"])
            
        except (BotoCoreError, ClientError, json.JSONDecodeError) as e:
            logger.warning("Bedrock 呼び出し試行 %d 回目失敗: %s", attempt + 1, e)
            if attempt < 2:
                time.sleep(1.0 * (attempt + 1))  # 簡易的なバックオフ
                continue
            raise BedrockError(f"Bedrock への接続または応答の取得に失敗しました (リトライ枯渇): {e}") from e

    raise BedrockError("Bedrock 呼び出しが予期せずリトライ制限に達しました")


def _parse_matching_json(text: str) -> dict:
    """AIが出力したテキストからJSON部分を抽出しパースする。"""
    text_clean = text.strip()
    # 稀に ```json ... ``` で囲まれるケースへの防衛策
    if text_clean.startswith("```"):
        lines = text_clean.splitlines()
        if lines[0].startswith("```"):
            lines = lines[1:]
        if lines and lines[-1].startswith("```"):
            lines = lines[:-1]
        text_clean = "\n".join(lines).strip()

    return json.loads(text_clean)


# ---------------------------------------------------------------------------
# E1: マッチング判定プロンプト制御
# ---------------------------------------------------------------------------

_MATCHING_SYSTEM_PROMPT = (
    "あなたは高度なIT人材マッチングを行うシステムです。給与、スキル、工程、勤務形態、"
    "通勤時間などの条件を総合的に分析し、指定されたフォーマットのJSONオブジェクトのみを返却してください。"
)


def invoke_matching(
    engineer: EngineerData,
    project: ProjectData,
    commute_time_minutes: Optional[int],
) -> MatchingAIResult:
    """Bedrock を呼び出して指定された案件とのマッチング判定を行い、クランプ・ランク検算済みの結果を返す。"""
    
    # プロンプトの組み立て（エンジニア情報、案件情報、通勤時間を埋め込む）
    user_prompt = (
        f"以下のエンジニア情報と案件情報を比較し、マッチング度を判定してください。\n\n"
        f"【エンジニア情報】\n"
        f"- 希望単価: {engineer.desired_rate}万円\n"
        f"- 最寄り駅: {engineer.nearest_station}\n"
        f"- スキル詳細: {[s.label for s in engineer.skills if s.label]}\n"
        f"\n"
        f"【案件情報】\n"
        f"- 案件内容: {project.description}\n"
        f"- 単価レンジ: {project.rate_min}〜{project.rate_max}万円\n"
        f"- 勤務地最寄り駅: {project.work_location_station}\n"
        f"- 計算された通勤時間: {commute_time_minutes if commute_time_minutes is not None else '算出失敗(NULL)'} 分\n"
        f"\n"
        f"【出力フォーマット】\n"
        f"以下の5つのキーを持つJSONのみを出力してください。余計な挨拶や解説文は一切含めないでください。\n"
        f"{{\n"
        f"  \"match_score\": 0から100の整数,\n"
        f"  \"match_rank\": \"A\", \"B\", \"C\", \"D\" のいずれか,\n"
        f"  \"ai_score_reason\": \"選定・配点根拠の解説（200文字以上300文字以内）\",\n"
        f"  \"ai_comment\": \"推薦コメント（150文字以上200文字以内）\",\n"
        f"  \"ai_missing\": \"不足スキルや懸念点（100文字以内）\"\n"
        f"}}\n"
    )

    text = _invoke_model(_MATCHING_SYSTEM_PROMPT, user_prompt, max_tokens=1000)
    
    try:
        data = _parse_matching_json(text)
    except (json.JSONDecodeError, KeyError, ValueError) as e:
        logger.warning("初回JSONパース失敗。フォーマットを厳密に指定して再試行します: %s", e)
        
        # フォーマット崩れ時の専用リトライプロンプト（AIプロンプト設計書に準拠）
        _RETRY_SYSTEM_PROMPT = "あなたはJSON出力専用のバリデーターです。プログラムが直接パースできる純粋なJSONのみを応答してください。"
        retry_prompt = (
            f"前回の出力はJSONとして不正、あるいは必要なキーが不足していました。\n"
            f"以下に提示する同一の条件について、必ず `match_score`, `match_rank`, `ai_score_reason`, `ai_comment`, `ai_missing` "
            f"の5つのキーを正確に持った純粋なJSONオブジェクトのみを出力してください。\n\n"
            f"【条件・テキスト】\n{text}"
        )
        text = _invoke_model(_RETRY_SYSTEM_PROMPT, retry_prompt, max_tokens=800)
        try:
            data = _parse_matching_json(text)
        except (json.JSONDecodeError, KeyError, ValueError) as exc:
            raise BedrockError(f"JSON パースリトライ後も失敗しました（フォーマット不正）: {exc}") from exc

    # アプリ層クランプ処理（TINYINT UNSIGNEDの下限保証）
    raw_score = int(data.get("match_score", 0))
    final_score = max(0, raw_score)

    logger.info(
        "マッチング判定完了 model_id=%s raw_score=%d final_score=%d",
        MODEL_ID,
        raw_score,
        final_score,
    )

    return MatchingAIResult(
        match_score=final_score,
        match_rank=_determine_rank(final_score),  # 設計書に準拠：AI出力を無視し、アプリ層で厳密に検算
        ai_score_reason=str(data.get("ai_score_reason", "")),
        ai_comment=str(data.get("ai_comment", "")),
        ai_missing=str(data.get("ai_missing", "")),
    )


# ---------------------------------------------------------------------------
# E2: プロフィール要約プロンプト制御（最新のAI活用方針・画面入力適合版）
# ---------------------------------------------------------------------------

_PROFILE_SYSTEM_PROMPT = (
    "あなたはプロフェッショナルなITエンジニア専門のエージェントです。提供されたアピールポイントと"
    "スキル情報を基に、クライアント企業（案件元）の心に刺さる魅力的なプロフィール紹介文を常体（である調）で生成してください。"
)


def invoke_profile_summary(appeal_point: str, raw_skills: str) -> str:
    """【仕様修正】画面から入力された「アピールポイント」と「フリーテキストスキル」を基に、
    古い経歴書に依存しない最新仕様のプロフィール紹介文をBedrockで生成する。
    """
    # どちらも空の場合はBedrockを呼ばずに空文字を返す（コスト削減・防衛策）
    if not appeal_point.strip() and not raw_skills.strip():
        return ""

    user_prompt = (
        f"以下の入力情報を基に、求人企業へ推薦するためのプロフィール紹介文を300文字〜400文字程度で生成してください。\n"
        f"エンジニアの強みが客観的かつ具体的に伝わる文章とし、挨拶文や余計な解説は含めず、紹介文本文のみを出力してください。\n\n"
        f"【本人のアピールポイント】\n"
        f"{appeal_point}\n\n"
        f"【保有スキル・経験テクノロジー（フリー入力欄）】\n"
        f"{raw_skills}\n"
    )

    ai_summary = _invoke_model(_PROFILE_SYSTEM_PROMPT, user_prompt, max_tokens=800)
    return ai_summary.strip()
