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

**全Step（1〜7）実装・テスト完了。正ドキュメントとの整合修正も完了。**

実装済みの内容：
- `python/app/main.py`：FastAPI app + 例外ハンドラー集約（`EngineerNotFoundError`/`NoActiveCandidateError`/`BedrockError`の変換対応）
- `python/app/config.py`：環境変数集約管理
- `python/app/routers/matching.py`：E1 エンドポイント実装完了（`engineer_id`・`project_ids`のみを受け付ける）
- `python/app/routers/profile.py`：E2 エンドポイント実装完了（`engineer_id`のみを受け付け、DBの`appeal_note`を使用）
- `python/app/models/db.py`：SQLAlchemy engine / session（重複していた`app/database.py`等は削除し、ここに一本化）
- `python/app/models/schemas.py`：Pydantic リクエスト/レスポンス型定義
- `python/app/models/internal_types.py`：内部データ構造定義（循環インポート解消のため新設）
- `python/app/services/matching_service.py`：E1/E2 ビジネスロジック・DB取得・カスケードソート・クランプ処理・ランク検算
- `python/app/services/bedrock_service.py`：Bedrock（Claude 3.5 Sonnet）呼び出しラッパー（リトライ・JSON再試行・配点目安ガイド埋め込み済みプロンプト）
- `python/app/services/gmaps_service.py`：Google Maps Distance Matrix API 呼び出し

---

## 受け入れ条件

### E1 `/api/v1/matching/calculate`

- リクエスト `engineer_id` で engineers テーブルからエンジニア情報を取得できる
- `project_ids` が指定された場合はその案件のみ、未指定の場合は全 open 案件を対象とする
- パイプライン登録済みの案件を除外してからスコア計算を行う（Step 3.5）
- **候補が30件超の場合はカスケードソートで上位30件に絞り込んでからAI評価する（Step 3.6）**
  ※AIは候補ごとに個別呼び出しするため、1プロンプトへの一括投入トークン上限には抵触しない。30件という上限自体はコスト・レスポンス時間（QA#30）の観点で設定されている。
- AI総合判定方式（ルールベースのF1ハードフィルタ・S1〜S8配点は廃止済）。8観点の配点目安をプロンプトのガイドラインとしてAIに渡し、AIが0〜100点で総合判定する（スコアリングロジック設計書 §3 準拠）
- `match_score = max(0, raw_score)` のクランプ処理を必ず行う
- `match_rank`：A=80-100 / B=65-79 / C=50-64 / D=0-49
- Google Maps Distance Matrix API で通勤時間（commute_time_minutes）を取得する
- AWS Bedrock（Claude 3.5 Sonnet）で `ai_score_reason`・`ai_comment`・`ai_missing` を一括生成する
- レスポンスフィールド：`engineer_id`, `generated_at`（ISO8601）, `matches`（配列、**常に上位5件固定**。QA#33・#50）
- `limit`・`rank_filter`・`total_hits` は**リクエスト/レスポンスに含めない**（v0.6改訂履歴 B-05・B-06・B-07 にて削除確定。フロント側で未使用のため）

### E2 `/api/v1/ai/profile-summary`

- リクエストは **`engineer_id` のみ**。`engineers.appeal_note` を Python 側が DB から取得して AI に渡す
  （職務経歴書ファイルのアップロードは廃止済み。画面から`appeal_point`/`raw_skills`を直接受け取る方式ではない）
- `engineer_id` でエンジニア情報の存在チェックを行い、AWS Bedrock で `ai_summary` を生成する
- `appeal_note` が空の場合は Bedrock を呼ばず空文字を返す（コスト削減）
- **Pythonは計算結果の返却に特化し、DBへの書き込みは一切行わない**（スコアリングロジック設計書 v0.6 §1.3「データ連携方針」準拠。QA#45「オンデマンド計算・DB保存なし」）。`engineers.ai_summary` / `engineers.ai_summary_generated_at` への反映は、レスポンスを受け取った**Laravel側の責務**
- レスポンスフィールド：`engineer_id`, `ai_summary`, `ai_summary_generated_at`（ISO8601）

### E3 `/api/v1/health`

- `{"status": "ok"}` を返す（実装済み）

### 共通

- エラー時は `{"error_code": "...", "message": "..."}` 形式で返す
- `INVALID_PARAMETER`（400）/ `ENGINEER_NOT_FOUND`（404）/ `NO_ACTIVE_PROJECT`（422）/ `INTERNAL_ERROR`（500）/ `UPSTREAM_TIMEOUT`（504）
- 500エラー時、内部の例外メッセージ（DBカラム名・SQL断片等）をレスポンスに含めない（汎用メッセージに統一。ログにのみ詳細を残す）
- PII をログに記録しない（マスキング必須）。氏名・生年月日はAIプロンプトに含めない
- AWS 認証は IAM ロールベース（APIキー直書き禁止）
- Google Maps API キーは SSM Parameter Store（`Nexus-google-maps-key`）から取得
- Bedrock 呼び出しパラメータ：`temperature=0.3` / `top_p=0.9` / `max_tokens`（マッチング800・要約600）

### テスト

- pytest が全件通過すること（現状103件）
- カバレッジ90%以上必須（現状96%。サービス層、ルーター層、エラーハンドリングすべてで高水準を維持すること）
- 外部 API（Bedrock / Google Maps）は `pytest-mock` でモック化すること（DBセッションのモックには標準の `MagicMock` を使用可）
- モックのパッチ対象は「呼び出し元モジュールの名前空間」を指定すること（`from x import y` した場合、`x.y`ではなく呼び出し元の名前でパッチする）

---

## 制約事項

- `SELECT *` 禁止。必要なカラムを明示的に指定すること
- **Pythonは全エンドポイント共通でDBへの書き込みを一切行わない（読み取り専用）**。RDS(MySQL)からのデータ取得はPythonが直接行うが、パイプライン登録・ai_summary保存等の書き込み処理は全てLaravel側に集約する（スコアリングロジック設計書 v0.6 §1.3「データ連携方針」準拠）
- Pydantic で全リクエスト/レスポンスを型定義すること（ロジック層の内部データやり取りにはdataclassを用いること）
- 循環インポートを排除するため、共有データ構造は独立したモジュール（`internal_types.py`）に集約し、トップレベルインポートを行うこと
- 環境変数は `config.py` で集約管理すること
- DBセッション取得（`get_db`）は `models/db.py` に一本化すること（`app/database.py`等の重複ファイルは作らない）
- `requirements.txt` に不要な依存（`openai`等）を含めないこと
- マッチングエンジン設計書（旧）は参照しない
- 要件定義書 §5.3.3 / §5.3.6 は旧仕様のため無視すること（スコアリングロジック設計書 v0.6 §3.1 に注釈あり。要件定義書側の更新は別途PM依頼中）

---

## 既知の残課題（本番リリース前に対応必須）

- **`MOCK_MODE`**：AWS本番アカウント未整備のための開発用スタブ。本番リリース前に無効化・削除が必要
- Laravel⇔Python間の通信経路（VPC Endpoint / NAT Gateway等）が未確定（スコアリングロジック設計書 v0.6 §6 T29、インフラ担当確認待ち）
- `スコアリングロジック設計書.md` §3.2の出力文字数表記（「200字前後」等）が`AIプロンプト設計書.md`の精密な数値（150-250字等）と食い違っている（ドキュメント側の軽微な同期漏れ、文書オーナーへ確認予定）
