# APIエンドポイント一覧（ルーティング・Props定義書）

> 技術方針：Laravel + Inertia.js + React  
> ルーティング定義：`routes/web.php`  
> 認証方式：Laravel Breeze（セッション認証）  
> 最終更新：2026-05-22

---

## 前提

- **認証**：Laravel Breezeを使用。`/login` / `/logout` はBreeze自動生成のため本一覧に記載しない。`/register` / `/forgot-password` / `/reset-password` は無効化する
- **認可（ロール別制御）**：ロールは **管理者 / 一般営業** の2階層（QA #17確定）。詳細な操作権限は権限・ロール設計書に記載する
- **バリデーション**：各フィールドの詳細ルールは「バリデーション・エラー表示設計書」を参照すること
- **必須/任意の制御**：システム固定必須（DBレベルでNOT NULL）は `name` / `name_kana` のみ。それ以外のフィールドはDBレベルでNULL許容であり、必須/任意の制御は `form_field_settings` テーブルをアプリ層で参照して行う（DB設計書 §1-2 / QA #65確定）
- **SharedProps**：全ページに `HandleInertiaRequests` ミドルウェアで以下を共有する。詳細は権限・ロール設計書を参照すること
  - `auth.user`：ログインユーザー情報（id / name / role）
  - `flash.success`：成功フラッシュメッセージ（`string | null`）
  - `flash.error`：エラーフラッシュメッセージ（`string | null`）
  - ※ `fieldSettings` は登録・編集画面の Props に個別に含める。SharedProps には含めない。
- **CSRFトークン**：Inertia.js + Laravel Breeze の組み合わせにより、POST / PUT / DELETE リクエスト時のCSRFトークンは自動付与される。フロントエンド側での明示的な送信処理は不要

---

## 凡例

| 記号 | 意味 |
|------|------|
| クエリパラメータ | GETリクエスト時にURLに付与してサーバーへ送るデータ（検索条件・ページング・ソート情報） |
| Props | GETリクエスト時にControllerからReactコンポーネントへ渡すデータ。**本書ではJSONツリー形式（jsonc）で表記する** |
| 送信データ | POST/PUTリクエスト時にフロントから送るデータ |
| ルート定義順 | Laravelのルーティング定義順を表す。静的ルート（create等）は動的ルート（{id}）より前に定義する |

### Propsのツリー記法について

Propsの構造はJSONツリー形式で記述する。型は値の位置に文字列で示し、説明はインラインコメントで付与する。

| 型表記 | 意味 |
|---|---|
| `"int"` | 整数 |
| `"string"` | 文字列 |
| `"bool"` | 真偽値 |
| `"date(YYYY-MM-DD)"` | 日付文字列 |
| `"datetime(ISO8601)"` | 日時文字列 |
| `[{ ... }]` | オブジェクトの配列 |

> **Propsのnull許容**：システム固定必須（`name` / `name_kana`）以外のフィールドはnullを返す場合がある。各フィールドの型表記では `| null` を省略する。

---

## 詳細ブロックの構成ルール

```
### GET /xxx
クエリパラメータ → 表形式（パラメータ名 / 型 / 必須 / 説明 / 備考）
Props           → JSONツリー形式（jsonc）

### POST・PUT /xxx
送信データ       → 表形式（フィールド名 / 型 / 必須 / 備考）
```

### 共通HTTPレスポンス

全エンドポイントで共通のレスポンスパターン。エンドポイント固有の挙動がある場合は各セクションに追記する。

| ステータス | 発生ケース |
|---|---|
| `200 OK` | GET 正常取得・PUT 正常更新 |
| `201 Created` | POST 正常作成 |
| `302 Found` | POST・PUT・DELETE 後の Inertia リダイレクト |
| `403 Forbidden` | ロール権限不足（例：一般営業が DELETE を呼んだ場合） |
| `404 Not Found` | 存在しない ID を指定 |
| `422 Unprocessable Entity` | バリデーションエラー。詳細形式はバリデーション・エラー表示設計書を参照 |

---

## 1. ダッシュボード

> ※ 作成予定

---

## 2. 人材管理（Engineer）

