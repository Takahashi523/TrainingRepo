# CSV入出力 APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-06-15  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。  

---

## 設計上の前提

### 対象範囲

| 対象 | インポート | エクスポート | 根拠 |
|---|:---:|:---:|---|
| 人材（engineers） | ✅ | ✅ | QA #15確定 |
| 案件（projects） | ✅ | ✅ | QA #15確定 |
| パイプライン | ❌ | ❌ | WF_11スコープ外 |
| 操作ログ | ❌ | ❌ | QA #55/#57確定（OUTスコープ） |

### インポート方式

| 条件 | 処理 |
|---|---|
| CSVの `id` 列が空 | 新規追加として処理 |
| CSVの `id` 列に既存ID | 既存データを上書き更新 |
| CSVの `id` 列に存在しないID | エラーとして処理（全行ロールバック） |

> エクスポートしたCSVを編集して再インポートする運用を想定（WF_11確定）。
> **エラー行処理方針**：1行でもエラーがある場合は全行ロールバックし、エラー内容を返す。データ整合性を優先するため「スキップして成功件数を返す」方式は採用しない。

### ファイル仕様（確定）

| 項目 | 仕様 |
|---|---|
| 対応形式 | `.csv`（UTF-8 / BOM付き） |
| 最大ファイルサイズ | 5MB |
| 改行コード | CRLF・LF両対応 |
| 文字コード | UTF-8（BOMあり・なし両対応） |

### 権限

| エンドポイント | アクセス可能ロール | 根拠 |
|---|---|---|
| 全エンドポイント | 管理者 / 一般営業 | 画面一覧「主なアクター：一般営業、管理者」より |

---

## 1. APIエンドポイント一覧

> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。

### エンドポイント一覧表

| # | メソッド | URL | Controller#Action | アクセス可能ロール | 対応WF |
|---|---|---|---|---|---|
| 1 | GET | /csv | CsvController@index | 管理者 / 一般営業 | WF_11（画面表示） |
| 2 | POST | /csv/engineers/import | CsvController@importEngineers | 管理者 / 一般営業 | WF_11（人材インポート） |
| 3 | GET | /csv/engineers/export | CsvController@exportEngineers | 管理者 / 一般営業 | WF_11（人材エクスポート） |
| 4 | POST | /csv/projects/import | CsvController@importProjects | 管理者 / 一般営業 | WF_11（案件インポート） |
| 5 | GET | /csv/projects/export | CsvController@exportProjects | 管理者 / 一般営業 | WF_11（案件エクスポート） |

---

### GET /csv　Props（#1）

```jsonc
{
  // エクスポート絞り込み条件の選択肢（人材・案件共通で1リクエストで返す）
  "engineer_filter_options": {
    "statuses": [
      { "value": "string", "label": "string" }  // proposable | interviewing | not_proposable
    ],
    "users": [
      { "id": "int", "name": "string" }          // 担当営業選択肢
    ],
    "work_styles": [
      { "key": "string", "name": "string" }      // onsite | hybrid | remote
    ]
  },
  "project_filter_options": {
    "statuses": [
      { "value": "string", "label": "string" }   // open | closed | pending
    ],
    "users": [
      { "id": "int", "name": "string" }
    ],
    "work_styles": [
      { "key": "string", "name": "string" }      // onsite | hybrid | remote
    ]
  }
}
```

---

### POST /csv/engineers/import　送信データ（#2）

> ファイルアップロードのため `Content-Type: multipart/form-data` で送信する。

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| file | file | ✓ | CSVファイル。`.csv` 形式・最大5MB・UTF-8（BOM付き対応） |

#### レスポンス

| 条件 | HTTPステータス | レスポンス内容 |
|---|---|---|
| 全行正常処理 | 200 OK | 処理結果サマリーを返す（後述） |
| ファイルバリデーションエラー | 422 | エラー内容を返す |
| 1行でもエラーあり | 422 | 全行ロールバック。エラー行情報を返す（後述） |

```jsonc
// 成功時レスポンス（200 OK）
{
  "summary": {
    "total_rows": "int",  // 処理対象行数（ヘッダー行除く）
    "created": "int",     // 新規追加件数
    "updated": "int"      // 更新件数
  }
}

// エラー時レスポンス（422）
{
  "errors": [
    {
      "row": "int",        // エラー発生行番号（1オリジン・ヘッダー行を1行目としてカウント）
      "field": "string",   // エラーフィールド名（特定できない場合は null）
      "message": "string"  // エラーメッセージ（日本語）
    }
  ]
}
```

