# Nexus 結合テスト（Laravel-Python）シナリオ

| 項目 | 内容 |
|---|---|
| 対象システム | Nexus（国内SES向け 人材・案件マッチングシステム） |
| ドキュメント種別 | 結合テストシナリオ（V字モデル：狭義の結合テストレベル。Laravel↔Python間の外部インターフェース結合に限定） |
| 対象範囲 | Laravel（`app/Services/Matching/*`, `app/Services/Ai/*`）とPython（マッチング／AI要約エンジン）間のHTTP結合点。画面表示・DB永続化・他機能連携は対象外（該当部分は `Nexus_結合テストシナリオ.md`〔総合テストシナリオ〕を参照） |
| 参照元 | `laravel/app/Services/Matching/HttpMatchingEngineClient.php`／`MatchResult.php`／`MatchingEngineException.php`、`laravel/app/Services/Ai/HttpAiSummaryClient.php`／`AiSummaryResult.php`／`AiSummaryException.php`、既存PHPUnit（`laravel/tests/Feature/MatchingControllerTest.php`／`EngineerControllerTest.php`）、設計書PR#12・QA#48 |
| 作成日 | 2026-08-14 |
| 経緯 | Laravelチームからの提案（「結合テストシナリオ レビュー：スコープと観点の整理について」）を受け、`Nexus_結合テストシナリオ.md` を総合テストシナリオへ位置づけ直した上で、Laravel↔Python間の外部結合を狭く検証する本書を新規に切り出した。既存PHPUnitとの重複を避けるため、提案された5観点は実際のテストコードと突き合わせて再検証し、既にカバー済みの観点（§1）と、真に不足している観点（§2）に整理し直している |

---

## 0. 本書の位置づけと前提

### 0.1 なぜ別紙に切り出したか

Laravelチームの指摘どおり、`Nexus_結合テストシナリオ.md` が扱う「12画面横断・DB状態/Props/フラッシュまで確認」という内容はV字モデルでは総合テスト（システムテスト）レベルにあたる。一方、Laravel↔Pythonという2コンポーネント間の結合点そのものを狭く検証するのが本来の結合テストであり、本書がその役割を担う。

### 0.2 既存PHPUnitとの重複排除方針

Laravelチームが「追加したい観点」として挙げた5点を `laravel/tests/` と突き合わせたところ、3点はすでにPHPUnit（Feature テスト、`Http::fake` によるモック）で厚くカバーされていた。これらを本書でも同じ粒度で再度手順化すると、前回のレビューで指摘された「PHPUnitと結合テストの二重化」が再発するため、以下の方針で整理した。

- **§1（PHPUnitカバー済み・参照のみ）**：AI要約フロー（正常/504/空出力）、IDのみ送信の契約、404側のerror_code分岐（ENGINEER_NOT_FOUND／裸404）。既存テストの存在と内容を記録するに留め、新規の手順化は行わない。
- **§2（真のギャップ・本書の主眼）**：タイムアウト境界、応答の契約違反耐性、422側のerror_code分岐の一部（NO_ACTIVE_PROJECT以外）。いずれもPHPUnit（`Http::fake`）では原理的に検証しづらい、または現状テストが存在しない観点。

### 0.3 前提環境

- マッチングエンジン疎通設定：`config('services.matching_engine.*')`（`connect_timeout` 既定5秒／`timeout` 既定10秒）
- AI要約エンジン疎通設定：`config('services.ai_summary.*')`（`connect_timeout` 既定5秒／`timeout` 既定30秒）
- §2のシナリオは実時間の経過を伴うため、`Http::fake` によるモックでは代替できない。ステージング環境等で疑似エンジン（意図的に応答を遅延させるスタブサーバ）を用意して実施することを前提とする。

---

## 1. PHPUnitでカバー済みの契約（参照のみ）

以下は本書のスコープ内の観点だが、既存PHPUnit Featureテストで既に検証されているため、本書では新規シナリオ化しない。実装変更時はこれらのテストが引き続きグリーンであることの確認をもって結合点の健全性とみなす。

