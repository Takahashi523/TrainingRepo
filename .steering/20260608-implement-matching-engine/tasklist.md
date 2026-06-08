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
- [ ] コミット

---

## Step 3: Bedrock クライアント

- [ ] bedrock_service.py 作成
- [ ] boto3 bedrock-runtime クライアント初期化
- [ ] invoke_model 呼び出しラッパー実装
- [ ] AIプロンプト設計書 §2 準拠のプロンプト組み立て
- [ ] レスポンス JSON パース（ai_score_reason / ai_comment / ai_missing）
- [ ] EXTERNAL_API_ERROR エラーハンドリング実装
- [ ] テスト作成（pytest-mock でモック化）
- [ ] コミット

---

## Step 4: スコアリングロジック単体

- [ ] 8次元スコア計算関数実装（S1〜S8）
- [ ] クランプ処理実装（match_score = max(0, raw_score)）
- [ ] match_rank 算定実装（A/B/C/D）
- [ ] テスト作成（各次元・クランプ・ランク境界値）
- [ ] コミット

---

## Step 5: マッチング計算フロー

- [ ] パイプライン除外ロジック実装（Step 3.5）
- [ ] カスケードソート実装（候補 >30 件時、Step 3.6）
- [ ] E1 フロー全体を matching_service.py に統合（Step 3.0〜3.12）
- [ ] テスト作成（フロー統合テスト）
- [ ] コミット

---

## Step 6: E1 エンドポイント完成

- [ ] routers/matching.py の E1 スタブを実装に差し替え
- [ ] HTTPException によるエラーレスポンス整形
- [ ] TestClient による E1 エンドポイントテスト
- [ ] コミット

---

## Step 7: Google Maps クライアント

- [ ] gmaps_service.py 作成
- [ ] SSM Parameter Store から API キー取得（Nexus-google-maps-key）
- [ ] Distance Matrix API 呼び出し実装
- [ ] commute_time_minutes 算出（秒→分変換）
- [ ] EXTERNAL_API_ERROR エラーハンドリング実装
- [ ] テスト作成（pytest-mock でモック化）
- [ ] E1 フローへの組み込み（Step 3.7）
- [ ] コミット

---

## Step 8: E2 エンドポイント（プロフィール要約）

- [ ] プロフィール要約フロー実装（matching_service.py に追加）
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
