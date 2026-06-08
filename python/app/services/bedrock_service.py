from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass
from typing import Optional

import boto3
from botocore.exceptions import BotoCoreError, ClientError

from app.config import AWS_REGION
from app.services.matching_service import EngineerData, ProjectData

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
# 定数
# ---------------------------------------------------------------------------

_PROC_LABELS = {
    "proc_requirements": "要件定義",
    "proc_basic_design": "基本設計",
    "proc_detail_design": "詳細設計",
    "proc_development": "開発",
    "proc_testing": "テスト",
    "proc_maintenance": "保守運用",
}

_WORK_STYLE_JP = {
    "onsite": "常駐",
    "hybrid": "一部リモート可",
    "remote": "フルリモート",
}

_MATCHING_SYSTEM_PROMPT = """あなたはSES営業を補助するアシスタントです。
以下の人材情報と案件情報を読み、人材が案件にどの程度適合するかを総合判定し、
必ず指定の JSON 形式で出力してください。

判定の原則：
- 提示データの範囲で回答してください。記載のない事項について推測や断定はしないでください。
- ソフトスキル（マネジメント経験・上流工程経験・議事録作成経験等）はアピールポイントおよび人材スキルの自由記述から判断してください。
- 単価・稼働時期・勤務形態・勤務地などの定量条件は厳密に判定してください。
- match_score は各観点の点数の合計値と必ず一致させること。AI独自の総合判断による値ではなく、足し算結果をそのまま出力すること。"""

_RETRY_SYSTEM_PROMPT = """あなたはSES営業を補助するアシスタントです。
前回出力はJSON形式違反でした。指定のJSONスキーマに従い、JSONオブジェクトのみを出力してください。
説明文・前置き・コードフェンスは一切含めないでください。"""

_SUMMARY_SYSTEM_PROMPT = """あなたはSES営業を補助するアシスタントです。
人材のアピールポイントを読み、強み・特徴を簡潔に要約してください。
事実に基づき、提示データの範囲で回答してください。
推測や断定は避け、書かれていない情報は要約に含めないでください。"""


# ---------------------------------------------------------------------------
# プロンプト構築ヘルパー
# ---------------------------------------------------------------------------

def _tinyint_label(value: Optional[int], true_lbl: str, false_lbl: str) -> str:
    if value == 1:
        return true_lbl
    if value == 0:
        return false_lbl
    return "未入力"


def _proc_list(obj: object) -> str:
    """工程経験カラムのうち値=1 のラベルをカンマ区切りで返す。"""
    active = [lbl for field, lbl in _PROC_LABELS.items() if getattr(obj, field, None) == 1]
    return "、".join(active) if active else "なし"


def _engineer_work_style(e: EngineerData) -> str:
    styles = []
    if e.work_style_onsite == 1:
        styles.append("常駐可")
    if e.work_style_hybrid == 1:
        styles.append("一部リモート可")
    if e.work_style_remote == 1:
        styles.append("フルリモート希望")
    return "、".join(styles) if styles else "未入力"


def _skills_text(skills: list, indent: str = "  ") -> str:
    if not skills:
        return f"{indent}（なし）"
    return "\n".join(
        f"{indent}- {s.label or '（ラベルなし）'}: {s.detail or '詳細なし'}"
        for s in skills
    )


