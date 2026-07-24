# マスタ管理（Master）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-06-10  
> 前提・凡例・SharedProps・共通HTTPレスポンスは `00_共通仕様_APIエンドポイント一覧.md` を参照すること。  

---

## 設計上の前提

### タブ構成（v2.0確定）

| タブ | 内容 | 変更履歴 |
|---|---|---|
| ユーザー管理 | 社内営業担当者のアカウントCRUD | デフォルト表示タブ |
| フォーム設定 | 登録フォームの必須/任意トグル | QA #65・#82確定 |

### アクセス権限

| 機能 | アクセス可能ロール | 根拠 |
|---|---|---|
| マスタ管理画面の閲覧 | **管理者のみ** | QA #16確定 |
| ユーザーの追加・編集・削除 | **管理者のみ** | QA #16確定 |
| フォーム設定の変更 | **管理者のみ** | QA #65確定 |

> すべてのエンドポイントに管理者ロールチェックのミドルウェアを適用すること。一般営業がアクセスした場合は 403 を返す。

### ユーザー管理の確定事項

| 項目 | 仕様 | 根拠 |
|---|---|---|
| 登録項目 | 氏名・メールアドレス・パスワード・ロールの4項目 | QA #18確定 |
| ログインID | 社内メールアドレスのみ。社外メール不可 | QA #19確定 |
| ドメインチェック | マスタ登録時とログイン時の両方でドメイン制限を行う（二重チェック・暫定）。許容ドメインが確定次第 `ends_with` バリデーションを追加すること | QA #19確定 / TBD#3 |
| ロール | 管理者（admin）/ 一般（general）の2階層 | QA #17確定 |
| 氏名 | 必須。担当営業名として各画面に表示 | QA #68確定 |
| パスワードリセット | 管理者が手動再設定・本人へ通知する運用 | QA #20確定 |
| 最終ログイン日時 | `users.last_login_at`（DATETIME NULL）カラムを追加済み。ログインイベント（`Login`）のリスナーで更新する | 設計確定 |
| 無効化 | ステータス列なし。ユーザーの無効化は削除で運用 | WF_12確定 |
| 削除制約 | 担当中の人材・案件が1件でも残っている場合は削除不可 | DB設計書 §7確定 |
| 物理削除 | 論理削除なし。物理削除のみ | DB設計書 §1-5確定 |

---

## 1. APIエンドポイント一覧

### エンドポイント一覧表

| # | メソッド | URL | Controller#Action | アクセス可能ロール |
|---|---|---|---|---|
| 1 | GET | /master | MasterController@index | **管理者のみ** |
| 2 | POST | /master/users | UserController@store | **管理者のみ** |
| 3 | PUT | /master/users/{id} | UserController@update | **管理者のみ** |
| 4 | DELETE | /master/users/{id} | UserController@destroy | **管理者のみ** |
| 5 | PUT | /master/form-settings | FormSettingController@update | **管理者のみ** |

> **GETエンドポイントについて**：ユーザー一覧・フォーム設定一覧はどちらも `/master` の1リクエストでまとめて返す（WF_12がタブ切り替えUIのため、初期ロード時に両方のデータを取得しておくのが自然）。  
> **ユーザーの個別GET（`GET /master/users/{id}`）**：WF_12は編集もモーダル内で完結するため、個別取得エンドポイントは設けない。編集時は一覧Props のデータを使い回す。  
> **`POST /master/users/reset-password`**：パスワードリセットは管理者の手動運用（QA #20確定）のため、専用APIは設けない。編集（PUT）でパスワードを上書きすることで対応する。

---

### GET /master　Props（#1）

```jsonc
{
  // ユーザー一覧
  "users": [
    {
      "id": "int",
      "name": "string",                    // 氏名
      "email": "string",                   // メールアドレス（ログインID）
      "role": "string",                    // admin | general
      "role_label": "string",              // 表示名（管理者 | 一般）
      "last_login_at": "datetime(ISO8601)" // 最終ログイン日時。未ログインはnull
                                           // users.last_login_at カラムから取得
                                           // ログインイベント（Login）のリスナーで更新
    }
  ],

  // フォーム設定一覧（engineer・project 両方を返す）
  "form_settings": {
    "engineer": [
      {
        "field_key": "string",         // フィールドキー（birth_date 等）
                                       // ※ キー名は form_field_settings テーブルの field_key カラムと1対1で対応する
        "field_label": "string",       // 表示名（年齢（生年月日） 等）
        "is_required": "bool",         // 現在の必須/任意設定
        "is_system_required": "bool"   // true = システム固定必須（管理者も変更不可）
      }
      // 全フィールド分返す
    ],
    "project": [
      {
        "field_key": "string",
        "field_label": "string",
        "is_required": "bool",
        "is_system_required": "bool"
      }
    ]
  }
}
```

