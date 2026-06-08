# requirements.md — マッチングエンジン実装

## 作業概要

Nexus SES マッチングシステムの Python バックエンド（FastAPI）を実装する。

設計根拠となる正ドキュメント：
- `docs/02_design/backend/スコアリングロジック設計書.md`（v0.6）
- `docs/02_design/backend/AIプロンプト設計書.md`（v0.3）
- `docs/データモデル・DB設計書.md`（v1.7）

---

## 実装対象エンドポイント

| ID | メソッド | パス | 説明 |
|---|---|---|---|
| E1 | POST | `/api/v1/matching/calculate` | マッチングスコア計算 |
| E2 | POST | `/api/v1/ai/profile-summary` | AIプロフィール要約生成 |
| E3 | GET | `/api/v1/health` | ヘルスチェック |

---

## 現在の状態

**Step 1（プロジェクト骨格）完了・コミット済み。**

実装済みの内容：
- `python/app/main.py`：FastAPI app + router 登録
- `python/app/config.py`：環境変数集約管理
- `python/app/routers/matching.py`：E3 ヘルスチェック（動作確認済み）、E1 スタブ（NotImplementedError）
- `python/app/routers/profile.py`：E2 スタブ（NotImplementedError）
- `python/app/models/db.py`：SQLAlchemy engine / session
- `python/app/models/schemas.py`：Pydantic リクエスト/レスポンス型定義
- `python/app/services/`：空（Step 3 以降で実装）

---

## 受け入れ条件

### E1 `/api/v1/matching/calculate`

- リクエスト `engineer_id` で engineers テーブルからエンジニア情報を取得できる
- `project_ids` が指定された場合はその案件のみ、未指定の場合は全 open 案件を対象とする
- パイプライン登録済みの案件を除外してからスコア計算を行う（Step 3.5）
- 候補が30件超の場合はカスケードソートで絞り込む（Step 3.6）
- 8次元スコアリングロジック（スコアリングロジック設計書 §3 準拠）を実装する
- `match_score = max(0, raw_score)` のクランプ処理を必ず行う
- `match_rank`：A=80-100 / B=65-79 / C=50-64 / D=0-49
- Google Maps Distance Matrix API で通勤時間（commute_time_minutes）を取得する
- AWS Bedrock（Claude 3.5 Sonnet）で `ai_score_reason`・`ai_comment`・`ai_missing` を生成する
- レスポンスフィールド：`engineer_id`, `generated_at`（ISO8601）, `matches`（配列）

### E2 `/api/v1/ai/profile-summary`

- `engineer_id` でエンジニア職歴を取得し、AWS Bedrock で `ai_summary` を生成する
- 生成結果を `engineers.ai_summary` / `engineers.ai_summary_generated_at` に保存（UPSERT）する
- レスポンスフィールド：`engineer_id`, `ai_summary`, `ai_summary_generated_at`（ISO8601）

### E3 `/api/v1/health`

- `{"status": "ok"}` を返す（実装済み）

### 共通

- エラー時は `{"error_code": "...", "message": "..."}` 形式で返す
- `ENGINEER_NOT_FOUND`（404）/ `PROJECT_NOT_FOUND`（404）/ `EXTERNAL_API_ERROR`（502）など
- PII をログに記録しない（マスキング必須）
- AWS 認証は IAM ロールベース（APIキー直書き禁止）
- Google Maps API キーは SSM Parameter Store（`Nexus-google-maps-key`）から取得

### テスト

- pytest が全件通過すること
- カバレッジ90%以上（マッチングロジック・クランプ処理・エラーハンドリングは必須）
- 外部 API（Bedrock / Google Maps）は pytest-mock でモック化すること

---

## 制約事項

- `SELECT *` 禁止。必要なカラムを明示的に指定すること
- Pydantic で全リクエスト/レスポンスを型定義すること
- 環境変数は `config.py` で集約管理すること
- マッチングエンジン設計書（旧）は参照しない
- 要件定義書 §5.3.3 / §5.3.6 は旧仕様のため無視すること
