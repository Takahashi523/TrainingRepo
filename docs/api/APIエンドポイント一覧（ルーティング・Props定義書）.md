# APIエンドポイント一覧（ルーティング・Props定義書）

> 技術方針：Laravel + Inertia.js + React  
> ルーティング定義：`routes/web.php`  
> 認証方式：Laravel Breeze（セッション認証）  
> 最終更新：2026-05-06

---

## 前提

- **認証**：Laravel Breezeを使用。`/login` / `/logout` はBreeze自動生成のため本一覧に記載しない。`/register` / `/forgot-password` / `/reset-password` は無効化する
- **認可（ロール別制御）**：ロールは **管理者 / 一般営業** の2階層（QA #17確定）。詳細な操作権限は権限・ロール設計書に記載する
- **バリデーション**：各フィールドの詳細ルールは「バリデーション・エラー表示設計書」を参照すること

---

## 凡例

| 記号 | 意味 |
|------|------|
| クエリパラメータ | GETリクエスト時にURLに付与してサーバーへ送るデータ（検索条件・ページング・ソート情報） |
| Props | GETリクエスト時にControllerからReactコンポーネントへ渡すデータ |
| 送信データ | POST/PUTリクエスト時にフロントから送るデータ |


## 詳細ブロックの構成ルール

```
### GET /xxx
**クエリパラメータ** → 表形式（パラメータ名 / 型 / 必須 / 説明 / 備考）
**Props**           → 表形式（フィールド名 / 型 / 説明）

### POST・PUT /xxx
**送信データ**       → 表形式（フィールド名 / 型 / 必須 / 備考）
```

---

## 1. ダッシュボード

---

## 2. 人材管理（Engineer）

| # | メソッド | URL | Controller#Action | Props（GET時） | 送信データ |
|---|---------|-----|------------------|--------------|-----------|
| 1 | GET | /engineers | EngineerController@index | engineers, filters, skillTags, savedFilters | - |
| 2 | GET | /engineers/{id} | EngineerController@show | engineer | - |
| 3 | GET | /engineers/create | EngineerController@create | skillTags, phases, work_types, statuses, users | - |
| 4 | POST | /engineers | EngineerController@store | - | 下記参照 |
| 5 | GET | /engineers/{id}/edit | EngineerController@edit | engineer, skillTags, phases, work_types, statuses, users | - |
| 6 | PUT | /engineers/{id} | EngineerController@update | - | 下記参照 |
| 7 | DELETE | /engineers/{id} | EngineerController@destroy | - | - |

> **削除ルール（QA #37確定）**：管理者は物理削除可。一般営業はステータス変更で対応し、データは残す。

### GET /engineers　クエリパラメータ（#1）

| パラメータ名 | 型 | 必須 | 説明 | 備考 |
|------------|---|:----:|------|------|
| status_ids[] | int[] | 任意 | ステータスID配列 | 同一項目内OR |
| skill_ids[] | int[] | 任意 | スキルID配列 | 同一項目内OR |
| work_types[] | string[] | 任意 | 勤務形態キー配列 | onsite / partial_remote / full_remote |
| phases[] | string[] | 任意 | 工程経験キー配列 | basic_design / development など |
| keyword | string | 任意 | フリーワード検索 | 氏名・スキル名・アピールポイントに対して部分一致 検索対象項目はTBD |
| sort | string | 任意 | ソート項目 | デフォルト：updated_at |
| order | string | 任意 | 並び順 | asc / desc（デフォルト：desc） |
| page | int | 任意 | ページ番号 | デフォルト：1 |
| per_page | int | 任意 | 1ページあたり件数 | デフォルト：20・上限はTBD |

> 異なる項目間はAND条件。例：`(Java OR PHP) AND (提案可) AND (フルリモート)`

### GET /engineers　Props（#1）

