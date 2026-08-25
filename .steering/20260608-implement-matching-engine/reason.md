# reason.md — 実装・設計の根拠

## Step 1: プロジェクト骨格

### config.py で環境変数を集約管理する理由
各ファイルで `os.getenv()` を直接呼ぶと、変数名のタイポや変更時の修正漏れが起きやすい。`config.py` に一元化することで、参照箇所が単一になり変更コストが下がる。

### `openai` を `boto3` に差し替えた理由
本プロジェクトの LLM は AWS Bedrock（Claude 3.5 Sonnet）を使用する。`openai` ライブラリは不要であり、`requirements.txt` に含めると依存関係の混乱を招く。
（※この方針決定後も`requirements.txt`に`openai`の記載が残っていたため、Step 8 で削除した。）

### `database.py` を `models/db.py` に移動した理由
ルーター層・サービス層・モデル層を明確に分離することで、単一責任の原則（SRP）を守る。
（※この移動が不完全で、`app/database.py`・`app/models/database.py`・`app/models/main.py`という重複・未使用ファイルが残存し、`routers/matching.py`が誤って`app.database`を参照していた時期があった。Step 8 で`models/db.py`に一本化し、重複ファイルを削除した。）

### ルーターを `matching.py` と `profile.py` に分割した理由
E1/E3（マッチング）と E2（プロフィール要約）は責務が異なる。同一ファイルに混在させると肥大化し、変更時の影響範囲が広がる。

---

## Step 2: DB接続 + データ取得

### 内部データクラスに Pydantic を使わない理由
Pydantic は API の入出力型定義（schemas.py）において外部データのバリデーションとスキーマ変換に特化させる。DBから取得した内部データのやり取りには、軽量な dataclass を採用することで、不要なバリデーションオーバーヘッドを排除し、データ構造の「器」としての責務を明確に分離する。

### project_skills を IN 句で一括取得する理由
案件ごとに個別クエリを発行すると N+1 問題が発生する。`IN :ids` による一括取得で 1回に抑え、レスポンス時間要件（QA#30 の 5〜10 秒）に対応する。

### fetch_active_projects で project_ids=[] のとき DB に触れない理由
空リストを IN 句に渡すと MySQL でエラーになる。早期リターンすることで不要なクエリを完全に排除する（防御的プログラミング）。

### fetch_registered_project_ids が set を返す理由
パイプライン除外チェック（Step 3.5）では `project_id in registered_ids` の判定を案件件数分繰り返す。list の O(n) に対し set の O(1) で高速化する。

### テストにおけるモックライブラリの使い分けの理由
外部 API（Bedrock / Google Maps）は `pytest-mock` を使用して適切にモック化し、DBセッションのモックには標準ライブラリの `unittest.mock.MagicMock` を使用可とすることで、役割を明確に分けてテストの記述を簡潔にする。

### モックのパッチ対象は「呼び出し元モジュールの名前空間」を指定する理由
`matching_service.py` は `from app.services.bedrock_service import invoke_matching` のように**名前をインポート**している。この場合、`mocker.patch("app.services.bedrock_service.invoke_matching")` としてもモックは効かない（`matching_service`側は既に自分の名前空間にコピーした関数を参照しているため）。正しくは `mocker.patch("app.services.matching_service.invoke_matching")` のように、**実際に呼び出しているモジュール側**をパッチする必要がある。
（※この原則を誤っていたテストが一時的に本物のAWSへ接続を試みてエラーになる不具合があった。全テストを呼び出し元パッチに統一して解消した。）

---

## Step 3: Bedrock クライアント

### Bedrock クライアントを遅延シングルトンにする理由
テスト時にモック差し込みが容易になるため。起動時に初期化すると `import` 時点で AWS 接続が走り、CI 環境で失敗する。`_get_client()` を経由する設計にすることで `mocker.patch.object(svc, "_get_client", ...)` でテスト内に差し込める。

### アプリ層でランクを検算する理由（AI 出力を使わない）
`AIプロンプト設計書.md`（v0.3）§5.1 の「match_rank の閾値判定はアプリ層でも検算する（AI が誤った match_rank を返した場合の保険）」に準拠。AI が match_score=85 でも match_rank="C" を返すことがある。アプリ層で `_determine_rank(score)` を実行し、AI 出力のランクは無視する。

