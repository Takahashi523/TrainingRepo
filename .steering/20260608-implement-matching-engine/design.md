# design.md — マッチングエンジン実装

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
| 5.5 | E1 仕様適合修正 | schemas.py / matching_service.py / routers/matching.py / main.py | ✅ 完了 |
| 6 | Google Maps クライアント | services/gmaps_service.py | 未着手 |
| 7 | E2 エンドポイント（プロフィール要約） | services/matching_service.py / routers/profile.py | ✅ 完了 |

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
```

Router は薄く保ち、ビジネスロジックは Service に集約する。

---

## E1 マッチング計算フロー（AIプロンプト設計書 v0.3 / スコアリングロジック設計書 v0.6 準拠）

```
Step 3.0  リクエスト受付・engineer_id バリデーション
Step 3.1  エンジニア情報取得（engineers + engineer_skills）
Step 3.2  対象案件一覧取得（projects + project_skills, status='open'）
Step 3.3  project_ids 指定がある場合はフィルタ
Step 3.4  エンジニアが稼働中かチェック（status='proposable'）
Step 3.5  パイプライン除外（pipelines テーブルで既登録の project_id を除外）
Step 3.6  候補 >5 件なら カスケードソートで絞込
          （工程経験重複数 → 単価 → 勤務形態 → 開始時期 → 登録日）
Step 3.7  Google Maps で commute_time_minutes 取得（案件ごと）
Step 3.8  Bedrock AI 総合判定
          （match_score / ai_score_reason / ai_comment / ai_missing を一括生成）
Step 3.9  アプリ層クランプ（match_score = max(0, raw_score)）
Step 3.10 アプリ層ランク検算（AI 出力の match_rank は無視し _determine_rank で確定）
Step 3.11 上位5件にソート・絞込
Step 3.12 レスポンス構築・返却
```

---

## AI 総合判定（AIプロンプト設計書 v0.3 準拠）

Bedrock（Claude 3.5 Sonnet）が以下の8観点をプロンプトで指示された上で総合判定し、
`match_score` / `ai_score_reason` / `ai_comment` / `ai_missing` を **一括生成** する。

| 観点 | 配点目安 |
|---|---|
| 必須スキル充足度 | 最大30点 |
| 工程経験適合度 | 最大20点 |
| 尚可スキル適合度 | 最大10点 |
| 勤務形態適合度 | 最大10点 |
| 勤務地/通勤適合度 | 最大10点 |
| 単価適合度 | 最大10点 |
| 稼働開始時期適合度 | 最大5点 |
| 顧客折衝/人物要件適合度 | 最大5点 |

配点の詳細・計算ルールは `docs/02_design/backend/AIプロンプト設計書.md`（v0.3）のプロンプト本文を正とする。
**Python コードが担う計算はクランプとランク検算のみ。**

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

## E2 プロフィール要約フロー（AIプロンプト設計書 v0.3 §2 準拠）

```
Step 8.1  engineer_id でエンジニア情報取得
Step 8.2  Bedrock でプロフィール要約生成（appeal_note を入力）
Step 8.3  engineers.ai_summary / ai_summary_generated_at を UPSERT
Step 8.4  レスポンス返却
```

---

## 変更コンポーネント一覧

| ファイル | 変更内容 |
|---|---|
| `python/app/services/matching_service.py` | DB取得・フロー全体・`NoActiveCandidateError`・`limit`/`rank_filter`/`total_hits` 追加 ✅ |
| `python/app/services/bedrock_service.py` | Bedrock 呼び出しラッパー（リトライ・JSON再試行） ✅ |
| `python/app/services/gmaps_service.py` | 新規作成。Google Maps API ラッパー（Step 6） |
| `python/app/routers/matching.py` | E1 実装完成。`limit`/`rank_filter`/`total_hits` をリクエスト/レスポンスに追加 ✅ |
| `python/app/routers/profile.py` | E2 スタブ → 実装完成（Step 7） |
| `python/app/models/schemas.py` | `MatchingRequest`（limit/rank_filter）・`MatchingResponse`（total_hits）追加 ✅ |
| `python/app/main.py` | 例外ハンドラ集約（EngineerNotFoundError / NoActiveCandidateError / BedrockError） ✅ |
| `python/tests/test_matching_service.py` | 78件・カバレッジ98% ✅ |
| `python/tests/test_bedrock_service.py` | 32件・カバレッジ97% ✅ |
| `python/tests/test_routers.py` | 8件・カバレッジ100% ✅ |
| `python/tests/test_gmaps_service.py` | 新規作成（Step 6） |

---

## 外部サービス連携設計

### AWS Bedrock

- クライアント：`boto3.client("bedrock-runtime", region_name=AWS_REGION)`
- 認証：IAM ロールベース（`boto3` が自動取得、APIキー直書き禁止）
- モデル：`anthropic.claude-3-5-sonnet-20240620-v1:0`
- 呼び出し：`invoke_model`（同期）
- ローカルテスト時：`pytest-mock` でモック化

### Google Maps Distance Matrix API

- キー取得：boto3 SSM `get_parameter(Name="Nexus-google-maps-key", WithDecryption=True)`
- エンドポイント：`https://maps.googleapis.com/maps/api/distancematrix/json`
- 単位：commute_time_minutes（秒→分に変換）
- ローカルテスト時：`pytest-mock` でモック化

---

## エラーハンドリング方針

| エラー | HTTP | error_code |
|---|---|---|
| エンジニアが見つからない | 404 | `ENGINEER_NOT_FOUND` |
| パイプライン除外後に候補ゼロ | 422 | `NO_ACTIVE_PROJECT` |
| バリデーションエラー | 422 | Pydantic デフォルト |
| Bedrock タイムアウト（リトライ後も失敗） | 504 | `UPSTREAM_TIMEOUT` |

`@app.exception_handler` を `main.py` に集約し、Router 層は薄く保つ（try/except を書かない）。

---

## テスト設計方針

- `tests/test_matching_service.py`：DB取得・フロー統合（モック）
- `tests/test_bedrock_service.py`：Bedrock 呼び出し（モック）（実装済み）
- `tests/test_gmaps_service.py`：Google Maps 呼び出し（モック）
- `tests/test_routers.py`：エンドポイント結合（TestClient + DB モック）
- カバレッジ90%以上が必須

---

## 影響範囲

- Laravel 側への影響なし（HTTP 呼び出しのインターフェースは変わらない）
- DB スキーマへの変更なし（既存テーブルを読み書きするのみ）
