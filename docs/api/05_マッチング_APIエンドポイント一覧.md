# マッチング（Matching）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-07-27  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。

> **【2026-07-27 確定/修正】マッチング結果機能とパイプライン追加の実装（PR #48）に合わせて Props・レスポンスを実装と一致させた。**
> **背景：** 本書は 2026-05-27 版のまま実装より古く、`results[]` に `is_available` / `is_project_full`、`emptyReason`、`results: null`（追加直後は再スコアリングしない）、ラベルのサーバー解決、`targetState`（追加失敗時のカード差分更新）が未反映で、POST /pipelines のレスポンスも Inertia の実挙動（back リダイレクト）と乖離していた。
> **理由：** 永続ドキュメントを北極星として実装と一致させ続けるため（機能単位 diff では抜けやすい横断挙動＝並行制御・stale 操作・失敗時 UX を明文化する）。

> **【2026-08-20 確定】`results: null` の意味を「サーバーがスコアを取得していない＝一覧を置き換える中身が無い」へ一般化し、エンジン通信失敗（`engine_error`）も `null` を返すことにした（#52）。**
> **背景：** マッチング結果画面に明示的な「再マッチング」導線を追加した。従来どおり失敗時に `results: []` を返すと、ユーザーが再マッチングを押した結果として手元の有効な一覧が空状態に置き換わってしまう（#48 のレビューで「成功直後に engine_error で空状態へ落ちる」ことを避けたのと同じ問題が、手動操作で現実化する）。
> **理由：** 「据え置き」を表す語彙を新設せず、既に定義済みの `results: null` に寄せることで、フロントの同期ロジック（`results !== null` のときだけ差し替える）を1箇所のまま保てる。初回ロードで失敗した場合は据え置く一覧が無く、`emptyReason='engine_error'` により従来どおり空状態が表示される。

---

## 設計上の前提

### マッチングの方向と件数

| 項目 | 仕様 | 根拠 |
|---|---|---|
| 起動方向 | 人材から案件へのマッチングのみ。案件からの逆引きマッチングは不要 | WF_09確定 |
| 表示件数 | 上位5件固定。Pythonエンジンが計算後に上位5件のみ返却 | QA #33 / #50確定 |
| スコアリング | AIによる総合判定（0〜100点満点）。ランク：A≥80 / B65〜79 / C50〜64 / D≤49 | WF_09 v2.0確定 |
| AI処理タイミング | 同期処理（マッチング実行リクエストに対してレスポンスとしてスコアを返す） | QA #47確定 |
| スコアDB保存 | マッチング結果自体はDBに保存しない（オンデマンド計算）。パイプライン追加時のみスナップショットとして `pipelines` テーブルに保存 | QA #45確定 |

### パイプライン追加ルール

| 項目 | 仕様 | 根拠 |
|---|---|---|
| 追加上限 | 1案件あたり上位5件（マッチング表示件数と同じ） | QA #50確定 |
| 初期ステータス | `proposed`（上位提案）固定 | QA #49確定 |
| 重複追加防止 | `(engineer_id, project_id)` にUNIQUE制約。重複時は422を返す | DB設計書 §5 |
| 保存するスナップショット | match_score / match_rank / ai_score_reason / ai_comment / ai_missing | DB設計書 §4 pipelines |

### ドロワーの実装方針

WF_09は「一覧＋右ドロワー方式」を採用する。カード1枚クリックでドロワーが開き、AIスコア算出理由・AI推薦理由・不足条件を表示する。

ドロワー表示に必要なAIテキスト（`ai_score_reason` / `ai_comment` / `ai_missing`）は `GET /engineers/{id}/matching` の `results[]` Props にあらかじめ含めて返す。ドロワー専用のGETエンドポイントは設けない。

理由：マッチング結果はDBに保存せずPythonエンジンがオンデマンドで生成するため（QA #45確定）、ドロワー開封時に再度Pythonエンジンを呼び出す設計はパフォーマンス上のデメリットが大きい。上位5件固定のため一覧PropsにAIテキストを含めてもデータ量は許容範囲内。

---

## 1. APIエンドポイント一覧

> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` の前提セクションに準じる。

### エンドポイント一覧表

| # | メソッド | URL | Controller#Action | アクセス可能ロール | 対応WF |
|---|---|---|---|---|---|
| 1 | GET | /engineers/{id}/matching | MatchingController@show | 管理者 / 一般営業 | WF_09（マッチング結果一覧・ドロワー） |
| 2 | POST | /pipelines | PipelineController@store | 管理者 / 一般営業 | WF_09（パイプライン追加ボタン） |

> **URL設計の意図**
> マッチングは「特定の人材に対するマッチング結果を表示する」という GET 操作のため、`GET /engineers/{id}/matching` とネストしたURLで表現する。
> パイプライン追加は進捗管理設計書と同じ `POST /pipelines` を使用する（マッチング画面専用エンドポイントは設けない）。  
> **ドロワー専用エンドポイントは設けない**。カード一覧とドロワー表示に必要な情報をすべて `GET /engineers/{id}/matching` の Props に含めて返す（設計上の前提「ドロワーの実装方針」参照）。  

---

### GET /engineers/{id}/matching　クエリパラメータ（#1）

> マッチング結果は上位5件固定のためページネーション・ソートパラメータは不要。
> Pythonエンジンへのリクエストパラメータはサーバー側で `engineers/{id}` のデータから組み立てるため、フロントからのクエリパラメータは不要。

クエリパラメータなし。

---

### GET /engineers/{id}/matching　Props（#1）

```jsonc
{
  // 対象人材のサマリー情報（WF_09ページヘッダー表示用）
  "engineer": {
    "id": "int",
    "name": "string",                          // 氏名
    "age": "int",                              // 年齢（birth_date から算出。生成ルールは人材一覧Propsと同じ）
    "status": "string",                        // proposable | interviewing | not_proposable
    "nearest_station": "string",               // 最寄駅
    "nearest_line": "string",                  // 路線名
    "available_from": "date(YYYY-MM-DD)",
    "available_label": "string",               // 表示用ラベル（生成ルールは人材一覧Propsと同じ）
    "desired_rate": "int",                     // 希望単価月額（万円）
    // 勤務形態：選択中のもののみ返す（人材一覧Propsと同じ方針）
    "work_styles": [
      { "key": "string", "name": "string" }   // onsite | hybrid | remote
    ],
    // スキル一覧（サマリー表示用。detailは含めない）
    "skills": [
      { "label": "string" }
    ],
    // 工程経験（6固定。人材一覧Propsと同じ構造）
    "phases": [
      {
        "key": "string",                       // proc_requirements など
        "name": "string",
        "has_experience": "bool"
      }
    ]
  },

  // マッチング結果（Pythonエンジンが返した上位5件固定）。カード・ドロワー表示に必要な情報を全て含める。
  // ★ null＝「サーバーはスコアを取得していない＝一覧を置き換える中身が無い」。フロントは既存表示を
  //   preserveState で据え置く。該当するのは次の2ケースで、それ以外（0件含む）は必ず配列。
  //   (1) パイプライン追加直後の back：意図的に再スコアリングしない（#4 / 楽観的更新）。追加カードのみ更新する
  //   (2) エンジン通信失敗：スコアを1件も得られていない（#52）。再マッチングで手元の一覧を失わないため。
  //       失敗は flash.error のトーストで伝える。初回ロードでは据え置く一覧が無く emptyReason で空状態を出す
  "results": [
    {
      // --- カード表示用スコア情報 ---
      "match_score": "int",                    // AIスコア（0〜100）
      "match_rank": "string",                  // A | B | C | D

      // --- ドロワー表示用AIテキスト（null許容） ---
      // カードクリックでドロワーが開く（WF_09）。再度Pythonエンジンを呼ばないため一覧Propsに含める。
      "ai_score_reason": "string|null",        // AIスコア算出理由
      "ai_comment": "string|null",             // AI推薦理由
      "ai_missing": "string|null",             // 不足条件（不足なしは null）

      // --- 案件情報（カード・ドロワー共通表示用。ENUM はサーバーで表示ラベルに解決して返す） ---
      "project": {
        "id": "int",
        "name": "string",                      // 案件名
        "client_name": "string|null",          // 顧客名
        "commercial_flow_label": "string|null",// 商流ラベル（サーバー解決。未設定は null）
        "headcount": "int|null",               // 募集人数
        "rate_min": "int|null",                // 単価下限（万円）
        "rate_max": "int|null",                // 単価上限（万円）
        "rate_note": "string|null",            // 単価備考（"スキル見合い"等）
        "work_style_label": "string|null",     // 勤務形態ラベル（サーバー解決。未設定は null）
        "status_label": "string",              // 掲載状態ラベル（募集中 / 終了 / ペンディング）
        "start_date": "date(YYYY-MM-DD)|null",
        "start_label": "string",               // 表示用ラベル（生成ルールは案件一覧Propsと同じ）
        "required_skills": [ { "label": "string" } ], // 必須（skill_type=required）。detail は含めない
        "preferred_skills": [ { "label": "string" } ],// 尚可（skill_type=preferred）
        "phases": [ { "key": "string", "name": "string", "is_target": "bool" } ] // 対象工程（6固定）
      },

      // --- パイプライン追加可否（カード表示・追加ボタンの活性制御） ---
      "is_in_pipeline": "bool",                 // 追加済みか（true→「追加済み」表示・ボタン非活性）
      "is_available": "bool",                   // 募集中(open)か（false=掲載停止 closed/pending→追加無効表示）
      "is_project_full": "bool"                 // 進行中5件到達か（true→「上限到達」表示・追加無効）
    }
  ],

  // 結果0件のときの理由。空状態の文言・アイコンを出し分ける（結果ありのときは null）。
  //  no_match     : 候補案件なし / スコア0件
  //  engine_error : エンジン通信失敗（flash.error のトーストも併発）
  //  unavailable  : マッチはあったが対象案件が全てハード削除で全滅（掲載停止は残して無効表示するため非該当）
  "emptyReason": "string|null",

  // パイプライン追加「失敗」の back でのみ非 null（成功時・通常ロードは null）。
  // 試行した案件1件の最新状態を返し、フロントが該当カードのフラグ（is_in_pipeline / is_available /
  // is_project_full / status_label）を差分更新して追加ボタンを無効化する（★エンジンは再実行しない）。
  //   exists=false … ハード削除済み＝カードを一覧から除去（他フィールドは含めない）
  //   exists=true  … 以下のフラグ・ラベルを同梱
  "targetState": {
    "project_id": "int",
    "exists": "bool",
    "is_in_pipeline": "bool",
    "is_available": "bool",
    "is_project_full": "bool",
    "status_label": "string"
  }
}
```

> **実装注意（Pythonエンジン連携）**：Controller はエンジニア情報をDBから取得した後、PythonエンジンにAIマッチングリクエストを送信し、スコア・ランク・AIテキスト付きの上位5件を受け取る。その結果と案件情報（DBから取得）を合わせてPropsを組み立てる。  
> **実装注意（追加可否フラグ）**：`is_in_pipeline` は `pipelines` を `(engineer_id, project_id)` で検索して既存有無を、`is_available` は案件 `status==='open'` を、`is_project_full` は同一案件の**進行中（アクティブ）**パイプライン件数が上限（5件）以上かをセットする。いずれも `whereIn` 一括取得でN+1を防ぐこと。掲載停止（closed/pending）・上限到達の案件も一覧から消さず、無効表示（追加ボタン非活性）で残す（ユーザーが注視するカードを黙って消さない）。ハード削除された案件のみ突合で除外する。  
> **実装注意（`results: null` / 楽観的更新）**：パイプライン追加（成功・失敗とも）後の back では、戻り先 `show` は AI エンジンを再実行せず `results=null` を返す（`preserve_matching_results` セッションフラグを1回限り pull）。再スコアリングによる並び替わり・AI 再実行コスト・成功直後の空状態化を防ぐため（#4）。フロントは `preserveState` で既存表示を保持し、成功は当該カードを楽観更新、失敗は `targetState` で当該カードを差分更新する。  
> **実装注意（`results: null` / エンジン通信失敗）**：上流障害（400/500/504・接続不可・不正応答）でも `results=null` ＋ `emptyReason='engine_error'` ＋ `flash.error` を返す（#52）。`[]` は「スコアリングは成立したが0件」の意味に限定し、「スコアを取得できていない」と区別する。これにより再マッチングの失敗で手元の一覧が消えず、初回ロードの失敗では（据え置く一覧が無いため）従来どおり `engine_error` の空状態が表示される。  
> **実装注意（再マッチング導線）**：マッチング結果画面ヘッダーの「再マッチング」は、専用エンドポイントもフラグも持たず**この GET を素で叩き直す**（Inertia の `router.reload()`）。`preserve_matching_results` が無い GET は常にエンジンを実行するという既存の意味づけをそのまま使う。`reload()` は `preserveState` を強制するため、`results=null` による据え置きが成立する。実行中は `AiLoadingOverlay` を表示し、ボタンを無効化して二重実行を防ぐ。  
> **実装注意（提案不可ガードの戻り先）**：`not_proposable` の人材は `show` の入口で弾くが、再マッチングは同一 URL への GET のため `back()` の戻り先がこの画面自身になり得る（＝同じガードで再び弾かれ、リダイレクトが自己ループする）。戻り先が現在 URL のときは人材詳細（`engineers.show`）へ振り替えること。  
> **実装注意（`targetState`）**：追加失敗（掲載停止/削除/重複/上限）の back でのみ、試行した案件1件の最新状態を返す。フロントはエンジン非実行のまま該当カードのフラグ・ラベルを更新して追加ボタンを無効化し、「古い addable 表示のまま再度押せてしまう」ことを防ぐ。  
> **実装注意（TEXT除外不適用）**：`ai_score_reason` / `ai_comment` / `ai_missing` はTEXTカラム相当だが、マッチング結果はDBから取得するのではなくPythonエンジンから都度受け取る値のため、通常のTEXT除外ルールは適用しない。上位5件固定のためデータ量も許容範囲内。  
> **実装注意（同期処理）**：AI処理は同期処理（QA #47確定）のため、タイムアウト設計（応答目標：詳細2秒以内 QA #27確定）に注意すること。レスポンスが遅延する場合はローディング表示をフロントで実装する。  
> **実装注意（案件TEXT除外）**：案件の `description` / `work_env` / `remarks` はカード・ドロワーいずれの表示対象でもないため取得しないこと。  

---

### POST /pipelines　送信データ（#2）

> マッチング画面のドロワー内「＋ パイプラインに追加」ボタン押下時に送信する。
> 進捗管理設計書と同じ `POST /pipelines` エンドポイントを使用する。

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| engineer_id | int | ✓ | 対象人材ID。画面表示中の人材IDを固定でセット |
| project_id | int | ✓ | 追加対象の案件ID。ドロワーを開いたカードの案件IDをセット |
| match_score | int | ✓ | AIスコア（0〜100）。マッチング結果のスナップショット |
| match_rank | string | ✓ | A / B / C / D。マッチング結果のスナップショット |
| ai_score_reason | string | 任意 | AIスコア算出理由テキスト。スナップショット |
| ai_comment | string | 任意 | AI推薦理由テキスト。スナップショット |
| ai_missing | string | 任意 | 不足条件テキスト。スナップショット |

> **スナップショットについて**：`match_score` 等はマッチング実行時点の値をフロントから送信する。サーバー側で再計算はしない（QA #45確定）。フロントは `results[]` の各フィールド値をそのまま送信すればよい。  
> **`status` について**：パイプライン初期ステータスは `proposed` 固定のためフロントから送信しない。Controller側でハードコードする（QA #49確定）。  
> **`user_id` について**：担当営業は `engineers.main_user_id` を参照するため送信不要（QA #83確定）。  

#### レスポンス

> Inertia のため成功・失敗とも matching 画面へ **back リダイレクト**する（REST の 201/422 ボディは返さない）。いずれの back でも戻り先 `show` は AI エンジンを再実行しない（`preserve_matching_results`）。失敗は原則 `errors.project_id`（field エラー）で返し、`useForm` の `onError` を発火させて誤「追加済み」（`onSuccess` 誤発火）を防ぐ。カードの見た目・追加ボタンの無効化は `targetState`（当該案件1件の最新状態）で差分更新する。

| 条件 | 動作 |
|---|---|
| 成功 | back＋`flash.success`。フロントは当該カードの `is_in_pipeline` を `true` に楽観更新し、ドロワーを閉じる |
| 重複追加（すでに登録済み） | `errors.project_id`「この人材はすでにこの案件のパイプラインに追加されています。」＋ `targetState`(is_in_pipeline=true)。ドロワーは開いたまま、カードを「追加済み」に更新しボタン非活性 |
| 上限超過（進行中5件到達） | `errors.project_id`「この案件のパイプラインはすでに上限（5件）に達しています。」＋ `targetState`(is_project_full=true)。カードを「上限到達」に更新しボタン非活性 |
| 掲載停止（closed / pending） | `errors.project_id`「選択した案件は現在募集していないため、パイプラインに追加できませんでした。」＋ `targetState`(is_available=false, status_label)。カードを「掲載停止」に更新しボタン非活性 |
| 案件がハード削除済み | `errors.project_id`＋`flash.error`（トースト）＋ `targetState`(exists=false)。カードを一覧から除去しドロワーを閉じる（ドロワーが閉じ field エラーが不可視になるためトーストでも通知） |
| 対象人材が存在しない | 人材一覧（`engineers.index`）へ back＋`flash.error`（削除済み人材の matching へ戻ると route model binding が 404 になるため誘導） |

---

---

## 2. 他機能との関連

### 人材詳細（WF_05）との連携

WF_05（人材詳細画面）からマッチング画面へ遷移する動線がある場合、`GET /engineers/{id}/matching` にそのまま遷移する。人材IDはURLパラメータで引き継ぐ。

### 進捗管理（WF_10）との連携

`POST /pipelines` で追加されたパイプラインは、進捗管理画面（WF_10）の「進行中タブ」に初期ステータス `proposed`（上位提案）のカードとして表示される。追加時のスナップショット（match_score / match_rank / ai_score_reason / ai_comment / ai_missing）はドロワー内で参照できる。

---

## 3. 人材管理・案件管理との主な差分

| 項目 | 人材・案件管理 | マッチング |
|---|---|---|
| データ取得元 | DBのみ | DB（人材・案件情報）+ Pythonエンジン（スコア・AIテキスト） |
| 更新操作 | PUT（全フィールド） | なし（マッチング結果自体は保存しない） |
| バリデーションの複雑さ | form_field_settings による動的制御あり | 固定ルールのみ |
| ページネーション | あり | なし（上位5件固定） |
| エラー制御 | フォームバリデーション中心 | 重複・上限チェックがメイン |
| ドロワーの実装 | 詳細画面への画面遷移 or 別途GET | 一覧Propsにドロワー用データを含める（追加エンドポイントなし） |
| TEXTカラムの扱い | 一覧では除外・詳細GETで取得 | Pythonエンジン返却値のためTEXT除外ルール適用外。一覧Propsに含める |

---

## 4. 未確定事項（TBD）

| # | 項目 | QA# | 状態 | 理由 |
|---|------|-----|------|------|
| 1 | Pythonエンジンとの通信仕様（API形式・タイムアウト値） | - | **確定（2026-07-27）** | HTTP 同期呼び出しで実装（`POST {MATCHING_ENGINE_URL}/api/v1/matching/calculate`・body は `engineer_id` のみ）。接続先・タイムアウトは `config/services.php`（`matching_engine.url` / `timeout`）＋ env に外部化し、実↔ローカル代替エンジンを URL 差し替えのみで切替可。4xx/5xx・不正 200 応答は例外種別へマップ（下表 #2 参照）。認証方式は社内ネットワーク前提で当面なし（必要時に追加） |
| 2 | Pythonエンジンがタイムアウト・エラーを返した場合のフォールバック | QA #47 | **確定（2026-07-27／2026-08-20 改訂）** | 404（人材なし）→404、候補0件/スコア0件→空状態 `no_match`、上流障害（400/500/504・接続不可・不正応答）→`flash.error` トースト＋`results=null`（既存表示があれば据え置き、無ければ `engine_error` の空状態。2026-08-20 に `[]` から変更・#52）、突合後に案件が全滅→`unavailable` |
| 3 | `ai_score_reason` / `ai_comment` / `ai_missing` の文字数上限 | - | TBD | DBがTEXT型のためDB上限なし。Pythonエンジンの返却値に依存する。バリデーション上の制限値を設けるか確認が必要 |
| 4 | マッチング実行のトリガー（画面遷移時に自動実行 or 「マッチング実行」ボタン押下） | - | **確定（2026-07-27／2026-08-20 追記）** | ページロード時に同期自動実行し結果を表示する（WF_09 準拠）。追加直後の back では再実行しない（`preserve_matching_results`）。明示的な「再マッチング」導線を画面ヘッダーに追加（#52・2026-08-20 確定）。自動再実行は復活させず、ユーザーが押したときだけ回す明示的オプトインとする |