### プロンプトに配点目安ガイドを埋め込む理由
`AIプロンプト設計書.md`（v0.3）§3.3・`スコアリングロジック設計書.md`（v0.6）§5.3 に、8観点の配点目安（必須スキル30点・工程経験20点…）と各観点の計算式・マトリクス表を**プロンプト本文にガイドラインとして埋め込む**ことが明記されている。
（※この配点目安がプロンプトに一切含まれておらず、AIが採点基準を知らないまま0〜100点で判定していた不備があった。Step 8 で配点目安・計算式・「match_scoreは各観点の合計値と完全に一致させること」という厳守指示を追加した。）

### Bedrock呼び出しパラメータを temperature=0.3 / top_p=0.9 に修正した理由
`スコアリングロジック設計書.md`（v0.6）§5.2 に明記された値。
（※以前は`temperature=0.0`固定・`top_p`未設定という誤った値だった。Step 8 で設計書通りに修正した。）

### JSON パース失敗時に別プロンプトでリトライする理由
`AIプロンプト設計書.md`（v0.3）§3.6.1 の指定。同じプロンプトで再呼び出しすると同じ形式で失敗する可能性が高い。専用の「JSON のみ出力せよ」指示プロンプトに切り替えることで成功率を上げる。

### time.sleep をモジュールレベルで参照する理由
テストで `mocker.patch("app.services.bedrock_service.time.sleep")` によってスリープをスキップできるため。関数内で `import time` を行うとパスが変わりモックが効かなくなる。

---

## Step 4: マッチング計算フロー（E1 骨格）

### 内部データ構造を別ファイル（internal_types.py）に分離した理由
`bedrock_service.py` と `matching_service.py` の間でデータ構造（`EngineerData` など）の参照による循環インポートが発生するため。データ構造定義を独立したファイルに切り出すことで、依存関係をクリーンにする。

### カスケードソートの絞り込み閾値を「30件」とする理由
`スコアリングロジック設計書.md`（v0.6）§3.4 Step 3.6 に「候補件数チェック：30件以下ならそのまま全件AI評価、30件超なら…カスケードソートで上位30件のみAI評価対象とする」と明記されている。
AIは候補案件ごとに**個別に**Bedrockを呼び出す設計（Step 3.8）のため、1回のプロンプトに全候補をまとめて投入するわけではなく、Claudeの出力トークン上限に抵触することはない。30件という上限は、コスト・レスポンス時間要件（QA#30の同期5〜10秒）を満たすために設定された値である。

### proposable 以外エンジニアでもフローを続行する理由
エラーレスポンスの仕様（`スコアリングロジック設計書.md` v0.6）に「エンジニアが proposable でない場合のエラーコード」が定義されていない。ログ警告を記録するにとどめ、フローは正常続行する。

### _get_commute_time_minutes をGoogle Maps呼び出しに置き換えた理由
Step 6 実装により、暫定スタブ（`None`固定）から実際のDistance Matrix API呼び出しに置き換えた。

---

## Step 5: E1 エンドポイント完成

### エラーハンドラーを main.py に集約した理由
`@app.exception_handler` で main.py に集約することで、Router は薄く保たれ、エラーレスポンス形式の変更がファイル1か所で完結する。

### BedrockError を 504 UPSTREAM_TIMEOUT として返却する理由
インフラ層（AWS Bedrock）の接続エラーやリトライ超過によるタイムアウトは、外部API側が応答していない遅延状態を指すため、`502` ではなく `504 UPSTREAM_TIMEOUT` を返却する。

### ルーターに `except BedrockError: raise` を明示的に置く理由
ルーターの `except Exception` が `BedrockError` より先に評価される位置にあると、`main.py` の `@app.exception_handler(BedrockError)` に到達する前に握りつぶされ、意図しない500になってしまう。`except Exception` より手前に `except BedrockError: raise` を置くことで、握りつぶさずに `main.py` 側のハンドラへ確実に横流しする。
（※この対応が漏れており、Bedrockタイムアウト時に504ではなく500が返る不具合が実際に発生していた。修正済み。）

### run_matching としてインポートしエイリアスした理由
Router 関数名を `matching_calculate` にすると `calculate_matching`（サービス層関数）と区別できる。エイリアスにより「呼び出し元（router）がどの関数を呼んでいるか」がテスト時の `mocker.patch("app.routers.matching.run_matching")` で明示される。

### dependency_overrides で DB を差し替えた理由
TestClient でエンドポイントを叩く際に実 DB に接続させないため。`app.dependency_overrides[get_db] = lambda: MagicMock()` により `Depends(get_db)` が差し替わる。この`get_db`は`models/db.py`の実体を指す必要があるため、ルーター側も同じ場所からインポートすることで一致させている。

