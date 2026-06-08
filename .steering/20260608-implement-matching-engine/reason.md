# reason.md — 実装・設計の根拠

## Step 1: プロジェクト骨格

### config.py で環境変数を集約管理する理由
各ファイルで `os.getenv()` を直接呼ぶと、変数名のタイポや変更時の修正漏れが起きやすい。`config.py` に一元化することで、参照箇所が単一になり変更コストが下がる。

### `openai` を `boto3` に差し替えた理由
本プロジェクトの LLM は AWS Bedrock（Claude 3.5 Sonnet）を使用する。`openai` ライブラリは不要であり、`requirements.txt` に含めると依存関係の混乱を招く。

### `database.py` を `models/db.py` に移動した理由
CLAUDE.md のファイル配置規約（`models/` 配下に `db.py` を置く）に準拠するため。ルーター層・サービス層・モデル層を明確に分離することで、単一責任の原則（SRP）を守る。

### ルーターを `matching.py` と `profile.py` に分割した理由
E1/E3（マッチング）と E2（プロフィール要約）は責務が異なる。同一ファイルに混在させると肥大化し、変更時の影響範囲が広がる。

---

## Step 2: DB接続 + データ取得

### 内部データクラスに Pydantic を使わない理由
Pydantic は API の入出力型定義（schemas.py）に使用する。DB→サービス層の内部構造は `dataclass` で表現し、バリデーションオーバーヘッドを避ける。ロジック層に Pydantic を混在させると「どこでバリデーションされるか」が曖昧になるため、責務を明確に分離する。

### project_skills を IN 句で一括取得する理由
案件ごとに個別クエリを発行すると N+1 問題が発生し、30件案件の場合 30回の DB ラウンドトリップが生じる。`IN :ids` による一括取得で 1回に抑え、レスポンス時間要件（QA#30 の 5〜10 秒）に対応する。

### fetch_active_projects で project_ids=[] のとき DB に触れない理由
空リストを IN 句に渡すと MySQL でエラーになる。早期リターンすることで不要なクエリを完全に排除する（防御的プログラミング）。

### fetch_registered_project_ids が set を返す理由
パイプライン除外チェック（Step 3.5）では `project_id in registered_ids` の判定を案件件数分繰り返す。list の O(n) に対し set の O(1) で高速化する。

### テストに MagicMock を使用する理由
`pytest-mock` は Bedrock / Google Maps など外部 API のモックに使用する（Steps 3・7）。SQLAlchemy Session の mock は標準ライブラリの `unittest.mock.MagicMock` で十分であり、依存を増やさない。

---

## Step 3: Bedrock クライアント

### Bedrock クライアントを遅延シングルトンにする理由
テスト時にモック差し込みが容易になるため。起動時に初期化すると `import` 時点で AWS 接続が走り、CI 環境で失敗する。`_get_client()` を経由する設計にすることで `mocker.patch.object(svc, "_get_client", ...)` でテスト内に差し込める。

### アプリ層でランクを検算する理由（AI 出力を使わない）
`docs/02_design/backend/AIプロンプト設計書.md`（v0.3）§5.1 の「match_rank の閾値判定はアプリ層でも検算する（AI が誤った match_rank を返した場合の保険）」に準拠。AI が match_score=85 でも match_rank="C" を返すことがある。アプリ層で `_determine_rank(score)` を実行し、AI 出力のランクは無視する。

### JSON パース失敗時に別プロンプトでリトライする理由
`docs/02_design/backend/AIプロンプト設計書.md`（v0.3）§3.6.1 の指定。同じプロンプトで再呼び出しすると同じ形式で失敗する可能性が高い。専用の「JSON のみ出力せよ」指示プロンプトに切り替えることで成功率を上げる。

### time.sleep をモジュールレベルで参照する理由
テストで `mocker.patch("app.services.bedrock_service.time.sleep")` によってスリープをスキップできるため。関数内で `import time` を行うとパスが変わりモックが効かなくなる。

---

## Step 4: マッチング計算フロー（E1 骨格）

### カスケードソートで使う3指標を関数に分離した理由
`_proc_overlap_count` / `_rate_in_range` / `_work_style_match` を個別関数として切り出すことで、`_cascade_sort` の sort_key が読みやすくなり、各指標を独立してテストできる。単一責任の原則（SRP）に従い、ソートキーの組み立てとカスケードロジックを分離した。

### proposable 以外エンジニアでもフローを続行する理由
エラーレスポンスの仕様（スコアリングロジック設計書 v0.6）に「エンジニアが proposable でない場合のエラーコード」が定義されていない。UI でもマッチングボタンはステータスに関係なく表示される（画面一覧・遷移図 WF_03/WF_05 確認済み）。ログ警告を記録するにとどめ、フローは正常続行する。

### bedrock_service をローカルインポートする理由
`bedrock_service.py` が `matching_service.py` の `EngineerData` / `ProjectData` をインポートしている。`matching_service.py` のトップレベルで `bedrock_service` をインポートすると循環インポートになる。`calculate_matching` 関数内でローカルインポートすることで解決する（Python のモジュールキャッシュにより実行時コストは初回のみ）。

### _get_commute_time_minutes をスタブとして残す理由
Step 6（Google Maps クライアント）が未実装のため、暫定的に `None` を返すスタブを配置する。呼び出し箇所は `calculate_matching` の1か所のみなので、Step 6 実装時に差し替えコストが最小化される。`invoke_matching` は `commute_time_minutes=None` を受け付けプロンプトに「NULL（算出失敗）」と出力するため、スタブのままでも AI 判定は機能する。

---

## Step 5: E1 エンドポイント完成

### エラーハンドラーを main.py に集約した理由
Router に try/except を書くと「HTTP ステータスへの変換ロジック」がルーター層に混入する。`@app.exception_handler` で main.py に集約することで、Router は薄く保たれ（ビジネスロジックなし）、エラーレスポンス形式の変更がファイル1か所で完結する。

### run_matching としてインポートしエイリアスした理由
Router 関数名を `matching_calculate` にすると `calculate_matching`（サービス層関数）と区別できる。エイリアスにより「呼び出し元（router）がどの関数を呼んでいるか」がテスト時の `mocker.patch("app.routers.matching.run_matching")` で明示される。

### dependency_overrides で DB を差し替えた理由
TestClient でエンドポイントを叩く際に実 DB に接続させないため。`app.dependency_overrides[get_db] = lambda: MagicMock()` により Depends(get_db) が差し替わり、テスト環境に MySQL が不要になる。

---

## Step 6: E1 エンドポイント完成

（実装時に追記）

---

## Step 7: Google Maps クライアント

（実装時に追記）

---

## Step 8: E2 エンドポイント

（実装時に追記）
