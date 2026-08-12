# 検索条件保存（SavedSearch）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-08-06  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。  
> **注意**：検索条件保存は人材管理・案件管理の両方から使用するが、エンドポイントは共通のため本ファイルに独立して定義する。人材一覧・案件一覧の Props（`savedSearches`）との関連は `03_人材管理_APIエンドポイント一覧.md` / `04_案件管理_APIエンドポイント一覧.md` を参照すること。  

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

> **name 未入力時の扱い（画面側の仕様）**：name はAPI上は必須だが、画面の保存フォームでは任意入力とし、未入力の場合は現在の絞り込みタグ（例：「募集中 × フルリモート」）を組み合わせて画面側で自動生成し、常に非空文字列にしてから送信する。保存導線（「条件を保存」ボタン）自体が絞り込み条件が1件以上ある時のみ表示される仕様になったため（PR #60 レビュー対応：保存と削除の1ボタン2責務を解消）、絞り込み条件0件のまま本APIへリクエストが送られることはない。ソート（sort/order）が既定値以外の場合は、絞り込みタグの末尾にソートのラベル（例：「更新日順（新しい順）」）も追加して自動生成する。既定値のままの場合は名前に含めない（PR #60 レビュー対応：ソートも保存・復元されるのに名前・表示に一切出ないのは実態と不一致、という指摘への対応）。

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
