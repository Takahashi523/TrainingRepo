# design.md — マッチングエンジン実装

> **2026-07-12 改訂**：正ドキュメントとの突き合わせにより、カスケードソート閾値（5件→30件）・
> `limit`/`rank_filter`/`total_hits`の削除・E2入力仕様・Bedrock呼び出しパラメータ等を修正した。
> 修正の経緯は `reason.md` を参照。

## 実装アプローチ

段階的に理解しながら実装を進める。各 Step を完結させてからコミットし、次の Step に移る。

### Step 構成

| Step | 内容 | 実装ファイル | 状態 |
|---|---|---|---|
| 1 | プロジェクト骨格 | main.py / config.py / routers / models | ✅ 完了 |
| 2 | DB接続 + データ取得 | models/db.py / services/matching_service.py（DB部分） | ✅ 完了 |
| 3 | Bedrock クライアント | services/bedrock_service.py | ✅ 完了 |
| 4 | マッチング計算フロー（E1 骨格） | services/matching_service.py（フロー全体） | ✅ 完了 |
| 5 | E1 エンドポイント完成 | routers/matching.py / main.py | ✅ 完了 |
| 6 | Google Maps クライアント | services/gmaps_service.py | ✅ 完了 |
| 7 | E2 エンドポイント（プロフィール要約） | services/matching_service.py / routers/profile.py | ✅ 完了 |
| 8 | 正ドキュメントとの整合修正 | 全ファイル | ✅ 完了（下記参照） |

> **旧 Step 5.5 について**：以前ここに「`limit`/`rank_filter`/`total_hits`をE1に追加する」という Step が存在したが、
> `スコアリングロジック設計書.md` v0.6 改訂履歴（B-05・B-06・B-07）でこれらは**削除**と確定していたことが判明したため、
> Step 8 にて実装・テストとも巻き戻した。E1のレスポンス件数は当初から一貫して「常に上位5件固定」（QA#33・#50）である。

---

## アーキテクチャ

### レイヤー構成

```
Router（routers/）
  └─ Service（services/）
       ├─ matching_service.py   # ビジネスロジック・DBアクセス
       ├─ bedrock_service.py    # AWS Bedrock 呼び出し
       └─ gmaps_service.py      # Google Maps API 呼び出し
           └─ Model（models/）
                ├─ db.py        # SQLAlchemy engine / session
                └─ schemas.py   # Pydantic 型定義
                └─ internal_types.py # 内部データ構造定義（dataclass）
```

- Router は薄く保ち、ビジネスロジックは Service に集約する。
- 循環インポートを解消しテスト容易性を高めるため、サービス間で共有する内部データ構造（`EngineerData`等）は `models/internal_types.py` に切り出して独立させる。
- DBセッション取得（`get_db`）は `models/db.py` に一本化する（過去に`app/database.py`・`app/models/database.py`・`app/models/main.py`という重複・未使用ファイルが存在したが削除済み）。

---

## E1 マッチング計算フロー（AIプロンプト設計書 v0.3 / スコアリングロジック設計書 v0.6 準拠）

```
Step 3.0  リクエスト受付・engineer_id バリデーション
Step 3.1  エンジニア情報取得（engineers + engineer_skills）
Step 3.2  対象案件一覧取得（projects + project_skills, status='open'）
Step 3.3  project_ids 指定がある場合はフィルタ
Step 3.4  エンジニアが稼働中かチェック（status='proposable'）。非proposableでも警告ログのみでフロー続行
Step 3.5  パイプライン除外（pipelines テーブルで既登録の project_id を除外）
Step 3.6  候補 >30 件なら カスケードソートで上位30件に絞込
（工程経験重複数 → 単価 → 勤務形態 → 開始時期 → 登録日）
※AIは候補ごとに個別呼び出しするため、1プロンプトへの一括投入トークン上限には抵触しない。
30件という上限は、コスト・レスポンス時間要件（QA#30の同期5〜10秒）を満たすための上限。
Step 3.7  Google Maps で commute_time_minutes 取得（案件ごと）
Step 3.8  Bedrock AI 総合判定（候補案件ごとに個別呼び出し）
（match_score / ai_score_reason / ai_comment / ai_missing を一括生成。
 プロンプトには配点目安ガイド・判定ルールを明示的に埋め込む）
Step 3.9  アプリ層クランプ（match_score = max(0, raw_score)）
Step 3.10 アプリ層ランク検算（AI 出力の match_rank は無視し _determine_rank で確定）
Step 3.11 スコア降順ソート → 上位5件に絞込（QA#33・QA#50 確定。常に固定5件）
Step 3.12 レスポンス構築・返却
```