| 観点 | 対応するPHPUnitテスト | 確認内容 |
|---|---|---|
| AI要約フロー：正常系 | `EngineerControllerTest::test_ai_summary_and_generated_at_are_saved_when_engine_returns_text` | エンジンが200・テキストありで応答した場合、`ai_summary`／`ai_summary_generated_at` が保存される |
| AI要約フロー：上流障害（504等） | `EngineerControllerTest::test_engineer_is_saved_and_error_flash_set_when_ai_engine_fails` | 504応答時、人材登録自体は成功し、`ai_summary` はNULLのまま、`flash.error` が設定される |
| AI要約フロー：空出力 | `EngineerControllerTest::test_ai_summary_is_null_and_no_error_flash_when_engine_returns_empty` | `ai_summary` が空文字で返った場合、失敗扱いにはせずNULLのまま・`flash.error` は出さない |
| AI要約フロー：更新時の再生成／非再生成 | `test_update_regenerates_ai_summary_when_appeal_note_changes`／`test_update_does_not_regenerate_ai_summary_when_appeal_note_unchanged` | `appeal_note` の変更有無によりエンジン呼び出しの要否が切り替わる |
| IDのみ送信の契約 | `MatchingControllerTest::test_engine_receives_only_engineer_id` | リクエストに `engineer_id` のみが含まれ、`project_ids` は未指定時に送信されないことを明示的にアサート |
| error_code分岐（404側） | `test_engine_404_returns_not_found`／`test_bare_404_without_error_code_is_treated_as_upstream_not_404` | `ENGINEER_NOT_FOUND` の404は「対象なし」、error_codeなしの裸404は上流障害として区別される |

> 上記のうち「IDのみ送信」については、`project_ids` を指定した場合（案件を絞り込んでのマッチング）の送信内容を検証するテストは存在しない。追加の価値が小さいと判断し本書でも新規シナリオ化はしないが、実装変更時は留意すること。

---

## 2. 本書で新規に検証する観点（真のギャップ）

### LP-TIMEOUT-01 タイムアウト境界（connect / read）

| 項目 | 内容 |
|---|---|
| 目的 | `connectTimeout`（既定5秒）・`timeout`（既定10秒）の設定値が実際に機能し、それぞれの境界を超えた場合にLaravel側が上流障害として扱うことを確認する |
| 事前条件 | 疑似マッチングエンジン（応答を意図的に遅延させられるスタブ）を用意し、Laravelから疎通可能な状態にする |

**手順**
1. TCP接続自体が確立しない（またはSYNに応答しない）宛先に対し `matching_engine.base_url` を向け、`/engineers/{id}/matching` を表示する。connectTimeout（5秒）前後の応答時間を計測する。
2. 接続は確立するが、レスポンスボディの返却を10秒超遅延させる疑似エンジンに対し、同様にマッチング画面を表示する。timeout（10秒）前後の応答時間を計測する。
3. 比較として、9秒程度で応答する疑似エンジン（タイムアウト未満）でも同様に確認し、正常応答として扱われることを確認する。

**期待結果**
- 1・2いずれも `ConnectionException` として捕捉され、`MatchingEngineException::upstream()` に変換される。画面は `emptyReason: "engine_error"`・`flash.error`「マッチングエンジンとの通信に失敗しました。時間をおいて再度お試しください。」で応答が返る（`test_connection_failure_is_treated_as_upstream` と同じ挙動になること）。
- 1の応答時間が概ね5秒前後、2の応答時間が概ね10秒前後であり、無制限に待ち続けないことを確認する。
- 3では正常にマッチング結果が表示される。

---

### LP-ERR-01 422応答のうちNO_ACTIVE_PROJECT以外のerror_codeが上流障害として扱われること

| 項目 | 内容 |
|---|---|
| 目的 | 422応答が `NO_ACTIVE_PROJECT` の場合のみ「候補0件（no_match）」として扱われ、それ以外のerror_code（バリデーションエラー等、想定外の422）は上流障害（engine_error）として区別されることを確認する |
| 事前条件 | なし（`Http::fake` でも再現可能） |