| フィールド名 | 型 | 説明 |
| ----------- | ------ | ---------------- |
| engineers.data[].id | int | 人材ID |
| engineers.data[].name | string | 氏名 |
| engineers.data[].age | int | 年齢 |
| engineers.data[].nearest_station.id | int | 最寄駅ID |
| engineers.data[].nearest_station.name | string | 最寄駅名 |
| engineers.data[].route.id | int | 路線ID |
| engineers.data[].route.name | string | 路線名 |
| engineers.data[].status.id | int | ステータスID |
| engineers.data[].status.name | string | ステータス名 |
| engineers.data[].available_date | date（YYYY-MM-DD） | 稼働可能日（元データ） |
| engineers.data[].available_label | string | 表示用ラベル（即日〜 / YYYY/MM/DD〜など） |
| engineers.data[].users.main.id | int | 主担当ユーザーID |
| engineers.data[].users.main.name | string | 主担当ユーザー名 |
| engineers.data[].users.sub | object/null | サブ担当（未設定の場合null） |
| engineers.data[].users.sub.id | int | サブ担当ユーザーID（subがnullの場合は存在しない） |
| engineers.data[].users.sub.name | string | サブ担当ユーザー名（subがnullの場合は存在しない） |
| engineers.data[].skills[].id | int | スキルID |
| engineers.data[].skills[].name | string | スキル名 |
| engineers.data[].phases[] | array | 工程経験 |
| engineers.data[].phases[].key | string | キー（basic_designなど） |
| engineers.data[].phases[].name | string | 工程名 |
| engineers.data[].phases[].has_experience | boolean | 経験有無 |
| engineers.data[].work_types[] | array | 勤務形態 |
| engineers.data[].work_types[].key | string | キー（onsiteなど） |
| engineers.data[].work_types[].name | string | 表示名 |
| engineers.data[].updated_at | datetime（ISO8601） | 最終更新日 |
| engineers.meta.current_page | int | 現在ページ番号 |
| engineers.meta.per_page | int | 1ページあたり件数 |
| engineers.meta.total | int | 全件数 |
| engineers.meta.from | int | 現在ページの開始位置 |
| engineers.meta.to | int | 現在ページの終了位置 |
| filters | object | 現在適用中の検索条件（クエリパラメータをそのまま反映。画面の状態復元に使用） |
| filters.status_ids | int[] | ステータス |
| filters.skill_ids | int[] | スキル |
| filters.work_types | string[] | 勤務形態 |
| filters.phases | string[] | 工程 |
| filters.keyword | string | フリーワード検索（名前・スキルなど） |
| filters.sort | string | ソート |
| filters.order | string | 並び順 |
| skillTags[] | array | スキルタグ一覧（検索フォームの選択肢として使用するマスタデータ） |
| skillTags[].id | int | スキルID |
| skillTags[].name | string | スキル名 |
| skillTags[].category | string | カテゴリ名 |
| savedFilters[].id | int | 保存条件ID |
| savedFilters[].label | string | 表示名 |
| savedFilters[].conditions | object | 検索条件（filtersと同構造） |
| savedFilters[].conditions.status_ids | int[] | ステータス |
| savedFilters[].conditions.skill_ids | int[] | スキル |
| savedFilters[].conditions.work_types | string[] | 勤務形態 |
| savedFilters[].conditions.phases | string[] | 工程 |
| savedFilters[].conditions.keyword | string | フリーワード検索 |

### GET /engineers/{id}　Props（#2）

| フィールド名 | 型 | 説明 |
| ----------- | ------ | ---------------- |
| engineer.id | int | 人材ID |
| engineer.name | string | 氏名 |
| engineer.name_kana | string | カナ |
| engineer.age | int | 年齢 |
| engineer.status.id | int | ステータスID |
| engineer.status.name | string | ステータス名 |
| engineer.available_date | date | 稼働可能日 |
| engineer.available_label | string | 表示用（即日〜など） |
| engineer.nearest_station.id | int | 最寄駅ID |
| engineer.nearest_station.name | string | 最寄駅名 |
| engineer.route.id | int | 路線ID |
| engineer.route.name | string | 路線名 |
| engineer.users.main.id | int | 主担当ID |
| engineer.users.main.name | string | 主担当名 |
| engineer.users.sub | object/null | サブ担当 |
| engineer.users.sub.id | int | サブ担当ID |
| engineer.users.sub.name | string | サブ担当名 |
| engineer.skills[] | array | スキル一覧 |
| engineer.skills[].id | int | スキルID |
| engineer.skills[].name | string | スキル名 |
| engineer.skills[].experience_years | int | 経験年数 |
| engineer.phases[] | array | 工程経験 |
| engineer.phases[].key | string | キー |
| engineer.phases[].name | string | 工程名 |
| engineer.phases[].has_experience | boolean | 経験有無 |
| engineer.client_communication_experience | boolean | 顧客折衝経験 |
| engineer.self_promotion | string | アピールポイント |
| engineer.desired_monthly_rate | int | 希望単価月額（単位：万円） |
| engineer.work_types[] | array | 勤務形態 |
| engineer.work_types[].key | string | キー |
| engineer.work_types[].name | string | 表示名 |
| engineer.notes | string | 特記事項 |
| engineer.updated_at | datetime（ISO8601） | 最終更新日 |

### GET /engineers/create Props（#3）