---

## AI 総合判定（AIプロンプト設計書 v0.3 準拠）

Bedrock（Claude 3.5 Sonnet）が以下の8観点をプロンプトで指示された上で総合判定し、
`match_score` / `ai_score_reason` / `ai_comment` / `ai_missing` を **一括生成** する。

配点目安（プロンプト本文に埋め込み済み。以前はこの表の情報がプロンプトに含まれておらず、AIが判断基準を知らないまま採点していた不備があったため、Step 8 で修正した）：

| 観点 | 配点目安 |
|---|---|
| 必須スキル充足度 | 最大30点（未充足0件の場合は追加で-30点ペナルティ） |
| 工程経験適合度 | 最大20点 |
| 尚可スキル適合度 | 最大10点 |
| 勤務形態適合度 | 最大10点（マトリクス判定） |
| 勤務地/通勤適合度 | 最大10点 |
| 単価適合度 | 最大10点 |
| 稼働開始時期適合度 | 最大5点 |
| 顧客折衝/人物要件適合度 | 最大5点 |

配点の詳細・計算式は `docs/02_design/backend/AIプロンプト設計書.md`（v0.3）§3.3 を正とする。
**Python コードが担う計算はクランプとランク検算のみ**（match_scoreの各観点合計値との一致自体はプロンプト指示でAIに担保させる）。

### クランプ処理（スコアリングロジック設計書 v0.6 §3.3.1）

```python
match_score = max(0, raw_score)  # TINYINT UNSIGNED の下限保証
```

### ランク算定

| ランク | 条件 |
|---|---|
| A | 80 ≤ match_score ≤ 100 |
| B | 65 ≤ match_score ≤ 79 |
| C | 50 ≤ match_score ≤ 64 |
| D | 0 ≤ match_score ≤ 49 |

---

## E2 プロフィール要約フロー（AIプロンプト設計書 v0.3 §4 / スコアリングロジック設計書 v0.6 §4.3 準拠）

```
Step 8.1  engineer_id でエンジニア情報の存在チェック（同時に appeal_note を取得）
Step 8.2  Bedrock でプロフィール要約生成（engineers.appeal_note のみを入力とする）
Step 8.3  ai_summary が空でなければ engineers.ai_summary / ai_summary_generated_at を UPDATE
Step 8.4  レスポンス返却
```

> 入力は `engineer_id` のみ。画面から`appeal_point`・`raw_skills`を直接受け取る方式ではない
> （以前この誤った仕様で実装されていたため Step 8 で巻き戻した）。

---

## 変更コンポーネント一覧

