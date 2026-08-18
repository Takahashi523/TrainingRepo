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

> **⚠️ 訂正（2026-08-17）**：下記のうちコード・テストに関する2項目は、2026-07-14 に「完了」と記録されていたが**実際には実装されていなかった**。`matching_service.py`を最後に変更したコミットは`b161703`（2026-07-12＝Step 8）であり、Step 9 の対応を記録したコミット`60d02db`が変更したファイルは本 `tasklist.md` 1件のみだった。テストも`test_updates_db_when_summary_is_not_empty`が残り「UPDATEすること」をアサートし続けていたため、緑のまま気づけない状態になっていた。2026-08-17 のLaravelチームからの再指摘を受け、当初の方針どおりコードへ反映した（経緯は `reason.md` の Step 9 を参照）。

- [x] ✅ ~~2026-07-14~~ → **2026-08-17 実装反映** `generate_profile_summary`からDB書込（`UPDATE engineers ...`・`db.commit()`）を削除
- [x] ✅ ~~2026-07-14~~ → **2026-08-17 実装反映** テストを「PythonはDBに一切書き込まないこと」を検証する内容に置き換え（`test_does_not_write_to_db_when_summary_is_not_empty`）
- [x] ✅ 2026-08-17 `routers/profile.py` の description（「DBのエンジニア情報を更新します」）を実態に合わせて修正
- [x] ✅ 2026-07-14 `design.md`・`reason.md`・`requirements.md`・`tasklist.md`（本ファイル）を今回の結果に合わせて改訂（※ 2026-08-17 に実態との差異を訂正）
- [x] ✅ 2026-07-14 Laravelチームへ、`engineers.ai_summary`等への反映がLaravel側の実装責務である旨を回答

---

## Step 10: Laravel↔Python 契約不整合の是正（2026-08-17・Laravelチーム指摘対応）

結合テストシナリオ（PR #59）のレビュー過程で、Laravel側の`Http::fake`が固定している想定応答と実Python実装が食い違っている箇所が判明したため修正。

- [x] ✅ 2026-08-17 `routers/matching.py`：`EngineerNotFoundError`／`NoActiveCandidateError`を`HTTPException(detail={...})`へ変換していたため応答が`{"detail": {"error_code": ...}}`と入れ子になり、`error_code`をトップレベルで読むLaravel側（`HttpMatchingEngineClient::mapErrorResponse`）が404/422を判定できず一律`upstream()`に落ちていた問題を修正。`BedrockError`と同じ re-raise パターンに揃え、`main.py`のapp-levelハンドラに委ねる形とした
- [x] ✅ 2026-08-17 `routers/profile.py`：同様に404を re-raise へ変更（Laravel側は`error_code`を読まないため挙動影響はないが、OpenAPI宣言との整合のため）
- [x] ✅ 2026-08-17 `tests/test_routers.py`：404/422のアサーションを`response.json()["detail"]["error_code"]`からトップレベル参照に修正
- [x] ✅ 2026-08-17 `tests/test_routers.py`：レスポンスの形自体を固定する契約テスト`test_error_response_body_is_flat_not_nested`を追加（修正前のコードでは4件失敗することを確認済み）
- [ ] マージ後、結合テストシナリオ LP-CONTRACT-02（実エンジンに対する契約適合確認）を実施

> **注意**：`except EngineerNotFoundError` の節を単純に削除する方式は使えない。両例外とも`Exception`のサブクラスであるため、最後の`except Exception`が捕捉して500に化ける。`BedrockError`と同じく「捕捉して`raise`で再送出」する必要がある。

> **残課題（Laravel側・要協議）**：`PipelineStoreRequest`が`match_rank`を`in:A,B,C,D`・`match_score`を`between:0,100`で検証しているため、エンジンが範囲外の値を返すとカードは表示されるのにパイプライン追加で弾かれ、ユーザーに回避手段がない。Python側は`matching_service.py`で降順ソート・上位5件固定を保証しているため現状は顕在化しないが、表示経路と書き込み経路で許容ポリシーが不一致である点はLaravelチームと協議中。

---

## 完了基準

- [x] ✅ ~~2026-07-14 pytest 全件通過（103件）~~ → **2026-08-17 pytest 全件通過（104件）**（Step 10 で契約テスト `test_error_response_body_is_flat_not_nested` を1件追加）
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
- [x] ✅ 2026-08-17 Laravel側：Python API連携の実装 — `develop` にて実装済みを確認。ファイル名は当時の想定（`app/Services/AiSummaryService.php`）から変更され、`app/Services/Ai/HttpAiSummaryClient.php` として実装されている
- [x] ✅ 2026-08-17 Laravel側：E2レスポンス（`ai_summary`・`ai_summary_generated_at`）の`engineers`テーブルへの反映 — `develop` にて `EngineerService::refreshAiSummary()` が `$engineer->update([...])` で実施していることを確認。これによりStep 9の「保存責務はLaravel側」が両側で成立する