---

### POST /master/users　送信データ（#2）

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| name | string | ✓ | 氏名（QA #68確定）。担当営業名として各画面に表示 |
| email | string | ✓ | 社内メールアドレスのみ（QA #19確定）。ログインIDとして使用。一意制約あり。ドメインチェックあり（暫定・TBD#3） |
| password | string | ✓ | 初期パスワード |
| password_confirmation | string | ✓ | パスワード確認入力 |
| role | string | ✓ | admin / general（QA #17確定） |

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 302 リダイレクト。`flash.success` を返す |
| メールアドレス重複 | 422。エラーメッセージ：「このメールアドレスはすでに使用されています」 |
| バリデーションエラー | 422。各フィールドのエラーメッセージを返す |

---

### PUT /master/users/{id}　送信データ（#3）

> 編集モーダルでパスワードを変更しない場合は `password` / `password_confirmation` を省略可能。  
> パスワードリセット（管理者による再設定）もこのエンドポイントで対応する（QA #20確定）。

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| name | string | ✓ | 氏名 |
| email | string | ✓ | 社内メールアドレス。自分以外で重複不可。ドメインチェックあり（暫定・TBD#3） |
| password | string | 任意 | 変更する場合のみ送信。省略時はパスワード変更なし |
| password_confirmation | string | 任意 | `password` 送信時のみ必須 |
| role | string | ✓ | admin / general |

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 302 リダイレクト。`flash.success` を返す |
| メールアドレス重複（他ユーザー） | 422。エラーメッセージ：「このメールアドレスはすでに使用されています」 |
| 対象ユーザーなし | 404 |

---

### DELETE /master/users/{id}（#4）

#### 挙動

| 条件 | 動作 |
|---|---|
| 削除対象ユーザーが**主担当（`main_user_id`）**として人材・案件を1件以上保持 | 削除不可。422を返す。エラーメッセージ：「担当中の案件が〇件、人材が〇件あるため削除できません。一覧画面から別の担当者へ変更してから再度実行してください。」（DB設計書 §7確定） |
| 主担当としての人材・案件が0件 | 物理削除を実行する。**副担当（`sub_user_id`）**の参照は `ON DELETE SET NULL` で自動的にNULLになる（DB設計書 §7確定） |
| 自分自身を削除しようとした場合 | 422を返す。エラーメッセージ：「自分自身のアカウントは削除できません」（TBD#1） |
| 最後の管理者を削除しようとした場合 | 422を返す。エラーメッセージ：「管理者が不在になるため、最後の管理者は削除できません」（TBD#4） |
| 対象ユーザーなし | 404 |

> **主担当＝RESTRICT / 副担当＝SET NULL（重要）**：`engineers` / `projects` の `main_user_id` は FK `RESTRICT`
> のため、主担当が残っているユーザーは DB レベルで削除できない。アプリ側で事前に主担当件数を COUNT して
> 422 の親切なメッセージで弾く（COUNT→DELETE 間に担当が付いた場合は FK 例外を捕捉して 422 に変換）。
> `sub_user_id` は `SET NULL` のため削除ガードの対象外（削除時に自動で NULL 化される）。

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 302 リダイレクト。`flash.success` を返す |
| 削除不可（担当中） | 422。`flash.error` に件数付きメッセージを返す |

---

### PUT /master/form-settings　送信データ（#5）

> フォーム設定タブで変更したトグルを一括保存する。変更はシステム全体に即時反映される（WF_12確定）。  
> `is_system_required = true` のフィールド（氏名/カナ・案件名・ステータス・担当営業）は送信されても変更を無視する。Controller側でガード処理を実装すること。

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| settings | array | ✓ | 変更するフィールド設定の配列 |
| settings[].form_type | string | ✓ | engineer / project |
| settings[].field_key | string | ✓ | 変更対象フィールドキー（form_field_settings.field_key と一致すること） |
| settings[].is_required | bool | ✓ | true=必須 / false=任意 |

