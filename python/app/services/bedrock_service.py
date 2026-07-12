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
from botocore.config import Config

logger = logging.getLogger(__name__)

MODEL_ID = "anthropic.claude-3-5-sonnet-20240620-v1:0"
PROMPT_VERSION = "v0.3"

# IAM ロールベースで boto3 が自動的に認証情報を取得するため APIキーの直書きは行わない
_bedrock_client = None

# AIプロンプト設計書 v0.3 §5.4 準拠：Bedrock 1リクエストあたりのタイムアウトは30秒。
# アプリ層の指数バックオフ・リトライ（最大3回）と二重にならないよう、boto3自体のリトライは無効化する。
_BEDROCK_CLIENT_CONFIG = Config(
    connect_timeout=10,
    read_timeout=30,
    retries={"max_attempts": 0},
)


def _get_client():
    global _bedrock_client
    if _bedrock_client is None:
        _bedrock_client = boto3.client(
            "bedrock-runtime", region_name=AWS_REGION, config=_BEDROCK_CLIENT_CONFIG
        )
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
# プロンプト整形ヘルパー（AIプロンプト設計書 v0.3 準拠の表現に変換する）
# ---------------------------------------------------------------------------

# エンジニア・案件の工程経験カラム → 日本語ラベル
_PROC_LABELS: dict[str, str] = {
    "proc_requirements": "要件定義",
    "proc_basic_design": "基本設計",
    "proc_detail_design": "詳細設計",
    "proc_development": "開発",
    "proc_testing": "テスト",
    "proc_maintenance": "保守",
}

# エンジニアの勤務形態希望カラム → 日本語ラベル
_ENGINEER_WORK_STYLE_LABELS: dict[str, str] = {
    "work_style_onsite": "常駐可",
    "work_style_hybrid": "一部リモート可",
    "work_style_remote": "フルリモート希望",
}

# 案件の勤務形態文字列 → 日本語ラベル
_PROJECT_WORK_STYLE_LABELS: dict[str, str] = {
    "onsite": "常駐",
    "hybrid": "一部リモート可",
    "remote": "フルリモート可",
}


def _tinyint_label(value: Optional[int], label_true: str, label_false: str = "不問") -> str:
    """tinyint(0 / 1 / NULL) を日本語ラベルに変換する。
    NULL（未入力）は「不問」と混同しないよう明確に区別する。
    """
    if value is None:
        return "未入力"
    return label_true if value == 1 else label_false


def _skills_text(skills) -> str:
    """スキルのリストを 'ラベル（詳細）' 形式のカンマ区切りテキストに変換する。空リストは '（なし）'。"""
    if not skills:
        return "（なし）"
    parts = []
    for s in skills:
        label = s.label or "(不明)"
        detail = getattr(s, "detail", None)
        parts.append(f"{label}（{detail}）" if detail else label)
    return "、".join(parts)


def _proc_experience_text(data) -> str:
    """proc_* フラグが立っている工程を日本語ラベルで列挙する。該当なしは '（なし）'。"""
    names = [label for field, label in _PROC_LABELS.items() if getattr(data, field) == 1]
    return "、".join(names) if names else "（なし）"


def _engineer_work_style_text(engineer: EngineerData) -> str:
    """エンジニアの勤務形態希望（複数可）を日本語ラベルで列挙する。"""
    names = [
        label for field, label in _ENGINEER_WORK_STYLE_LABELS.items()
        if getattr(engineer, field) == 1
    ]
    return "・".join(names) if names else "指定なし"


def _project_work_style_text(project: ProjectData) -> str:
    """案件の勤務形態を日本語ラベルに変換する。"""
    return _PROJECT_WORK_STYLE_LABELS.get(project.work_style, "指定なし")


# ---------------------------------------------------------------------------
# 共通 Bedrock 呼び出しラッパー
# ---------------------------------------------------------------------------

