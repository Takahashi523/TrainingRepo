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
- [x] ✅ 2026-06-08 テスト作成
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
- [x] ✅ 2026-06-08 テスト作成
- [x] ✅ 2026-06-08 コミット
- [x] ✅ 2026-07-12 **【Step 8で修正】** プロンプトに配点目安ガイド（8観点・計算式・マトリクス）を追加
- [x] ✅ 2026-07-12 **【Step 8で修正】** Bedrock呼び出しパラメータ修正（temperature=0.3・top_p=0.9・max_tokens 800/600）
- [x] ✅ 2026-07-12 **【Step 8で修正】** 出力文字数レンジ修正（ai_comment 150-250字・ai_missing 50-150字）

---

## Step 4: マッチング計算フロー（E1 骨格）

- [x] ✅ 2026-06-08 エンジニア status='proposable' チェック実装（Step 3.4）
- [x] ✅ 2026-06-08 パイプライン除外ロジック実装（Step 3.5）
- [x] ✅ 2026-06-08 カスケードソート実装（候補 >30 件時、Step 3.6）
      （工程経験重複数 → 単価 → 勤務形態 → 開始時期 → 登録日）
- [x] ✅ 2026-06-08 E1 フロー全体を matching_service.py に統合（Step 3.0〜3.12）
- [x] ✅ 2026-06-08 上位5件絞込・ソート実装（Step 3.11）
- [x] ✅ 2026-06-08 テスト作成
- [x] ✅ 2026-06-08 コミット
- [x] ✅ 2026-07-12 **【Step 8で修正】** カスケードソート閾値を5件→**30件**に修正（当初「Claudeのトークン上限のため5件」としていたが誤り。AIは候補ごと個別呼び出しのため抵触しない）

---

## Step 5: E1 エンドポイント完成

- [x] ✅ 2026-06-08 routers/matching.py の E1 スタブを実装に差し替え
- [x] ✅ 2026-06-08 HTTPException によるエラーレスポンス整形（ENGINEER_NOT_FOUND 等）
- [x] ✅ 2026-06-08 TestClient による E1 エンドポイントテスト
- [x] ✅ 2026-06-08 コミット
- [x] ✅ 2026-07-12 **【Step 8で修正】** `except BedrockError: raise` を追加し、504が誤って500になっていた不具合を修正

---

## ~~Step 5.5: E1 仕様適合修正~~（2026-07-12 巻き戻し済み）

> ⚠️ **このStepは全体が誤りだったため、Step 8 にて巻き戻しました。**
> `スコアリングロジック設計書.md` v0.6 改訂履歴（B-05・B-06・B-07）により、`limit`・`rank_filter`・`total_hits`は
> 「フロント側で未使用・QA#33/50で5件固定済」を理由に**削除**と確定していた仕様でした。
> 以下は当時実施した（誤った）作業の記録として残しますが、全て元に戻しています。

- [x] ~~2026-06-08 schemas.py: MatchingRequest に `limit`（default 5）・`rank_filter` を追加~~ → 2026-07-12 削除
- [x] ~~2026-06-08 schemas.py: MatchingResponse に `total_hits` を追加~~ → 2026-07-12 削除
- [x] ✅ 2026-06-08 matching_service.py: `NoActiveCandidateError` 追加（候補0件 → 422 NO_ACTIVE_PROJECT）※これは正しい仕様のため維持
- [x] ~~2026-06-08 matching_service.py: `MatchingOutput.total_hits` 追加~~ → 2026-07-12 削除
- [x] ~~2026-06-08 matching_service.py: `calculate_matching` に `limit`・`rank_filter` パラメータ追加~~ → 2026-07-12 削除
- [x] ~~2026-06-08 routers/matching.py: `limit`・`rank_filter` を run_matching に渡す・`total_hits` を返す~~ → 2026-07-12 削除
- [x] ✅ 2026-06-08 main.py: `NoActiveCandidateError` ハンドラ追加（422）・`BedrockError` を 504 UPSTREAM_TIMEOUT に修正 ※維持

---

## Step 6: Google Maps クライアント

- [x] ✅ 2026-06-09 gmaps_service.py 作成
- [x] ✅ 2026-06-09 SSM Parameter Store から API キー取得（Nexus-google-maps-key）
- [x] ✅ 2026-06-09 Distance Matrix API 呼び出し実装
- [x] ✅ 2026-06-09 commute_time_minutes 算出（秒→分変換）
- [x] ✅ 2026-06-09 失敗時は None を返しマッチングフロー継続
- [x] ✅ 2026-06-09 テスト作成（pytest-mock でモック化）
- [x] ✅ 2026-06-09 E1 フローへの組み込み（Step 3.7）
- [x] ✅ 2026-06-09 コミット

---

## Step 7: E2 エンドポイント（プロフィール要約）

