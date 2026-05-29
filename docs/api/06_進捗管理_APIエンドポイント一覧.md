# 進捗管理（Pipeline）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-05-27  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。

---

## 設計上の前提

### パイプラインの生成・削除ルール

| 項目 | 仕様 | 根拠 |
|---|---|---|
| 生成 | マッチング結果経由でのみ生成。手動追加不可 | QA #43確定 |
| 追加上限 | 1案件あたり上位5件まで | QA #50確定 |
| 削除 | 管理者のみ物理削除可。一般営業は削除不可 | QA #71確定 |
| 初期ステータス | `proposed`（上位提案） | QA #49確定 |

> パイプラインカードの **新規追加（POST）はマッチング画面から行う**ため、進捗管理画面のエンドポイントに POST は含めない。

### 担当営業の表示方針

パイプラインカードの担当営業は `engineers.main_user_id` を参照して表示する。`pipelines` テーブルへのFKカラム追加は不要（QA #83確定）。
初期表示はログインユーザーがメインまたはサブ担当の人材のカードのみ表示する（QA #70確定）。

### ステータス遷移ルール

- 進行中12種 → 任意の進行中ステータスへの変更は自由（前後スキップ・巻き戻し許可）（QA #4確定）
- 進行中 → 終了（4種）への遷移は**不可逆**。一度終了ステータスにしたカードは進行中へ戻せない（QA #64確定）
- アプリ層でガード処理を実装すること

### 各ステータスの業務定義

**進行中（12種）**（QA #2確定）

| DB値 | 表示名 | カンバングループ |
|---|---|---|
| `proposed` | 上位提案 | 応募前 |
| `applied_by_candidate` | 求職者応募済み | 応募前 |
| `applying` | 応募中 | 応募前 |
| `first_scheduling` | 一次調整中 | 一次選考 |
| `first_waiting` | 一次待ち | 一次選考 |
| `first_result_waiting` | 一次結果待ち | 一次選考 |
| `final_scheduling` | 最終調整中 | 最終選考 |
| `final_waiting` | 最終待ち | 最終選考 |
| `final_result_waiting` | 最終結果待ち | 最終選考 |
| `offered` | オファー | オファー |
| `assign_waiting` | アサイン承諾待ち | オファー |
| `contracted` | 成約 | オファー |

**終了（4種）**（QA #63確定）

| DB値 | 表示名 | 業務定義 |
|---|---|---|
| `rejected` | 不成立 | 選考落選 |
| `closed` | 募集終了 | 案件側クローズ（発注取消・充足） |
| `assign_declined` | アサイン辞退 | 成約後にエンジニアがアサインを辞退 |
| `declined` | 辞退 | 選考途中でエンジニアが辞退 |

> 各ステータスの詳細業務定義は QA #62 未着手・TBD。確定後に追記する。

---

## 1. APIエンドポイント一覧

> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` の前提セクションに準じる。

### エンドポイント一覧表

| # | メソッド | URL | Controller#Action | アクセス可能ロール | 対応WF |
|---|---|---|---|---|---|
| 1 | GET | /pipelines | PipelineController@index | 管理者 / 一般営業 | WF_10（進行中タブ） |
| 2 | GET | /pipelines/completed | PipelineController@completed | 管理者 / 一般営業 | WF_10（完了済みタブ） |
| 3 | GET | /pipelines/{id} | PipelineController@show | 管理者 / 一般営業 | WF_10（ドロワー） |
| 4 | PATCH | /pipelines/{id} | PipelineController@update | 管理者 / 一般営業 | WF_10（ドロワー） |
| 5 | DELETE | /pipelines/{id} | PipelineController@destroy | **管理者のみ** | WF_10 |

> **PATCHについて**：パイプラインの更新は全フィールド送信が前提ではなく、ドロワーで変更した項目のみ送信する部分更新のため、PUT ではなく PATCH を使用する。

---

### GET /pipelines　クエリパラメータ（#1・進行中タブ）

| パラメータ名 | 型 | 必須 | 説明 | 備考 |
|------------|---|:----:|------|------|
| keyword | string | 任意 | 人材名・案件名で部分一致検索 | |
| user_id | int | 任意 | 担当営業フィルタ | 未指定時：ログインユーザーがメイン・サブ担当の人材のカードを表示（QA #70確定）。指定時：指定ユーザーがメイン担当の人材のカードを表示 |
| rank | string[] | 任意 | マッチングランクフィルタ | A / B / C / D |
| status | string[] | 任意 | 進行中ステータスフィルタ | 進行中12種の値。終了ステータスは `/pipelines/completed` で取得 |
| sort | string | 任意 | ソート項目 | デフォルト：next_action_date。有効値：next_action_date / match_score / updated_at |
| order | string | 任意 | 並び順 | asc / desc（デフォルト：asc） |

> **初期表示フィルタ（QA #70確定）**：`user_id` 未指定時はログインユーザーの `id` を使い、`engineers.main_user_id = ? OR engineers.sub_user_id = ?` で絞り込む。  
> **ソートデフォルト**：次回アクション日（近い順）= `next_action_date ASC`。null のものは末尾に表示する。

---

### GET /pipelines　Props（#1・進行中タブ）

```jsonc
{
  // カンバン構造：4グループ固定
  "columns": [
    {
      "key": "string",    // applying_before | first_interview | final_interview | offer
      "label": "string",  // 応募前 | 一次選考 | 最終選考 | オファー
      "count": "int",     // グループ内のカード件数
      "cards": [
        {
          "id": "int",                            // pipeline.id
          "status": "string",                     // 現在のステータス値（DB値）
          "status_label": "string",               // 表示名（例：上位提案）
          "match_score": "int",                   // マッチングスコア（追加時スナップショット）
          "match_rank": "string",                 // A | B | C | D
          "next_action_date": "date(YYYY-MM-DD)", // 次回アクション予定日。null許容
          "updated_at": "datetime(ISO8601)",
          "engineer": {
            "id": "int",
            "name": "string",                     // 人材氏名
            "main_user": {
              "id": "int",
              "name": "string"                    // メイン担当営業（QA #83確定・サブは非表示）
            }
          },
          "project": {
            "id": "int",
            "name": "string",                     // 案件名
            "client_name": "string"               // 顧客名
          }
        }
      ]
    }
    // 以下 4グループ分繰り返し
  ],

  // 適用中フィルタ（状態復元用）
  "filters": {
    "keyword": "string",
    "user_id": "int",      // 担当営業フィルタ。未指定時はログインユーザーID
    "rank": ["string"],    // ランクフィルタ
    "status": ["string"],  // ステータスフィルタ
    "sort": "string",
    "order": "string"
  },

  // フィルタ選択肢
  "users": [
    { "id": "int", "name": "string" }  // 担当営業選択肢（全ユーザー）
  ],

  "ranks": [
    { "value": "string", "label": "string" }  // A | B | C | D（固定4値）
  ],

  // 進行中ステータス一覧（フィルタ選択肢用）
  "statuses": [
    {
      "value": "string",  // DB値
      "label": "string",  // 表示名
      "group": "string"   // カンバングループキー（applying_before / first_interview / final_interview / offer）
    }
  ]
}
```

> **実装注意（N+1対策）**：`engineer`・`engineer.mainUser`・`project` を Eager Loading すること  
> **実装注意（スコア）**：`match_score` / `match_rank` は `pipelines` テーブルの追加時スナップショット値を返す。リアルタイム再計算はしない（QA #45確定）  
> **実装注意（カンバングループ）**：DBのステータス値をカンバングループへのマッピングは以下の通り
> - `applying_before`：proposed / applied_by_candidate / applying
> - `first_interview`：first_scheduling / first_waiting / first_result_waiting
> - `final_interview`：final_scheduling / final_waiting / final_result_waiting
> - `offer`：offered / assign_waiting / contracted

---

### GET /pipelines/completed　クエリパラメータ（#2・完了済みタブ）

| パラメータ名 | 型 | 必須 | 説明 | 備考 |
|------------|---|:----:|------|------|
| keyword | string | 任意 | 人材名・案件名で部分一致検索 | |
| status | string[] | 任意 | 終了ステータスフィルタ | rejected / closed / assign_declined / declined |
| user_id | int | 任意 | 担当営業フィルタ | 未指定時は全員表示（WF_10の完了済みタブ仕様） |
| ended_from | date | 任意 | 日付範囲フィルタ（開始） | ⚠ 絞り込み対象はTBD#5。現在は `pipelines.updated_at` で絞り込む暫定仕様 |
| ended_to | date | 任意 | 日付範囲フィルタ（終了） | ⚠ 絞り込み対象はTBD#5。現在は `pipelines.updated_at` で絞り込む暫定仕様 |
| sort | string | 任意 | ソート項目 | デフォルト：updated_at。有効値：updated_at / match_score / engineer_name / project_name |
| order | string | 任意 | 並び順 | asc / desc（デフォルト：desc） |

> 完了済みタブは進行中タブと異なり担当フィルタの初期値が「全員」（WF_10注記より）

---

### GET /pipelines/completed　Props（#2・完了済みタブ）

```jsonc
{
  // 完了済みはカンバンではなくテーブル表示（WF_10確定）
  "pipelines": [
    {
      "id": "int",
      "status": "string",                   // 終了ステータス値（rejected / closed / assign_declined / declined）
      "status_label": "string",             // 表示名（不成立 / 募集終了 / アサイン辞退 / 辞退）
      "ng_reason": "string",                // NG理由・備考。null許容
      "updated_at": "datetime(ISO8601)",    // 終了日として表示（WF_10のテーブル列「終了日」）
                                            // ⚠ 終了専用の日付カラムはDBに存在しないため updated_at で代替（TBD#5）
      "engineer": {
        "id": "int",
        "name": "string",
        "main_user": {
          "id": "int",
          "name": "string"
        }
      },
      "project": {
        "id": "int",
        "name": "string",
        "client_name": "string"
      }
    }
  ],

  // 適用中フィルタ（状態復元用）
  "filters": {
    "keyword": "string",
    "status": ["string"],
    "user_id": "int",
    "ended_from": "date",  // ⚠ 絞り込み対象はTBD#5。現在は updated_at で絞り込む暫定仕様
    "ended_to": "date",    // ⚠ 絞り込み対象はTBD#5。現在は updated_at で絞り込む暫定仕様
    "sort": "string",      // デフォルト：updated_at（WF_10の「終了日（新しい順）」に対応）
    "order": "string"
  },

  // フィルタ選択肢
  "users": [
    { "id": "int", "name": "string" }
  ],

  // 終了ステータス一覧（フィルタ選択肢用）
  "statuses": [
    { "value": "string", "label": "string" }
  ]
}
```

> **実装注意（TEXT除外）**：`client_comment` / `ai_score_reason` / `ai_comment` / `ai_missing` はTEXTカラムのため完了済み一覧では取得しないこと  
> **実装注意（updated_at）**：WF_10の「終了日」列は `pipelines.updated_at` を表示する。終了専用の日付カラムはDBに存在しない（TBD#5）

---

### GET /pipelines/{id}　ドロワー用詳細Props（#3）

> WF_10のドロワーは画面遷移ではなくオーバーレイ表示だが、Inertia.js では `GET /pipelines/{id}` を呼び出してPropsを取得する。  
> 一覧PropsにTEXTカラム（`ai_score_reason` 等）を含めるとパフォーマンス劣化のリスクがあるため、ドロワー開封時に別途GETして取得する方針を推奨する（TBD#3）。  

```jsonc
{
  "pipeline": {
    "id": "int",
    "status": "string",                            // 現在のステータス値
    "status_label": "string",                      // 表示名（不成立 / 募集終了 / アサイン辞退 / 辞退）
    "match_score": "int",                          // マッチングスコア（追加時スナップショット）
    "match_rank": "string",                        // A | B | C | D
    "ai_score_reason": "string",                   // AIスコア算出理由（追加時スナップショット）
    "ai_comment": "string",                        // AI推薦コメント（追加時スナップショット）
    "ai_missing": "string",                        // 不足条件（追加時スナップショット）
    "client_comment": "string",                    // 顧客コメント。null許容
    "ng_reason": "string",                         // NG理由。null許容
    "next_action_date": "date(YYYY-MM-DD)",        // 次回アクション予定日。null許容
    "updated_at": "datetime(ISO8601)",
    "engineer": {
      "id": "int",
      "name": "string",
      "main_user": {
        "id": "int",
        "name": "string"                           // QA #83確定：ドロワーは参照表示のみ・変更は人材登録画面で行う
      }
    },
    "project": {
      "id": "int",
      "name": "string",
      "client_name": "string"
    }
  },

  // ステータス変更プルダウンの選択肢（進行中12種 + 終了4種）
  // 終了ステータスへの変更後は不可逆のため、フロントで警告ダイアログを表示すること
  "statuses": [
    {
      "value": "string",
      "label": "string",
      "group": "string",      // applying_before / first_interview / final_interview / offer / completed
      "is_terminal": "bool"   // true = 終了ステータス（不可逆）
    }
  ]
}
```

---

### PATCH /pipelines/{id}　送信データ（#4）

> ドロワーで変更した項目のみ送信する部分更新。全フィールド送信は不要。  
> `status` を終了ステータスに変更した場合、サーバー側でアプリ層ガード処理を行い、その後の進行中ステータスへの変更リクエストを拒否する（QA #64確定）。

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| status | string | 任意 | 進行中12種 + 終了4種の値。終了ステータスへの変更は不可逆（QA #64確定） |
| client_comment | string | 任意 | 顧客コメント（null許容） |
| ng_reason | string | 任意 | NG理由（null許容） |
| next_action_date | date | 任意 | 次回アクション予定日（null許容・QA #54確定） |

> **送信しないフィールド（変更不可）**
> - `engineer_id` / `project_id`：パイプライン生成後に変更不可
> - `match_score` / `match_rank` / `ai_score_reason` / `ai_comment` / `ai_missing`：追加時スナップショットのため変更不可
> - 担当営業：`engineers.main_user_id` を参照するため、パイプライン側で変更不可（QA #83確定）

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 200 OK。更新後のパイプライン情報を返す（Inertia リロード） |
| ステータス遷移ガード（終了→進行中への変更試行） | 422。エラーメッセージを返す |
| 権限不足時 | 403 |
| 対象データなし | 404 |

---

### DELETE /pipelines/{id}（#5）

#### アクセス可能ロール：管理者のみ（QA #71確定）

#### 挙動

| 条件 | 動作 |
|---|---|
| 管理者による実行 | 対象パイプラインを物理削除する。削除前にフロントエンドで確認ダイアログを表示すること |
| 一般営業による実行 | 403 を返す。WF_10 上「削除（管理者のみ）」ボタンは管理者のみ表示する |

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 302 リダイレクト。SharedPropsの `flash.success` を返す |
| 権限不足時 | 前画面へリダイレクトし SharedProps の `flash.error` を返す |
| 対象データなし | 404 を返す |

---

---

## 3. 他機能との関連

### マッチング画面との連携（POST /pipelines に相当する処理）

パイプラインの新規作成はマッチング画面からのみ行う（QA #43確定）。マッチング画面のエンドポイントで生成されるため、進捗管理のエンドポイントには POST を設けない。生成時に以下の値が `pipelines` テーブルに保存される。

| カラム | 値 |
|---|---|
| `engineer_id` | マッチング対象の人材ID |
| `project_id` | マッチング対象の案件ID |
| `status` | `proposed`（固定・QA #49確定） |
| `match_score` | AI総合判定スコア（0〜100）のスナップショット |
| `match_rank` | A / B / C / D のスナップショット |
| `ai_score_reason` | AIスコア算出理由テキストのスナップショット |
| `ai_comment` | AI推薦コメントのスナップショット |
| `ai_missing` | 不足条件テキストのスナップショット |

### 人材・案件との参照関係

パイプライン削除時の CASCADE 挙動：

| 操作 | pipelines への影響 |
|---|---|
| `engineers` の物理削除 | `ON DELETE CASCADE` により関連パイプラインも全件削除 |
| `projects` の物理削除 | `ON DELETE CASCADE` により関連パイプラインも全件削除 |

---

## 4. 未確定事項（TBD）

| # | 項目 | QA# | 理由 |
|---|------|-----|------|
| 1 | 各ステータスの業務定義 | QA #62 | 未着手。確定後にステータス一覧表に追記する |
| 2 | `client_comment` / `ng_reason` の文字数上限 | - | DBがTEXT型のためDB上限なし。バリデーション上の制限値を設けるか確認が必要 |
| 3 | ドロワーの実装方式（GET /pipelines/{id} の要否） | - | Inertia.js でドロワーを実装する場合、別途GETエンドポイントが必要かフロント側で一覧データを使い回すかは実装判断に委ねる。一覧PropsにTEXTカラム（ai_score_reason等）を含めるとパフォーマンス劣化のリスクがあるため、別途GETを推奨する |
| 4 | 完了済みタブの件数上限・ページネーション要否 | - | WF_10に件数表示「14件」があるが、ページネーションの記載なし。件数が増加した場合の対応を確認すること |
| 5 | 完了済みタブの日付範囲フィルタの絞り込み対象 | - | WF_10の完了済みタブに日付〜日付の入力枠があるが、何の日付で絞り込むか（終了日 / 参画開始時期 / 追加日等）がQA・DB設計書に明記されておらず未確定。**現在は「終了日 = `pipelines.updated_at`」として絞り込む仕様を暫定で記載している**（`ended_from` / `ended_to` パラメータ・`updated_at` での絞り込み）。確定後に本書のクエリパラメータ定義・バリデーション・Props定義・実装コメントを更新すること |