| # | メソッド | URL | Controller#Action | アクセス可能ロール | 対応WF |
|---|---|---|---|---|---|
| 1 | GET | /engineers | EngineerController@index | 管理者 / 一般営業 | WF_03 |
| 2 | GET | /engineers/create | EngineerController@create | 管理者 / 一般営業 | WF_04 |
| 3 | POST | /engineers | EngineerController@store | 管理者 / 一般営業 | WF_04 |
| 4 | GET | /engineers/{id} | EngineerController@show | 管理者 / 一般営業 | WF_05 |
| 5 | GET | /engineers/{id}/edit | EngineerController@edit | 管理者 / 一般営業 | WF_04 |
| 6 | PUT | /engineers/{id} | EngineerController@update | 管理者 / 一般営業 | WF_04 |
| 7 | DELETE | /engineers/{id} | EngineerController@destroy | **管理者のみ** | WF_05 |

> **削除ルール（QA #37確定）**：管理者は物理削除（`DELETE /engineers/{id}`）。一般営業がステータスを変更したい場合は `PUT /engineers/{id}` で `status: "not_proposable"` を送信すること。

---

### GET /engineers　クエリパラメータ（#1）

| パラメータ名 | 型 | 必須 | 説明 | 備考 |
|------------|---|:----:|------|------|
| status | string[] | 任意 | ステータス配列 | proposable / interviewing / not_proposable |
| work_styles | string[] | 任意 | 勤務形態キー配列 | onsite / hybrid / remote |
| phases | string[] | 任意 | 工程経験キー配列 | proc_requirements / proc_basic_design / proc_detail_design / proc_development / proc_testing / proc_maintenance |
| keyword | string | 任意 | フリーワード検索 | 氏名・スキルラベル・アピールポイントに対して部分一致。検索対象項目はTBD |
| sort | string | 任意 | ソート項目 | デフォルト：created_at |
| order | string | 任意 | 並び順 | asc / desc（デフォルト：desc） |
| page | int | 任意 | ページ番号 | デフォルト：1 |
| per_page | int | 任意 | 1ページあたり件数 | デフォルト：20・上限はTBD |

> 異なる項目間はAND条件。例：`(提案可) AND (フルリモート)`  
> スキル検索は `keyword` のフリーワード検索（スキルラベル前方一致）で対応する。スキルマスタ廃止のため `skill_ids[]` は廃止（WF_03 v3.0確定）

---

### GET /engineers　Props（#1）

```jsonc
{
  "engineers": {
    "data": [
      {
        "id": "int",
        "name": "string",                     // 氏名
        "age": "int",                         // 年齢
                                              // birth_date から算出
                                              // 算出基準日はサーバーの現在日（リクエスト時点）
        "nearest_station": "string",          // 最寄駅（フリーテキスト / QA #59確定）
        "nearest_line": "string",             // 路線名（フリーテキスト）
        "status": "string",                   // proposable | interviewing | not_proposable
        "available_from": "date(YYYY-MM-DD)", // 稼働可能日
        "available_label": "string",          // 表示用ラベル
                                              // available_from が null → "未定"
                                              // available_from に日付あり → "YYYY/MM/DD〜"
                                              // Controller内で生成する
                                              // ※ 過去日付の扱い（そのまま表示 or "即日〜"）はTBD
        "users": {
          "main": { "id": "int", "name": "string" },
          "sub":  { "id": "int", "name": "string" } // null許容：未設定の場合null
        },
        "skills": [
          // ※ detail（TEXTカラム）は一覧では取得しない（DB設計書 §1-9）
          { "label": "string" }               // スキルラベル（最大15文字）
        ],
        "phases": [
          // DBの proc_* カラム（6固定）からController内で生成する
          {
            "key": "string",                  // proc_requirements など
            "name": "string",                 // 工程名
            "has_experience": "bool"          // 経験有無
          }
        ],
        "work_styles": [
          // DBの work_style_* カラムからController内で生成する
          // 選択中（true）の勤務形態のみ返す。未選択のものは含まない
          {
            "key": "string",                  // onsite | hybrid | remote
            "name": "string"                  // 表示名
          }
        ],
        "updated_at": "datetime(ISO8601)"
      }
    ],
    "meta": {
      "current_page": "int",                  // 現在ページ番号
      "last_page": "int",                     // 最終ページ番号
      "per_page": "int",                      // 1ページあたり件数
      "total": "int",                         // 全件数
      "from": "int",                          // 現在ページの開始位置
      "to": "int"                             // 現在ページの終了位置
    }
  },

  // 現在適用中の検索条件（クエリパラメータをそのまま反映。画面の状態復元に使用）
  "filters": {
    "status": ["string"],                     // ステータス
    "work_styles": ["string"],                // 勤務形態
    "phases": ["string"],                     // 工程経験
    "keyword": "string",                      // フリーワード検索
    "sort": "string",                         // ソート項目
    "order": "string",                        // 並び順
    "per_page": "int",                        // 1ページあたり件数
    "page": "int"                             // ページ番号
  },

  // ログインユーザーの保存検索条件一覧
  "savedSearches": [
    {
      "id": "int",
      "name": "string",                      // 表示名（DB: saved_searches.name）
      "conditions": {
        "status": ["string"],                 // ステータス
        "work_styles": ["string"],            // 勤務形態
        "phases": ["string"],                 // 工程経験
        "keyword": "string",                  // フリーワード検索
        "sort": "string",                     // ソート項目
        "order": "string"                     // 並び順
      }
    }
  ]
}
```

