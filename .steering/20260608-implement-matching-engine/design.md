# design.md — マッチングエンジン実装

## 実装アプローチ

段階的に理解しながら実装を進める。各 Step を完結させてからコミットし、次の Step に移る。

### Step 構成

| Step | 内容 | 実装ファイル |
|---|---|---|
| 1 | プロジェクト骨格 | main.py / config.py / routers / models | ✅ 完了 |
| 2 | DB接続 + データ取得 | models/db.py / services/matching_service.py（DB部分） |
| 3 | Bedrock クライアント（モック付き） | services/bedrock_service.py |
| 4 | スコアリングロジック単体 | services/matching_service.py（スコア計算部分） |
| 5 | マッチング計算フロー（E1 骨格） | services/matching_service.py（フロー全体） |
| 6 | E1 エンドポイント完成 | routers/matching.py |
| 7 | Google Maps クライアント | services/gmaps_service.py |
| 8 | E2 エンドポイント（プロフィール要約） | services/matching_service.py / routers/profile.py |

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

## E1 マッチング計算フロー（スコアリングロジック設計書 §3 準拠）

```
Step 3.0  リクエスト受付・engineer_id バリデーション
Step 3.1  エンジニア情報取得（engineers + engineer_skills + engineer_work_histories）
Step 3.2  対象案件一覧取得（projects + project_skills, status='open'）
Step 3.3  project_ids 指定がある場合はフィルタ
Step 3.4  エンジニアが稼働中かチェック（status='proposable'）
Step 3.5  パイプライン除外（pipelines テーブルで既登録の project_id を除外）
Step 3.6  候補 >30 件なら カスケードソートで絞込
          （工程経験重複数 → 単価 → 勤務形態 → 開始時期 → 登録日）
Step 3.7  Google Maps で commute_time_minutes 取得（案件ごと）
Step 3.8  8次元スコアリング（下記参照）
Step 3.9  match_score = max(0, raw_score)  ← クランプ必須
Step 3.10 match_rank 算定（A/B/C/D）
Step 3.11 Bedrock で ai_score_reason / ai_comment / ai_missing 生成
Step 3.12 レスポンス構築・返却
```

### 8次元スコアリング（スコアリングロジック設計書 §3.2 準拠）

| 次元 | 内容 |
|---|---|
| S1 | 必須スキル一致率 |
| S2 | 優遇スキル一致率 |
| S3 | 工程経験一致 |
| S4 | 単価レンジ適合 |
| S5 | 勤務形態適合 |
| S6 | 通勤時間適合（commute_time_minutes） |
| S7 | 開始時期適合 |
| S8 | 商流制限適合 |

各次元の配点・計算式はスコアリングロジック設計書 §3.2 を正とする。

### クランプ処理（§3.3.1）

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

## E2 プロフィール要約フロー

```
Step 8.1  engineer_id でエンジニア情報・職歴取得
Step 8.2  Bedrock でプロンプト実行（AIプロンプト設計書 §2 準拠）
Step 8.3  engineers.ai_summary / ai_summary_generated_at を UPSERT
Step 8.4  レスポンス返却
```

---

## 変更コンポーネント一覧

| ファイル | 変更内容 |
|---|---|
| `python/app/services/matching_service.py` | 新規作成。DB取得・スコアリング・フロー全体 |
| `python/app/services/bedrock_service.py` | 新規作成。Bedrock 呼び出しラッパー |
| `python/app/services/gmaps_service.py` | 新規作成。Google Maps API ラッパー |
| `python/app/routers/matching.py` | E1 スタブ → 実装完成（E3 は既存） |
| `python/app/routers/profile.py` | E2 スタブ → 実装完成 |
| `python/app/models/schemas.py` | 必要に応じてスキーマ追加 |
| `python/tests/` | 各 Step に対応するテストコードを追加 |

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
| 案件が見つからない | 404 | `PROJECT_NOT_FOUND` |
| Bedrock / Google Maps 呼び出し失敗 | 502 | `EXTERNAL_API_ERROR` |
| バリデーションエラー | 422 | Pydantic デフォルト |

FastAPI の `HTTPException` を使用し、Router 層でキャッチする。

---

## テスト設計方針

- `tests/test_matching_service.py`：スコアリングロジック・クランプ・フロー
- `tests/test_bedrock_service.py`：Bedrock 呼び出し（モック）
- `tests/test_gmaps_service.py`：Google Maps 呼び出し（モック）
- `tests/test_routers.py`：エンドポイント結合（TestClient + DB モック）
- カバレッジ90%以上が必須

---

## 影響範囲

- Laravel 側への影響なし（HTTP 呼び出しのインターフェースは変わらない）
- DB スキーマへの変更なし（既存テーブルを読み書きするのみ）
- `requirements.txt` への追加が必要な場合は都度確認する
