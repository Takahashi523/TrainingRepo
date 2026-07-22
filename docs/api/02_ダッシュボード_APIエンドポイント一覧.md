# ダッシュボード（Dashboard）APIエンドポイント一覧

> 最終更新：2026-06-12  
> 対象WF：WF_02 v1.1  
> 照合ソース：データモデル・DB設計書 v1.7 / 仕様QA表  

---

## 設計上の前提

### ダッシュボードの表示内容

WF_02より、ダッシュボードは以下の3セクションで構成される。

| セクション | 内容 |
|---|---|
| ① KPIサマリーバー | 提案可能人材数・稼働中案件数・進行中カード総数の3指標 |
| ② パイプライン進捗サマリー | 自分担当の進行中パイプラインをステータス別に件数・割合で表示 |
| ③ 近日アクション予定 | `next_action_date` が近い順のパイプライン一覧 |

### 集計軸の確定事項

| 項目 | 仕様 | 根拠 |
|---|---|---|
| 集計対象 | ログインユーザーがメイン・サブのいずれかで担当している人材のパイプライン | QA #70 / #70-1確定（4/24） |
| KPI「提案可能人材」（大きい数字） | `engineers.status = 'proposable'` かつ `(main_user_id = 自分 OR sub_user_id = 自分)` の件数 | WF_02・QA #70確定 |
| KPI「提案可能人材」（小さい数字） | `engineers.status = 'proposable'` の全件数（システム全体）。自分が全体のうちどれくらい担当しているか把握するために表示 | 設計確定 |
| KPI「稼働中案件」（大きい数字） | `projects.status = 'open'` かつ `(main_user_id = 自分 OR sub_user_id = 自分)` の件数 | WF_02・QA #70確定 |
| KPI「稼働中案件」（小さい数字） | `projects.status = 'open'` の全件数（システム全体）。自分が全体のうちどれくらい担当しているか把握するために表示 | 設計確定 |
| KPI「進行中カード総数」 | 自分担当人材のパイプラインのうち進行中12種のいずれかに該当するもの | WF_02・QA #70確定 |
| パイプライン進捗サマリー | 同上。進行中12種をステータス別に集計 | WF_02・QA #70確定 |
| 近日アクション予定 | 自分担当人材のパイプラインのうち `next_action_date` が今日から7日以内のもの（土日を含むカレンダー7日）+ 今日より前の期限超過分。日付昇順で返す。7日以内に該当なし・期限超過もない場合は空配列を返す（フロントで「アクション予定なし」と表示） | - |

> **「近日」の定義（7日以内・土日含む）の具体例**：今日が月曜日（6/9）の場合、翌月曜日（6/16）までが対象範囲となる。

### 更新系操作なし

ダッシュボードは参照専用画面のため、POST / PUT / PATCH / DELETE は存在しない。
バリデーションは GET パラメータのみ対象となる。

---

## 1. APIエンドポイント一覧

> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` の前提セクションに準じる。

### エンドポイント一覧表

| # | メソッド | URL | Controller#Action | アクセス可能ロール | 対応WF |
|---|---|---|---|---|---|
| 1 | GET | /dashboard | DashboardController@index | 管理者 / 一般営業 | WF_02 |

> ダッシュボードは参照専用のため、エンドポイントは GET 1本のみ。

---

### GET /dashboard　クエリパラメータ（#1）

クエリパラメータなし。
集計対象はすべてログインユーザー（セッション）から自動的に決定する。

---

### GET /dashboard　Props（#1）

```jsonc
{
  // ① KPIサマリーバー
  "kpi": {
    // 提案可能人材
    // 大きい数字：自分担当（メイン・サブ含む）の提案可能人材数
    // engineers.status = 'proposable' かつ (main_user_id = 自分 OR sub_user_id = 自分) の件数
    "proposable_engineer_count": "int",       // 大きい数字（メイン・サブ含む合計）
                                              // フロント表示ラベル：「ステータス『提案可』の担当人数（メイン・サブ含む）」
    "proposable_engineer_count_total": "int", // 小さい数字（システム全体の提案可能人材数）
                                              // フロント表示ラベル：「全体 〇名」

    // 稼働中案件
    // 大きい数字：自分担当（メイン・サブ含む）の稼働中案件数
    // projects.status = 'open' かつ (main_user_id = 自分 OR sub_user_id = 自分) の件数
    "open_project_count": "int",              // 大きい数字（メイン・サブ含む合計）
                                              // フロント表示ラベル：「ステータス『募集中』の担当案件数（メイン・サブ含む）」
    "open_project_count_total": "int",        // 小さい数字（システム全体の稼働中案件数）
                                              // フロント表示ラベル：「全体 〇件」

    // 進行中カード総数
    // 自分担当人材のパイプラインのうち進行中12種に該当するものの件数
    // （engineers.main_user_id = 自分 OR engineers.sub_user_id = 自分）
    // AND pipelines.status IN (進行中12種)
    "active_pipeline_count": "int"            // 自分担当（メイン・サブ含む）の進行中パイプライン総数
  },

  // ② パイプライン進捗サマリー
  // 自分担当パイプラインを進行中ステータス別に集計
  // ステータスに1件もなくても全12種を返す（件数0で返す）
  "pipeline_summary": [
    {
      "status": "string",        // DB値（proposed / applying 等・進行中12種）
      "status_label": "string",  // 表示名（上位提案 / 応募中 等）
      "group": "string",         // カンバングループ（entry / first_interview / final_interview / offer）※旧 applying_before
      "count": "int",            // 件数
      "percentage": "int"        // 全進行中カード総数に対する割合（%・小数点切り捨て）
                                 // active_pipeline_count = 0 の場合は 0 を返す
    }
    // 進行中12種すべてを固定順で返す
  ],

  // ③ 近日アクション予定
  // 対象：自分担当人材（メイン・サブ含む）のパイプライン
  // ・next_action_date が今日から7日以内（土日含むカレンダー7日）のもの
  // ・next_action_date が今日より前（期限超過）のもの
  // 上記を合わせて日付昇順で返す
  // 該当なし（期限超過もなし）の場合は空配列を返す（フロントで「アクション予定なし」と表示）
  "upcoming_actions": [
    {
      "id": "int",                             // pipeline.id
      "next_action_date": "date(YYYY-MM-DD)",
      "is_overdue": "bool",                    // true = 期限超過（today > next_action_date）
                                               // フロントはtrueの場合、日付を赤字で表示すること
      "status": "string",                      // パイプラインのステータス値
      "status_label": "string",                // 表示名
      "engineer": {
        "id": "int",
        "name": "string"                       // 人材氏名
      },
      "project": {
        "id": "int",
        "name": "string",                      // 案件名
        "client_name": "string"                // 顧客名
      }
    }
  ]
}
```

> **実装注意（N+1対策）**：`upcoming_actions` の `engineer` / `project` を Eager Loading すること  
> **実装注意（KPI集計）**：KPIの3指標は個別クエリで取得してよい（3本のSQLをまとめるより可読性を優先）。件数が増えた場合はキャッシュ（Laravel Cache）の導入を検討すること  
> **実装注意（集計クエリ例）**：

```sql
-- KPI: 提案可能人材数（大きい数字）
SELECT COUNT(*) FROM engineers
WHERE status = 'proposable'
  AND (main_user_id = ? OR sub_user_id = ?)

