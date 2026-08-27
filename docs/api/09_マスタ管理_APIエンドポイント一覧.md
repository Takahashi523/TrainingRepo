# マスタ管理（Master）APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> 最終更新：2026-07-29  
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
| role | string | ✓ | admin / general。**自分自身のロールは変更不可**（自己降格で保存後の再取得が 403 になる事故を防ぐ。確定事項 #5・下記レスポンス参照） |

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 302 リダイレクト。`flash.success` を返す |
| メールアドレス重複（他ユーザー） | 422。エラーメッセージ：「このメールアドレスはすでに使用されています」 |
| 自分自身のロールを変更しようとした場合 | 422。エラーメッセージ：「自分自身のロールは変更できません」（確定事項 #5）。氏名・メール・パスワード等の他項目は自分自身でも編集可 |
| 最後の管理者を general 化しようとした場合 | 422。エラーメッセージ：「管理者が不在になるため、最後の管理者を一般に変更できません」（確定事項 #4） |
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

> フォーム設定タブは保存ボタンを持たず、**トグル変更のたびに即時反映**する（変更はシステム全体に即時適用・WF_12確定）。  
> `is_system_required = true` のフィールド（氏名/カナ・案件名・ステータス・担当営業）は送信されても変更を無視する。Controller側でガード処理を実装すること。

| フィールド名 | 型 | 必須 | 備考 |
|------------|---|:----:|------|
| settings | array | ✓ | 変更するフィールド設定の配列 |
| settings[].form_type | string | ✓ | engineer / project |
| settings[].field_key | string | ✓ | 変更対象フィールドキー（form_field_settings.field_key と一致すること） |
| settings[].is_required | bool | ✓ | true=必須 / false=任意 |

> **送信単位について（実装）**：WF_12 は保存ボタンを持たず**即時反映**のため、フロントは**トグル変更のあった1件**を
> `settings[]`（1要素）で送信する。エンドポイント仕様としては 1〜N 件を受け付ける（Controller は送信されたフィールドのみ更新）。  
> **`updated_by` の記録**：更新時はセッションのログインユーザーIDを `updated_by` にセットする。フロントから送信しない。

#### レスポンス

| 条件 | 動作 |
|---|---|
| 成功時 | 302 リダイレクト。**フラッシュ（トースト）は返さない**。即時反映トグルのため、成功は画面側の**行内フィードバック**（「✓保存」を短時間表示）で通知する（連続トグルでトーストが頻発するのを避けるため）。`preserveState`/`preserveScroll` で同画面に留まる |
| システム固定必須フィールドへの変更試行 | 当該フィールドへの変更を無視して他フィールドは正常処理する（エラーにしない） |
| 存在しない `field_key` | 422。エラーメッセージを返す（画面側は destructive トーストで通知） |

> **成功時にフラッシュを返さない点の補足**：他のエンドポイント（ユーザー CRUD）は成功時に `flash.success` を返すが、
> フォーム設定トグルのみ**即時反映 UX のためフラッシュを出さない**方針とした（乖離理由は
> `.steering/20260723-add-master-management/reason.md` に記録）。

---

## 2. フォーム設定のフィールドキー一覧

`form_field_settings` テーブルの `field_key` 値とWF_12表示名の対応。バリデーション・シードデータの参照として使用する。

> **【2026-08-26 追記】この表の行順は画面の表示順ではない**（`is_system_required` のものを先頭にまとめた読みやすさ優先の並び）。  
> **画面（マスタ管理のフォーム設定一覧）の表示順は `FormFieldSetting::FIELD_LABELS` の定義順が SSOT** で、`MasterController::orderedSettings()` が `displayOrder()` でその順に並べ替えて `form_settings.*` を返す。  
> **背景：** 案件側の並びが登録フォームと無関係な順序で、設定したい項目をフォームと同じ順に辿れなかった（issue #43）。  
> **理由：** 表示順を定数1箇所に集約したまま、その定数を登録フォームのセクション順（基本情報 → 契約条件 → 勤務条件 → スキル要件 → 管理情報）に並べ替えることで、この一覧表の記述形式を変えずに画面の順序整合を取れるため。順序の固定は `MasterFormSettingControllerTest` が担保する。

> **【2026-08-26 修正】表示名を `FormFieldSetting::FIELD_LABELS` に合わせた（PR #96 レビュー指摘）**
> **背景：** この表の表示名のうち 4 件（`birth_date`「年齢（生年月日）」/ `desired_rate`「希望単価」/ `work_styles`「勤務形態タグ」/ `rate`「単価（下限〜上限）」）が、実装と WF_12 の表示名（順に「生年月日」「希望単価（月額）」「勤務形態」「単価（月額）」）とずれたまま残っていた。
> **理由：** 表示名の SSOT は `FormFieldSetting::FIELD_LABELS` であり、この表はその対応を読むためのものなので、ずれていると「どちらが画面に出る名前か」を判断できない。**`field_key`・`is_system_required`・WF_12初期値は変更していない**（表示名の表記のみ）。

> **キー名の統一方針**：`fieldSettings` の各キーは form_field_settings テーブルの `field_key` カラムと1対1で対応する。人材管理・案件管理の API 定義書の `fieldSettings` と完全に一致させること。