- [x] ✅ 2026-06-09 プロフィール要約フロー実装
- [x] ~~2026-06-09 engineers テーブルへの ai_summary / ai_summary_generated_at UPDATE 実装~~ → 2026-07-14 削除（Step 9参照。Pythonは書き込みを行わない方針のため）
- [x] ✅ 2026-06-09 routers/profile.py の E2 スタブを実装に差し替え
- [x] ✅ 2026-06-09 テスト作成
- [x] ✅ 2026-06-09 コミット
- [x] ✅ 2026-07-12 **【Step 8で修正】** 入力を`appeal_point`+`raw_skills`→**`engineer_id`のみ（DBのappeal_noteを使用）**に巻き戻し
- [x] ✅ 2026-07-14 **【Step 9で修正】** DB書込（UPDATE）処理を削除し、読み取り専用に修正

---

## Step 8: 正ドキュメントとの整合修正（2026-07-12）

`スコアリングロジック設計書.md`（v0.6）・`AIプロンプト設計書.md`（v0.3）の原本を入手し、実装全体をクロスチェックした結果判明した乖離をまとめて修正。

- [x] ✅ 2026-07-12 E1から`limit`/`rank_filter`/`total_hits`を削除（schemas.py / internal_types.py / matching_service.py / routers/matching.py / 関連テスト）
- [x] ✅ 2026-07-12 AI一括投入の絞込閾値を5件→30件に修正（最終返却件数は引き続き5件固定）
- [x] ✅ 2026-07-12 E2の入力を`engineer_id`のみに修正（DBのappeal_noteを使用）
- [x] ✅ 2026-07-12 プロンプトに配点目安ガイド（8観点・計算式・マトリクス・-30点ペナルティ）を追加
- [x] ✅ 2026-07-12 Bedrock呼び出しパラメータ修正（temperature/top_p/max_tokens）
- [x] ✅ 2026-07-12 出力文字数レンジ修正（ai_comment/ai_missing）
- [x] ✅ 2026-07-12 `BedrockError`が誤って500になる不具合を修正（504に）
- [x] ✅ 2026-07-12 `get_db`の二重管理を解消（`models/db.py`に一本化、重複ファイル削除）
- [x] ✅ 2026-07-12 `requirements.txt`から不要な`openai`依存を削除
- [x] ✅ 2026-07-12 `design.md`・`reason.md`・`requirements.md`・`tasklist.md`（本ファイル）を今回の結果に合わせて改訂

---

## Step 9: E2のDB書込方針修正（2026-07-14・Laravelチーム指摘対応）

Laravelチームより「PythonがUPDATEを行っていないか」という確認を受け、`スコアリングロジック設計書.md` §1.3「データ連携方針」を再確認した結果、Python側は本来DBへの書き込みを一切行わない方針（書込責務はLaravel側に集約）であることが判明。E2実装がこの方針に反していたため修正。

- [x] ✅ 2026-07-14 `generate_profile_summary`からDB書込（`UPDATE engineers ...`・`db.commit()`）を削除
- [x] ✅ 2026-07-14 テストを「PythonはDBに一切書き込まないこと」を検証する内容に置き換え
- [x] ✅ 2026-07-14 `design.md`・`reason.md`・`requirements.md`・`tasklist.md`（本ファイル）を今回の結果に合わせて改訂
- [x] ✅ 2026-07-14 Laravelチームへ、`engineers.ai_summary`等への反映がLaravel側の実装責務である旨を回答

---

## 完了基準

- [x] ✅ 2026-07-14 pytest 全件通過（103件）
- [x] ✅ 2026-07-14 カバレッジ90%以上（96% 達成）
- [x] ✅ 2026-07-14 `SELECT *` が存在しないこと
- [x] ✅ 2026-07-14 Pydantic 型定義がすべてのリクエスト/レスポンスに存在すること
- [x] ✅ 2026-07-14 機密情報がコードに直書きされていないこと（SSM・IAMロール経由）
- [x] ✅ 2026-07-14 実装内容が正ドキュメント（スコアリングロジック設計書 v0.6・AIプロンプト設計書 v0.3）と一致していること
- [x] ✅ 2026-07-14 PythonがDBへの書き込みを一切行わないこと（§1.3データ連携方針準拠）

---

## 残課題（本番リリース前に対応必須）

- [ ] `MOCK_MODE`の無効化・削除（AWS本番アカウント整備後）
- [ ] Laravel⇔Python間の通信経路確定（スコアリングロジック設計書 v0.6 §6 T29、インフラ担当確認待ち）
- [ ] `スコアリングロジック設計書.md` §3.2の出力文字数表記を`AIプロンプト設計書.md`の数値に同期（文書オーナーへ確認予定、低優先度）
- [ ] Laravel側：`app/Services/AiSummaryService.php`のPython API連携実装（現状`// TODO`のまま。Laravelチームの最新ブランチで進捗確認中）
- [ ] Laravel側：E2レスポンス（`ai_summary`・`ai_summary_generated_at`）を受け取った後の`engineers`テーブルへのUPDATE処理の実装
