# 人材管理（Engineer）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-05-27  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。  

---

## APIエンドポイント一覧

> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` の前提セクションに準じる。

### エンドポイント一覧表

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
| keyword | string | 任意 | フリーワード検索 | 氏名（部分一致）・スキルラベル（前方一致）を対象とする。アピールポイントは検索対象外（カードに非表示の項目がヒットするとユーザーが理由を確認できず混乱を招くため） |
| sort | string | 任意 | ソート項目 | デフォルト：created_at 許容される組み合わせはsortOptions(#1 Props)の4パターンに限る |
| order | string | 任意 | 並び順 | asc / desc（デフォルト：desc）許容される組み合わせはsortOptions(#1 Props)の4パターンに限る |
| page | int | 任意 | ページ番号 | デフォルト：1 |
| per_page | int | 任意 | 1ページあたり件数 | デフォルト：20・上限：100（超過時は100にクランプ） |

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
                                              // 過去日付でも特別扱いせず、そのまま"YYYY/MM/DD〜"と表示する
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

  // ログインユーザーの保存済み検索条件一覧
  // エンドポイント定義は 07_検索条件保存_APIエンドポイント一覧.md を参照すること
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
  ],

  // ソート選択肢（DB設計書 §8 準拠・sort × order の組み合わせが決まっている4パターン固定）
  // フロントはこの配列をそのまま SortSelect の options として使用する
  "sortOptions": [
    { "sort": "string", "order": "string", "label": "string" }
    // 例：
    // { "sort": "created_at",     "order": "desc", "label": "登録日順（新しい順）" }
    // { "sort": "created_at",     "order": "asc",  "label": "登録日順（古い順）" }
    // { "sort": "updated_at",     "order": "desc", "label": "更新日順（新しい順）" }
    // { "sort": "available_from", "order": "asc",  "label": "提案可能タイミング順" }
  ]
}
```

> **実装注意（N+1対策）**：`skills`・`mainUser`・`subUser` を Eager Loading すること（`with(['skills', 'mainUser', 'subUser'])` 相当）  
> **実装注意（TEXT除外）**：`appeal_note` / `ai_summary` / `remarks` はTEXTカラムのため `EngineerListResource` で明示的に除外すること（DB設計書 §1-9）  
> **実装注意（ソート制御）**：sort・order は独立した値としてではなく、`sortOptions` の4組の組み合わせ単位でバリデーションすること（DB設計書 §8 準拠）。フロントの選択肢にない組み合わせ（例：updated_at＋asc）をURL経由で許可しないよう統一する  
> **実装注意（per_page上限）**：100件を超える指定はサーバー側で100にクランプすること
---

### GET /engineers/create　Props（#2）

```jsonc
{
  // フォームフィールドの必須/任意設定（form_field_settings テーブルの値）
  // フロントはこの値を参照してフィールドの必須バッジ表示・バリデーションを制御する
  // is_required: true = 現在の設定で必須 / false = 任意
  // ※ システム固定必須（name / name_kana / status / main_user_id）はここに含めない（常にrequired固定のため）
  // ※ キー名は form_field_settings テーブルの field_key カラムと1対1で対応する
  "fieldSettings": {
    "birth_date":          { "is_required": "bool" },
    "nearest_station":     { "is_required": "bool" },
    "nearest_line":        { "is_required": "bool" },
    "available_from":      { "is_required": "bool" },
    "skills":              { "is_required": "bool" },
    // 工程経験6項目（proc_requirements 〜 proc_maintenance）はまとめて1設定として管理する。
    // form_field_settings の field_key = "proc_experience" で1レコード管理。
    // WF_12のフォーム設定タブでも「経験工程」として1つのトグルで管理する。
    // is_required: true の場合、フロントは工程経験チェックボックスグループ全体を必須として扱う。
    "proc_experience":     { "is_required": "bool" },
    "has_negotiation_exp": { "is_required": "bool" },
    "appeal_note":         { "is_required": "bool" },
    "desired_rate":        { "is_required": "bool" },
    "work_styles":         { "is_required": "bool" },
    "remarks":             { "is_required": "bool" }
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

> **必須/任意について**：システム固定必須（DBレベルでNOT NULL）は `name` / `name_kana` / `status` / `main_user_id` のみ。その他のフィールドはDBレベルでNULL許容であり、実際の必須/任意制御は `form_field_settings` をアプリ層で参照して行う（DB設計書 §1-2 / QA #65確定）。  
> **PATCHについて**：部分更新（PATCH）は使用しない。ステータスのみ変更する場合も PUT で全フィールドを送信すること。  
> **bool値の変換**：`proc_requirements` 〜 `proc_maintenance` / `has_negotiation_exp` の bool 値はEloquentモデルに `boolean` キャストを定義し、DB側の `TINYINT(1)`（1:true / 0:false）へ変換すること。

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
| proc_requirements | bool | 任意 | 要件定義経験（true:有）。proc_experience の is_required 設定で制御 |
| proc_basic_design | bool | 任意 | 基本設計経験（true:有）。proc_experience の is_required 設定で制御 |
| proc_detail_design | bool | 任意 | 詳細設計経験（true:有）。proc_experience の is_required 設定で制御 |
| proc_development | bool | 任意 | 開発経験（true:有）。proc_experience の is_required 設定で制御 |
| proc_testing | bool | 任意 | テスト経験（true:有）。proc_experience の is_required 設定で制御 |
| proc_maintenance | bool | 任意 | 保守運用経験（true:有）。proc_experience の is_required 設定で制御 |
| has_negotiation_exp | bool | 任意 | 顧客折衝経験（true:有・form_field_settings制御） |
| appeal_note | string | 任意 | アピールポイント（form_field_settings制御・最大4000文字） |
| desired_rate | int | 任意 | 希望単価月額（単位：万円・form_field_settings制御） |
| work_styles | string[] | 任意 | 勤務形態。選択値を配列で送る（onsite / hybrid / remote）。未選択の場合は空配列 [] または省略。Controller内で work_style_* カラムに変換する（form_field_settings制御） |
| remarks | string | 任意 | 特記事項（form_field_settings制御・最大1000文字） |
| status | string | ✓ | ステータス（システム固定必須・proposable / interviewing / not_proposable） |
| main_user_id | int | ✓ | 主担当ユーザーID（システム固定必須） |
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

## 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