def _invoke_model(
    system_prompt: str,
    user_prompt: str,
    max_tokens: int,
    temperature: float = 0.3,
    top_p: float = 0.9,
) -> str:
    """共通のリトライ付き Bedrock 呼び出し処理。
    タイムアウトや各種エラー時はリトライを行い、枯渇した場合は BedrockError を送出する。
    パラメータの既定値（temperature=0.3 / top_p=0.9）はスコアリングロジック設計書 v0.6 §5.2 準拠。
    """
    client = _get_client()

    body = json.dumps({
        "anthropic_version": "bedrock-2023-05-31",
        "max_tokens": max_tokens,
        "system": system_prompt,
        "messages": [
            {"role": "user", "content": user_prompt}
        ],
        "temperature": temperature,
        "top_p": top_p,
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
    "あなたはSES営業を補助するアシスタントです。\n"
    "以下の人材情報と案件情報を読み、人材が案件にどの程度適合するかを総合判定し、\n"
    "必ず指定の JSON 形式で出力してください。\n"
    "\n"
    "判定の原則：\n"
    "- 提示データの範囲で回答してください。記載のない事項について推測や断定はしないでください。\n"
    "- ソフトスキル（マネジメント経験・上流工程経験・議事録作成経験等）はアピールポイントおよび人材スキルの自由記述から判断してください。\n"
    "- 単価・稼働時期・勤務形態・勤務地などの定量条件は厳密に判定してください。\n"
    "- 説明・推薦コメントは事実ベースで簡潔に書いてください。\n"
    "- 後述の【判定観点と配点目安】に従って各観点ごとに点数を算出し、合計して総合スコアを決定してください。\n"
    "- match_score は各観点の点数の合計値と必ず一致させること。AI独自の総合判断による値ではなく、上記の足し算結果をそのまま出力すること。"
)


def _build_matching_user_prompt(
    engineer: EngineerData,
    project: ProjectData,
    commute_time_minutes: Optional[int],
) -> str:
    """E1 マッチング判定用のユーザープロンプトを組み立てる（AIプロンプト設計書 v0.3 §3.3 準拠）。
    人材・案件情報に加えて【判定観点と配点目安】をガイドラインとしてプロンプトへ明示的に埋め込み、
    AIが8観点の重視度（必須スキル30点・工程経験20点…）を理解した上で採点できるようにする。
    """
    desired_rate_text = (
        f"{engineer.desired_rate}万円" if engineer.desired_rate is not None else "希望なし"
    )
    commute_text = (
        f"{commute_time_minutes}分" if commute_time_minutes is not None else "NULL（算出失敗）"
    )
    required_skills = [s for s in project.skills if s.skill_type == "required"]
    preferred_skills = [s for s in project.skills if s.skill_type == "preferred"]

    return (
        f"以下の人材と案件のマッチングを総合判定してください。\n"
        f"\n"
        f"【人材プロフィール】\n"
        f"- アピールポイント: {engineer.appeal_note or '（なし）'}\n"
        f"- 顧客折衝経験: {_tinyint_label(engineer.has_negotiation_exp, '有', '無')}\n"
        f"- 稼働可能時期: {engineer.available_from if engineer.available_from else '未入力'}\n"
        f"- 希望単価: {desired_rate_text}\n"
        f"- 最寄駅: {engineer.nearest_station or '未入力'}\n"
        f"- 経験工程: {_proc_experience_text(engineer)}\n"
        f"- 勤務形態希望: {_engineer_work_style_text(engineer)}\n"
        f"- 保有スキル: {_skills_text(engineer.skills)}\n"
        f"\n"
        f"【案件情報】\n"
        f"- 業務内容詳細: {project.description or '（なし）'}\n"
        f"- 顧客折衝経験要否: {_tinyint_label(project.negotiation_required, '要', '不問')}\n"
        f"- 参画開始時期: {project.start_date if project.start_date else '未定'}\n"
        f"- 単価レンジ: {project.rate_min}〜{project.rate_max}万円/月（備考: {project.rate_note or 'なし'}）\n"
        f"- 稼働形態: {_project_work_style_text(project)}\n"
        f"- 勤務地: {project.work_location_station or '未入力'}\n"
        f"- 計算された通勤時間: {commute_text}\n"
        f"- 対象工程: {_proc_experience_text(project)}\n"
        f"- 必須スキル: {_skills_text(required_skills)}\n"
        f"- 尚可スキル: {_skills_text(preferred_skills)}\n"
        f"\n"
        f"【判定観点と配点目安】\n"
        f"合計100点満点で総合判定してください。各観点の配点と判定基準を以下に示します。\n"
        f"\n"
        f"■ 必須スキル充足度(最大30点)\n"
        f"- 案件の必須スキルを人材がどの程度満たしているかを判定\n"
        f"- スキル詳細の「必要年数」と人材スキル詳細の「経験年数」を読み取って充足率を算出\n"
        f"- 充足率の比例和方式: (各スキルの充足率の平均) × 30\n"
        f"- 各スキルの充足率は1.0(必要年数以上)で頭打ち、経験超過は加点しない\n"
        f"- 必須スキルを1つも保有していない場合: 0点\n"
        f"- かつ、上記計算で算出した総合スコアから30点を減点する（必須スキル全不足の人材は構造的にDランクに落とすため）\n"
        f"\n"
        f"■ 工程経験適合度(最大20点)\n"
        f"- 案件の対象工程と人材の経験工程の重なりで判定\n"
        f"- 計算式: |案件対象工程 ∩ 人材経験工程| ÷ |案件対象工程| × 20\n"
        f"- 例: 案件対象=4工程・人材経験で3つ重複 → (3/4) × 20 = 15点\n"
        f"\n"
        f"■ 尚可スキル適合度(最大10点)\n"
        f"- 案件の尚可スキルを人材がどの程度満たしているかを判定\n"
        f"- 経験年数判定なし、保有有無のみで充足率を算出\n"
        f"- 計算式: |案件尚可スキル ∩ 人材保有スキル| ÷ |案件尚可スキル| × 10\n"
        f"- 案件に尚可スキルが無い場合は満点扱い(10点)\n"
        f"\n"
        f"■ 勤務形態適合度(最大10点) ※マトリクス判定\n"
        f"人材の勤務形態希望 ／ 案件の稼働形態:\n"
        f"- 常駐可のみ × 常駐         : 10\n"
        f"- 常駐可のみ × 一部リモート可  : 10\n"
        f"- 常駐可のみ × フルリモート    :  0\n"
        f"- 一部リモート可のみ × 常駐    :  7\n"
        f"- 一部リモート可のみ × 一部    : 10\n"
        f"- 一部リモート可のみ × フル    :  3\n"
        f"- フルリモート希望のみ × 常駐  :  0\n"
        f"- フルリモート希望のみ × 一部  :  5\n"
        f"- フルリモート希望のみ × フル  : 10\n"
        f"- 常駐可+一部リモート可 × フル :  3\n"
        f"- 全タグ保有 × 任意           : 10\n"
        f"\n"
        f"■ 勤務地/通勤適合度(最大10点)\n"
        f"- あらかじめ外部APIで算出された通勤時間（分単位）を「計算された通勤時間」として渡すため、その値に基づいて配点する\n"
        f"- 実通勤時間 ≤ 30分 → 10点\n"
        f"- ≤ 60分 → 7点\n"
        f"- ≤ 90分 → 4点\n"
        f"- それ以上 → 0点\n"
        f"- 両者フルリモート時（人材がフルリモート希望かつ案件がフルリモート）→ 10点扱い（通勤時間は判定対象外）\n"
        f"- 通勤時間がNULL（外部API算出失敗）の場合は0点扱いとし、その旨を ai_score_reason に明記\n"
        f"\n"
        f"■ 単価適合度(最大10点)\n"
        f"- 人材希望単価が案件単価レンジ内に収まるか\n"
        f"- レンジ内: 10点\n"
        f"- 上限超過幅5万円以内: 5点\n"
        f"- 希望単価NULL(「希望なし」): 加点対象で10点扱い\n"
        f"- 単価備考に「スキル見合い」等あり、かつ単価レンジがNULL: 満点扱い(10点)\n"
        f"- レンジを大きく外れる場合: 0点\n"
        f"\n"
        f"■ 稼働開始時期適合度(最大5点)\n"
        f"- 人材稼働可能時期 ≤ 案件参画開始時期: 5点\n"
        f"- 案件開始から30日以内に稼働可能: 3点\n"
        f"- 60日以内: 1点\n"
        f"- それ以上遅い: 0点\n"
        f"\n"
        f"■ 顧客折衝/人物要件適合度(最大5点)\n"
        f"- 案件が顧客折衝「要」× 人材が経験「有」: 5点\n"
        f"- 案件が顧客折衝「不問」: 満点扱い(5点)\n"
        f"- 案件が顧客折衝「要」× 人材が経験「無」: 0点\n"
        f"- 加えて、案件の業務内容詳細にマネジメント経験・上流工程経験等のソフトスキル要件が含まれる場合、人材のアピールポイント/保有スキルから該当記述を探索して反映\n"
        f"\n"
        f"合計 = 必須スキル + 工程経験 + 尚可スキル + 勤務形態 + 勤務地 + 単価 + 稼働開始時期 + 顧客折衝/人物要件\n"
        f"(理論上の最大値 = 100点)\n"
        f"※ 必須スキル該当0の場合は、上記合計からさらに-30点ペナルティを適用\n"
        f"ai_score_reason には、各観点での点数の根拠を簡潔に記述してください\n"
        f"\n"
        f"【スコア閾値(match_rank 判定)】\n"
        f"A: 80〜100点(即提案レベル)\n"
        f"B: 65〜79点(検討可能)\n"
        f"C: 50〜64点(条件交渉次第)\n"
        f"D: 0〜49点(見送り推奨)\n"
        f"\n"
        f"【重要・厳守事項】\n"
        f"match_score は上記「合計 = 必須スキル + 工程経験 + ... + 顧客折衝/人物要件」の計算結果と完全に一致させること。\n"
        f"独自の総合判断で値を変えてはならない。\n"
        f"\n"
        f"【スコアのクランプ処理】\n"
        f"合計値が0未満（マイナス）になる場合は、必ず 0 として出力すること。\n"
        f"match_score は必ず 0〜100 の整数で出力すること。\n"
        f"\n"
        f"【出力フォーマット(厳守・他の文章は一切出力しないこと)】\n"
        f"{{\n"
        f"  \"match_score\": <0〜100 の整数。0未満になる場合は0として出力>,\n"
        f"  \"match_rank\": \"<A | B | C | D>\",\n"
        f"  \"ai_score_reason\": \"<スコアの根拠説明。200字以上300字以下で記述。適合点と不足点を両方触れる>\",\n"
        f"  \"ai_comment\": \"<推薦理由。150字以上250字以下で記述。営業担当が顧客に伝えられる文面>\",\n"
        f"  \"ai_missing\": \"<満たしていない要件の説明。50字以上150字以下で記述。すべて満たしている場合は『特になし』>\"\n"
        f"}}\n"
    )


def invoke_matching(
    engineer: EngineerData,
    project: ProjectData,
    commute_time_minutes: Optional[int],
) -> MatchingAIResult:
    """Bedrock を呼び出して指定された案件とのマッチング判定を行い、クランプ・ランク検算済みの結果を返す。"""

    user_prompt = _build_matching_user_prompt(engineer, project, commute_time_minutes)

    text = _invoke_model(_MATCHING_SYSTEM_PROMPT, user_prompt, max_tokens=800)

    try:
        data = _parse_matching_json(text)
    except (json.JSONDecodeError, KeyError, ValueError) as e:
        logger.warning("初回JSONパース失敗。フォーマットを厳密に指定して再試行します: %s", e)

        # フォーマット崩れ時の専用リトライプロンプト（AIプロンプト設計書 v0.3 §3.6.1 準拠）
        _RETRY_SYSTEM_PROMPT = (
            "あなたはSES営業を補助するアシスタントです。\n"
            "前回出力はJSON形式違反でした。指定のJSONスキーマに従い、JSONオブジェクトのみを出力してください。\n"
            "説明文・前置き・コードフェンスは一切含めないでください。"
        )
        retry_prompt = (
            f"{user_prompt}\n\n"
            f"※ 前回出力は JSON 形式違反でした。"
            f"{{match_score, match_rank, ai_score_reason, ai_comment, ai_missing}} "
            f"の5キーを持つ純粋なJSONオブジェクトのみを出力してください。"
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
# E2: 人材プロフィール要約プロンプト制御（スコアリングロジック設計書 v0.6 §4.3 準拠）
# ---------------------------------------------------------------------------

_PROFILE_SYSTEM_PROMPT = (
    "あなたはSES営業を補助するアシスタントです。\n"
    "人材のアピールポイントを読み、強み・特徴を簡潔に要約してください。\n"
    "事実に基づき、提示データの範囲で回答してください。\n"
    "推測や断定は避け、書かれていない情報は要約に含めないでください。"
)


def _build_summary_user_prompt(appeal_note: str) -> str:
    """E2 プロフィール要約用のユーザープロンプトを組み立てる（AIプロンプト設計書 v0.3 §4.3 準拠）。
    engineers.appeal_note（H1）のみを入力とする（スコアリングロジック設計書 v0.6 §2.6・§4.3 準拠）。
    """
    return (
        f"以下のアピールポイントから、人材の強み・特徴を 300〜400字程度で要約してください。\n"
        f"箇条書きではなく、自然な日本語の文章で記述してください。\n"
        f"\n"
        f"【アピールポイント】\n"
        f"{appeal_note}"
    )


def invoke_profile_summary(appeal_note: str) -> str:
    """engineers.appeal_note を基に、AIがプロフィール紹介文をBedrockで生成する
    （スコアリングロジック設計書 v0.6 §4.3・AIプロンプト設計書 v0.3 §4 準拠）。
    """
    # 空の場合はBedrockを呼ばずに空文字を返す（コスト削減・防衛策）
    if not appeal_note.strip():
        return ""

    user_prompt = _build_summary_user_prompt(appeal_note)

    ai_summary = _invoke_model(_PROFILE_SYSTEM_PROMPT, user_prompt, max_tokens=600)
    return ai_summary.strip()