> **実装注意（N+1対策）**：`skills`・`mainUser`・`subUser` を Eager Loading すること（`with(['skills', 'mainUser', 'subUser'])` 相当）  
> **実装注意（TEXT除外）**：`appeal_note` / `ai_summary` / `remarks` はTEXTカラムのため `EngineerListResource` で明示的に除外すること（DB設計書 §1-9）

---

### GET /engineers/create　Props（#2）

```jsonc
{
  // フォームフィールドの必須/任意設定（form_field_settings テーブルの値）
  // フロントはこの値を参照してフィールドの必須バッジ表示・バリデーションを制御する
  // is_required: true = 現在の設定で必須 / false = 任意
  // ※ システム固定必須（name / name_kana）はここに含めない（常にrequired固定のため）
  // ※ キー名は form_field_settings テーブルの field_key カラムと1対1で対応する
  "fieldSettings": {
    "birth_date":          { "is_required": "bool" },
    "nearest_station":     { "is_required": "bool" },
    "nearest_line":        { "is_required": "bool" },
    "available_from":      { "is_required": "bool" },
    "skills":              { "is_required": "bool" },
    "proc_requirements":   { "is_required": "bool" }, // 要件定義経験
    "proc_basic_design":   { "is_required": "bool" }, // 基本設計経験
    "proc_detail_design":  { "is_required": "bool" }, // 詳細設計経験
    "proc_development":    { "is_required": "bool" }, // 開発経験
    "proc_testing":        { "is_required": "bool" }, // テスト経験
    "proc_maintenance":    { "is_required": "bool" }, // 保守運用経験
    "has_negotiation_exp": { "is_required": "bool" },
    "appeal_note":         { "is_required": "bool" },
    "desired_rate":        { "is_required": "bool" },
    "work_styles":         { "is_required": "bool" },
    "remarks":             { "is_required": "bool" },
    "status":              { "is_required": "bool" },
    "main_user_id":        { "is_required": "bool" }
  },

  // ※ スキルはフリーテキスト入力のため skillTags[] は不要（WF_04 v2.0確定）
  "phases": [
    // 固定6件
    { "key": "string", "name": "string" }   // key: proc_requirements など
  ],
  "work_styles": [
    // 固定3件
    { "key": "string", "name": "string" }   // key: onsite | hybrid | remote
  ],
  "statuses": [
    // ENUMの3値（独立したstatusマスタテーブルは存在しない）
    { "value": "string", "label": "string" } // value: proposable | interviewing | not_proposable
  ],
  "users": [
    { "id": "int", "name": "string" }        // 担当営業氏名
  ]
}
```

---

### GET /engineers/{id}　Props（#4）