| フィールド名 | 型 | 説明 |
| ----------- | ------ | ---------------- |
| skillTags[] | array | スキル一覧 |
| skillTags[].id | int | スキルID |
| skillTags[].name | string | スキル名 |
| skillTags[].category | string | カテゴリ名 |
| phases[] | array | 工程マスタ |
| phases[].key | string | キー（basic_designなど） |
| phases[].name | string | 表示名 |
| work_types[] | array | 勤務形態マスタ |
| work_types[].key | string | キー（onsiteなど） |
| work_types[].name | string | 表示名 |
| statuses[] | array | ステータス一覧 |
| statuses[].id | int | ステータスID |
| statuses[].name | string | ステータス名 |
| users[] | array | 担当者一覧 |
| users[].id | int | ユーザーID |
| users[].name | string | ユーザー名 |

### GET /engineers/{id}/edit　Props（#5）

| フィールド名 | 型 | 説明 |
| ----------- | ------ | ---------------- |
| engineer | object | 既存データ（#2 showと同構造） |
| skillTags[] | array | スキル一覧 |
| phases[] | array | 工程マスタ |
| work_types[] | array | 勤務形態マスタ |
| statuses[] | array | ステータス一覧 |
| users[] | array | 担当者一覧 |

### POST /engineers ・ PUT /engineers/{id}　送信データ（#4 / #6 共通）

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| name | string | ✓ | 氏名 |
| name_kana | string | ✓ | カナ |
| birth_date | date | ✓ | 生年月日 |
| nearest_station_id | int | ✓ | 最寄駅ID |
| route_id | int | ✓ | 路線ID |
| available_date | date | ✓ | 稼働可能日 |
| skills[] | array | ✓ | スキル配列 |
| skills[].skill_id | int | ✓ | スキルID |
| skills[].experience_years | int | ✓ | 経験年数 |
| phases.requirement_definition | bool | ✓ | 要件定義（true:経験あり） |
| phases.basic_design | bool | ✓ | 基本設計（true:経験あり） |
| phases.detailed_design | bool | ✓ | 詳細設計（true:経験あり） |
| phases.development | bool | ✓ | 開発（true:経験あり） |
| phases.test | bool | ✓ | テスト（true:経験あり） |
| phases.maintenance | bool | ✓ | 保守運用（true:経験あり） |
| client_communication_experience | bool | ✓ | 顧客折衝経験（true:有） |
| self_promotion | string | 任意 | アピールポイント |
| desired_monthly_rate | int | 任意 | 希望単価月額（単位：万円） |
| work_types[] | string[] | 任意 | 勤務形態（onsite / partial_remote / full_remote） |
| notes | string | 任意 | 特記事項 |
| status_id | int | ✓ | ステータス |
| main_user_id | int | ✓ | 主担当 |
| sub_user_id | int/null | 任意 | サブ担当 |

### DELETE /engineers/{id}（#7）

#### 挙動
- 管理者：物理削除
- 一般営業：status_idを「削除（無効）」に更新（論理削除）

#### リダイレクト
- 削除後は /engineers にリダイレクトする
- フラッシュメッセージを付与する

#### Props（リダイレクト後）
| フィールド名 | 型 | 説明 |
|-------------|----|------|
| flash.success | string | 成功メッセージ |
| flash.error | string | 失敗メッセージ（削除不可時など） |

---

## 3. 案件管理（Project）

---

## 4. マッチング（Matching）

---

## 5. 進捗管理（Pipeline）

---

## 6. 保存検索条件（SavedFilter）

| # | メソッド | URL | Controller#Action | Props | 送信データ |
|---|---------|-----|------------------|-------|-----------|
| 1 | POST | /saved-filters | SavedFilterController@store | - | 下記参照 |
| 2 | DELETE | /saved-filters/{id} | SavedFilterController@destroy | - | - |

### POST /saved-filters　送信データ（#1）

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| label | string | ✓ | 保存条件の表示名 |
| conditions | object | ✓ | 検索条件（filtersと同構造） |
| conditions.status_ids | int[] | 任意 | ステータス |
| conditions.skill_ids | int[] | 任意 | スキル |
| conditions.work_types | string[] | 任意 | 勤務形態 |
| conditions.phases | string[] | 任意 | 工程 |
| conditions.keyword | string | 任意 | フリーワード |
| conditions.sort | string | 任意 | ソート項目 |
| conditions.order | string | 任意 | 並び順 |

---

## 7. CSV入出力

---

## 8. マスタ管理（Master）

---

## 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
| 1 | 人材一覧フリーワード検索の検索対象項目 | - | - |
| 2 | 人材一覧ページの1ページあたり件数 | - | - |