### 人材登録フォーム（form_type = engineer）

| field_key | 表示名 | is_system_required | WF_12初期値 |
|---|---|:---:|:---:|
| name | 氏名 | true（変更不可） | 必須（固定） |
| name_kana | 氏名カナ | true（変更不可） | 必須（固定） |
| status | ステータス | true（変更不可） | 必須（固定） |
| main_user_id | 担当営業 | true（変更不可） | 必須（固定） |
| birth_date | 生年月日 | false | 必須 |
| nearest_station | 最寄駅 | false | 必須 |
| nearest_line | 路線 | false | 必須 |
| available_from | 稼働可能時期 | false | 必須 |
| skills | 経験スキル | false | 必須 |
| proc_experience | 経験工程 | false | 必須 |
| has_negotiation_exp | 顧客折衝経験 | false | 必須 |
| appeal_note | アピールポイント | false | 任意 |
| desired_rate | 希望単価（月額） | false | 任意 |
| work_styles | 勤務形態 | false | 任意 |
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
| rate | 単価（月額） | false | 必須 |
| start_date | 参画開始時期 | false | 必須 |
| work_style | 稼働形態 | false | 必須 |
| work_location | 勤務地（路線名） | false | 必須 |
| commercial_flow | 商流 | false | 必須 |
| interview_count | 面談回数 | false | 任意 |
| headcount | 募集人数 | false | 任意 |
| work_env | 稼働環境 | false | 任意 |
| billing_range | 精算幅 | false | 任意 |
| proc_experience | 対象工程 | false | 必須 |
| negotiation_required | 顧客折衝経験 | false | 必須 |
| description | 業務内容詳細 | false | 必須 |
| remarks | 特記事項 | false | 任意 |

> **`work_location`（勤務地）ラベルについて**
> 案件フォームの勤務地は「路線名（`work_location_line`）」と「最寄駅（`work_location_station`）」の 2 入力に分かれるが、
> `form_field_settings` の `work_location` トグルが制御するのは **路線名の必須/任意のみ**。
> 最寄駅は稼働形態が常駐/一部リモートのとき**常に必須**（業務ルール固定・マスタ設定の対象外、`ProjectRequest` 参照）。
> このため必須トグルの実効範囲を正確に示す目的でラベルを「**勤務地（路線名）**」としている。
>
> **UI側の表示（Issue #50 対応・確定）**：案件登録・編集フォーム（`ProjectForm.tsx`）では「勤務地（最寄駅）」「勤務地（路線名）」を
> 別行・別バッジで表示する。最寄駅側のバッジは常時「必須」固定、路線名側のバッジはこの`work_location`トグルの値をそのまま反映する。
> 旧実装では1行に統合し路線名側のバッジのみを表示していたため、`work_location`を任意にすると最寄駅の実際の必須条件と
> バッジ表示が矛盾していた（WF_07参照）。

---

## 3. 確定事項（旧 TBD・2026-07-23 確定）

| # | 項目 | 確定内容 |
|---|------|------|
| 1 | 自分自身の削除防止 | **削除不可・422**。「自分自身のアカウントは削除できません」 |
| 2 | パスワードの要件 | **`Password::min(8)->letters()->numbers()`**（8文字以上＋英字・数字）。`AppServiceProvider::boot()` の `Password::defaults()` に集約し、FormRequest から参照 |
| 3 | 社内メールアドレスのドメイン | **環境変数 `ALLOWED_EMAIL_DOMAINS`（カンマ区切り・複数可）→ `config/organization.php`** で保持し、`ends_with:@domain1,@domain2,...` で検証。未設定時はドメイン制限なし（形式チェックのみ）。ハードコードしない。組織横断のアカウント方針のため、マスタ管理専用ではなく組織方針 config に配置（今後のログイン/認証からも参照） |
| 4 | 最後の管理者のロール変更・削除 | **ガードあり・422**。最後の admin を general 化／削除しようとするとエラー（更新は `lockForUpdate` で並行時も再検査） |
| 5 | 自分自身のロール変更防止 | **変更不可・422**。「自分自身のロールは変更できません」。氏名・メール・パスワード等の他項目は自己編集可。既存の自己削除防止（#1）と対称のガード |

> **【2026-07-29 確定】自分自身のロール変更を禁止（確定事項 #5）**
> **背景：** 設計書・QA 未確定のまま実装されており、管理者が自分のロールを admin→general に下げると DB 更新自体は成功するが、保存直後に Inertia が自動再取得する `GET /master` が admin ミドルウェア（`EnsureUserIsAdmin`）で 403 になり、成功しているのに「失敗した」ように見える不具合が発生していた（PR #51 レビュー指摘）。
> **理由：** 少数の管理者で運用する社内ツールであり、自己降格を許可する実務上の必要性が薄い一方、上記の事故は管理者が2名以上いれば誰でも容易に踏める。既存の自己削除防止ガード（#1）と思想を揃え、`UpdateUserRequest` で「自分自身かつロールを変更した場合のみ 422」で弾く方針（A案）を採用。フロントでも自己編集時はロール選択を無効化し、エラーの往復を防ぐ。ロールを変えない限り自己編集（氏名・メール・パスワード）は妨げない。
