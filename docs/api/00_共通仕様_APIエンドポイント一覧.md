# 共通仕様 APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> ルーティング定義：`routes/web.php`  
> 認証方式：Laravel Breeze（セッション認証）  
> 最終更新：2026-08-17  

---

## 前提

- **認証**：Laravel Breezeを使用。`/login` / `/logout` はBreeze自動生成のため本一覧に記載しない。`/register` / `/forgot-password` / `/reset-password` は無効化する
- **認可（ロール別制御）**：ロールは **管理者 / 一般営業** の2階層（QA #17確定）。詳細な操作権限は権限・ロール設計書に記載する
- **バリデーション**：各フィールドの詳細ルールは「バリデーション・エラー表示設計書」を参照すること
- **必須/任意の制御**：システム固定必須（DBレベルでNOT NULL）は `name` / `name_kana` / `status` / `main_user_id` のみ。それ以外のフィールドはDBレベルでNULL許容であり、必須/任意の制御は `form_field_settings` テーブルをアプリ層で参照して行う（DB設計書 §1-2 / QA #65確定）
- **SharedProps**：全ページに `HandleInertiaRequests` ミドルウェアで以下を共有する。詳細は権限・ロール設計書を参照すること
  - `auth.user`：ログインユーザー情報（id / name / role）
  - `flash.success`：成功フラッシュメッセージ（`string | null`）
  - `flash.error`：エラーフラッシュメッセージ（`string | null`）
  - ※ `fieldSettings` は登録・編集画面の Props に個別に含める。SharedProps には含めない。
- **CSRFトークン**：Inertia.js + Laravel Breeze の組み合わせにより、POST / PUT / DELETE リクエスト時のCSRFトークンは自動付与される。フロントエンド側での明示的な送信処理は不要

---

## 凡例

| 記号 | 意味 |
|------|------|
| クエリパラメータ | GETリクエスト時にURLに付与してサーバーへ送るデータ（検索条件・ページング・ソート情報） |
| Props | GETリクエスト時にControllerからReactコンポーネントへ渡すデータ。**本書ではJSONツリー形式（jsonc）で表記する** |
| 送信データ | POST/PUTリクエスト時にフロントから送るデータ |
| ルート定義順 | Laravelのルーティング定義順を表す。静的ルート（create等）は動的ルート（{id}）より前に定義する |

### Propsのツリー記法について

Propsの構造はJSONツリー形式で記述する。型は値の位置に文字列で示し、説明はインラインコメントで付与する。

| 型表記 | 意味 |
|---|---|
| `"int"` | 整数 |
| `"string"` | 文字列 |
| `"bool"` | 真偽値 |
| `"date(YYYY-MM-DD)"` | 日付文字列 |
| `"datetime(ISO8601)"` | 日時文字列 |
| `[{ ... }]` | オブジェクトの配列 |

> **Propsのnull許容**：システム固定必須（`name` / `name_kana` / `status` / `main_user_id`）以外のフィールドはnullを返す場合がある。各フィールドの型表記では `| null` を省略する。

---

## 詳細ブロックの構成ルール

```
### GET /xxx
クエリパラメータ → 表形式（パラメータ名 / 型 / 必須 / 説明 / 備考）
Props           → JSONツリー形式（jsonc）

### POST・PUT /xxx
送信データ       → 表形式（フィールド名 / 型 / 必須 / 備考）
```

### 共通HTTPレスポンス

全エンドポイントで共通のレスポンスパターン。エンドポイント固有の挙動がある場合は各セクションに追記する。

| ステータス | 発生ケース |
|---|---|
| `200 OK` | GET 正常取得・PUT 正常更新 |
| `201 Created` | POST 正常作成 |
| `302 Found` | GET リクエスト後の Inertia リダイレクト |
| `303 See Other` | POST・PUT・DELETE 後の Inertia リダイレクト。Inertiaは非GET（PUT/PATCH/DELETE）への302リダイレクトを追えない（ブラウザがメソッドを保持したまま追ってしまう）ため、非安全メソッドへの応答は302ではなく303を返す（issue #44 / #63） |
| `401 Unauthorized` | 未ログイン状態でのアクセス。JSONを期待する非Inertiaリクエスト（APIコール等）の場合 |
| `403 Forbidden` | ロール・所有権チェックで弾かれた場合（例：一般営業がマスタ管理にアクセスした場合、他ユーザーの保存済み検索条件を削除しようとした場合）。人材・案件・パイプラインの削除は権限不足時に403を素で返さず、前画面へ戻し `flash.error` を返す（issue #65） |
| `404 Not Found` | 存在しない ID を指定 |
| `409 Conflict` | 未ログイン状態でのInertiaリクエスト。ログイン画面への強制的なフルページ遷移を指示する `X-Inertia-Location` ヘッダーを付与する（`UnauthenticatedInertiaRedirector`、issue #63） |
| `419 Page Expired` | CSRFトークン不一致（主にセッション切れ後の再送信）。Inertiaリクエストの場合は元の画面へ303リダイレクトし、「セッションの有効期限が切れました。もう一度お試しください。」のフラッシュメッセージを表示する（`TokenMismatchInertiaRedirector`、issue #63） |
| `422 Unprocessable Entity` | バリデーションエラー。詳細形式はバリデーション・エラー表示設計書を参照 |

> **【2026-08-17 追加】未ログイン・セッション切れ時のInertia向けエラーハンドリングを追加（issue #63）**
> **背景：** auth ミドルウェア（`AuthenticationException`）・CSRF検証（`TokenMismatchException`）はいずれも `HandleInertiaRequests`（Inertia\Middleware）の後処理より前で例外を投げるため、Inertiaが標準で行う「非GETへの302→303書き換え」を経由しない。302のまま返すと、DELETE/PUT/PATCHのようなリクエストがメソッドを保持したままログイン画面等を再度叩いてしまい、`405 MethodNotAllowedHttpException` になる不具合があった。
> **対応：** `bootstrap/app.php` に例外ごとの `render()` ハンドラを追加し、Inertiaリクエストかどうかで応答を出し分ける（非Inertiaは従来どおりLaravel標準の挙動）。未ログイン（401/302だったもの）は409 + `X-Inertia-Location` でフルページ遷移、CSRFトークン切れ（419）は303 + `back()` で元画面へ戻す。実装は `app/Exceptions/UnauthenticatedInertiaRedirector.php` / `app/Exceptions/TokenMismatchInertiaRedirector.php`。既存の `StaleResourceRedirector`（issue #44）と同じ「例外種別ごとに1クラスへ委譲する」構成に揃えている。