> **実装注意（トランザクション）**：1行でもエラーがある場合は全行ロールバックする。エラー内容をすべて収集してから422を返すこと（最初のエラーで中断しない）。
> **実装注意（フィードバックUI）**：インポート結果はフォームの下にインライン表示する。成功時は「新規追加：〇件 / 更新：〇件」、失敗時は「エラー内容の一覧」を表示すること。
> **実装注意（大量データ）**：5MBのCSVは数千行になりうる。パフォーマンス問題が発生した場合はLaravel Queueによる非同期処理への移行を検討すること。

---

### GET /csv/engineers/export　クエリパラメータ（#3）

| パラメータ名 | 型 | 必須 | 説明 | 備考 |
|------------|---|:----:|------|------|
| status | string[] | 任意 | ステータス絞り込み | proposable / interviewing / not_proposable |
| user_id | int | 任意 | 担当営業絞り込み | 指定なしで全員 |
| available_from_start | date | 任意 | 稼働可能時期（開始） | YYYY-MM-DD形式 |
| available_from_end | date | 任意 | 稼働可能時期（終了） | YYYY-MM-DD形式 |
| keyword | string | 任意 | スキルキーワード（前方一致） | engineer_skills.label に対して検索。スキルはCSV列に含まれないが絞り込み条件としては使用可能 |
| work_styles | string[] | 任意 | 勤務形態絞り込み | onsite / hybrid / remote |

#### レスポンス

```
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename="engineers_YYYYMMDD_HHmmss.csv"
```

> 0件の場合もヘッダー行のみのCSVをレスポンスする（WF_11確定）。
> **実装注意（TEXT除外不可）**：エクスポートはすべてのカラムを出力対象とするため、一覧表示時のTEXT除外ルールは適用しない。`appeal_note` / `ai_summary` / `remarks` も出力する。
> **実装注意（ai_summary）**：`ai_summary` はエクスポートに含めるが、インポート時は無視する（上書き不可）。AI生成値のため手動更新は不可とする。
> **実装注意（ファイル名）**：ダウンロードファイル名はサーバー側で生成する。例：`engineers_20260522_143022.csv`

---

### POST /csv/projects/import　送信データ（#4）

人材インポート（#2）と同じ仕様。`Content-Type: multipart/form-data` でファイル送信。

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| file | file | ✓ | CSVファイル。`.csv` 形式・最大5MB・UTF-8（BOM付き対応） |

レスポンス形式は人材インポート（#2）と同じ構造を返す。

---

### GET /csv/projects/export　クエリパラメータ（#5）

| パラメータ名 | 型 | 必須 | 説明 | 備考 |
|------------|---|:----:|------|------|
| status | string[] | 任意 | ステータス絞り込み | open / closed / pending |
| user_id | int | 任意 | 担当営業絞り込み | 指定なしで全員 |
| start_date_from | date | 任意 | 参画開始時期（開始） | YYYY-MM-DD形式 |
| start_date_to | date | 任意 | 参画開始時期（終了） | YYYY-MM-DD形式 |
| keyword | string | 任意 | 必須スキルキーワード（前方一致） | project_skills.label に対して検索。スキルはCSV列に含まれないが絞り込み条件としては使用可能 |
| work_style | string[] | 任意 | 稼働形態絞り込み | onsite / hybrid / remote |

#### レスポンス

```
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename="projects_YYYYMMDD_HHmmss.csv"
```

> 0件の場合もヘッダー行のみのCSVをレスポンスする（WF_11確定）。
> **実装注意（TEXT除外不可）**：`description` / `work_env` / `remarks` も含めてすべてのカラムを出力する。
> **実装注意（ai_summary）**：案件にai_summaryカラムは存在しないため対象外。

---

## 2. バリデーション定義（CSV入出力）

> バリデーション定義は `バリデーション・エラー表示設計書.md` の「8. CSV入出力」セクションを参照すること。

---

## 3. CSVカラム定義（暫定）

> WF_11注記「エクスポートCSVのカラム定義はDB設計フェーズで確定予定」のため暫定。
> インポートのカラム定義はエクスポートと同一とする（エクスポートしたCSVを再インポートする運用想定）。