```jsonc
{
  "engineer": {
    "id": "int",
    "name": "string",                              // 氏名
    "name_kana": "string",                         // カナ
    "age": "int",                                  // 年齢（一覧Propsの age と同じ生成ルール）
    "status": "string",                            // proposable | interviewing | not_proposable
    "nearest_station": "string",                   // 最寄駅（フリーテキスト）
    "nearest_line": "string",                      // 路線名（フリーテキスト）
    "available_from": "date(YYYY-MM-DD)",          // 稼働可能日
    "available_label": "string",                   // 表示用ラベル（一覧Propsの available_label と同じ生成ルール）
    "users": {
      "main": { "id": "int", "name": "string" },
      "sub":  { "id": "int", "name": "string" }   // null許容
    },
    "skills": [
      {
        "label": "string",                        // スキルラベル（最大15文字）
        "detail": "string"                        // 詳細テキスト（最大500文字）
      }
    ],
    "phases": [
      {
        "key": "string",                          // proc_requirements など
        "name": "string",                         // 工程名
        "has_experience": "bool"                  // 経験有無
      }
    ],
    "work_styles": [
      {
        "key": "string",                          // onsite | hybrid | remote
        "name": "string"                          // 表示名
      }
    ],
    "has_negotiation_exp": "bool",                 // 顧客折衝経験
    "appeal_note": "string",                       // アピールポイント
    "desired_rate": "int",                         // 希望単価月額（単位：万円）
    "remarks": "string",                           // 特記事項
    "ai_summary": "string",                        // AI職務要約テキスト（未生成時はnull）
    "ai_summary_generated_at": "datetime(ISO8601)", // 最終生成日時（WF_05「最終生成：YYYY-MM-DD」表示用）
    "updated_at": "datetime(ISO8601)"
  }
}
```

---

### GET /engineers/{id}/edit　Props（#5）

```jsonc
{
  "engineer":    { /* GET /engineers/{id} の engineer と同構造 */ },
  "fieldSettings": { /* GET /engineers/create の fieldSettings と同構造 */ },
  "phases":      [ /* GET /engineers/create の phases と同構造 */ ],
  "work_styles": [ /* GET /engineers/create の work_styles と同構造 */ ],
  "statuses":    [ /* GET /engineers/create の statuses と同構造 */ ],
  "users":       [ /* GET /engineers/create の users と同構造 */ ]
}
```

---

### POST /engineers ・ PUT /engineers/{id}　送信データ（#3 / #6 共通）

> **必須/任意について**：システム固定必須（DBレベルでNOT NULL）は `name` / `name_kana` のみ。その他のフィールドはDBレベルでNULL許容であり、実際の必須/任意制御は `form_field_settings` をアプリ層で参照して行う（DB設計書 §1-2 / QA #65確定）。  
> **PATCHについて**：部分更新（PATCH）は使用しない。ステータスのみ変更する場合も PUT で全フィールドを送信すること。  
> **bool値の変換**：`proc_*` / `has_negotiation_exp` の bool 値はEloquentモデルに `boolean` キャストを定義し、DB側の `TINYINT(1)`（1:true / 0:false）へ変換すること。

> **勤務形態の変換方針：**  
> フロントは選択中の勤務形態キーを string[] で送信する。Controller が以下のようにDBカラムへ変換する。
> - work_styles に "onsite" が含まれる → work_style_onsite = true
> - work_styles に "hybrid" が含まれる → work_style_hybrid = true
> - work_styles に "remote" が含まれる → work_style_remote = true
> - 配列に含まれない値 → 対応カラム = false

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| name | string | ✓ | 氏名（システム固定必須） |
| name_kana | string | ✓ | カナ（システム固定必須） |
| birth_date | date | 任意 | 生年月日（form_field_settings制御） |
| nearest_station | string | 任意 | 最寄駅（フリーテキスト・form_field_settings制御） |
| nearest_line | string | 任意 | 路線名（フリーテキスト・form_field_settings制御） |
| available_from | date | 任意 | 稼働可能日（form_field_settings制御） |
| skills[] | array | 任意 | スキル配列。空配列 [] または省略で全削除。PUT時は送信された配列で全件洗い替え（既存レコードを全削除後に再挿入）。件数の必須制御は form_field_settings に従いアプリ層で行う |
| skills[].label | string | 任意 | スキルラベル（最大15文字） |
| skills[].detail | string | 任意 | スキル詳細（最大500文字） |
| proc_requirements | bool | 任意 | 要件定義経験（true:有・form_field_settings制御） |
| proc_basic_design | bool | 任意 | 基本設計経験（form_field_settings制御） |
| proc_detail_design | bool | 任意 | 詳細設計経験（form_field_settings制御） |
| proc_development | bool | 任意 | 開発経験（form_field_settings制御） |
| proc_testing | bool | 任意 | テスト経験（form_field_settings制御） |
| proc_maintenance | bool | 任意 | 保守運用経験（form_field_settings制御） |
| has_negotiation_exp | bool | 任意 | 顧客折衝経験（true:有・form_field_settings制御） |
| appeal_note | string | 任意 | アピールポイント（form_field_settings制御） |
| desired_rate | int | 任意 | 希望単価月額（単位：万円・form_field_settings制御） |
| work_styles | string[] | 任意 | 勤務形態。選択値を配列で送る（onsite / hybrid / remote）。未選択の場合は空配列 [] または省略。Controller内で work_style_* カラムに変換する（form_field_settings制御） |
| remarks | string | 任意 | 特記事項（form_field_settings制御） |
| status | string | 任意 | ステータス（proposable / interviewing / not_proposable・form_field_settings制御） |
| main_user_id | int | 任意 | 主担当ユーザーID（form_field_settings制御） |
| sub_user_id | int | 任意 | サブ担当ユーザーID（null許容） |

