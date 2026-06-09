# tasklist.md — マッチングエンジン実装

## 進捗状況

| 状態 | 記法 |
|---|---|
| 未着手 | `[ ]` |
| 進行中 | `[-] 🏃` |
| 完了 | `[x] ✅ YYYY-MM-DD` |

---

## Step 1: プロジェクト骨格

- [x] ✅ 2026-06-08 ディレクトリ構成作成（routers / services / models）
- [x] ✅ 2026-06-08 config.py（環境変数集約）
- [x] ✅ 2026-06-08 models/db.py（SQLAlchemy engine / session）
- [x] ✅ 2026-06-08 models/schemas.py（Pydantic 型定義）
- [x] ✅ 2026-06-08 routers/matching.py（E3 ヘルスチェック実装・E1 スタブ）
- [x] ✅ 2026-06-08 routers/profile.py（E2 スタブ）
- [x] ✅ 2026-06-08 main.py（FastAPI app + router 登録）
- [x] ✅ 2026-06-08 requirements.txt（boto3 / sqlalchemy / pymysql 等）
- [x] ✅ 2026-06-08 .env.example 更新
- [x] ✅ 2026-06-08 コミット

---

## Step 2: DB接続 + データ取得

- [x] ✅ 2026-06-08 matching_service.py 作成
- [x] ✅ 2026-06-08 engineers テーブルからエンジニア情報取得（engineer_id 指定）
- [x] ✅ 2026-06-08 engineer_skills テーブルからスキル一覧取得
- [x] ✅ 2026-06-08 projects テーブルから対象案件一覧取得（status='open'）
- [x] ✅ 2026-06-08 project_skills テーブルからスキル一覧取得（N+1回避・IN句一括取得）
- [x] ✅ 2026-06-08 pipelines テーブルから既登録案件 ID 取得（パイプライン除外用）
- [x] ✅ 2026-06-08 ENGINEER_NOT_FOUND エラーハンドリング実装
- [x] ✅ 2026-06-08 テスト作成（tests/test_matching_service.py・13件・カバレッジ100%）
- [x] ✅ 2026-06-08 コミット

---

## Step 3: Bedrock クライアント

- [x] ✅ 2026-06-08 bedrock_service.py 作成
- [x] ✅ 2026-06-08 boto3 bedrock-runtime クライアント初期化（遅延シングルトン）
- [x] ✅ 2026-06-08 invoke_model 呼び出しラッパー実装（指数バックオフ・最大3回リトライ）
- [x] ✅ 2026-06-08 AIプロンプト設計書 v0.3 準拠のプロンプト組み立て（8観点を AI に指示）
- [x] ✅ 2026-06-08 レスポンス JSON パース・コードフェンス除去（match_score / ai_score_reason / ai_comment / ai_missing）
- [x] ✅ 2026-06-08 BedrockError 例外・JSON パース失敗時リトライ（v0.3 §3.6.1 専用プロンプト）
- [x] ✅ 2026-06-08 アプリ層クランプ処理（max(0, raw_score)）・ランク検算
- [x] ✅ 2026-06-08 テスト作成（tests/test_bedrock_service.py・32件・カバレッジ97%）
- [x] ✅ 2026-06-08 コミット

---

## Step 4: マッチング計算フロー（E1 骨格）

- [x] ✅ 2026-06-08 エンジニア status='proposable' チェック実装（Step 3.4）
- [x] ✅ 2026-06-08 パイプライン除外ロジック実装（Step 3.5）
- [x] ✅ 2026-06-08 カスケードソート実装（候補 >30 件時、Step 3.6）
      （工程経験重複数 → 単価 → 勤務形態 → 開始時期 → 登録日）
- [x] ✅ 2026-06-08 E1 フロー全体を matching_service.py に統合（Step 3.0〜3.12）
- [x] ✅ 2026-06-08 上位5件絞込・ソート実装（Step 3.11）
- [x] ✅ 2026-06-08 テスト作成（フロー統合テスト・35件・カバレッジ100%）
- [x] ✅ 2026-06-08 コミット

---

## Step 5: E1 エンドポイント完成

- [x] ✅ 2026-06-08 routers/matching.py の E1 スタブを実装に差し替え
- [x] ✅ 2026-06-08 HTTPException によるエラーレスポンス整形（ENGINEER_NOT_FOUND / EXTERNAL_API_ERROR）
- [x] ✅ 2026-06-08 TestClient による E1 エンドポイントテスト（8件・カバレッジ100%）
- [x] ✅ 2026-06-08 コミット

## Step 5.5: E1 仕様適合修正（スコアリングロジック設計書 v0.6 §4.2 準拠）

- [x] ✅ 2026-06-08 schemas.py: MatchingRequest に `limit`（default 5）・`rank_filter` を追加
- [x] ✅ 2026-06-08 schemas.py: MatchingResponse に `total_hits` を追加
- [x] ✅ 2026-06-08 matching_service.py: `NoActiveCandidateError` 追加（候補0件 → 422 NO_ACTIVE_PROJECT）
- [x] ✅ 2026-06-08 matching_service.py: `MatchingOutput.total_hits` 追加
- [x] ✅ 2026-06-08 matching_service.py: `calculate_matching` に `limit`・`rank_filter` パラメータ追加
- [x] ✅ 2026-06-08 routers/matching.py: `limit`・`rank_filter` を run_matching に渡す・`total_hits` を返す
- [x] ✅ 2026-06-08 main.py: `NoActiveCandidateError` ハンドラ追加（422）・`BedrockError` を 504 UPSTREAM_TIMEOUT に修正
- [x] ✅ 2026-06-08 test_matching_service.py: 候補0件テストを `NoActiveCandidateError` 期待に変更・`total_hits` アサーション追加・`limit`/`rank_filter` テスト追加（78件・カバレッジ98%）
- [x] ✅ 2026-06-08 test_routers.py: `total_hits` フィールド追加・504 UPSTREAM_TIMEOUT テストに修正・422 NO_ACTIVE_PROJECT テスト追加

---

## Step 6: Google Maps クライアント

- [x] ✅ 2026-06-09 gmaps_service.py 作成
- [x] ✅ 2026-06-09 SSM Parameter Store から API キー取得（Nexus-google-maps-key）
- [x] ✅ 2026-06-09 Distance Matrix API 呼び出し実装
- [x] ✅ 2026-06-09 commute_time_minutes 算出（秒→分変換）
- [x] ✅ 2026-06-09 失敗時は None を返しマッチングフロー継続（API仕様に EXTERNAL_API_ERROR なし）
- [x] ✅ 2026-06-09 テスト作成（pytest-mock でモック化・10件）
- [x] ✅ 2026-06-09 E1 フローへの組み込み（Step 3.7）
- [x] ✅ 2026-06-09 コミット

---

## Step 7: E2 エンドポイント（プロフィール要約）

- [ ] プロフィール要約フロー実装（AIプロンプト設計書 v0.3 §2 準拠）
- [ ] engineers テーブルへの ai_summary / ai_summary_generated_at UPSERT 実装
- [ ] routers/profile.py の E2 スタブを実装に差し替え
- [ ] テスト作成
- [ ] コミット

---

## 完了基準

- [ ] pytest 全件通過
- [ ] カバレッジ90%以上
- [ ] `SELECT *` が存在しないこと（Grep で確認）
- [ ] Pydantic 型定義がすべてのリクエスト/レスポンスに存在すること
- [ ] 機密情報がコードに直書きされていないこと