### 人材CSV カラム順（暫定）

| 列 | カラム名（ヘッダー） | DBカラム | 備考 |
|---|---|---|---|
| A | id | id | 新規追加時は空。更新時は既存ID |
| B | 氏名 | name | |
| C | 氏名カナ | name_kana | 全角カタカナ・長音符・全角スペースのみ許容 |
| D | 生年月日 | birth_date | YYYY-MM-DD |
| E | 最寄駅 | nearest_station | |
| F | 路線 | nearest_line | |
| G | 稼働可能時期 | available_from | YYYY-MM-DD |
| H | 希望単価（万円） | desired_rate | 整数 |
| I | アピールポイント | appeal_note | |
| J | AI要約 | ai_summary | エクスポートのみ。インポート時は無視（上書き不可） |
| K | 特記事項 | remarks | |
| L | ステータス | status | proposable / interviewing / not_proposable |
| M | 主担当ID | main_user_id | users.id |
| N | サブ担当ID | sub_user_id | users.id |
| O | 常駐可 | work_style_onsite | 0 / 1 |
| P | 一部リモート可 | work_style_hybrid | 0 / 1 |
| Q | フルリモート希望 | work_style_remote | 0 / 1 |
| R | 要件定義経験 | proc_requirements | 0 / 1 |
| S | 基本設計経験 | proc_basic_design | 0 / 1 |
| T | 詳細設計経験 | proc_detail_design | 0 / 1 |
| U | 開発経験 | proc_development | 0 / 1 |
| V | テスト経験 | proc_testing | 0 / 1 |
| W | 保守運用経験 | proc_maintenance | 0 / 1 |
| X | 顧客折衝経験 | has_negotiation_exp | 0 / 1 |

> **スキルについて**：現時点ではCSV対象外とする。スキルの登録・編集は人材詳細画面から行うこと。スキルキーワードはエクスポートの絞り込み条件としては使用可能（TBD#1参照）。

### 案件CSV カラム順（暫定）

| 列 | カラム名（ヘッダー） | DBカラム | 備考 |
|---|---|---|---|
| A | id | id | 新規追加時は空。更新時は既存ID |
| B | 案件名 | name | |
| C | 顧客名 | client_name | |
| D | 募集人数 | headcount | 整数 |
| E | 参画開始時期 | start_date | YYYY-MM-DD |
| F | 単価下限（万円） | rate_min | 整数 |
| G | 単価上限（万円） | rate_max | 整数 |
| H | 単価備考 | rate_note | スキル見合い等 |
| I | 商流 | commercial_flow | prime / secondary / tertiary / other |
| J | 稼働形態 | work_style | onsite / hybrid / remote |
| K | 勤務地（路線） | work_location_line | |
| L | 勤務地（最寄駅） | work_location_station | |
| M | 面談回数 | interview_count | 整数 |
| N | 顧客折衝経験要否 | negotiation_required | 0 / 1 |
| O | 業務内容詳細 | description | |
| P | 稼働環境 | work_env | |
| Q | 精算幅 | billing_range | |
| R | 特記事項 | remarks | |
| S | ステータス | status | open / closed / pending |
| T | 主担当ID | main_user_id | users.id |
| U | サブ担当ID | sub_user_id | users.id |
| V | 要件定義対象 | proc_requirements | 0 / 1 |
| W | 基本設計対象 | proc_basic_design | 0 / 1 |
| X | 詳細設計対象 | proc_detail_design | 0 / 1 |
| Y | 開発対象 | proc_development | 0 / 1 |
| Z | テスト対象 | proc_testing | 0 / 1 |
| AA | 保守運用対象 | proc_maintenance | 0 / 1 |

> **スキルについて**：現時点ではCSV対象外とする。スキルの登録・編集は案件詳細画面から行うこと。スキルキーワードはエクスポートの絞り込み条件としては使用可能（TBD#1参照）。

---

## 4. 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
| 1 | スキル列のCSVカラム構成 | - | 現時点ではスキルはCSV対象外とし、スキルの登録・編集は各詳細画面から行う運用とする。将来的にCSVでのスキル一括管理が必要になった場合は、複数列方式（スキル1ラベル・スキル1詳細・スキル2ラベル…）での対応を検討すること |