| ファイル | 変更内容 |
|---|---|
| `python/app/services/matching_service.py` | DB取得・フロー全体。カスケードソート閾値は30件（`_MAX_AI_BATCH_SIZE`）、最終返却件数は常に5件固定（`_MAX_RESPONSE_MATCHES`）。E2は`engineer_id`のみ受け付け、`appeal_note`をDBから取得。✅ |
| `python/app/services/bedrock_service.py` | Bedrock 呼び出しラッパー（リトライ・JSON再試行）。プロンプトに配点目安ガイド・判定ルールを埋め込み。呼び出しパラメータ`temperature=0.3`/`top_p=0.9`/`max_tokens`（マッチング800・要約600）。✅ |
| `python/app/services/gmaps_service.py` | Google Maps API ラッパー ✅ |
| `python/app/routers/matching.py` | E1 実装完成。リクエストは`engineer_id`・`project_ids`のみ。`BedrockError`はexcept Exceptionに握りつぶされないよう明示的に再送出。✅ |
| `python/app/routers/profile.py` | E2 実装完成。リクエストは`engineer_id`のみ。✅ |
| `python/app/models/schemas.py` | `MatchingRequest`/`MatchingResponse`は`engineer_id`・`project_ids`・`matches`のみ（`limit`/`rank_filter`/`total_hits`は持たない）。`ProfileSummaryRequest`は`engineer_id`のみ。✅ |
| `python/app/models/db.py` | DBセッション取得の唯一の実体。`matching.py`・`profile.py`双方から参照 ✅ |
| `python/app/main.py` | 例外ハンドラ集約（`EngineerNotFoundError`/`NoActiveCandidateError`/`BedrockError`の変換対応） ✅ |
| `python/tests/` | 全体で103件・カバレッジ96% ✅ |

---

## 外部サービス連携設計

### AWS Bedrock

- クライアント：`boto3.client("bedrock-runtime", region_name=AWS_REGION, config=Config(connect_timeout=10, read_timeout=30, retries={"max_attempts": 0}))`
  （アプリ層の指数バックオフ・リトライと二重にならないよう、boto3自体のリトライは無効化）
- 認証：IAM ロールベース（`boto3` が自動取得、APIキー直書き禁止）
- モデル：`anthropic.claude-3-5-sonnet-20240620-v1:0`
- 呼び出し：`invoke_model`（同期）
- パラメータ：`temperature=0.3` / `top_p=0.9` / `max_tokens`（マッチング800・要約600）
- ローカルテスト時：`pytest-mock`を使用して適切にモック化すること（DBセッションのモックには標準の `MagicMock` を使用可）

### Google Maps Distance Matrix API

- キー取得：boto3 SSM `get_parameter(Name="Nexus-google-maps-key", WithDecryption=True)`
- エンドポイント：`https://maps.googleapis.com/maps/api/distancematrix/json`
- 単位：commute_time_minutes（秒→分に変換）
- ローカルテスト時：`pytest-mock` を使用して適切にモック化すること

---

## エラーハンドリング方針

| エラー | HTTP | error_code |
|---|---|---|
| バリデーションエラー | 400 | `INVALID_PARAMETER` |
| エンジニアが見つからない | 404 | `ENGINEER_NOT_FOUND` |
| パイプライン除外後に候補ゼロ | 422 | `NO_ACTIVE_PROJECT` |
| その他の予期せぬエラー | 500 | `INTERNAL_SERVER_ERROR`（内部の例外メッセージは含めず汎用文言で返す） |
| Bedrock タイムアウト（リトライ後も失敗） | 504 | `UPSTREAM_TIMEOUT` |

`@app.exception_handler` を `main.py` に集約する。ルーター層にも`except BedrockError: raise`を置き、
ルーターの`except Exception`に握りつぶされず`main.py`側のハンドラへ確実に到達するようにする
（以前この対応が漏れており、Bedrockタイムアウトが誤って500として返っていた不具合があったため）。

---

## テスト設計方針

- `tests/test_matching_service.py`：DB取得・フロー統合（モック）
- `tests/test_bedrock_service.py`：Bedrock 呼び出し・プロンプト内容の検証（モック）
- `tests/test_gmaps_service.py`：Google Maps 呼び出し（モック）
- `tests/test_routers.py`：エンドポイント結合（TestClient + DB モック）
- カバレッジ90%以上が必須（現状96%）
- モックのパッチ対象は「呼び出し元モジュールの名前空間」を指定する（`from x import y`した場合、`x.y`ではなく呼び出し元でパッチする）

---

## 影響範囲

- Laravel 側への影響なし（HTTP 呼び出しのインターフェースは変わらない。E1は当初から常に上位5件固定のため、`limit`/`rank_filter`巻き戻しによるLaravel側の変更も不要）
- DB スキーマへの変更なし（既存テーブルを読み書きするのみ）