**手順**
1. マッチングエンジンが `422` かつ `error_code: "NO_ACTIVE_PROJECT"` 以外（例：`error_code: "INVALID_REQUEST"` や `error_code` フィールドなし）を返す状態を用意し、マッチング画面を表示する。

**期待結果**
- `emptyReason: "engine_error"` かつ `flash.error` が表示される（`NO_ACTIVE_PROJECT` の場合の `emptyReason: "no_match"`・flashなしとは異なる挙動になること）。
- `MatchingEngineException::KIND_UPSTREAM` として扱われ、`KIND_NO_CANDIDATE` に誤分類されないこと。

> 本シナリオは `Http::fake` のみで再現可能なため、PHPUnitへの自動テスト追加も併せて推奨する（`test_no_active_project_shows_empty_results` の隣に `test_422_with_other_error_code_is_treated_as_upstream` 相当を追加するイメージ）。

---

### LP-CONTRACT-01 応答契約違反への耐性（現状動作の記録）

| 項目 | 内容 |
|---|---|
| 目的 | `MatchResult::fromArray()` は `project_id`／`match_score` の欠落・非数値、`match_rank` の空文字のみを検出して例外化するが、**rankがA〜D以外の値・scoreが0〜100の範囲外・matches件数が5〜6件超過・スコア降順でない**場合は現状バリデーションされていないことを確認・記録する |
| 事前条件 | なし（`Http::fake` で再現可能） |
| 前提の確認 | 本シナリオは「あるべき挙動」の合否判定ではなく、**現状実装の挙動を記録する調査シナリオ**である。是正が必要かどうかはLaravelチーム・設計チームでの協議事項とする |

**手順（それぞれ個別に確認）**
1. `match_rank` に `"E"`（A〜D以外）を含む応答を返し、マッチング画面を表示する。
2. `match_score` に `150`（0〜100の範囲外）を含む応答を返し、マッチング画面を表示する。
3. `matches` を7件（上位5件表示の想定を超過）返し、マッチング画面を表示する。
4. `matches` をスコア昇順（本来は降順のはず）で返し、マッチング画面を表示する。

**期待結果（現状実装ベース）**
- 1〜4いずれも例外化されず、`MatchResult::fromArray()` を通過してそのまま画面に表示される（＝現状は上流の応答を信頼する実装になっている）ことを確認する。
- 各ケースで実際に画面上どう表示されるか（不正なrankバッジの表示崩れ、件数超過時の一覧の見え方、順序が守られない場合の並び）をスクリーンショット等で記録し、UI上の実害があるかを判定する。
- UI上の実害が確認された項目については、`MatchResult::fromArray()` 側でのバリデーション追加要否を別途Issue化する。

---

## 3. 実施結果サマリー（記入用テンプレート）

| シナリオID | 実施日 | 実施者 | 結果（OK/NG/現状記録） | 不具合・Issue番号 | 備考 |
|---|---|---|---|---|---|
| LP-TIMEOUT-01 | | | | | |
| LP-ERR-01 | | | | | |
| LP-CONTRACT-01 | | | | | |

---

## 4. 未確定事項・今後の対応

| 項目 | 内容 | 対応 |
|---|---|---|
| LP-CONTRACT-01の是正要否 | rank範囲外／score範囲外／件数超過／順序不正のいずれについても現状バリデーションなし | 本書での動作記録結果をもとに、Laravelチーム・設計チームで是正要否を協議する |
| LP-ERR-01の自動化 | `Http::fake` のみで再現可能なため、PHPUnitへの追加が望ましい | Laravelチームでの対応を推奨（本書は手順の記録として残す） |
| project_ids指定時の送信内容 | 案件を絞り込んだマッチング実行時に `project_ids` が正しく送信されるかの直接テストは現状なし | 優先度は低いと判断し本書では見送るが、実装変更時は留意 |