-- KPI: 提案可能人材数（小さい数字・システム全体）
SELECT COUNT(*) FROM engineers
WHERE status = 'proposable'

-- KPI: 稼働中案件数（大きい数字）
SELECT COUNT(*) FROM projects
WHERE status = 'open'
  AND (main_user_id = ? OR sub_user_id = ?)

-- KPI: 稼働中案件数（小さい数字・システム全体）
SELECT COUNT(*) FROM projects
WHERE status = 'open'

-- 近日アクション予定（期限超過 + 7日以内）
SELECT * FROM pipelines
INNER JOIN engineers ON pipelines.engineer_id = engineers.id
WHERE (engineers.main_user_id = ? OR engineers.sub_user_id = ?)
  AND pipelines.next_action_date IS NOT NULL
  AND pipelines.next_action_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
ORDER BY pipelines.next_action_date ASC

-- パイプライン進捗サマリー
SELECT pipelines.status, COUNT(*) as count
FROM pipelines
INNER JOIN engineers ON pipelines.engineer_id = engineers.id
WHERE (engineers.main_user_id = ? OR engineers.sub_user_id = ?)
  AND pipelines.status IN (進行中12種)
GROUP BY pipelines.status
```

> **実装注意（is_overdue）**：`is_overdue` は Controller で `next_action_date < today` を判定してセットする。フロントはこのフラグを参照して日付を赤字表示すること  
> **実装注意（パーセンテージ）**：`percentage` は Controller で算出する。`active_pipeline_count` が 0 の場合はゼロ除算を防ぐため全ステータスを 0% で返すこと  
> **実装注意（インデックス）**：`engineers.sub_user_id` / `projects.sub_user_id` にインデックスあり（DB設計書 §5 確認済み）。OR 条件でのフィルタに対応済み  

---

## 2. バリデーション定義（ダッシュボード）

クエリパラメータが存在しないため、バリデーション定義なし。
`auth()->id()` でログインユーザーIDを取得し、全集計に使用する。未認証の場合は Laravel Breeze の認証ミドルウェアにより `/login` へリダイレクトされる。

> バリデーション定義は `バリデーション・エラー表示設計書.md` の「2. ダッシュボード」セクションを参照すること。

---

## 3. 他機能との関連

| セクション | 遷移先 | 遷移方法 |
|---|---|---|
| KPI「提案可能人材」 | `/engineers?status[]=proposable` | 件数クリックで人材一覧（ステータス「提案可」フィルタ適用済み）へ遷移 |
| KPI「稼働中案件」 | `/projects?status[]=open` | 件数クリックで案件一覧（ステータス「募集中」フィルタ適用済み）へ遷移 |
| KPI「進行中カード総数」 | `/pipelines` | 件数クリックで進捗管理画面（進行中タブ・自分担当フィルタ自動適用済み）へ遷移 |
| 近日アクション予定「進捗管理を見る →」 | `/pipelines` | リンク遷移（WF_02確定） |
| 近日アクション予定・各行 | リンク遷移なし | 行クリックでのドロワー展開はしない。「進捗管理を見る →」リンクから進捗管理画面へ遷移する運用 |

---

## 4. 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
| 1 | 各パイプラインステータスの業務定義 | QA #62 | 未着手。WF_02のパイプライン進捗サマリーのステータス行はすべてTBDラベル付き。確定後に本書のステータス一覧を更新すること |
