# 保存検索条件（SavedSearch）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React
> 最終更新：2026-05-27

> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。

> **注意**：保存検索条件は人材管理・案件管理の両方から使用するが、エンドポイントは共通のため本ファイルに独立して定義する。人材一覧・案件一覧の Props（`savedSearches`）との関連は `03_人材管理_APIエンドポイント一覧.md` / `04_案件管理_APIエンドポイント一覧.md` を参照すること。

---

## エンドポイント一覧表

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
| conditions | object | ✓ | 検索条件。search_type に応じて以下のフィールドを送信する |

**search_type = "engineer" の場合（人材一覧からの保存）**

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| conditions.status | string[] | 任意 | ステータス（proposable / interviewing / not_proposable） |
| conditions.work_styles | string[] | 任意 | 勤務形態（onsite / hybrid / remote） |
| conditions.phases | string[] | 任意 | 工程経験（proc_requirements 等） |
| conditions.keyword | string | 任意 | フリーワード |
| conditions.sort | string | 任意 | ソート項目 |
| conditions.order | string | 任意 | 並び順 |

**search_type = "project" の場合（案件一覧からの保存）**

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| conditions.status | string[] | 任意 | ステータス（open / closed / pending） |
| conditions.work_style | string[] | 任意 | 稼働形態（onsite / hybrid / remote） |
| conditions.commercial_flow | string[] | 任意 | 商流（prime / secondary / tertiary / other） |
| conditions.interview_count | int[] | 任意 | 面談回数 |
| conditions.keyword | string | 任意 | フリーワード |
| conditions.sort | string | 任意 | ソート項目 |
| conditions.order | string | 任意 | 並び順 |