def _build_matching_user_prompt(
    engineer: EngineerData,
    project: ProjectData,
    commute_time_minutes: Optional[int],
) -> str:
    desired_rate = f"{engineer.desired_rate} 万円/月" if engineer.desired_rate is not None else "希望なし"
    rate_range = (
        f"{project.rate_min or 'NULL'}〜{project.rate_max or 'NULL'} 万円/月"
        f"（備考: {project.rate_note or 'なし'}）"
    )
    work_style_jp = _WORK_STYLE_JP.get(project.work_style or "", "未入力")
    commute_str = str(commute_time_minutes) if commute_time_minutes is not None else "NULL（算出失敗）"

    required_skills = [s for s in project.skills if s.skill_type == "required"]
    preferred_skills = [s for s in project.skills if s.skill_type == "preferred"]

    return f"""以下の人材と案件のマッチングを総合判定してください。

【人材プロフィール】
- アピールポイント: {engineer.appeal_note or '未入力'}
- 顧客折衝経験: {_tinyint_label(engineer.has_negotiation_exp, '有', '無')}
- 稼働可能時期: {engineer.available_from or '未入力'}
- 希望単価: {desired_rate}
- 最寄駅: {engineer.nearest_station or '未入力'}
- 経験工程: {_proc_list(engineer)}
- 勤務形態希望: {_engineer_work_style(engineer)}
- 保有スキル:
{_skills_text(engineer.skills)}

【案件情報】
- 業務内容詳細: {project.description or '未入力'}
- 顧客折衝経験要否: {_tinyint_label(project.negotiation_required, '要', '不問')}
- 参画開始時期: {project.start_date or '未入力'}
- 単価レンジ: {rate_range}
- 稼働形態: {work_style_jp}
- 勤務地: {project.work_location_station or '未入力'}
- 対象工程: {_proc_list(project)}
- 必須スキル:
{_skills_text(required_skills)}
- 尚可スキル:
{_skills_text(preferred_skills)}

【通勤時間（外部API算出値）】
commute_time_minutes: {commute_str}

【判定観点と配点目安】
合計100点満点で総合判定してください。

■ 必須スキル充足度(最大30点)
- 充足率の比例和方式: (各スキル充足率の平均) × 30
- 必須スキルを1つも保有しない場合: 0点 かつ総合スコアから30点を減点

■ 工程経験適合度(最大20点)
- |案件対象工程 ∩ 人材経験工程| ÷ |案件対象工程| × 20

■ 尚可スキル適合度(最大10点)
- |案件尚可スキル ∩ 人材保有スキル| ÷ |案件尚可スキル| × 10
- 案件に尚可スキルなし → 満点(10点)

■ 勤務形態適合度(最大10点)
- 人材の勤務形態希望と案件稼働形態のマトリクスで判定

■ 勤務地/通勤適合度(最大10点)
- ≤30分→10点 / ≤60分→7点 / ≤90分→4点 / それ以上→0点
- 両者フルリモート→10点 / NULL→0点（ai_score_reason に明記）

■ 単価適合度(最大10点)
- レンジ内→10点 / 上限超過5万以内→5点 / 希望なし→10点 / それ以外→0点

■ 稼働開始時期適合度(最大5点)
- 稼働可能≤案件開始→5点 / 30日以内→3点 / 60日以内→1点 / それ以上→0点

■ 顧客折衝/人物要件適合度(最大5点)
- 案件要×人材有→5点 / 不問→5点 / 案件要×人材無→0点

合計 = 上記8観点の合計（理論最大100点）
※ 必須スキル該当0の場合は合計からさらに-30点ペナルティ

【スコア閾値】
A: 80〜100点 / B: 65〜79点 / C: 50〜64点 / D: 0〜49点

【スコアのクランプ処理】
合計値が0未満になる場合は必ず 0 として出力すること。

【出力フォーマット(厳守・他の文章は一切出力しないこと)】
{{
  "match_score": <0〜100 の整数>,
  "match_rank": "<A | B | C | D>",
  "ai_score_reason": "<200字以上300字以下>",
  "ai_comment": "<150字以上250字以下>",
  "ai_missing": "<50字以上150字以下>"
}}"""


def _build_summary_user_prompt(appeal_note: str) -> str:
    return f"""以下のアピールポイントから、人材の強み・特徴を 300〜400字程度で要約してください。
箇条書きではなく、自然な日本語の文章で記述してください。

【アピールポイント】
{appeal_note}"""


# ---------------------------------------------------------------------------
# Bedrock 呼び出し
# ---------------------------------------------------------------------------