> **一括送信について**：変更のあったフィールドのみ送信するか、全フィールドを常に送信するかはフロント実装に委ねる。Controller側では送信されたフィールドのみ更新する。  
> **`updated_by` の記録**：更新時はセッションのログインユーザーIDを `updated_by` にセットする。フロントから送信しない。

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 302 リダイレクト。`flash.success` を返す |
| システム固定必須フィールドへの変更試行 | 当該フィールドへの変更を無視して他フィールドは正常処理する（エラーにしない） |
| 存在しない `field_key` | 422。エラーメッセージを返す |

---

## 2. フォーム設定のフィールドキー一覧

`form_field_settings` テーブルの `field_key` 値とWF_12表示名の対応。バリデーション・シードデータの参照として使用する。

> **キー名の統一方針**：`fieldSettings` の各キーは form_field_settings テーブルの `field_key` カラムと1対1で対応する。人材管理・案件管理の API 定義書の `fieldSettings` と完全に一致させること。

### 人材登録フォーム（form_type = engineer）

| field_key | 表示名 | is_system_required | WF_12初期値 |
|---|---|:---:|:---:|
| name | 氏名 | true（変更不可） | 必須（固定） |
| name_kana | 氏名カナ | true（変更不可） | 必須（固定） |
| status | ステータス | true（変更不可） | 必須（固定） |
| main_user_id | 担当営業 | true（変更不可） | 必須（固定） |
| birth_date | 年齢（生年月日） | false | 必須 |
| nearest_station | 最寄駅 | false | 必須 |
| nearest_line | 路線 | false | 必須 |
| available_from | 稼働可能時期 | false | 必須 |
| skills | 経験スキル | false | 必須 |
| proc_experience | 経験工程 | false | 必須 |
| has_negotiation_exp | 顧客折衝経験 | false | 必須 |
| appeal_note | アピールポイント | false | 任意 |
| desired_rate | 希望単価 | false | 任意 |
| work_styles | 勤務形態タグ | false | 任意 |
| remarks | 特記事項 | false | 任意 |

> **`name` / `name_kana` / `status` / `main_user_id` は `is_system_required = true`** のため管理者もトグル変更不可。  
> **「職務経歴ファイル」**：WF_12のフォーム設定に表示されているが、DB設計書で「職務経歴ファイルパスはカラムとして持たない」と確定済み（WF_04 v2.0・前田様確認）。`field_key` は設けない。シードデータに含めないこと。

### 案件登録フォーム（form_type = project）

| field_key | 表示名 | is_system_required | WF_12初期値 |
|---|---|:---:|:---:|
| name | 案件名 | true（変更不可） | 必須（固定） |
| status | ステータス | true（変更不可） | 必須（固定） |
| main_user_id | 担当営業 | true（変更不可） | 必須（固定） |
| client_name | 顧客名 | false | 任意 |
| required_skills | 必須スキル | false | 必須 |
| preferred_skills | 尚可スキル | false | 任意 |
| rate | 単価（下限〜上限） | false | 必須 |
| start_date | 参画開始時期 | false | 必須 |
| work_style | 稼働形態 | false | 必須 |
| work_location | 勤務地 | false | 必須 |
| commercial_flow | 商流 | false | 必須 |
| interview_count | 面談回数 | false | 任意 |
| headcount | 募集人数 | false | 任意 |
| work_env | 稼働環境 | false | 任意 |
| billing_range | 精算幅 | false | 任意 |
| proc_experience | 対象工程 | false | 必須 |
| negotiation_required | 顧客折衝経験 | false | 必須 |
| description | 業務内容詳細 | false | 任意 |
| remarks | 特記事項 | false | 任意 |

---

## 3. 確定事項（旧 TBD・2026-07-23 確定）

| # | 項目 | 確定内容 |
|---|------|------|
| 1 | 自分自身の削除防止 | **削除不可・422**。「自分自身のアカウントは削除できません」 |
| 2 | パスワードの要件 | **`Password::min(8)->letters()->numbers()`**（8文字以上＋英字・数字）。`AppServiceProvider::boot()` の `Password::defaults()` に集約し、FormRequest から参照 |
| 3 | 社内メールアドレスのドメイン | **環境変数 `ALLOWED_EMAIL_DOMAINS`（カンマ区切り・複数可）→ `config/master.php`** で保持し、`ends_with:@domain1,@domain2,...` で検証。未設定時はドメイン制限なし（形式チェックのみ）。ハードコードしない |
| 4 | 最後の管理者のロール変更・削除 | **ガードあり・422**。最後の admin を general 化／削除しようとするとエラー（更新は `lockForUpdate` で並行時も再検査） |
