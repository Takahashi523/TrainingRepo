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
| `403 Forbidden` | ①ルート単位のアクセス制御（例：一般営業がマスタ管理にアクセスした場合。`EnsureUserIsAdmin` ミドルウェアの `abort(403)`）→ 案内ページ（`ErrorPageResponder`、issue #70 / PR #77）を表示する。②人材・案件・パイプライン・保存済み検索条件の削除で、操作対象への所有権・権限が無い場合 → 403を素で返さず、前画面へ戻し `flash.error` を返す（issue #65 / #94）。**切り分け基準**：そもそもその画面へ遷移させるべきでないルート単位の制御は①（案内ページ）、ユーザーがすでに見えている画面上のアクション（削除ボタン等）に対する認可失敗は②（`back()` + `flash.error` で文脈を保つ）とする |
| `404 Not Found` | 存在しない ID を指定 |
| `409 Conflict` | ①未ログイン状態でのInertiaリクエスト。ログイン画面への強制的なフルページ遷移を指示する `X-Inertia-Location` ヘッダーを付与する（`UnauthenticatedInertiaRedirector`、issue #63）。②Inertiaのアセットバージョン不一致時（`HandleInertiaRequests::version()`由来、フロント資材更新後の強制リロード）にも同じ形（409 + `X-Inertia-Location`）で返る。「未ログイン時のみ」ではない点に注意 |
| `419 Page Expired` | CSRFトークン不一致。ただしLaravel 13の`PreventRequestForgery`は`Sec-Fetch-Site: same-origin`をトークン検証より先に通すため、**同一オリジンのブラウザ操作では通常発生しない**（画面操作でのセッション期限切れは`UnauthenticatedInertiaRedirector`による409 + `X-Inertia-Location`でのログイン画面遷移経由。issue #63）。到達するのは主にクロスサイト送信・`Sec-Fetch-Site`を送らないクライアント向け。issue #70（PR #77）の`ErrorPageResponder`が一本化して対応：ログイン済みなら元画面へ（自ホスト内に限定して）リダイレクト + `flash.error`、未ログインならログイン画面へ`status`を渡して復帰導線を出す |
| `422 Unprocessable Entity` | バリデーションエラー。詳細形式はバリデーション・エラー表示設計書を参照 |

> **【2026-08-17 追加 / 2026-08-24 更新】未ログイン時のInertia向けエラーハンドリングを追加（issue #63）**
> **背景：** authミドルウェアが投げる`AuthenticationException`は`HandleInertiaRequests`（Inertia\Middleware）の後処理より前で発生するため、Inertiaが標準で行う「非GETへの302→303書き換え」を経由しない。302のまま返すと、DELETE/PUT/PATCHのようなリクエストがメソッドを保持したままログイン画面等を再度叩いてしまい、`405 MethodNotAllowedHttpException` になる不具合があった。
> **対応：** `bootstrap/app.php` の `withExceptions` に `AuthenticationException` 用の `render()` ハンドラを追加し、Inertiaリクエストかどうかで応答を出し分ける（非Inertiaは従来どおりLaravel標準の挙動）。未ログイン（401/302だったもの）は409 + `X-Inertia-Location` でフルページ遷移させる。実装は `app/Exceptions/UnauthenticatedInertiaRedirector.php`。既存の `StaleResourceRedirector`（issue #44）と同じ「例外種別ごとに1クラスへ委譲する」構成に揃えている。ログイン後に元の画面へ戻せるよう、`url.intended` のセッション保存もあわせて行っている（Laravel標準の`redirect()->guest()`相当）。
> **補足：** CSRFトークン不一致（419）への対応は当初issue #63の②として本対応に含めていたが、issue #70（PR #77・共通エラーページ）と対応範囲が重複するため、419のハンドリングはPR #77に一本化した。