def _invoke_model(system_prompt: str, user_prompt: str, max_tokens: int) -> str:
    """指数バックオフでリトライする Bedrock invoke_model ラッパー。
    失敗し続けた場合は BedrockError を送出する。
    """
    client = _get_client()
    body = {
        "anthropic_version": "bedrock-2023-05-31",
        "max_tokens": max_tokens,
        "temperature": 0.3,
        "top_p": 0.9,
        "system": system_prompt,
        "messages": [{"role": "user", "content": user_prompt}],
    }
    max_retries = 3
    for attempt in range(max_retries + 1):
        try:
            response = client.invoke_model(
                modelId=MODEL_ID,
                body=json.dumps(body, ensure_ascii=False),
                contentType="application/json",
                accept="application/json",
            )
            raw = json.loads(response["body"].read())
            return raw["content"][0]["text"]
        except (ClientError, BotoCoreError) as exc:
            logger.warning(
                "Bedrock invoke_model 失敗 attempt=%d model_id=%s prompt_version=%s error=%s",
                attempt,
                MODEL_ID,
                PROMPT_VERSION,
                exc,
            )
            if attempt == max_retries:
                raise BedrockError(f"Bedrock 呼び出し失敗（{max_retries}回リトライ後）: {exc}") from exc
            time.sleep(2 ** attempt)


def _parse_matching_json(text: str) -> dict:
    """AI テキストから JSON を抽出してパースする。
    コードフェンスが含まれる場合は除去する。
    """
    text = text.strip()
    if text.startswith("```"):
        lines = text.splitlines()
        text = "\n".join(
            line for i, line in enumerate(lines)
            if not (i == 0 or i == len(lines) - 1) or not line.startswith("```")
        )
    return json.loads(text)


def _determine_rank(score: int) -> str:
    """match_score からランクを算定する（AI 出力の検算）。"""
    if score >= 80:
        return "A"
    if score >= 65:
        return "B"
    if score >= 50:
        return "C"
    return "D"


def invoke_matching(
    engineer: EngineerData,
    project: ProjectData,
    commute_time_minutes: Optional[int],
) -> MatchingAIResult:
    """Bedrock でマッチング判定を実行し MatchingAIResult を返す。
    JSON パース失敗時は §3.6.1 の専用プロンプトで 1 回リトライする。
    """
    user_prompt = _build_matching_user_prompt(engineer, project, commute_time_minutes)

    # 通常呼び出し
    text = _invoke_model(_MATCHING_SYSTEM_PROMPT, user_prompt, max_tokens=800)

    try:
        data = _parse_matching_json(text)
    except (json.JSONDecodeError, KeyError, ValueError):
        logger.warning(
            "JSON パース失敗。リトライプロンプトで再呼び出し。model_id=%s prompt_version=%s",
            MODEL_ID,
            PROMPT_VERSION,
        )
        retry_prompt = (
            user_prompt
            + "\n\n※ 前回出力は JSON 形式違反でした。"
            "{match_score, match_rank, ai_score_reason, ai_comment, ai_missing}"
            " の5キーを持つ純粋なJSONオブジェクトのみを出力してください。"
        )
        text = _invoke_model(_RETRY_SYSTEM_PROMPT, retry_prompt, max_tokens=800)
        try:
            data = _parse_matching_json(text)
        except (json.JSONDecodeError, KeyError, ValueError) as exc:
            raise BedrockError(f"JSON パースリトライ後も失敗: {exc}") from exc

    # クランプ処理（TINYINT UNSIGNED との整合）
    raw_score = int(data["match_score"])
    final_score = max(0, raw_score)

    logger.info(
        "マッチング判定完了 model_id=%s prompt_version=%s raw_score=%d final_score=%d",
        MODEL_ID,
        PROMPT_VERSION,
        raw_score,
        final_score,
    )

    return MatchingAIResult(
        match_score=final_score,
        match_rank=_determine_rank(final_score),  # AI 出力ではなくアプリ層で検算
        ai_score_reason=str(data["ai_score_reason"]),
        ai_comment=str(data["ai_comment"]),
        ai_missing=str(data["ai_missing"]),
    )


def invoke_profile_summary(appeal_note: str) -> str:
    """Bedrock でプロフィール要約を生成してテキストを返す。
    appeal_note が空の場合は空文字を返す（Bedrock を呼ばない）。
    """
    if not appeal_note or not appeal_note.strip():
        return ""

    user_prompt = _build_summary_user_prompt(appeal_note)
    text = _invoke_model(_SUMMARY_SYSTEM_PROMPT, user_prompt, max_tokens=600)

    logger.info(
        "プロフィール要約生成完了 model_id=%s prompt_version=%s",
        MODEL_ID,
        PROMPT_VERSION,
    )
    return text.strip()