### 500エラー時に内部の例外メッセージをレスポンスに含めない理由
DBカラム名やSQL文の断片など、内部実装の詳細が外部APIレスポンスから漏れることを防ぐため。詳細はログにのみ残し、レスポンスは汎用メッセージ（「予期せぬエラーが発生しました。」）に統一する。

---

## Step 6: Google Maps クライアント

### 失敗時に None を返し、マッチングフローを継続する理由
Google Maps APIが失敗しても、通勤時間以外の観点でのマッチング判定自体は継続可能なため。エラーコードとしての専用ハンドリングは設けず、AIプロンプト側で「通勤時間NULL＝算出失敗」として扱う。

---

## Step 7: E2 エンドポイント

### E2の入力を `engineer_id` のみとする理由
`スコアリングロジック設計書.md`（v0.6）§2.6・§4.3、`AIプロンプト設計書.md`（v0.3）§4.3 に、E2は`engineer_id`のみをリクエストとして受け取り、`engineers.appeal_note`（H1）をPython側がDBから取得してAIに渡す、と明記されている。職務経歴書ファイルのアップロード自体は廃止されているが（QA#13・QA#85）、その代替として画面から`appeal_point`・`raw_skills`を直接送る方式が採用されたわけではない。
（※過去にこの点を誤解し、`appeal_point`/`raw_skills`の2項目を画面から受け取る実装になっていた時期があった。Step 8 で設計書通りの`engineer_id`のみの方式に巻き戻した。）

### PythonがDBへの書き込みを一切行わない理由
`スコアリングロジック設計書.md`（v0.6）§1.3「データ連携方針」に「Pythonは計算結果の返却に特化。DB書込（パイプライン登録等）はLaravel側で集約」「スコアは QA#45 によりオンデマンド計算・DB保存なしと確定。書込責務を Laravel に寄せて責務分離」と明記されている。この方針は`パイプライン登録`に限定した話ではなく、Python側の全エンドポイントに共通する原則であり、E2の`ai_summary`保存も対象に含まれる。
（※過去にこの点を見落とし、E2の`generate_profile_summary`内で`UPDATE engineers ...`を直接実行する実装になっていた誤りがあった。Laravelチームからの指摘を受け、Step 9 で修正した。）

---

## Step 8: 正ドキュメントとの整合修正（2026-07-12）

`スコアリングロジック設計書.md`（v0.6）・`AIプロンプト設計書.md`（v0.3）の原本を入手し、実装・テスト・ドキュメント（本ファイル群）とのクロスチェックを実施した結果、以下の乖離が判明したため修正した。

| # | 内容 | 修正前 | 修正後 |
|---|---|---|---|
| 1 | E1 API仕様 | `limit`/`rank_filter`/`total_hits`を実装 | 削除（v0.6改訂履歴B-05・B-06・B-07により元々「削除確定」だった） |
| 2 | カスケードソート閾値 | 5件 | 30件（AIは候補ごと個別呼び出しのため、5件に絞る根拠自体が誤りだった） |
| 3 | E2入力 | `appeal_point`+`raw_skills` | `engineer_id`のみ（DBの`appeal_note`使用） |
| 4 | プロンプト内容 | 配点目安ガイドなし | 8観点の配点目安・計算式・厳守事項を追加 |
| 5 | Bedrockパラメータ | temperature=0.0固定・top_p未設定・max_tokens不一致 | temperature=0.3・top_p=0.9・max_tokens（800/600）に修正 |
| 6 | 出力文字数 | ai_comment 150-200字・ai_missing 100字以内 | ai_comment 150-250字・ai_missing 50-150字 |
| 7 | BedrockErrorのステータスコード | ルーターのexcept Exceptionに握りつぶされ500 | except BedrockError: raiseで504に修正 |
| 8 | get_dbの二重管理 | `app.database`と`app.models.db`が別実体 | `models/db.py`に一本化、重複ファイル削除 |
| 9 | requirements.txt | `openai`が残存 | 削除 |

対応の結果、テストは103件全て成功・カバレッジ96%を維持している。

---

## Step 9: E2のDB書込方針修正（2026-07-14・Laravelチーム指摘対応）

Laravelチームより「PythonがDBのUPDATEを行っていないか」という確認があり、`スコアリングロジック設計書.md` §1.3「データ連携方針」を再確認したところ、以下が判明した。

- 表内「データ書き込み」の決定内容：「Pythonは計算結果の返却に特化。DB書込（パイプライン登録等）はLaravel側で集約」（🟢確定、根拠：QA#45・QA#48）
- この方針は「パイプライン登録」に限定されず、Python側の書き込み処理全般に適用される原則である

