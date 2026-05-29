# マッチング（Matching）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-05-27  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。

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

  // マッチング結果（Pythonエンジンが返した上位5件固定）
  // カード表示・ドロワー表示に必要な情報をすべて含める
  // ドロワー専用GETエンドポイントは設けないため、AIテキストも一覧Propsに含める
  "results": [
    {
      // --- カード表示用スコア情報 ---
      "match_score": "int",                    // AIスコア（0〜100）
      "match_rank": "string",                  // A | B | C | D

      // --- ドロワー表示用AIテキスト ---
      // カードクリック時にドロワーが開く（WF_09確定）
      // 再度Pythonエンジンを呼び出すコストを避けるため一覧Propsに含める
      "ai_score_reason": "string",             // AIスコア算出理由テキスト
      "ai_comment": "string",                  // AI推薦理由テキスト
      "ai_missing": "string",                  // 不足条件テキスト（null許容：不足条件なしの場合）

      // --- 案件情報（カード・ドロワー共通表示用） ---
      "project": {
        "id": "int",
        "name": "string",                      // 案件名
        "client_name": "string",               // 顧客名
        "commercial_flow": "string",           // prime | secondary | tertiary | other
        "headcount": "int",                    // 募集人数
        "rate_min": "int",                     // 単価下限（万円）
        "rate_max": "int",                     // 単価上限（万円）
        "rate_note": "string",                 // 単価備考（"スキル見合い"等）
        "work_style": "string",                // onsite | hybrid | remote（単一値）
        "start_date": "date(YYYY-MM-DD)",
        "start_label": "string",               // 表示用ラベル（生成ルールは案件一覧Propsと同じ）
        // 必須スキル（skill_type = required）
        "required_skills": [
          { "label": "string" }                // detailは表示対象外のため含めない
        ],
        // 尚可スキル（skill_type = preferred）
        "preferred_skills": [
          { "label": "string" }
        ],
        // 対象工程（6固定）
        "phases": [
          {
            "key": "string",
            "name": "string",
            "is_target": "bool"
          }
        ]
      },

      // --- パイプライン追加状態 ---
      // すでにパイプラインに追加済みかどうか（追加ボタンの活性/非活性制御に使用）
      // ドロワー内の「＋ パイプラインに追加」ボタンの表示制御にも使用する
      "is_in_pipeline": "bool"
    }
  ]
}
```

> **実装注意（Pythonエンジン連携）**：Controller はエンジニア情報をDBから取得した後、PythonエンジンにAIマッチングリクエストを送信し、スコア・ランク・AIテキスト付きの上位5件を受け取る。その結果と案件情報（DBから取得）を合わせてPropsを組み立てる。  
> **実装注意（`is_in_pipeline`）**：`pipelines` テーブルを `(engineer_id, project_id)` で検索し、既存レコードの有無を確認してセットする。Eager Loading または `whereIn` でN+1を防ぐこと。  
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

| 条件 | 動作 |
|---|---|
| 成功時 | 201 Created。フロント側で当該カードの `is_in_pipeline` を `true` に更新し、ドロワー内の追加ボタンを非活性にする |
| 重複追加（すでにパイプライン登録済み） | 422。エラーメッセージ：「この人材はすでにこの案件のパイプラインに追加されています」 |
| 上限超過（1案件あたり5件超） | 422。エラーメッセージ：「この案件のパイプラインはすでに上限（5件）に達しています」 |
| 対象エンジニア / 案件が存在しない | 404 |

---

---

## 3. 他機能との関連

### 人材詳細（WF_05）との連携

WF_05（人材詳細画面）からマッチング画面へ遷移する動線がある場合、`GET /engineers/{id}/matching` にそのまま遷移する。人材IDはURLパラメータで引き継ぐ。

### 進捗管理（WF_10）との連携

`POST /pipelines` で追加されたパイプラインは、進捗管理画面（WF_10）の「進行中タブ」に初期ステータス `proposed`（上位提案）のカードとして表示される。追加時のスナップショット（match_score / match_rank / ai_score_reason / ai_comment / ai_missing）はドロワー内で参照できる。

---

## 4. 人材管理・案件管理との主な差分

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

## 5. 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
| 1 | Pythonエンジンとの通信仕様（API形式・認証方法・タイムアウト値） | - | LaravelからPythonエンジンへのリクエスト方法（HTTP / キュー等）・エンドポイントURL・認証方式が未定義。Pythonチームとの調整が必要 |
| 2 | Pythonエンジンがタイムアウト・エラーを返した場合のフォールバック | QA #47 | 同期処理確定（QA #47）だが、エラー時にユーザーへ何を表示するかが未定義 |
| 3 | `ai_score_reason` / `ai_comment` / `ai_missing` の文字数上限 | - | DBがTEXT型のためDB上限なし。Pythonエンジンの返却値に依存する。バリデーション上の制限値を設けるか確認が必要 |
| 4 | マッチング実行のトリガー（画面遷移時に自動実行 or 「マッチング実行」ボタン押下） | - | WF_09ではページを開いた時点で結果が表示されているが、ボタン押下トリガーかページロードトリガーかが設計書に明記されていない。UX・パフォーマンスの観点から確認が必要 |