---

### DELETE /engineers/{id}（#7）

#### アクセス可能ロール：管理者のみ

> 一般営業のステータス変更（提案不可化）は `PUT /engineers/{id}` で `status: "not_proposable"` を送信すること。

#### 挙動

| 条件 | 動作 |
|---|---|
| 管理者による実行 | 対象エンジニアを物理削除する。関連する `pipelines` は `ON DELETE CASCADE` により連鎖削除される（DB設計書 §4）。削除前にフロントエンドで「この人材に紐づくパイプライン〇件が同時に削除されます」の確認ダイアログを表示すること |

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | `/engineers` へリダイレクトし SharedProps の `flash.success` を返す |
| 権限不足時 | 前画面へリダイレクトし SharedProps の `flash.error` を返す |
| 対象データなし | 404 を返す |

---

## 3. 案件管理（Project）

> ※ 作成予定

---

## 4. マッチング（Matching）

> ※ 作成予定

---

## 5. 進捗管理（Pipeline）

> ※ 作成予定

---

## 6. 保存検索条件（SavedSearch）

| # | メソッド | URL | Controller#Action | アクセス可能ロール | 対応WF |
|---|---|---|---|---|---|
| 1 | POST | /saved-searches | SavedSearchController@store | 管理者 / 一般営業 | WF_03 / WF_06 |
| 2 | DELETE | /saved-searches/{id} | SavedSearchController@destroy | 管理者 / 一般営業 | WF_03 / WF_06 |

> `user_id` はセッションのログインユーザーから取得する。クライアントから送信しないこと（DB設計書 §4 / QA #81確定）

---

### POST /saved-searches　送信データ（#1）

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| name | string | ✓ | 保存条件の表示名（DB: saved_searches.name） |
| search_type | string | ✓ | 対象種別。画面側で固定値をセット（engineer / project） |
| conditions | object | ✓ | 検索条件 |
| conditions.status | string[] | 任意 | ステータス（proposable / interviewing / not_proposable） |
| conditions.work_styles | string[] | 任意 | 勤務形態 |
| conditions.phases | string[] | 任意 | 工程経験 |
| conditions.keyword | string | 任意 | フリーワード |
| conditions.sort | string | 任意 | ソート項目 |
| conditions.order | string | 任意 | 並び順 |

---

## 7. CSV入出力

> ※ 作成予定

---

## 8. マスタ管理（Master）

> ※ 作成予定

---

## 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
| 1 | 人材一覧フリーワード検索の検索対象項目 | - | スキルラベル前方一致を含む方針だが、氏名以外の対象項目が未確定 |
| 2 | 人材一覧ページの1ページあたり件数上限値 | - | デフォルト20は確定。上限値（推奨：100件）は未決定 |
| 3 | available_label の過去日付の扱い | - | 過去日付をそのまま"YYYY/MM/DD〜"と表示するか"即日〜"に変換するかが未確定 |
