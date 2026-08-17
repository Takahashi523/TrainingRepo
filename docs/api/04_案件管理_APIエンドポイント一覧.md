# 案件管理（Project）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-06-10  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。

---

## 1. APIエンドポイント一覧

> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` の前提セクションに準じる。

### エンドポイント一覧表

| # | メソッド | URL | Controller#Action | アクセス可能ロール | 対応WF |
|---|---|---|---|---|---|
| 1 | GET | /projects | ProjectController@index | 管理者 / 一般営業 | WF_06 |
| 2 | GET | /projects/create | ProjectController@create | 管理者 / 一般営業 | WF_07 |
| 3 | POST | /projects | ProjectController@store | 管理者 / 一般営業 | WF_07 |
| 4 | GET | /projects/{id} | ProjectController@show | 管理者 / 一般営業 | WF_08 |
| 5 | GET | /projects/{id}/edit | ProjectController@edit | 管理者 / 一般営業 | WF_07 |
| 6 | PUT | /projects/{id} | ProjectController@update | 管理者 / 一般営業 | WF_07 |
| 7 | DELETE | /projects/{id} | ProjectController@destroy | **管理者のみ** | WF_08 |

> **削除ルール（QA #38確定）**：管理者は物理削除（`DELETE /projects/{id}`）。一般営業がステータスを変更したい場合は `PUT /projects/{id}` で `status: "closed"` を送信すること。

---

### GET /projects　クエリパラメータ（#1）

| パラメータ名 | 型 | 必須 | 説明 | 備考 |
|------------|---|:----:|------|------|
| status | string[] | 任意 | ステータス配列 | open / closed / pending |
| work_style | string[] | 任意 | 稼働形態キー配列 | onsite / hybrid / remote |
| commercial_flow | string[] | 任意 | 商流キー配列 | prime / secondary / tertiary / other |
| interview_count | int[] | 任意 | 面談回数配列 | 1 / 2 / 3（3以上を含む） |
| keyword | string | 任意 | フリーワード検索 | 案件名（部分一致）・スキルラベル（前方一致）が対象。業務内容詳細は対象外（人材一覧と同方針） |
| sort | string | 任意 | ソート項目 | デフォルト：created_at。有効値：created_at / updated_at / start_date / rate_max |
| order | string | 任意 | 並び順 | asc / desc（デフォルト：desc） |
| page | int | 任意 | ページ番号 | デフォルト：1 |
| per_page | int | 任意 | 1ページあたり件数 | デフォルト：20・上限：100件 |

> 異なる項目間はAND条件。例：`(募集中) AND (フルリモート) AND (プライム)`  
> スキル検索は `keyword` のフリーワード検索（スキルラベル前方一致）で対応する。スキルマスタ廃止のため `skill_ids[]` は設けない（WF_06 v2.0確定）  
> ソートのデフォルトは `created_at DESC`（QA #85確定）。タイブレークは `id ASC`  

---

### GET /projects　Props（#1）

```jsonc
{
  "projects": {
    "data": [
      {
        "id": "int",
        "name": "string",                      // 案件名
        "client_name": "string",               // 顧客名
        "status": "string",                    // open | closed | pending
        "commercial_flow": "string",           // prime | secondary | tertiary | other
        "headcount": "int",                    // 募集人数
        "start_date": "date(YYYY-MM-DD)",      // 参画開始時期
        "start_label": "string",               // 表示用ラベル
                                               // start_date が null → "未定"
                                               // start_date に日付あり → "YYYY/MM/DD〜"
                                               // Controller内で生成する
                                               // 過去日付でも特別扱いせず、そのまま"YYYY/MM/DD〜"と表示する
        "rate_min": "int",                     // 単価下限（万円）
        "rate_max": "int",                     // 単価上限（万円）
        "rate_note": "string",                 // 単価備考（"スキル見合い"等）
                                               // 単価表示ロジック（一覧・詳細共通）：
                                               //   1. rate_min/rate_max があればその範囲を表示
                                               //   2. 無くても rate_note があればそれを表示（"スキル見合い"等）
                                               //   3. どちらも無ければ「—」と表示する
                                               //    ※ 単価を意図的に非公開にする専用フラグは
                                               //       採用していないため（WF_06確定事項）、
                                               //       ③は常に「単なる未入力」を意味する
        "work_style": "string",                // onsite | hybrid | remote
        "interview_count": "int",              // 面談回数
        "users": {
          "main": { "id": "int", "name": "string" },
          "sub":  { "id": "int", "name": "string" }  // null許容：未設定の場合null
        },
        // 必須スキル（skill_type = required）
        // ※ detail（VARCHAR(500)）は一覧では取得しない（DB設計書 §1-9相当）
        "required_skills": [
          { "label": "string" }                // スキルラベル（最大15文字）
        ],
        // 尚可スキル（skill_type = preferred）
        // ※ detail は一覧では取得しない
        "preferred_skills": [
          { "label": "string" }                // スキルラベル（最大15文字）
        ],
        "updated_at": "datetime(ISO8601)"
      }
    ],
    "meta": {
      "current_page": "int", // 現在のページ番号（例：1）
      "last_page": "int",    // 最終ページ番号（例：7）
      "per_page": "int",     // 1ページあたりの表示件数（例：20）
      "total": "int",        // 検索条件に一致する全件数（「32件の案件が見つかりました」の表示に使用）
      "from": "int",         // 現在ページの開始件数（「1〜4件 / 全32件」の「1」）
      "to": "int"            // 現在ページの終了件数（「1〜4件 / 全32件」の「4」）
    }
  },

  // 現在適用中の検索条件（クエリパラメータをそのまま反映。画面の状態復元に使用）
  "filters": {
    "status": ["string"],                      // ステータス
    "work_style": ["string"],                  // 稼働形態
    "commercial_flow": ["string"],             // 商流
    "interview_count": ["int"],                // 面談回数
    "keyword": "string",                       // フリーワード検索
    "sort": "string",                          // ソート項目
    "order": "string",                         // 並び順
    "per_page": "int",                         // 1ページあたり件数
    "page": "int"                              // ページ番号（状態復元用）
  },

  // ログインユーザーの保存済み検索条件一覧（search_type = "project" のみ）
  "savedSearches": [
    {
      "id": "int",
      "name": "string",                        // 表示名（DB: saved_searches.name）
      "conditions": {
        "status": ["string"],
        "work_style": ["string"],
        "commercial_flow": ["string"],
        "interview_count": ["int"],
        "keyword": "string",
        "sort": "string",
        "order": "string"
      }
    }
  ]
}
```

> **実装注意（N+1対策）**：`required_skills`・`preferred_skills`・`mainUser`・`subUser` を Eager Loading すること（`with(['requiredSkills', 'preferredSkills', 'mainUser', 'subUser'])` 相当）  
> **実装注意（TEXT除外）**：`description` / `work_env` / `remarks` はTEXTカラムのため `ProjectListResource` で明示的に除外すること（DB設計書 §1-9）  
> **実装注意（billing_range）**：`billing_range` は一覧では表示対象外のため取得しないこと（DB設計書 §1-9相当）  

---

### GET /projects/create　Props（#2）

```jsonc
{
  // フォームフィールドの必須/任意設定（form_field_settings テーブルの値）
  // フロントはこの値を参照してフィールドの必須バッジ表示・バリデーションを制御する
  // is_required: true = 現在の設定で必須 / false = 任意
  // ※ システム固定必須（name / status / main_user_id）はここに含めない（常にrequired固定のため）
  // ※ キー名は form_field_settings テーブルの field_key カラムと1対1で対応する
  "fieldSettings": {
    "client_name":          { "is_required": "bool" },
    "headcount":            { "is_required": "bool" },
    "start_date":           { "is_required": "bool" },
    // 単価（rate_min / rate_max / rate_note）はまとめて1設定として管理する
    // form_field_settings の field_key = "rate" で1レコード管理
    "rate":                 { "is_required": "bool" },
    "commercial_flow":      { "is_required": "bool" },
    "work_style":           { "is_required": "bool" },
    // 勤務地：この設定が制御するのは work_location_line（路線名）の必須/任意のみ。
    // work_location_station（最寄駅）はこの設定の対象外（下記コメント参照）。
    // form_field_settings の field_key = "work_location" で1レコード管理
    "work_location":        { "is_required": "bool" },
    // ※ work_location_station（最寄駅）の条件付き必須について：
    // work_style が onsite / hybrid の場合、work_location_station は業務ルールにより必須となる。
    // この制御は fieldSettings の is_required とは独立した固定ルールであり、
    // フロントは work_style の選択値を監視して必須バッジ・バリデーションを切り替えること。
    // [Issue #50] work_location_line / work_location_station は「勤務地（路線名）」「勤務地（最寄駅）」の
    // 別行・別バッジとしてフロントに表示する（旧: 1行に統合しwork_locationのバッジのみ表示していたため、
    // 最寄駅の実際の必須条件と表示が矛盾していた）。
    // work_style が remote の場合は行を非表示にする（値のクリアはフロントではなく保存時にバックエンドが行う）。
    "interview_count":      { "is_required": "bool" },
    "required_skills":      { "is_required": "bool" },
    "preferred_skills":     { "is_required": "bool" },
    // 対象工程6項目（proc_requirements 〜 proc_maintenance）はまとめて1設定として管理する。
    // form_field_settings の field_key = "proc_experience" で1レコード管理。
    // WF_12のフォーム設定タブでも「対象工程」として1つのトグルで管理する。
    // is_required: true の場合、フロントは対象工程チェックボックスグループ全体を必須として扱う。
    "proc_experience":      { "is_required": "bool" },
    "negotiation_required": { "is_required": "bool" },
    "description":          { "is_required": "bool" },
    "work_env":             { "is_required": "bool" },
    "billing_range":        { "is_required": "bool" },
    "remarks":              { "is_required": "bool" }
  },

  "phases": [
    // 固定6件
    { "key": "string", "name": "string" }     // key: proc_requirements など
  ],
  "work_styles": [
    // 固定3件（案件側は単一選択）
    { "key": "string", "name": "string" }     // key: onsite | hybrid | remote
  ],
  "commercial_flows": [
    // 固定4件
    { "value": "string", "label": "string" }  // value: prime | secondary | tertiary | other
  ],
  "statuses": [
    // ENUMの3値
    { "value": "string", "label": "string" }  // value: open | closed | pending
  ],
  "users": [
    { "id": "int", "name": "string" }          // 担当営業氏名
  ]
}
```

---

### GET /projects/{id}　Props（#4）

```jsonc
{
  "project": {
    "id": "int",
    "name": "string",                          // 案件名
    "client_name": "string",                   // 顧客名
    "status": "string",                        // open | closed | pending
    "commercial_flow": "string",               // prime | secondary | tertiary | other
    "headcount": "int",                        // 募集人数
    "start_date": "date(YYYY-MM-DD)",          // 参画開始時期
    "start_label": "string",                   // 表示用ラベル（一覧Propsの start_label と同じ生成ルール）
    "rate_min": "int",                         // 単価下限（万円）
    "rate_max": "int",                         // 単価上限（万円）
    "rate_note": "string",                     // 単価備考（"スキル見合い"等）
                                               // 単価表示ロジックは一覧Propsと同じ（③参照）
    "work_style": "string",                    // onsite | hybrid | remote
    "work_location_line": "string",            // 勤務地（路線名）。フリーテキスト
    "work_location_station": "string",         // 勤務地（最寄駅）。フリーテキスト
    "interview_count": "int",                  // 面談回数
    "negotiation_required": "bool",            // 顧客折衝経験要否（true=要 / false=不問）
    "description": "string",                   // 業務内容詳細
    "work_env": "string",                      // 稼働環境
    "billing_range": "string",                 // 精算幅
    "remarks": "string",                       // 特記事項
    "users": {
      "main": { "id": "int", "name": "string" },
      "sub":  { "id": "int", "name": "string" }  // null許容
    },
    // 必須スキル（skill_type = required）
    "required_skills": [
      {
        "label": "string",                    // スキルラベル（最大15文字）
        "detail": "string"                    // 詳細テキスト（最大500文字）
      }
    ],
    // 尚可スキル（skill_type = preferred）
    "preferred_skills": [
      {
        "label": "string",                    // スキルラベル（最大15文字）
        "detail": "string"                    // 詳細テキスト（最大500文字）
      }
    ],
    "phases": [
      {
        "key": "string",                      // proc_requirements など
        "name": "string",                     // 工程名
        "is_target": "bool"                   // 対象工程か否か
      }
    ],
    "updated_at": "datetime(ISO8601)"
  }
}
```

---

### GET /projects/{id}/edit　Props（#5）

```jsonc
{
  "project":        { /* GET /projects/{id} の project と同構造 */ },
  "fieldSettings":  { /* GET /projects/create の fieldSettings と同構造 */ },
  "phases":         [ /* GET /projects/create の phases と同構造 */ ],
  "work_styles":    [ /* GET /projects/create の work_styles と同構造 */ ],
  "commercial_flows": [ /* GET /projects/create の commercial_flows と同構造 */ ],
  "statuses":       [ /* GET /projects/create の statuses と同構造 */ ],
  "users":          [ /* GET /projects/create の users と同構造 */ ]
}
```

---

### POST /projects ・ PUT /projects/{id}　送信データ（#3 / #6 共通）

> **必須/任意について**：システム固定必須（DBレベルでNOT NULL）は `name` / `status` / `main_user_id` のみ。その他のフィールドはDBレベルでNULL許容であり、実際の必須/任意制御は `form_field_settings` をアプリ層で参照して行う（DB設計書 §1-2 / QA #82確定）。  
> **PATCHについて**：部分更新（PATCH）は使用しない。ステータスのみ変更する場合も PUT で全フィールドを送信すること。  
> **bool値の変換**：`proc_requirements` 〜 `proc_maintenance` / `negotiation_required` の bool 値はEloquentモデルに `boolean` キャストを定義し、DB側の `TINYINT(1)`（1:true / 0:false）へ変換すること。
> **単価の扱い**：`rate_is_negotiable`（スキル見合いフラグ）が `true` の場合は `rate_min` / `rate_max` を null として扱い、`rate_note` に "スキル見合い" 等のテキストを保存する（QA #14確定）。  
> **単価の表示ルール（一覧・詳細共通）**：① rate_min/rate_max があれば範囲表示、② 無くても rate_note があればそれを表示、③ どちらも無ければ「—」と表示する。rate_min/rate_maxは片方のみの登録ができないため、③は「未入力」または「rate_is_negotiableかつrate_note未入力」の場合のみ発生する。  
> ※ 単価を意図的に非公開にする専用フラグは採用しておらず（WF_06確定事項）、③は常に「単なる未入力」を意味するため、表示文言も「非公開」ではなく「—」に統一する。  
> **勤務地の扱い**：`work_style` が `remote`（フルリモート）の場合は `work_location_line` / `work_location_station` を null として扱う。値のクリアはフロントでは行わず、保存時にバックエンド（`ProjectService`）が null 化する（WF_07確定・Issue #50でフロント実装と整合するよう記述を修正）。  
> **スキルの更新処理**：PUT時に `required_skills[]` / `preferred_skills[]` を送信した場合、既存レコードを全件削除後に再挿入する（人材スキルと同方針）。空配列または省略の場合は全件削除として扱う。  

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| name | string | ✓ | 案件名（システム固定必須・最大255文字） |
| client_name | string | 任意 | 顧客名（form_field_settings制御・最大100文字） |
| headcount | int | 任意 | 募集人数（form_field_settings制御） |
| start_date | date | 任意 | 参画開始時期（form_field_settings制御） |
| rate_is_negotiable | bool | 任意 | スキル見合いフラグ。true時は rate_min / rate_max を null、rate_note に "スキル見合い" 等をセット（QA #14確定） |
| rate_min | int | 任意 | 単価下限（万円）。rate_is_negotiable が false の場合に使用（form_field_settings の rate 設定で制御） |
| rate_max | int | 任意 | 単価上限（万円）。rate_is_negotiable が false の場合に使用（form_field_settings の rate 設定で制御） |
| rate_note | string | 任意 | 単価備考（最大100文字）。スキル見合い時に使用 |
| commercial_flow | string | 任意 | 商流（form_field_settings制御）。prime / secondary / tertiary / other |
| work_style | string | 任意 | 稼働形態（form_field_settings制御）。onsite / hybrid / remote（単一選択） |
| work_location_line | string | 任意 | 勤務地（路線名）。work_style が remote の場合はnull（保存時にバックエンドでクリア）（form_field_settings の work_location 設定で制御。onsite / hybrid の場合のみ） |
| work_location_station | string | 任意 | 勤務地（最寄駅）。work_style が remote の場合はnull（保存時にバックエンドでクリア）。onsite / hybrid の場合は常に必須（業務ルール固定・form_field_settings非依存） |
| interview_count | int | 任意 | 面談回数（form_field_settings制御） |
| required_skills[] | array | 任意 | 必須スキル配列。空配列 [] または省略で全削除。PUT時は全件洗い替え（全削除後に再挿入）。件数の必須制御は form_field_settings に従いアプリ層で行う |
| required_skills[].label | string | 任意 | 必須スキルラベル（最大15文字） |
| required_skills[].detail | string | 任意 | 必須スキル詳細（最大500文字） |
| preferred_skills[] | array | 任意 | 尚可スキル配列。空配列 [] または省略で全削除。PUT時は全件洗い替え |
| preferred_skills[].label | string | 任意 | 尚可スキルラベル（最大15文字） |
| preferred_skills[].detail | string | 任意 | 尚可スキル詳細（最大500文字） |
| proc_requirements | bool | 任意 | 要件定義（true:対象）。proc_experience の is_required 設定で制御 |
| proc_basic_design | bool | 任意 | 基本設計（true:対象）。proc_experience の is_required 設定で制御 |
| proc_detail_design | bool | 任意 | 詳細設計（true:対象）。proc_experience の is_required 設定で制御 |
| proc_development | bool | 任意 | 開発（true:対象）。proc_experience の is_required 設定で制御 |
| proc_testing | bool | 任意 | テスト（true:対象）。proc_experience の is_required 設定で制御 |
| proc_maintenance | bool | 任意 | 保守運用（true:対象）。proc_experience の is_required 設定で制御 |
| negotiation_required | bool | 任意 | 顧客折衝経験要否（form_field_settings制御）。true=要 / false=不問 |
| description | string | 任意 | 業務内容詳細（form_field_settings制御・最大4000文字） |
| work_env | string | 任意 | 稼働環境（form_field_settings制御・最大1000文字） |
| status | string | ✓ | ステータス（システム固定必須）。open / closed / pending |
| main_user_id | int | ✓ | 主担当ユーザーID（システム固定必須） |
| sub_user_id | int | 任意 | サブ担当ユーザーID（null許容） |
| billing_range | string | 任意 | 精算幅（form_field_settings制御・最大100文字） |
| remarks | string | 任意 | 特記事項（form_field_settings制御・最大1000文字） |

> **文字数上限について（2026-08-17確定・#34対応）**：`description`(max:4000) / `work_env`(max:1000) / `remarks`(max:1000)は、人材側`appeal_note`(max:4000) / `remarks`(max:1000)と揃える形で確定した。§3 TBDにあった同項目は解消済み。

---

### DELETE /projects/{id}（#7）

#### アクセス可能ロール：管理者のみ

> 一般営業のステータス変更（終了化）は `PUT /projects/{id}` で `status: "closed"` を送信すること。

#### 挙動

| 条件 | 動作 |
|---|---|
| 管理者による実行 | 対象案件を物理削除する。関連する `pipelines` は `ON DELETE CASCADE` により連鎖削除される（DB設計書 §4）。削除前にフロントエンドで「この案件に紐づくパイプライン〇件が同時に削除されます」の確認ダイアログを表示すること |

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | `/projects` へリダイレクトし SharedProps の `flash.success` を返す |
| 権限不足時 | 前画面へリダイレクトし SharedProps の `flash.error` を返す |
| 対象データなし | 404 を返す |

---

---

## 2. 人材管理（Engineer）との主な差分

実装者が混乱しやすい箇所を整理する。

| 項目 | 人材管理（Engineer） | 案件管理（Project）|
|-----|------------------|--------------------|
| スキルテーブル | `engineer_skills`（1種類） | `project_skills`（`skill_type` で必須/尚可を区別） |
| スキルの送信キー | `skills[]` | `required_skills[]` / `preferred_skills[]` の2配列 |
| 勤務形態 | 複数選択（3カラム bool）→ string[] で送信 | 単一選択（ENUM）→ string で送信 |
| 勤務地 | なし | `work_location_line` / `work_location_station`（フリーテキスト） |
| 工程経験の意味 | 人材の経験有無（has_experience） | 案件の対象工程（is_target） |
| 単価 | `desired_rate`（単一値・万円） | `rate_min` / `rate_max`（範囲）+ `rate_note`（スキル見合い等） |
| AI生成フィールド | `ai_summary` / `ai_summary_generated_at` | なし（案件にAI要約フィールドは存在しない） |
| 削除時のステータス変更値 | `not_proposable` | `closed` |
| Props の工程経験フィールド名 | `has_experience` | `is_target` |
| fieldSettings の工程経験キー | `proc_requirements` 〜 `proc_maintenance`（6個別キー） | `proc_requirements` 〜 `proc_maintenance`（6個別キー・人材と同じ） |

---

## 3. 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
| 1 | `interview_count` の上限値 | - | WF_06では「3回以上」という表示があるが、DB上の上限（TINYINT UNSIGNED: 最大255）以外の業務的な上限の要否を確認すること |