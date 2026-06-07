# 共通仕様 APIエンドポイント一覧

> 技術方針：Laravel + Inertia.js + React  
> ルーティング定義：`routes/web.php`  
> 認証方式：Laravel Breeze（セッション認証）  
> 最終更新：2026-05-27  

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
| `302 Found` | POST・PUT・DELETE 後の Inertia リダイレクト |
| `403 Forbidden` | ロール権限不足（例：一般営業が DELETE を呼んだ場合） |
| `404 Not Found` | 存在しない ID を指定 |
| `422 Unprocessable Entity` | バリデーションエラー。詳細形式はバリデーション・エラー表示設計書を参照 |