これに対し、E2（`generate_profile_summary`）の実装が`UPDATE engineers SET ai_summary = ..., ai_summary_generated_at = ... WHERE id = ...`を直接実行しており、§1.3の方針に反していた。該当のDB書込処理（`db.execute`・`db.commit`）を削除し、Pythonは`ai_summary`・`ai_summary_generated_at`を**レスポンスとして返すだけ**（DB反映はLaravel側の責務）に修正する方針とした。

### ⚠️ 訂正（2026-08-18）：上記の修正は2026-07-14時点では実装されていなかった

**本セクションおよび`tasklist.md`のStep 9は、2026-07-14に「対応済み」として記録されていたが、実際にはドキュメントのみが更新され、コードとテストには反映されていなかった。** 2026-08-18にLaravelチームより再度の指摘を受けて調査した結果、以下が判明した。

- `matching_service.py`を最後に変更したコミットは`b161703`（2026-07-12＝Step 8）であり、Step 9の日付以降このファイルは一度も変更されていなかった
- Step 9の対応を記録したコミット`60d02db`（2026-07-21）が変更したファイルは`tasklist.md`の1件のみだった
- テストも置き換えられておらず、`test_matching_service.py`には`test_updates_db_when_summary_is_not_empty`（「要約テキストがある場合、engineers テーブルを UPDATE すること」）が残り、`mock_db.commit.assert_called_once()`で**書き込みが行われることを積極的にアサートしていた**。このためテストは緑のまま、方針違反が検出されない状態になっていた

この二重書き込みは、Laravel側が`services.ai_summary.timeout`（既定30秒）でタイムアウトした後にPythonが`commit`した場合、**Laravelは「AI要約の生成に失敗しました。」の失敗トーストを出すのに、人材詳細画面には要約が表示される**という食い違いを生む。加えてPythonの生SQLは`updated_at`を更新しないため、更新時刻の整合も崩れる。この事象はLaravel側の`Http::fake`では原理的に検出できない（fakeはHTTP応答を差し替えるだけで、Python側のDB副作用が発生しないため）。

**2026-08-18に、当初のStep 9の方針どおりコードへ反映した。**

- `generate_profile_summary()`から`db.execute`（`UPDATE engineers ...`）・`db.commit()`を削除し、返却のみとした
- `test_updates_db_when_summary_is_not_empty`を`test_does_not_write_to_db_when_summary_is_not_empty`に置き換え、`mock_db.execute.assert_not_called()`・`mock_db.commit.assert_not_called()`で**書き込みが復活しないことを固定**した
- `routers/profile.py`のOpenAPI description が「DBのエンジニア情報（ai_summary / ai_summary_generated_at）を更新します」のままだったため、「返却します。DBへの保存は行いません」に修正した

### 教訓

チェックリストに`[x]`を付ける前に、対応するコード差分が実際に存在することを確認する。ドキュメントとコードを別コミットに分ける場合は特に、片方だけが進んだ状態で「完了」と記録されやすい。**方針を変えたときは、その方針違反を検出するテストを同時に入れる**（本件では「書き込まないこと」を検証するテストがあれば、実装未反映の時点で赤くなり気づけた）。

---

## 改訂履歴（誤りの記録）

このファイルおよび `design.md`・`requirements.md`・`tasklist.md` は、開発初期の理解に基づくメモとして作成されたものであり、後から判明した誤りが複数含まれていた。特に以下の4点は、正ドキュメントとの突き合わせで明確に誤りと判明したため、Step 8・Step 9 にて修正・削除している。

1. 「カスケードソート閾値を5件とした理由」（Claudeの出力トークン上限8,192を超えるため、という説明）→ AIは候補ごとに個別呼び出しする設計であるため、この前提自体が誤りだった。
2. 「Step 5.5：`limit`/`rank_filter`/`total_hits`をE1に追加する理由」→ これらの機能はv0.6で既に削除確定していた仕様であり、追加自体が誤りだった。
3. 「E2の入力を`appeal_point`/`raw_skills`とする理由」→ 正しくは`engineer_id`のみで、DBの`appeal_note`を使う仕様だった。
4. 「E2で`engineers.ai_summary`をUPDATEする理由」→ そもそもPythonはDBに書き込まない方針（§1.3）であり、UPDATEを実装したこと自体が誤りだった。Laravelチームからの指摘で判明。

同様の勘違いを繰り返さないよう、今後このプロジェクトを担当する際は、本ファイル群よりも先に正ドキュメント（`スコアリングロジック設計書.md`・`AIプロンプト設計書.md`）を参照すること。特に§1.3「データ連携方針」は見落としやすいので要注意。
